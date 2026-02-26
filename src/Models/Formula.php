<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * Formula Model — versioned formulations linking finished goods to raw materials.
 *
 * Each finished good has one "current" formula. When a formula is updated,
 * a new version is created and the previous is marked is_current = 0.
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
     * Get all lines for a formula, with raw material data joined in.
     */
    public static function getLines(int $formulaId): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT fl.id, fl.formula_id, fl.raw_material_id, fl.pct, fl.sort_order,
                    rm.internal_code, rm.supplier, rm.supplier_product_name,
                    rm.voc_wt, rm.exempt_voc_wt, rm.water_wt, rm.flash_point_c
             FROM formula_lines fl
             JOIN raw_materials rm ON rm.id = fl.raw_material_id
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
     * @param array  $lines   Array of [raw_material_id, pct, sort_order].
     * @param string|null $notes
     * @param int|null    $userId
     * @return int   New formula ID.
     * @throws \InvalidArgumentException if lines don't sum to 100%.
     */
    public static function create(int $fgId, array $lines, ?string $notes, ?int $userId): int
    {
        $db = Database::getInstance();

        // Validate total percentage
        $validationError = self::validateTotalPercent($lines);
        if ($validationError !== null) {
            throw new \InvalidArgumentException($validationError);
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
                $db->insert('formula_lines', [
                    'formula_id'      => $formulaId,
                    'raw_material_id' => (int) $line['raw_material_id'],
                    'pct'             => (float) $line['pct'],
                    'sort_order'      => (int) ($line['sort_order'] ?? $i + 1),
                ]);
            }

            $db->commit();
            return $formulaId;

        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------
     *  Expanded Composition
     * ----------------------------------------------------------------*/

    /**
     * Expand all raw material constituents into final CAS-level concentrations.
     *
     * For each CAS number, the concentration is calculated as the sum across
     * all formula lines of:
     *
     *   (formula_line.pct / 100) * constituent_pct
     *
     * where constituent_pct is pct_exact if set, otherwise the midpoint of
     * pct_min and pct_max.
     *
     * @param int $formulaId
     * @return array  Sorted by concentration_pct descending. Each element:
     *   [
     *     'cas_number'             => string,
     *     'chemical_name'          => string,
     *     'concentration_pct'      => float,
     *     'is_trade_secret'        => bool,
     *     'contributing_materials' => [
     *       ['raw_material_id' => int, 'internal_code' => string, 'pct_in_rm' => float, 'pct_in_formula' => float],
     *       ...
     *     ],
     *   ]
     */
    public static function getExpandedComposition(int $formulaId): array
    {
        $db = Database::getInstance();

        // Fetch all formula lines with their constituents in a single query
        $rows = $db->fetchAll(
            "SELECT fl.raw_material_id, fl.pct AS line_pct,
                    rm.internal_code,
                    rmc.cas_number, rmc.chemical_name,
                    rmc.pct_exact, rmc.pct_min, rmc.pct_max,
                    rmc.is_trade_secret, rmc.is_non_hazardous,
                    rmc.trade_secret_description
             FROM formula_lines fl
             JOIN raw_materials rm ON rm.id = fl.raw_material_id
             JOIN raw_material_constituents rmc ON rmc.raw_material_id = fl.raw_material_id
             WHERE fl.formula_id = ?
             ORDER BY fl.sort_order, rmc.sort_order",
            [$formulaId]
        );

        // Aggregate by CAS number
        $casBuckets = [];
        foreach ($rows as $row) {
            $cas = $row['cas_number'];

            // Determine the constituent's percentage in the raw material
            if ($row['pct_exact'] !== null) {
                $constituentPct = (float) $row['pct_exact'];
            } elseif ($row['pct_min'] !== null && $row['pct_max'] !== null) {
                $constituentPct = ((float) $row['pct_min'] + (float) $row['pct_max']) / 2.0;
            } elseif ($row['pct_min'] !== null) {
                $constituentPct = (float) $row['pct_min'];
            } elseif ($row['pct_max'] !== null) {
                $constituentPct = (float) $row['pct_max'];
            } else {
                $constituentPct = 0.0;
            }

            // Contribution in the final product: (line_pct / 100) * constituent_pct
            $contribution = ((float) $row['line_pct'] / 100.0) * $constituentPct;

            if (!isset($casBuckets[$cas])) {
                $casBuckets[$cas] = [
                    'cas_number'               => $cas,
                    'chemical_name'            => $row['chemical_name'],
                    'concentration_pct'        => 0.0,
                    'is_trade_secret'          => false,
                    'is_non_hazardous'         => true, // assume non-hazardous until proven otherwise
                    'trade_secret_description' => null,
                    'contributing_materials'    => [],
                ];
            }

            $casBuckets[$cas]['concentration_pct'] += $contribution;

            // If any contributing constituent is trade secret, mark the CAS as trade secret
            if ((int) $row['is_trade_secret'] === 1) {
                $casBuckets[$cas]['is_trade_secret'] = true;
                if (!empty($row['trade_secret_description'])) {
                    $casBuckets[$cas]['trade_secret_description'] = $row['trade_secret_description'];
                }
            }

            // If any contributing constituent is NOT non-hazardous, mark CAS as hazardous
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

        // Round concentrations and sort by descending concentration
        foreach ($casBuckets as &$bucket) {
            $bucket['concentration_pct'] = round($bucket['concentration_pct'], 4);
        }
        unset($bucket);

        $result = array_values($casBuckets);

        usort($result, function (array $a, array $b): int {
            return $b['concentration_pct'] <=> $a['concentration_pct'];
        });

        return $result;
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
}
