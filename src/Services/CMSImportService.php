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
            'errors'                => [],
            'incomplete_materials'  => [],
        ];

        // Phase 1: Create all finished goods first (so sub-components exist)
        $fgMap = $this->importFinishedGoods($items, $userId, $results);

        // Phase 2: Collect and create all raw materials
        $rmMap = $this->importRawMaterials($items, $userId, $results);

        // Phase 3: Create or update formulas
        $this->importFormulas($items, $fgMap, $rmMap, $userId, $results);

        // Phase 4: Identify incomplete raw materials
        $results['incomplete_materials'] = $this->getIncompleteRawMaterials();

        return $results;
    }

    /* ------------------------------------------------------------------
     *  Phase 1: Import Finished Goods
     * ----------------------------------------------------------------*/

    private function importFinishedGoods(array $items, ?int $userId, array &$results): array
    {
        $fgMap = [];

        foreach ($items as $item) {
            $code = $item['ItemCode'];

            // If it already exists in SDS (regardless of status), map it
            if ($item['sds_entity_id'] !== null) {
                $fgMap[$code] = $item['sds_entity_id'];
                $results['fg_skipped'][] = $code;
                continue;
            }

            // Also check by product_code in case it was manually created
            $existing = FinishedGood::findByProductCode($code);
            if ($existing) {
                $fgMap[$code] = (int) $existing['id'];
                $results['fg_skipped'][] = $code;

                // Create an import log entry so future runs can track the recipe
                $this->upsertImportLog(
                    $code,
                    (int) $item['cms_item_pk'],
                    $item['RecipeNumber'],
                    (int) $item['cms_recipe_pk'],
                    'finished_good',
                    (int) $existing['id'],
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
                    // This is a FG sub-component — queue its recipe for scanning too
                    $subRecipePk = (int) $ing['ingredient_costing_recipe'];
                    if (!isset($scannedRecipes[$subRecipePk])) {
                        $recipesToScan[] = $subRecipePk;
                    }
                    continue;
                }

                // This is a raw material
                $code = $ing['ingredient_code'];

                if (isset($processed[$code])) {
                    continue;
                }
                $processed[$code] = true;

                $existing = RawMaterial::findByCode($code);
                if ($existing) {
                    $rmMap[$code] = (int) $existing['id'];
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
