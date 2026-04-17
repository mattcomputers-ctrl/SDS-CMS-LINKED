<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Models\Formula;
use SDS\Models\RawMaterial;

/**
 * CMSImportService — imports items and recipes from the CMS MSSQL database
 * into the SDS system as finished goods with formulas, and raw materials.
 *
 * Designed to be run repeatedly from the web interface:
 *   - New CMS items → created as finished goods or raw materials
 *   - Existing items with a different CostingRecipe number → formula updated
 *     (new formula version created in the SDS system)
 *   - Existing items with the same recipe → skipped
 *
 * Key classification:
 *   - CMS Items with a CostingRecipe → Finished Goods
 *   - Recipe ingredients (RecipeDetail Context='UI') that do NOT have their
 *     own CostingRecipe → Raw Materials
 *   - Recipe ingredients that DO have a CostingRecipe → Finished Good
 *     sub-components (linked via finished_good_component_id)
 */
class CMSImportService
{
    private CMSDatabase $cms;
    private Database $db;

    public function __construct()
    {
        $this->cms = CMSDatabase::getInstance();
        $this->db  = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  Query CMS data
     * ----------------------------------------------------------------*/

    /**
     * Get all CMS items that have a CostingRecipe.
     *
     * Each item is tagged with its SDS-system status:
     *   - 'new'       — not yet imported
     *   - 'current'   — imported, same recipe number
     *   - 'revised'   — imported, but CMS recipe number has changed
     */
    public function getAvailableItems(): array
    {
        $items = $this->cms->fetchAll(
            "SELECT
                i.Item AS cms_item_pk,
                i.ItemCode,
                i.Description,
                r.Recipe AS cms_recipe_pk,
                r.RecipeNumber,
                (SELECT COUNT(*) FROM CMS.dbo.RecipeDetail rd
                 WHERE rd.Recipe = r.Recipe AND rd.Context = 'UI') AS ingredient_count
             FROM CMS.dbo.Item i
             JOIN CMS.dbo.Recipe r ON i.CostingRecipe = r.Recipe
             WHERE i.CostingRecipe IS NOT NULL
               AND r.RecipeNumber LIKE '%.%'
             ORDER BY i.ItemCode"
        );

        // Load import log for FGs — keyed by item code
        $importLog = $this->getImportLogForFinishedGoods();

        foreach ($items as &$item) {
            $code = $item['ItemCode'];
            $log = $importLog[$code] ?? null;

            if ($log === null) {
                $item['import_status'] = 'new';
                $item['sds_entity_id'] = null;
                $item['last_recipe']   = null;
            } elseif ($log['cms_recipe_number'] !== $item['RecipeNumber']) {
                $item['import_status'] = 'revised';
                $item['sds_entity_id'] = (int) $log['entity_id'];
                $item['last_recipe']   = $log['cms_recipe_number'];
            } else {
                $item['import_status'] = 'current';
                $item['sds_entity_id'] = (int) $log['entity_id'];
                $item['last_recipe']   = $log['cms_recipe_number'];
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Get the recipe detail (ingredients) for a CMS recipe.
     */
    public function getRecipeIngredients(int $cmsRecipePk): array
    {
        return $this->cms->fetchAll(
            "SELECT
                rd.RecipeDetail,
                rd.Line,
                rd.QtyReqd,
                ing.Item AS ingredient_item_pk,
                ing.ItemCode AS ingredient_code,
                ing.Description AS ingredient_description,
                ing.CostingRecipe AS ingredient_costing_recipe,
                ing_r.RecipeNumber AS ingredient_recipe_number
             FROM CMS.dbo.RecipeDetail rd
             JOIN CMS.dbo.Item ing ON rd.Item = ing.Item
             LEFT JOIN CMS.dbo.Recipe ing_r ON ing.CostingRecipe = ing_r.Recipe
             WHERE rd.Recipe = ? AND rd.Context = 'UI'
             ORDER BY rd.Line",
            [$cmsRecipePk]
        );
    }

    /* ------------------------------------------------------------------
     *  Preview
     * ----------------------------------------------------------------*/

    /**
     * Analyse what would happen on import without making changes.
     */
    public function preview(): array
    {
        $items = $this->getAvailableItems();

        $fgToCreate    = [];
        $fgToUpdate    = [];
        $fgCurrent     = [];
        $rmToCreate    = [];
        $rmExisting    = [];
        $seenRmCodes   = [];

        $existingRmCodes = $this->getExistingRawMaterialCodes();

        foreach ($items as $item) {
            switch ($item['import_status']) {
                case 'new':
                    $fgToCreate[] = $item['ItemCode'];
                    break;
                case 'revised':
                    $fgToUpdate[] = [
                        'code'       => $item['ItemCode'],
                        'old_recipe' => $item['last_recipe'],
                        'new_recipe' => $item['RecipeNumber'],
                    ];
                    break;
                default:
                    $fgCurrent[] = $item['ItemCode'];
                    break;
            }

            // Check recipe ingredients for new raw materials
            $ingredients = $this->getRecipeIngredients((int) $item['cms_recipe_pk']);

            foreach ($ingredients as $ing) {
                if ($this->isFinishedGoodIngredient($ing)) {
                    continue; // This is a FG sub-component, not a RM
                }

                $code = $ing['ingredient_code'];
                if (isset($seenRmCodes[$code])) {
                    continue;
                }
                $seenRmCodes[$code] = true;

                if (in_array($code, $existingRmCodes, true)) {
                    $rmExisting[] = $code;
                } else {
                    $rmToCreate[] = $code;
                }
            }
        }

        return [
            'fg_to_create'   => $fgToCreate,
            'fg_to_update'   => $fgToUpdate,
            'fg_current'     => $fgCurrent,
            'rm_to_create'   => array_unique($rmToCreate),
            'rm_existing'    => array_unique($rmExisting),
            'total_items'    => count($items),
        ];
    }

    /* ------------------------------------------------------------------
     *  Execute import
     * ----------------------------------------------------------------*/

    /**
     * Import/sync all CMS items into the SDS system.
     *
     * - New items → created as FG with formula
     * - Revised recipes → new formula version created
     * - Unchanged → skipped
     * - New raw materials discovered → created
     */
    public function import(?int $userId): array
    {
        $items = $this->getAvailableItems();

        $results = [
            'fg_created'            => [],
            'fg_skipped'            => [],
            'formulas_created'      => 0,
            'formulas_updated'      => 0,
            'formulas_skipped'      => 0,
            'rm_created'            => [],
            'rm_skipped'            => [],
            'rm_refreshed'          => 0,
            'aliases_created'       => 0,
            'aliases_updated'       => 0,
            'shipments_imported'    => 0,
            'customers_created'     => 0,
            'errors'                => [],
            'incomplete_materials'  => [],
        ];

        // Phase 1: Create all finished goods first (so sub-components exist)
        $fgMap = $this->importFinishedGoods($items, $userId, $results);

        // Phase 2: Collect and create all raw materials
        $rmMap = $this->importRawMaterials($items, $userId, $results);

        // Phase 2b: Refresh RM metadata (description, preferred supplier, Their Code)
        $this->refreshRawMaterialMetadata($results);

        // Phase 3: Create or update formulas
        $this->importFormulas($items, $fgMap, $rmMap, $userId, $results);

        // Phase 4: Identify incomplete raw materials
        $results['incomplete_materials'] = $this->getIncompleteRawMaterials();

        // Phase 5: Import aliases from CMS
        $this->importAliases($results);

        // Phase 6: Import shipment data from CMS
        $this->importShipments($results);

        // Phase 7: Auto-create customers from shipment Ship To codes
        $this->autoCreateCustomers($results);

        // Record import completion metadata
        $this->recordImportCompletion($userId);

        return $results;
    }

    /**
     * Record when the import completed and who/what triggered it.
     * Cron invokes with $userId = null → trigger is "cron".
     * Web UI invokes with current user ID → trigger is "manual".
     */
    private function recordImportCompletion(?int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $trigger = $userId === null ? 'cron' : 'manual';

        $userName = 'cron';
        if ($userId !== null) {
            $user = $this->db->fetch("SELECT display_name, username FROM users WHERE id = ?", [$userId]);
            if ($user) {
                $userName = $user['display_name'] ?: $user['username'];
            }
        }

        foreach ([
            'cms_sync.last_completed_at' => $now,
            'cms_sync.last_trigger'      => $trigger,
            'cms_sync.last_triggered_by' => $userName,
        ] as $key => $val) {
            $existing = $this->db->fetch("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
            if ($existing) {
                $this->db->update('settings', ['value' => $val], "`key` = ?", [$key]);
            } else {
                $this->db->insert('settings', ['key' => $key, 'value' => $val]);
            }
        }
    }

    /* ------------------------------------------------------------------
     *  Phase 1: Import Finished Goods
     * ----------------------------------------------------------------*/

    private function importFinishedGoods(array $items, ?int $userId, array &$results): array
    {
        $fgMap = [];

        // Pre-load all existing FG product codes in one query
        $existingFgs = [];
        $rows = $this->db->fetchAll("SELECT id, product_code FROM finished_goods");
        foreach ($rows as $row) {
            $existingFgs[$row['product_code']] = (int) $row['id'];
        }

        foreach ($items as $item) {
            $code = $item['ItemCode'];

            // If it already exists in SDS (from import log), map it
            if ($item['sds_entity_id'] !== null) {
                $fgMap[$code] = $item['sds_entity_id'];
                $results['fg_skipped'][] = $code;
                continue;
            }

            // Check pre-loaded map in case it was manually created
            if (isset($existingFgs[$code])) {
                $fgMap[$code] = $existingFgs[$code];
                $results['fg_skipped'][] = $code;

                $this->upsertImportLog(
                    $code,
                    (int) $item['cms_item_pk'],
                    $item['RecipeNumber'],
                    (int) $item['cms_recipe_pk'],
                    'finished_good',
                    $existingFgs[$code],
                    $userId
                );
                continue;
            }

            try {
                $fgId = FinishedGood::create([
                    'product_code' => $code,
                    'description'  => $item['Description'] ?? '',
                    'is_active'    => 1,
                    'created_by'   => $userId,
                ]);

                $fgMap[$code] = $fgId;
                $results['fg_created'][] = $code;

                $this->upsertImportLog(
                    $code,
                    (int) $item['cms_item_pk'],
                    $item['RecipeNumber'],
                    (int) $item['cms_recipe_pk'],
                    'finished_good',
                    $fgId,
                    $userId
                );
            } catch (\Throwable $e) {
                $results['errors'][] = "FG {$code}: " . $e->getMessage();
            }
        }

        return $fgMap;
    }

    /* ------------------------------------------------------------------
     *  Phase 2: Import Raw Materials
     * ----------------------------------------------------------------*/

    private function importRawMaterials(array $items, ?int $userId, array &$results): array
    {
        $rmMap = [];
        $processed = [];

        // Pre-load all existing RM codes in one query
        $existingRms = [];
        $rmRows = $this->db->fetchAll("SELECT id, internal_code FROM raw_materials");
        foreach ($rmRows as $rmRow) {
            $existingRms[$rmRow['internal_code']] = (int) $rmRow['id'];
        }

        // Collect all CMS recipe PKs to scan — top-level items AND FG sub-components
        $recipesToScan = [];
        foreach ($items as $item) {
            $recipesToScan[] = (int) $item['cms_recipe_pk'];
        }

        // Also walk FG sub-component recipes recursively to find all leaf RMs
        $scannedRecipes = [];
        while (!empty($recipesToScan)) {
            $recipePk = array_shift($recipesToScan);

            if (isset($scannedRecipes[$recipePk])) {
                continue;
            }
            $scannedRecipes[$recipePk] = true;

            $ingredients = $this->getRecipeIngredients($recipePk);

            foreach ($ingredients as $ing) {
                if ($this->isFinishedGoodIngredient($ing)) {
                    $subRecipePk = (int) $ing['ingredient_costing_recipe'];
                    if (!isset($scannedRecipes[$subRecipePk])) {
                        $recipesToScan[] = $subRecipePk;
                    }
                    continue;
                }

                $code = $ing['ingredient_code'];

                if (isset($processed[$code])) {
                    continue;
                }
                $processed[$code] = true;

                // Check pre-loaded map instead of individual query
                if (isset($existingRms[$code])) {
                    $rmMap[$code] = $existingRms[$code];
                    $results['rm_skipped'][] = $code;
                    continue;
                }

                try {
                    $rmId = RawMaterial::create([
                        'internal_code'         => $code,
                        'supplier'              => '',
                        'supplier_product_name' => $ing['ingredient_description'] ?? '',
                        'created_by'            => $userId,
                    ]);

                    $rmMap[$code] = $rmId;
                    $results['rm_created'][] = $code;

                    $this->upsertImportLog(
                        $code,
                        (int) $ing['ingredient_item_pk'],
                        null,
                        null,
                        'raw_material',
                        $rmId,
                        $userId
                    );
                } catch (\Throwable $e) {
                    $results['errors'][] = "RM {$code}: " . $e->getMessage();
                }
            }
        }

        return $rmMap;
    }

    /* ------------------------------------------------------------------
     *  Phase 2b: Refresh RM metadata from CMS
     *  Pulls Item.Description, preferred Supplier (Entities.EntityName),
     *  and "Their Code" from the most recent PriceVersion for that
     *  preferred vendor. Batch-upserts into raw_materials keyed by id.
     * ----------------------------------------------------------------*/

    private function refreshRawMaterialMetadata(array &$results): void
    {
        $rows = $this->db->fetchAll("SELECT id, internal_code FROM raw_materials");
        if (empty($rows)) {
            return;
        }

        $idByCode = [];
        foreach ($rows as $r) {
            $idByCode[$r['internal_code']] = (int) $r['id'];
        }
        $codes = array_keys($idByCode);

        // Chunked CMS queries (SQL Server can handle thousands but we'll chunk to 500)
        $itemInfo        = [];  // code => ['description' => ..., 'supplier' => ...]
        $theirCodeByCode = [];  // code => 'EntityItemCode'

        foreach (array_chunk($codes, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            // Query 1: description + preferred vendor name
            try {
                $descRows = $this->cms->fetchAll(
                    "SELECT i.ItemCode,
                            i.Description,
                            es.EntityName AS SupplierName
                     FROM CMS.dbo.Item i
                     LEFT JOIN CMS.dbo.Entity   e  ON e.Entity      = i.Supplier
                     LEFT JOIN CMS.dbo.Entities es ON es.EntityCode = e.EntityCode
                     WHERE i.ItemCode IN ({$placeholders})",
                    $chunk
                );
                foreach ($descRows as $dr) {
                    $itemInfo[$dr['ItemCode']] = [
                        'description' => $dr['Description'] ?? null,
                        'supplier'    => $dr['SupplierName'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                $results['errors'][] = 'RM metadata (description/supplier) failed: ' . $e->getMessage();
                return;
            }

            // Query 2: Their Code scoped to preferred vendor, ordered newest first.
            // PHP picks first row per InvItemCode (alphabetical Their Code tiebreak).
            try {
                $priceRows = $this->cms->fetchAll(
                    "SELECT inv.ItemCode       AS InvItemCode,
                            pd.EntityItemCode  AS TheirCode
                     FROM CMS.dbo.Item inv
                     JOIN CMS.dbo.PriceDetail pd ON pd.InvItem = inv.Item
                     JOIN CMS.dbo.PriceVersion pv ON pv.PriceVersion = pd.PriceVersion
                     WHERE inv.Supplier IS NOT NULL
                       AND pv.Entity = inv.Supplier
                       AND inv.ItemCode IN ({$placeholders})
                       AND pd.EntityItemCode IS NOT NULL
                       AND pd.EntityItemCode != ''
                     ORDER BY inv.ItemCode ASC,
                              pv.EffectiveDate DESC,
                              pv.Version DESC,
                              pd.EntityItemCode ASC",
                    $chunk
                );
                foreach ($priceRows as $pr) {
                    // Keep only the first row seen per code (deterministic by ORDER BY)
                    $code = $pr['InvItemCode'];
                    if (!isset($theirCodeByCode[$code])) {
                        $theirCodeByCode[$code] = $pr['TheirCode'];
                    }
                }
            } catch (\Throwable $e) {
                $results['errors'][] = 'RM metadata (Their Code) failed: ' . $e->getMessage();
                return;
            }
        }

        // Update each raw material in a single transaction. ~500 UPDATEs
        // is still sub-second on local MySQL.
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare(
            "UPDATE `raw_materials`
             SET `supplier` = ?,
                 `supplier_product_name` = ?,
                 `supplier_product_code` = ?
             WHERE `id` = ?"
        );

        $pdo->beginTransaction();
        try {
            $total = 0;
            foreach ($idByCode as $code => $rmId) {
                $info  = $itemInfo[$code]        ?? ['description' => null, 'supplier' => null];
                $their = $theirCodeByCode[$code] ?? null;

                // Fall back to empty string for NOT NULL columns, NULL for the new column
                $stmt->execute([
                    $info['supplier']    ?? '',
                    $info['description'] ?? '',
                    $their,
                    $rmId,
                ]);
                $total++;
            }
            $pdo->commit();
            $results['rm_refreshed'] = $total;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $results['errors'][] = 'RM metadata update failed: ' . $e->getMessage();
        }
    }

    /* ------------------------------------------------------------------
     *  Phase 3: Import / Update Formulas
     * ----------------------------------------------------------------*/

    private function importFormulas(
        array $items,
        array $fgMap,
        array $rmMap,
        ?int $userId,
        array &$results
    ): void {
        foreach ($items as $item) {
            $code = $item['ItemCode'];
            $fgId = $fgMap[$code] ?? null;

            if ($fgId === null) {
                continue;
            }

            $status = $item['import_status'];

            // 'current' means the recipe hasn't changed — skip
            if ($status === 'current') {
                $results['formulas_skipped']++;
                continue;
            }

            // For 'new' items: only create if no formula exists yet
            // For 'revised' items: always create a new formula version
            if ($status === 'new') {
                $existingFormula = Formula::findCurrentByFinishedGood($fgId);
                if ($existingFormula) {
                    $results['formulas_skipped']++;
                    continue;
                }
            }

            $lines = $this->buildFormulaLines($item, $fgMap, $rmMap, $results);

            if (empty($lines)) {
                continue;
            }

            $note = ($status === 'revised')
                ? sprintf('Updated from CMS recipe %s (was %s)', $item['RecipeNumber'] ?? '', $item['last_recipe'] ?? '?')
                : 'Imported from CMS recipe ' . ($item['RecipeNumber'] ?? '');

            try {
                Formula::create($fgId, $lines, $note, $userId);

                if ($status === 'revised') {
                    $results['formulas_updated']++;
                } else {
                    $results['formulas_created']++;
                }

                // Update the import log with the new recipe number
                $this->upsertImportLog(
                    $code,
                    (int) $item['cms_item_pk'],
                    $item['RecipeNumber'],
                    (int) $item['cms_recipe_pk'],
                    'finished_good',
                    $fgId,
                    $userId
                );
            } catch (\Throwable $e) {
                $results['errors'][] = "Formula for {$code}: " . $e->getMessage();
            }
        }
    }

    /**
     * Build formula_lines array from CMS recipe ingredients.
     */
    private function buildFormulaLines(
        array $item,
        array $fgMap,
        array $rmMap,
        array &$results
    ): array {
        $code = $item['ItemCode'];
        $ingredients = $this->getRecipeIngredients((int) $item['cms_recipe_pk']);

        if (empty($ingredients)) {
            return [];
        }

        $lines = [];
        $sortOrder = 1;

        foreach ($ingredients as $ing) {
            $isSubComponent = ($this->isFinishedGoodIngredient($ing));
            $ingCode = $ing['ingredient_code'];
            $pct = round((float) $ing['QtyReqd'] * 100, 4);

            if ($isSubComponent) {
                $subFgId = $fgMap[$ingCode] ?? null;
                if ($subFgId === null) {
                    $results['errors'][] = "Formula for {$code}: sub-component FG '{$ingCode}' not found.";
                    continue;
                }
                $lines[] = [
                    'finished_good_component_id' => $subFgId,
                    'pct'                        => $pct,
                    'sort_order'                 => $sortOrder++,
                ];
            } else {
                $rmId = $rmMap[$ingCode] ?? null;
                if ($rmId === null) {
                    $results['errors'][] = "Formula for {$code}: raw material '{$ingCode}' not found.";
                    continue;
                }
                $lines[] = [
                    'raw_material_id' => $rmId,
                    'pct'             => $pct,
                    'sort_order'      => $sortOrder++,
                ];
            }
        }

        return $lines;
    }

    /* ------------------------------------------------------------------
     *  Incomplete Raw Materials
     * ----------------------------------------------------------------*/

    /**
     * Get raw materials imported from CMS that have no constituents saved.
     */
    public function getIncompleteRawMaterials(): array
    {
        return $this->db->fetchAll(
            "SELECT rm.id, rm.internal_code, rm.supplier_product_name, rm.created_at,
                    cil.cms_item_code
             FROM raw_materials rm
             INNER JOIN cms_import_log cil ON cil.entity_type = 'raw_material' AND cil.entity_id = rm.id
             LEFT JOIN raw_material_constituents rmc ON rmc.raw_material_id = rm.id
             WHERE rmc.id IS NULL
             ORDER BY rm.internal_code"
        );
    }

    /* ------------------------------------------------------------------
     *  Phase 5: Import Aliases
     * ----------------------------------------------------------------*/

    /**
     * Import aliases from CMS Item table (ReplacedBy self-join).
     * Alias ItemCode → customer_code, parent ItemCode → internal_code_base.
     */
    private function importAliases(array &$results): void
    {
        try {
            $aliases = $this->cms->fetchAll(
                "SELECT alias.ItemCode AS alias_code,
                        alias.Description AS alias_description,
                        inv.ItemCode AS inventory_code
                 FROM CMS.dbo.Item alias
                 JOIN CMS.dbo.Item inv ON alias.ReplacedBy = inv.Item
                 WHERE alias.ReplacedBy IS NOT NULL
                 ORDER BY alias.ItemCode"
            );
        } catch (\Throwable $e) {
            $results['errors'][] = 'Alias import failed: ' . $e->getMessage();
            return;
        }

        // Batch upsert using INSERT ... ON DUPLICATE KEY UPDATE
        $pdo = $this->db->getPdo();
        $batchSize = 500;
        $total = 0;

        for ($offset = 0; $offset < count($aliases); $offset += $batchSize) {
            $batch = array_slice($aliases, $offset, $batchSize);
            $placeholders = [];
            $params = [];

            foreach ($batch as $row) {
                $customerCode = trim($row['alias_code']);
                $description  = trim($row['alias_description'] ?? '');
                $internalCode = trim($row['inventory_code']);

                if ($customerCode === '' || $internalCode === '') {
                    continue;
                }

                $internalCodeBase = str_contains($internalCode, '-')
                    ? substr($internalCode, 0, strpos($internalCode, '-'))
                    : $internalCode;

                $placeholders[] = '(?, ?, ?, ?)';
                $params[] = $customerCode;
                $params[] = $description;
                $params[] = $internalCode;
                $params[] = $internalCodeBase;
            }

            if (empty($placeholders)) {
                continue;
            }

            $sql = "INSERT INTO `aliases` (`customer_code`, `description`, `internal_code`, `internal_code_base`)
                    VALUES " . implode(', ', $placeholders) . "
                    ON DUPLICATE KEY UPDATE
                        `description` = VALUES(`description`),
                        `internal_code` = VALUES(`internal_code`),
                        `internal_code_base` = VALUES(`internal_code_base`)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $total += count($placeholders);
        }

        $results['aliases_created'] = $total;
        $results['aliases_updated'] = 0;
    }

    /* ------------------------------------------------------------------
     *  Phase 6: Import Shipments
     * ----------------------------------------------------------------*/

    /**
     * Import shipment data from CMS ShipmentDetails view.
     * Uses a configurable lookback window (default 90 days).
     */
    private function importShipments(array &$results): void
    {
        $daysRow = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'cms_sync.shipment_days'");
        $days = (int) ($daysRow['value'] ?? 1095);

        try {
            $shipments = $this->cms->fetchAll(
                "SELECT sd.BillTo, sd.ShipTo, sd.ShipToName, sd.DateShipped,
                        sd.ItemCode, inv_item.Description AS ItemDescription,
                        sd.ItemName, alias_item.Description AS ItemNameDescription,
                        sd.QtyShipped, sd.TransDocument, sd.Ordr, sd.ChangeSet
                 FROM CMS.dbo.ShipmentDetails sd
                 LEFT JOIN CMS.dbo.Item inv_item ON inv_item.ItemCode = sd.ItemCode
                 LEFT JOIN CMS.dbo.Item alias_item ON alias_item.ItemCode = sd.ItemName
                 WHERE sd.DateShipped >= DATEADD(day, -{$days}, GETDATE())
                 ORDER BY sd.DateShipped DESC"
            );
        } catch (\Throwable $e) {
            $results['errors'][] = 'Shipment import failed: ' . $e->getMessage();
            return;
        }

        // Batch upsert using INSERT ... ON DUPLICATE KEY UPDATE
        $pdo = $this->db->getPdo();
        $batchSize = 500;
        $total = 0;
        $cols = ['bill_to','ship_to','ship_to_name','date_shipped','item_code','item_description',
                 'item_name','item_name_description','qty_shipped','invoice_number','order_number','cms_changeset'];
        $colList = '`' . implode('`, `', $cols) . '`';
        $updateParts = [];
        foreach ($cols as $c) {
            if ($c !== 'cms_changeset') { // don't update the dedup key
                $updateParts[] = "`{$c}` = VALUES(`{$c}`)";
            }
        }
        $updateSql = implode(', ', $updateParts);

        for ($offset = 0; $offset < count($shipments); $offset += $batchSize) {
            $batch = array_slice($shipments, $offset, $batchSize);
            $placeholders = [];
            $params = [];

            foreach ($batch as $row) {
                $itemCode    = trim($row['ItemCode'] ?? '');
                $dateShipped = $row['DateShipped'] ?? null;
                if ($itemCode === '' || $dateShipped === null) {
                    continue;
                }

                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params[] = trim($row['BillTo'] ?? '');
                $params[] = trim($row['ShipTo'] ?? '');
                $params[] = trim($row['ShipToName'] ?? '');
                $params[] = $dateShipped;
                $params[] = $itemCode;
                $params[] = trim($row['ItemDescription'] ?? '');
                $params[] = trim($row['ItemName'] ?? '');
                $params[] = trim($row['ItemNameDescription'] ?? '');
                $params[] = (float) ($row['QtyShipped'] ?? 0);
                $params[] = trim($row['TransDocument'] ?? '');
                $params[] = $row['Ordr'] !== null ? (string) $row['Ordr'] : null;
                $params[] = $row['ChangeSet'] ?? null;
            }

            if (empty($placeholders)) {
                continue;
            }

            $sql = "INSERT INTO `shipment_detail` ({$colList})
                    VALUES " . implode(', ', $placeholders) . "
                    ON DUPLICATE KEY UPDATE {$updateSql}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $total += count($placeholders);
        }

        $results['shipments_imported'] = $total;
    }

    /* ------------------------------------------------------------------
     *  Phase 7: Auto-Create Customers
     * ----------------------------------------------------------------*/

    /**
     * Create customer records for any Ship To codes found in shipment_detail
     * that don't already exist in the customers table.
     * Regulatory email is left blank — admin fills it in via Customer Manager.
     */
    private function autoCreateCustomers(array &$results): void
    {
        $newCustomers = $this->db->fetchAll(
            "SELECT DISTINCT sd.ship_to, sd.ship_to_name
             FROM shipment_detail sd
             LEFT JOIN customers c ON c.ship_to = sd.ship_to
             WHERE c.id IS NULL
               AND sd.ship_to IS NOT NULL AND sd.ship_to != ''"
        );

        $created = 0;
        foreach ($newCustomers as $row) {
            try {
                $this->db->insert('customers', [
                    'ship_to'      => $row['ship_to'],
                    'ship_to_name' => $row['ship_to_name'] ?? '',
                ]);
                $created++;
            } catch (\Throwable $e) {
                // Skip duplicates (race condition safe)
            }
        }

        $results['customers_created'] = $created;
    }

    /* ------------------------------------------------------------------
     *  Import Log helpers
     * ----------------------------------------------------------------*/

    /**
     * Get the import log for all finished goods, keyed by item code.
     */
    private function getImportLogForFinishedGoods(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT cms_item_code, cms_recipe_number, cms_recipe_pk, entity_id
             FROM cms_import_log
             WHERE entity_type = 'finished_good'"
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['cms_item_code']] = $row;
        }
        return $map;
    }

    /**
     * Insert or update the import log entry for an entity.
     *
     * Uses the unique index on (entity_type, entity_id) for upsert.
     */
    private function upsertImportLog(
        string $cmsItemCode,
        int $cmsItemPk,
        ?string $recipeNumber,
        ?int $recipePk,
        string $entityType,
        int $entityId,
        ?int $userId
    ): void {
        // Check if entry already exists
        $existing = $this->db->fetch(
            "SELECT id FROM cms_import_log WHERE entity_type = ? AND entity_id = ?",
            [$entityType, $entityId]
        );

        if ($existing) {
            $this->db->update('cms_import_log', [
                'cms_item_code'     => $cmsItemCode,
                'cms_item_pk'       => $cmsItemPk,
                'cms_recipe_number' => $recipeNumber,
                'cms_recipe_pk'     => $recipePk,
                'imported_by'       => $userId,
                'imported_at'       => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $existing['id']]);
        } else {
            $this->db->insert('cms_import_log', [
                'cms_item_code'     => $cmsItemCode,
                'cms_item_pk'       => $cmsItemPk,
                'cms_recipe_number' => $recipeNumber,
                'cms_recipe_pk'     => $recipePk,
                'entity_type'       => $entityType,
                'entity_id'         => $entityId,
                'imported_by'       => $userId,
            ]);
        }
    }

    /**
     * Determine if a recipe ingredient is a true finished good sub-component.
     *
     * An ingredient is only a FG if it has a CostingRecipe whose RecipeNumber
     * contains a dot (versioned formula like "E1202.03"). Ingredients whose
     * CostingRecipe points to a pack extension (like "2AB0200-10") are treated
     * as raw materials instead.
     */
    private function isFinishedGoodIngredient(array $ing): bool
    {
        if ($ing['ingredient_costing_recipe'] === null) {
            return false;
        }

        $recipeNumber = $ing['ingredient_recipe_number'] ?? '';
        return str_contains($recipeNumber, '.');
    }

    private function getExistingRawMaterialCodes(): array
    {
        $rows = $this->db->fetchAll("SELECT internal_code FROM raw_materials");
        return array_column($rows, 'internal_code');
    }
}
