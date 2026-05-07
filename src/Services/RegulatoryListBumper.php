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
