<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * SDSReadinessService — "Is an FG ready to publish an SDS?"
 *
 * Uses the same definition of "reviewed raw material" as the bulk-publish
 * eligibility check (BulkPublishController::computeEligibleFinishedGoods),
 * so the per-FG review page and the bulk job always agree on which FGs
 * are publishable.
 *
 * An RM is "reviewed" when either:
 *   (a) audit_log has a row for it with a real user_id (a human saved
 *       the RM at least once — counts as sign-off even if no hazard
 *       data was entered), OR
 *   (b) at least one of its constituent CAS numbers has an active
 *       competent_person_determinations row.
 *
 * An FG is ready when every RM in its transitive formula tree (walking
 * through finished_good_component_id sub-assemblies) is reviewed. If
 * any is not, the FG is blocked and the unreviewed RMs are what the
 * operator needs to work through.
 */
class SDSReadinessService
{
    /**
     * Return the set of raw_material ids that count as "reviewed".
     *
     * Shared with BulkPublishController so both code paths apply the
     * same rule — if the rule changes here, bulk publish and the review
     * page move together.
     *
     * @return array<int,true>  RM id => true
     */
    public static function loadReviewedRmIds(Database $db): array
    {
        $reviewed = [];

        foreach ($db->fetchAll(
            "SELECT DISTINCT entity_id
             FROM audit_log
             WHERE entity_type = 'raw_material' AND user_id IS NOT NULL"
        ) as $r) {
            $reviewed[(int) $r['entity_id']] = true;
        }

        foreach ($db->fetchAll(
            "SELECT DISTINCT rmc.raw_material_id
             FROM raw_material_constituents rmc
             INNER JOIN competent_person_determinations cpd
                 ON cpd.cas_number = rmc.cas_number AND cpd.is_active = 1"
        ) as $r) {
            $reviewed[(int) $r['raw_material_id']] = true;
        }

        return $reviewed;
    }

    /**
     * Compute the readiness report for a single finished good.
     *
     * @return array{
     *   fg: array,
     *   has_formula: bool,
     *   formula_version: int|null,
     *   total_rms: int,
     *   reviewed_count: int,
     *   unreviewed: array<int,array{id:int,internal_code:string,supplier:?string,supplier_product_name:?string,is_direct:bool,via_fg_codes:array<string>}>
     * }|null  Null if the FG doesn't exist.
     */
    public static function review(int $fgId): ?array
    {
        $db = Database::getInstance();

        $fg = $db->fetch(
            "SELECT id, product_code, description, family, is_active
             FROM finished_goods
             WHERE id = ?",
            [$fgId]
        );
        if ($fg === null) {
            return null;
        }

        $formula = $db->fetch(
            "SELECT id, version
             FROM formulas
             WHERE finished_good_id = ? AND is_current = 1
             LIMIT 1",
            [$fgId]
        );

        if ($formula === null) {
            return [
                'fg'              => $fg,
                'has_formula'     => false,
                'formula_version' => null,
                'total_rms'       => 0,
                'reviewed_count'  => 0,
                'unreviewed'      => [],
            ];
        }

        $rmContext = self::walkFormula((int) $formula['id'], $db);

        $reviewedRms = self::loadReviewedRmIds($db);

        $total      = count($rmContext);
        $reviewed   = 0;
        $unreviewed = [];

        if ($total > 0) {
            $rmIds        = array_keys($rmContext);
            $placeholders = implode(',', array_fill(0, count($rmIds), '?'));
            $rmRows       = $db->fetchAll(
                "SELECT id, internal_code, supplier, supplier_product_name
                 FROM raw_materials
                 WHERE id IN ({$placeholders})
                 ORDER BY internal_code ASC",
                $rmIds
            );

            foreach ($rmRows as $row) {
                $id = (int) $row['id'];
                if (isset($reviewedRms[$id])) {
                    $reviewed++;
                    continue;
                }

                $ctx = $rmContext[$id];
                $unreviewed[] = [
                    'id'                    => $id,
                    'internal_code'         => $row['internal_code'],
                    'supplier'              => $row['supplier'],
                    'supplier_product_name' => $row['supplier_product_name'],
                    'is_direct'             => $ctx['is_direct'],
                    'via_fg_codes'          => array_values(array_unique($ctx['via_fg_codes'])),
                ];
            }
        }

        return [
            'fg'              => $fg,
            'has_formula'     => true,
            'formula_version' => (int) $formula['version'],
            'total_rms'       => $total,
            'reviewed_count'  => $reviewed,
            'unreviewed'      => $unreviewed,
        ];
    }

    /**
     * Walk the formula tree, returning per-RM context.
     *
     * For each RM encountered, track whether it appears directly on the
     * root formula (is_direct = true) and, if not, which sub-FGs carry
     * it into this formulation.
     *
     * @return array<int,array{is_direct:bool,via_fg_codes:array<string>}>
     */
    private static function walkFormula(int $rootFormulaId, Database $db): array
    {
        // Batch-load lines + formula/FG lookup maps once; recursion is
        // purely in-memory from here.
        $linesByFormula = [];
        foreach ($db->fetchAll(
            "SELECT formula_id, raw_material_id, finished_good_component_id
             FROM formula_lines"
        ) as $r) {
            $linesByFormula[(int) $r['formula_id']][] = [
                'rm' => $r['raw_material_id'] !== null ? (int) $r['raw_material_id'] : null,
                'fg' => $r['finished_good_component_id'] !== null ? (int) $r['finished_good_component_id'] : null,
            ];
        }

        $fgToFormulaId = [];
        foreach ($db->fetchAll(
            "SELECT id, finished_good_id FROM formulas WHERE is_current = 1"
        ) as $r) {
            $fgToFormulaId[(int) $r['finished_good_id']] = (int) $r['id'];
        }

        $fgCodes = [];
        foreach ($db->fetchAll("SELECT id, product_code FROM finished_goods") as $r) {
            $fgCodes[(int) $r['id']] = $r['product_code'];
        }

        $rmContext = [];

        // $rootSubCode = the product_code of the top-level sub-FG that
        // carried us off the root formula, or null while we're still on
        // the root itself. Pinning it to the first sub-FG (rather than
        // updating on each recursion) means the operator sees "via
        // FG_B" — the line they would edit in the root formula — instead
        // of some deeper intermediate code.
        $walk = function (int $formulaId, ?string $rootSubCode, array $path) use (
            &$walk, $linesByFormula, $fgToFormulaId, $fgCodes, &$rmContext
        ): void {
            if (in_array($formulaId, $path, true)) {
                return; // cycle guard
            }
            $nextPath = array_merge($path, [$formulaId]);

            foreach ($linesByFormula[$formulaId] ?? [] as $line) {
                if ($line['rm'] !== null) {
                    $rmId = $line['rm'];
                    if (!isset($rmContext[$rmId])) {
                        $rmContext[$rmId] = ['is_direct' => false, 'via_fg_codes' => []];
                    }
                    if ($rootSubCode === null) {
                        $rmContext[$rmId]['is_direct'] = true;
                    } else {
                        $rmContext[$rmId]['via_fg_codes'][] = $rootSubCode;
                    }
                } elseif ($line['fg'] !== null) {
                    $subFormulaId = $fgToFormulaId[$line['fg']] ?? null;
                    if ($subFormulaId !== null) {
                        $nextRootSub = $rootSubCode
                            ?? ($fgCodes[$line['fg']] ?? ('FG #' . $line['fg']));
                        $walk($subFormulaId, $nextRootSub, $nextPath);
                    }
                }
            }
        };

        $walk($rootFormulaId, null, []);

        return $rmContext;
    }
}
