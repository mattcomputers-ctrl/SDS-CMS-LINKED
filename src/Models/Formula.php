<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * Formula Model — versioned formulations linking finished goods to raw materials
 * or other finished goods (sub-assemblies).
 *
 * Each finished good has one "current" formula. When a formula is updated,
 * a new version is created and the previous is marked is_current = 0.
 *
 * Formula lines can reference either a raw material (raw_material_id) or
 * another finished good (finished_good_component_id). When a finished good
 * is used as a component, its own current formula is recursively expanded
 * to resolve the final CAS-level composition.
 *
 * The key business method is getExpandedComposition(), which resolves
 * every formula line through its raw material constituents to produce
 * a final CAS-level concentration breakdown for the finished product.
 */
class Formula
{
    /* ------------------------------------------------------------------
     *  Finders
     * ----------------------------------------------------------------*/

    /**
     * Get the current formula for a finished good, including lines + raw material info.
     */
    public static function findCurrentByFinishedGood(int $fgId): ?array
    {
        $db = Database::getInstance();

        $formula = $db->fetch(
            "SELECT f.*, fg.product_code, u.display_name AS created_by_name
             FROM formulas f
             JOIN finished_goods fg ON fg.id = f.finished_good_id
             LEFT JOIN users u ON u.id = f.created_by
             WHERE f.finished_good_id = ? AND f.is_current = 1
             ORDER BY f.version DESC
             LIMIT 1",
            [$fgId]
        );

        if (!$formula) {
            return null;
        }

        $formula['lines'] = self::getLines((int) $formula['id']);

        return $formula;
    }

    /**
     * Find a formula by ID, including lines.
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();

        $formula = $db->fetch(
            "SELECT f.*, fg.product_code, u.display_name AS created_by_name
             FROM formulas f
             JOIN finished_goods fg ON fg.id = f.finished_good_id
             LEFT JOIN users u ON u.id = f.created_by
             WHERE f.id = ?",
            [$id]
        );

        if (!$formula) {
            return null;
        }

        $formula['lines'] = self::getLines($id);

        return $formula;
    }

    /* ------------------------------------------------------------------
     *  Formula Lines
     * ----------------------------------------------------------------*/

    /**
     * Get all lines for a formula, with raw material or finished good data joined in.
     *
     * Each line will have either raw_material_id or finished_good_component_id set.
     * A 'line_type' field is added: 'raw_material' or 'finished_good'.
     */
    public static function getLines(int $formulaId): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT fl.id, fl.formula_id, fl.raw_material_id, fl.finished_good_component_id,
                    fl.pct, fl.sort_order,
                    rm.internal_code, rm.supplier, rm.supplier_product_name,
                    rm.voc_wt, rm.exempt_voc_wt, rm.water_wt, rm.flash_point_c,
                    fg_comp.product_code AS component_product_code,
                    fg_comp.description AS component_description,
                    CASE
                        WHEN fl.finished_good_component_id IS NOT NULL THEN 'finished_good'
                        ELSE 'raw_material'
                    END AS line_type
             FROM formula_lines fl
             LEFT JOIN raw_materials rm ON rm.id = fl.raw_material_id
             LEFT JOIN finished_goods fg_comp ON fg_comp.id = fl.finished_good_component_id
             WHERE fl.formula_id = ?
             ORDER BY fl.sort_order ASC, fl.id ASC",
            [$formulaId]
        );
    }

    /* ------------------------------------------------------------------
     *  Create
     * ----------------------------------------------------------------*/

    /**
     * Create a new formula version for a finished good.
     *
     * Automatically sets is_current = 1 on the new formula and
     * unsets is_current on any previous formula for that finished good.
     *
     * @param int    $fgId    Finished good ID.
     * @param array  $lines   Array of [raw_material_id|finished_good_component_id, pct, sort_order].
     * @param string|null $notes
     * @param int|null    $userId
     * @return int   New formula ID.
     * @throws \InvalidArgumentException if lines don't sum to 100% or circular dependency detected.
     */
    public static function create(int $fgId, array $lines, ?string $notes, ?int $userId): int
    {
        $db = Database::getInstance();

        // Validate total percentage
        $validationError = self::validateTotalPercent($lines);
        if ($validationError !== null) {
            throw new \InvalidArgumentException($validationError);
        }

        // Check for circular dependencies
        foreach ($lines as $line) {
            $componentFgId = (int) ($line['finished_good_component_id'] ?? 0);
            if ($componentFgId > 0) {
                $circularError = self::detectCircularDependency($fgId, $componentFgId);
                if ($circularError !== null) {
                    throw new \InvalidArgumentException($circularError);
                }
            }
        }

        $db->beginTransaction();
        try {
            // Determine next version number
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM formulas WHERE finished_good_id = ?",
                [$fgId]
            );
            $nextVersion = ($lastVersion['max_ver'] ?? 0) + 1;

            // Unset previous current formula
            $db->query(
                "UPDATE formulas SET is_current = 0 WHERE finished_good_id = ? AND is_current = 1",
                [$fgId]
            );

            // Insert new formula
            $formulaId = (int) $db->insert('formulas', [
                'finished_good_id' => $fgId,
                'version'          => $nextVersion,
                'is_current'       => 1,
                'notes'            => $notes,
                'created_by'       => $userId,
            ]);

            // Insert lines
            foreach ($lines as $i => $line) {
                $rmId = (int) ($line['raw_material_id'] ?? 0);
                $fgCompId = (int) ($line['finished_good_component_id'] ?? 0);

                $lineData = [
                    'formula_id'                => $formulaId,
                    'raw_material_id'           => $rmId > 0 ? $rmId : null,
                    'finished_good_component_id' => $fgCompId > 0 ? $fgCompId : null,
                    'pct'                       => (float) $line['pct'],
                    'sort_order'                => (int) ($line['sort_order'] ?? $i + 1),
                ];

                $db->insert('formula_lines', $lineData);
            }

            $db->commit();
            return $formulaId;

        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------
     *  Circular Dependency Detection
     * ----------------------------------------------------------------*/

    /**
     * Detect if adding componentFgId as a component of parentFgId would create a cycle.
     *
     * Walks the dependency tree of componentFgId's current formula to see if
     * parentFgId appears anywhere in the chain.
     *
     * @return string|null  Error message if circular, null if safe.
     */
    public static function detectCircularDependency(int $parentFgId, int $componentFgId, array $visited = []): ?string
    {
        // Direct self-reference
        if ($parentFgId === $componentFgId) {
            return 'A finished good cannot use itself as a component.';
        }

        // Check if we've already visited this node (cycle in sub-tree)
        if (in_array($componentFgId, $visited, true)) {
            return 'Circular dependency detected in finished good components.';
        }

        $visited[] = $componentFgId;

        // Look at the component FG's current formula for any FG references
        $db = Database::getInstance();
        $subFormula = $db->fetch(
            "SELECT id FROM formulas WHERE finished_good_id = ? AND is_current = 1 LIMIT 1",
            [$componentFgId]
        );

        if (!$subFormula) {
            return null; // No formula = no dependencies = safe
        }

        $fgComponents = $db->fetchAll(
            "SELECT finished_good_component_id
             FROM formula_lines
             WHERE formula_id = ? AND finished_good_component_id IS NOT NULL",
            [(int) $subFormula['id']]
        );

        foreach ($fgComponents as $comp) {
            $subFgId = (int) $comp['finished_good_component_id'];
            if ($subFgId === $parentFgId) {
                return 'Circular dependency detected: this finished good is already used as a component in the target product\'s formula chain.';
            }
            $error = self::detectCircularDependency($parentFgId, $subFgId, $visited);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------
     *  Expanded Composition
     * ----------------------------------------------------------------*/

    /**
     * Expand all formula lines into final CAS-level concentrations.
     *
     * For raw material lines, constituents are expanded directly.
     * For finished good component lines, the component's current formula
     * is recursively expanded and scaled by the line percentage.
     *
     * @param int   $formulaId
     * @param float $scaleFactor  Multiplier for recursive expansion (1.0 at top level).
     * @param array $ancestorFgIds  Finished good IDs in the current expansion chain (cycle guard).
     * @return array  Sorted by concentration_pct descending.
     */
    public static function getExpandedComposition(int $formulaId, float $scaleFactor = 1.0, array $ancestorFgIds = []): array
    {
        $db = Database::getInstance();

        // Get the formula's finished_good_id for cycle detection
        $formulaRow = $db->fetch("SELECT finished_good_id FROM formulas WHERE id = ?", [$formulaId]);
        $thisFgId = $formulaRow ? (int) $formulaRow['finished_good_id'] : 0;

        // --- Raw material lines: expand CAS constituents (skip trade-secret RMs) ---
        $rows = $db->fetchAll(
            "SELECT fl.raw_material_id, fl.pct AS line_pct,
                    rm.internal_code,
                    rmc.cas_number, rmc.chemical_name,
                    rmc.pct_exact, rmc.pct_min, rmc.pct_max,
                    rmc.is_trade_secret, rmc.is_non_hazardous,
                    rmc.trade_secret_description, rmc.trade_secret_h_codes
             FROM formula_lines fl
             JOIN raw_materials rm ON rm.id = fl.raw_material_id
             JOIN raw_material_constituents rmc ON rmc.raw_material_id = fl.raw_material_id
             WHERE fl.formula_id = ?
               AND fl.raw_material_id IS NOT NULL
               AND (rm.hazardous_no_cas IS NULL OR rm.hazardous_no_cas = 0)
             ORDER BY fl.sort_order, rmc.sort_order",
            [$formulaId]
        );

        // Aggregate by CAS number
        $casBuckets = [];
        $tsConstituentHazards = [];

        foreach ($rows as $row) {
            $cas = $row['cas_number'];
            $isTs = (int) $row['is_trade_secret'] === 1;

            $constituentPct = self::resolveConstituentPct($row);
            $contribution = ($scaleFactor * (float) $row['line_pct'] / 100.0) * $constituentPct;

            // Trade secret constituent with no CAS but with H codes —
            // route into the TRADE_SECRET bucket instead of a CAS bucket.
            if ($isTs && $cas === '' && !empty($row['trade_secret_h_codes'])) {
                $tsConstituentHazards[] = [
                    'h_codes'      => $row['trade_secret_h_codes'],
                    'description'  => $row['trade_secret_description'] ?? ($row['chemical_name'] ?: 'Trade Secret'),
                    'contribution' => $contribution,
                    'raw_material_id' => (int) $row['raw_material_id'],
                    'internal_code'   => $row['internal_code'],
                    'pct_in_rm'       => $constituentPct,
                ];
                continue;
            }

            if ($cas === '') {
                continue;
            }

            if (!isset($casBuckets[$cas])) {
                $casBuckets[$cas] = [
                    'cas_number'               => $cas,
                    'chemical_name'            => $row['chemical_name'],
                    'concentration_pct'        => 0.0,
                    'concentration_min'        => null,
                    'concentration_max'        => null,
                    'is_trade_secret'          => false,
                    'is_non_hazardous'         => true,
                    'trade_secret_description' => null,
                    'contributing_materials'    => [],
                ];
            }

            $casBuckets[$cas]['concentration_pct'] += $contribution;

            // Preserve original min/max range when available
            $lineScale = $scaleFactor * (float) $row['line_pct'] / 100.0;
            if ($row['pct_min'] !== null && $row['pct_max'] !== null) {
                $contribMin = $lineScale * (float) $row['pct_min'];
                $contribMax = $lineScale * (float) $row['pct_max'];
                $casBuckets[$cas]['concentration_min'] =
                    ($casBuckets[$cas]['concentration_min'] ?? 0) + $contribMin;
                $casBuckets[$cas]['concentration_max'] =
                    ($casBuckets[$cas]['concentration_max'] ?? 0) + $contribMax;
            }

            if ($isTs) {
                $casBuckets[$cas]['is_trade_secret'] = true;
                if (!empty($row['trade_secret_description'])) {
                    $casBuckets[$cas]['trade_secret_description'] = $row['trade_secret_description'];
                }
            }

            if ((int) ($row['is_non_hazardous'] ?? 0) === 0) {
                $casBuckets[$cas]['is_non_hazardous'] = false;
            }

            $casBuckets[$cas]['contributing_materials'][] = [
                'raw_material_id' => (int) $row['raw_material_id'],
                'internal_code'   => $row['internal_code'],
                'pct_in_rm'       => $constituentPct,
                'pct_in_formula'  => $contribution,
            ];
        }

        // --- Trade-secret RMs: emit a single synthetic "Trade Secret" bucket ---
        // These RMs have no CAS constituents; the vendor disclosed GHS
        // classifications only. All trade-secret contributions across the
        // formula aggregate into one row so Section 3 shows a single line.
        $tsRmRows = $db->fetchAll(
            "SELECT fl.raw_material_id, fl.pct AS line_pct,
                    rm.internal_code, rm.manual_hazard_json
             FROM formula_lines fl
             JOIN raw_materials rm ON rm.id = fl.raw_material_id
             WHERE fl.formula_id = ?
               AND fl.raw_material_id IS NOT NULL
               AND rm.hazardous_no_cas = 1",
            [$formulaId]
        );

        if (!empty($tsRmRows)) {
            $tsKey = 'TRADE_SECRET';
            $casBuckets[$tsKey] = [
                // Sentinel value — matches HazardEngine's hazardousCas key and
                // passes Section 3's disclosure check without colliding with
                // any real CAS number format.
                'cas_number'               => 'TRADE_SECRET',
                'chemical_name'            => 'Trade Secret',
                'concentration_pct'        => 0.0,
                'is_trade_secret'          => true,
                'is_non_hazardous'         => false,
                'trade_secret_description' => 'Trade Secret',
                'manual_hazard_json'       => [],
                'contributing_materials'    => [],
            ];

            foreach ($tsRmRows as $row) {
                $contribution = $scaleFactor * (float) $row['line_pct'];
                $casBuckets[$tsKey]['concentration_pct'] += $contribution;

                if (!empty($row['manual_hazard_json'])) {
                    $decoded = is_string($row['manual_hazard_json'])
                        ? json_decode($row['manual_hazard_json'], true)
                        : $row['manual_hazard_json'];
                    if (is_array($decoded)) {
                        $casBuckets[$tsKey]['manual_hazard_json'][] = $decoded;
                    }
                }

                $casBuckets[$tsKey]['contributing_materials'][] = [
                    'raw_material_id' => (int) $row['raw_material_id'],
                    'internal_code'   => $row['internal_code'],
                    'pct_in_rm'       => 100.0,
                    'pct_in_formula'  => $contribution,
                ];
            }
        }

        // --- Trade-secret constituent lines with H codes (no CAS) ---
        // These come from per-constituent trade secrets where the vendor
        // withheld the chemical identity but disclosed H codes.
        if (!empty($tsConstituentHazards)) {
            $tsKey = 'TRADE_SECRET';
            if (!isset($casBuckets[$tsKey])) {
                $casBuckets[$tsKey] = [
                    'cas_number'               => 'TRADE_SECRET',
                    'chemical_name'            => 'Trade Secret',
                    'concentration_pct'        => 0.0,
                    'is_trade_secret'          => true,
                    'is_non_hazardous'         => false,
                    'trade_secret_description' => 'Trade Secret',
                    'manual_hazard_json'       => [],
                    'contributing_materials'    => [],
                ];
            }

            foreach ($tsConstituentHazards as $tsLine) {
                $casBuckets[$tsKey]['concentration_pct'] += $tsLine['contribution'];
                if (!empty($tsLine['description'])) {
                    $casBuckets[$tsKey]['trade_secret_description'] = $tsLine['description'];
                }

                $hCodes = array_filter(array_map('trim', explode(',', $tsLine['h_codes'])));
                if (!empty($hCodes)) {
                    $casBuckets[$tsKey]['manual_hazard_json'][] =
                        self::buildHazardJsonFromHCodes($hCodes);
                }

                $casBuckets[$tsKey]['contributing_materials'][] = [
                    'raw_material_id' => $tsLine['raw_material_id'],
                    'internal_code'   => $tsLine['internal_code'],
                    'pct_in_rm'       => $tsLine['pct_in_rm'],
                    'pct_in_formula'  => $tsLine['contribution'],
                ];
            }
        }

        // --- Finished good component lines: recursive expansion ---
        $fgLines = $db->fetchAll(
            "SELECT fl.finished_good_component_id, fl.pct AS line_pct,
                    fg.product_code AS component_product_code
             FROM formula_lines fl
             JOIN finished_goods fg ON fg.id = fl.finished_good_component_id
             WHERE fl.formula_id = ? AND fl.finished_good_component_id IS NOT NULL",
            [$formulaId]
        );

        // Batch-fetch current formula IDs for all FG components at once
        $fgCompIds = [];
        foreach ($fgLines as $fgLine) {
            $compFgId = (int) $fgLine['finished_good_component_id'];
            if (!in_array($compFgId, $ancestorFgIds, true)) {
                $fgCompIds[] = $compFgId;
            }
        }

        $compFormulaMap = [];
        if (!empty($fgCompIds)) {
            $fgCompIds = array_unique($fgCompIds);
            $placeholders = implode(',', array_fill(0, count($fgCompIds), '?'));
            $compFormulaRows = $db->fetchAll(
                "SELECT id, finished_good_id FROM formulas
                 WHERE finished_good_id IN ({$placeholders}) AND is_current = 1",
                array_values($fgCompIds)
            );
            foreach ($compFormulaRows as $cfr) {
                $compFormulaMap[(int) $cfr['finished_good_id']] = (int) $cfr['id'];
            }
        }

        foreach ($fgLines as $fgLine) {
            $compFgId = (int) $fgLine['finished_good_component_id'];

            // Cycle guard: skip if this FG is already in our ancestor chain
            if (in_array($compFgId, $ancestorFgIds, true)) {
                continue;
            }

            // Find the component's current formula from batch-fetched map
            $compFormulaId = $compFormulaMap[$compFgId] ?? null;

            if ($compFormulaId === null) {
                continue; // No formula defined for this component
            }

            // Recursively expand the component's formula
            $subScale = $scaleFactor * (float) $fgLine['line_pct'] / 100.0;
            $subAncestors = array_merge($ancestorFgIds, [$thisFgId]);
            $subComposition = self::getExpandedComposition(
                $compFormulaId,
                $subScale,
                $subAncestors
            );

            // Merge sub-composition into our buckets
            foreach ($subComposition as $subEntry) {
                $cas = $subEntry['cas_number'];

                if (!isset($casBuckets[$cas])) {
                    $casBuckets[$cas] = [
                        'cas_number'               => $cas,
                        'chemical_name'            => $subEntry['chemical_name'],
                        'concentration_pct'        => 0.0,
                        'is_trade_secret'          => false,
                        'is_non_hazardous'         => true,
                        'trade_secret_description' => null,
                        'contributing_materials'    => [],
                    ];
                }

                $casBuckets[$cas]['concentration_pct'] += $subEntry['concentration_pct'];

                if ($subEntry['is_trade_secret']) {
                    $casBuckets[$cas]['is_trade_secret'] = true;
                    if (!empty($subEntry['trade_secret_description'])) {
                        $casBuckets[$cas]['trade_secret_description'] = $subEntry['trade_secret_description'];
                    }
                }

                if (!$subEntry['is_non_hazardous']) {
                    $casBuckets[$cas]['is_non_hazardous'] = false;
                }

                // Tag contributing materials as coming through the FG component
                foreach ($subEntry['contributing_materials'] as $contrib) {
                    $contrib['via_finished_good'] = $fgLine['component_product_code'];
                    $casBuckets[$cas]['contributing_materials'][] = $contrib;
                }
            }
        }

        // Round concentrations and sort by descending concentration
        foreach ($casBuckets as &$bucket) {
            $bucket['concentration_pct'] = round($bucket['concentration_pct'], 4);
            if (isset($bucket['concentration_min'])) {
                $bucket['concentration_min'] = round($bucket['concentration_min'], 4);
            }
            if (isset($bucket['concentration_max'])) {
                $bucket['concentration_max'] = round($bucket['concentration_max'], 4);
            }
        }
        unset($bucket);

        $result = array_values($casBuckets);

        usort($result, function (array $a, array $b): int {
            return $b['concentration_pct'] <=> $a['concentration_pct'];
        });

        return $result;
    }

    /* ------------------------------------------------------------------
     *  Mass Replacement
     * ----------------------------------------------------------------*/

    /**
     * Replace one raw material with another across all current formulas.
     * Convenience wrapper around massReplaceComponent().
     */
    public static function massReplaceRawMaterial(int $oldRmId, int $newRmId, ?int $userId): int
    {
        return self::massReplaceComponent(
            'raw_material', $oldRmId,
            'raw_material', $newRmId,
            $userId
        );
    }

    /**
     * Replace a component (raw material or finished good) with another
     * component (raw material or finished good) across all current formulas.
     *
     * For each current formula that contains the old component, a new
     * formula version is created with the old component swapped 1:1 for
     * the new component (same percentage and sort order). All other lines
     * are copied unchanged.
     *
     * @param string   $oldType  'raw_material' or 'finished_good'
     * @param int      $oldId    The old component ID.
     * @param string   $newType  'raw_material' or 'finished_good'
     * @param int      $newId    The new component ID.
     * @param int|null $userId   The user performing the replacement.
     * @return int     Number of formulas updated.
     */
    public static function massReplaceComponent(
        string $oldType, int $oldId,
        string $newType, int $newId,
        ?int $userId
    ): int {
        $db = Database::getInstance();

        // Determine the column to search for the old component
        $oldColumn = $oldType === 'finished_good'
            ? 'fl.finished_good_component_id'
            : 'fl.raw_material_id';

        // Find all current formulas that contain the old component
        $formulas = $db->fetchAll(
            "SELECT DISTINCT f.id AS formula_id, f.finished_good_id
             FROM formulas f
             JOIN formula_lines fl ON fl.formula_id = f.id
             WHERE f.is_current = 1 AND {$oldColumn} = ?",
            [$oldId]
        );

        $count = 0;

        foreach ($formulas as $formula) {
            $lines = self::getLines((int) $formula['formula_id']);

            $newLines = [];
            foreach ($lines as $line) {
                $newLine = [
                    'pct'        => (float) $line['pct'],
                    'sort_order' => (int) $line['sort_order'],
                ];

                // Check if this is the line to replace
                $isMatch = false;
                if ($oldType === 'finished_good' && $line['line_type'] === 'finished_good') {
                    $isMatch = ((int) $line['finished_good_component_id'] === $oldId);
                } elseif ($oldType === 'raw_material' && $line['line_type'] === 'raw_material') {
                    $isMatch = ((int) $line['raw_material_id'] === $oldId);
                }

                if ($isMatch) {
                    // Replace with the new component
                    if ($newType === 'finished_good') {
                        $newLine['finished_good_component_id'] = $newId;
                    } else {
                        $newLine['raw_material_id'] = $newId;
                    }
                } else {
                    // Keep the line as-is
                    if ($line['line_type'] === 'finished_good') {
                        $newLine['finished_good_component_id'] = (int) $line['finished_good_component_id'];
                    } else {
                        $newLine['raw_material_id'] = (int) $line['raw_material_id'];
                    }
                }

                $newLines[] = $newLine;
            }

            $oldLabel = ($oldType === 'finished_good' ? 'FG' : 'RM') . " #{$oldId}";
            $newLabel = ($newType === 'finished_good' ? 'FG' : 'RM') . " #{$newId}";

            self::create(
                (int) $formula['finished_good_id'],
                $newLines,
                sprintf('Mass replacement: %s replaced with %s', $oldLabel, $newLabel),
                $userId
            );

            $count++;
        }

        return $count;
    }

    /* ------------------------------------------------------------------
     *  Validation
     * ----------------------------------------------------------------*/

    /**
     * Validate that formula lines sum to 100%.
     *
     * Allows a tolerance of +/- 0.01% for floating-point rounding.
     *
     * @param  array $lines  Each must have a 'pct' key.
     * @return string|null   Error message, or null if valid.
     */
    public static function validateTotalPercent(array $lines): ?string
    {
        if (empty($lines)) {
            return 'Formula must have at least one line.';
        }

        $total = 0.0;
        foreach ($lines as $line) {
            $pct = (float) ($line['pct'] ?? 0);
            if ($pct <= 0) {
                return 'Each formula line must have a positive percentage.';
            }
            $total += $pct;
        }

        // Allow +/- 0.01% tolerance
        if (abs($total - 100.0) > 0.01) {
            return sprintf(
                'Formula lines must total 100%%. Current total: %.4f%%.',
                $total
            );
        }

        return null;
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Resolve the effective percentage of a constituent from its raw data.
     */
    private static function resolveConstituentPct(array $row): float
    {
        if ($row['pct_exact'] !== null) {
            return (float) $row['pct_exact'];
        }
        if ($row['pct_min'] !== null && $row['pct_max'] !== null) {
            return ((float) $row['pct_min'] + (float) $row['pct_max']) / 2.0;
        }
        if ($row['pct_min'] !== null) {
            return (float) $row['pct_min'];
        }
        if ($row['pct_max'] !== null) {
            return (float) $row['pct_max'];
        }
        return 0.0;
    }

    /**
     * Build a manual_hazard_json structure from a list of H codes.
     * Reverse-looks up GHS hazard classifications to derive the full
     * set of P codes, pictograms, and signal word.
     */
    private static function buildHazardJsonFromHCodes(array $hCodes): array
    {
        $ghsData = \SDS\Services\GHSHazardData::HAZARD_CLASSIFICATIONS;

        $selectedHazards = [];
        $allH = [];
        $allP = [];
        $allPictograms = [];
        $signalWord = null;

        foreach ($ghsData as $key => $entry) {
            $entryHCodes = $entry['h_codes'] ?? [];
            if (!empty(array_intersect($hCodes, $entryHCodes))) {
                $selectedHazards[] = $key;
                foreach ($entryHCodes as $h) {
                    $allH[$h] = true;
                }
                foreach ($entry['p_codes'] ?? [] as $p) {
                    $allP[$p] = true;
                }
                foreach ($entry['pictograms'] ?? [] as $pic) {
                    $allPictograms[$pic] = true;
                }
                $sw = $entry['signal_word'] ?? null;
                if ($sw === 'Danger') {
                    $signalWord = 'Danger';
                } elseif ($sw === 'Warning' && $signalWord !== 'Danger') {
                    $signalWord = 'Warning';
                }
            }
        }

        return [
            'selected_hazards' => json_encode($selectedHazards),
            'h_statements'     => implode(',', array_keys($allH)),
            'p_statements'     => implode(',', array_keys($allP)),
            'pictograms'       => implode(',', array_keys($allPictograms)),
            'signal_word'      => $signalWord,
        ];
    }
}
