<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * RegulatoryListBumper — propagate regulatory-list edits to RMs.
 *
 * Bulk publish's staleness check compares sds_versions.published_at
 * against MAX(raw_materials.updated_at, raw_material_constituents.
 * updated_at, competent_person_determinations.updated_at) across the
 * formula tree. So when a CAS is added to / removed from / changed in
 * a regulatory list (hap_list, prop65_list), we bump
 * raw_materials.updated_at on every RM whose constituents include
 * that CAS — that way the next bulk publish sees the affected RMs
 * (and any FG using them, transitively) as stale, and re-renders
 * SDSs with the latest regulatory text.
 *
 * Used by:
 *   - AdminController (HAP + Prop 65 CRUD pages)
 *   - scripts/import-prop65-list.php (after batch upsert)
 *
 * Design choice: we explicitly write `updated_at = UTC_TIMESTAMP()`
 * rather than relying on MySQL's ON UPDATE CURRENT_TIMESTAMP. Two
 * reasons: (1) we need to bump even when no content column is
 * "changing" — there's no other column to touch on the RM, only the
 * timestamp. (2) explicit assignment is consistent with the patterns
 * elsewhere in the codebase that suppress / control updated_at.
 */
class RegulatoryListBumper
{
    /**
     * Bump raw_materials.updated_at for every RM whose constituents
     * include the given CAS. Returns the count bumped (0 if no RMs
     * carry that CAS).
     */
    public static function bumpByCas(string $cas): int
    {
        $cas = trim($cas);
        if ($cas === '') {
            return 0;
        }

        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT DISTINCT raw_material_id
             FROM raw_material_constituents
             WHERE cas_number = ?",
            [$cas]
        );
        if (empty($rows)) {
            return 0;
        }

        $ids = array_map(static fn(array $r): int => (int) $r['raw_material_id'], $rows);
        return self::bumpRmIds($ids);
    }

    /**
     * Bulk variant for importers that touch many CASes in one pass.
     * Single SELECT to find every affected RM across the union of
     * CASes, single UPDATE to bump them all. Returns the count bumped.
     *
     * @param string[] $casList
     */
    public static function bumpByCasMany(array $casList): int
    {
        $casList = array_values(array_unique(array_filter(
            array_map('trim', $casList),
            static fn(string $c): bool => $c !== ''
        )));
        if (empty($casList)) {
            return 0;
        }

        $db = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($casList), '?'));
        $rows = $db->fetchAll(
            "SELECT DISTINCT raw_material_id
             FROM raw_material_constituents
             WHERE cas_number IN ({$placeholders})",
            $casList
        );
        if (empty($rows)) {
            return 0;
        }

        $ids = array_map(static fn(array $r): int => (int) $r['raw_material_id'], $rows);
        return self::bumpRmIds($ids);
    }

    /**
     * Proactively enqueue an SDS-update review item for every published,
     * active finished good whose current formula uses a raw material that
     * lists one of the given CASes as a constituent. This surfaces the
     * affected products on the SDS Updates page immediately when a
     * regulatory list changes, without waiting for a manual scan.
     *
     * Walks the formula tree transitively: a finished good is affected if any
     * raw material in its own formula OR in any nested sub-formula (via
     * finished_good_component_id) carries one of the CASes. Deduped against
     * existing pending queue rows (one pending row per FG, matching the
     * scan's behaviour). Returns the number of finished goods newly queued.
     *
     * @param string[] $casList
     */
    public static function queueSdsUpdatesByCas(array $casList, ?int $userId, string $reason): int
    {
        $casList = array_values(array_unique(array_filter(
            array_map('trim', $casList),
            static fn(string $c): bool => $c !== ''
        )));
        if (empty($casList)) {
            return 0;
        }

        $db = Database::getInstance();

        // 1. Direct hits: FGs whose current formula uses a raw material that
        //    lists one of these CASes as a constituent.
        $placeholders = implode(',', array_fill(0, count($casList), '?'));
        $affected = [];
        foreach ($db->fetchAll(
            "SELECT DISTINCT f.finished_good_id AS fg_id
             FROM raw_material_constituents rmc
             JOIN formula_lines fl ON fl.raw_material_id = rmc.raw_material_id
             JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
             WHERE rmc.cas_number IN ({$placeholders})",
            $casList
        ) as $r) {
            $affected[(int) $r['fg_id']] = true;
        }
        if (empty($affected)) {
            return 0;
        }

        // 2. Transitive closure upward: any FG whose current formula uses an
        //    already-affected FG as a sub-component. Repeat to a fixpoint so
        //    arbitrarily-deep nesting is covered; the guard bounds cyclic data.
        for ($guard = 0; $guard < 50; $guard++) {
            $ids = array_keys($affected);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $grew = false;
            foreach ($db->fetchAll(
                "SELECT DISTINCT f.finished_good_id AS fg_id
                 FROM formula_lines fl
                 JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
                 WHERE fl.finished_good_component_id IN ({$ph})",
                $ids
            ) as $r) {
                $fgId = (int) $r['fg_id'];
                if (!isset($affected[$fgId])) {
                    $affected[$fgId] = true;
                    $grew = true;
                }
            }
            if (!$grew) {
                break;
            }
        }

        // 3. Enqueue the affected FGs that are active and already have a
        //    published (non-alias) SDS, deduped against pending queue rows.
        $ids = array_keys($affected);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $publishable = $db->fetchAll(
            "SELECT fg.id
             FROM finished_goods fg
             WHERE fg.is_active = 1 AND fg.id IN ({$ph})
               AND EXISTS (
                   SELECT 1 FROM sds_versions sv
                   WHERE sv.finished_good_id = fg.id
                     AND sv.status = 'published' AND sv.is_deleted = 0 AND sv.alias_id IS NULL
               )",
            $ids
        );

        $queued = 0;
        foreach ($publishable as $row) {
            $fgId = (int) $row['id'];
            $existing = $db->fetch(
                "SELECT id FROM sds_update_queue WHERE finished_good_id = ? AND status = 'pending'",
                [$fgId]
            );
            if ($existing) {
                continue;
            }
            $db->insert('sds_update_queue', [
                'finished_good_id' => $fgId,
                'reason'           => mb_substr($reason, 0, 500),
                'source_type'      => 'constituent',
                'source_id'        => null,
                'queued_by'        => $userId,
            ]);
            $queued++;
        }

        return $queued;
    }

    /**
     * @param int[] $ids
     */
    private static function bumpRmIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $db = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->query(
            "UPDATE raw_materials SET updated_at = UTC_TIMESTAMP() WHERE id IN ({$placeholders})",
            $ids
        );
        return count($ids);
    }
}
