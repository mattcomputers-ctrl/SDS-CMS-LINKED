<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;

/**
 * Dashboard review counts (2026-10-22): what is pending on CAS
 * Determinations, counted from the same lists the page shows —
 *   needs: rows of the "Needs Determination" tab (needsDeterminationRows());
 *   tsca:  rows of the TSCA Review list (sheets Bulk SDS Publish is cleared
 *          to publish, TSCAService::reviewCandidates());
 *   stot:  substances still needing target organs (StotTargetOrgans::needsOrgans()).
 * The TSCA count runs the bulk publish planner, so the counts are cached for
 * TTL seconds in paths.cache; every visit to CAS Determinations stores the
 * counts it has just computed (fromLists()), and the dashboard computes them
 * only when the cache is missing or old (DashboardController). A cache that
 * cannot be read or written just means computing every time.
 */
final class ReviewCounts
{
    /** Seconds the dashboard shows stored counts before computing them again. */
    public const TTL = 600;

    private const FILE = 'dashboard-review-counts.json';

    /**
     * The "Needs Determination" tab: every constituent CAS (not a trade
     * secret) without an active determination. Raw materials marked "No SDS
     * required" (migration 064) are left out unless a current formula uses
     * them (their data still feeds that finished good).
     */
    public static function needsDeterminationRows(Database $db): array
    {
        $rmNeedsSds = \SDS\Models\RawMaterial::sqlNeedsSds('rm');
        return $db->fetchAll(
            "SELECT rmc.cas_number,
                    rmc.chemical_name,
                    COUNT(DISTINCT rm.id) AS raw_material_count,
                    GROUP_CONCAT(DISTINCT rm.internal_code ORDER BY rm.internal_code SEPARATOR ', ') AS raw_material_codes,
                    MAX(CASE WHEN EXISTS (
                        SELECT 1 FROM formula_lines fl
                        JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
                        WHERE fl.raw_material_id = rm.id
                    ) THEN 1 ELSE 0 END) AS in_formula,
                    MAX(CASE WHEN EXISTS (
                        SELECT 1 FROM hazard_classifications hc
                        JOIN hazard_source_records hsr ON hsr.id = hc.hazard_source_record_id
                        WHERE hc.cas_number = rmc.cas_number AND hsr.is_current = 1
                    ) OR EXISTS (
                        SELECT 1 FROM exposure_limits el
                        JOIN hazard_source_records hsr ON hsr.id = el.hazard_source_record_id
                        WHERE el.cas_number = rmc.cas_number AND hsr.is_current = 1
                    ) THEN 1 ELSE 0 END) AS has_federal_data
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id
             WHERE rmc.cas_number != ''
               AND rmc.is_trade_secret = 0
               AND NOT EXISTS (
                   SELECT 1 FROM competent_person_determinations cpd
                   WHERE cpd.cas_number = rmc.cas_number AND cpd.is_active = 1
               )
               AND ({$rmNeedsSds} OR EXISTS (
                   SELECT 1 FROM formula_lines fl2
                   JOIN formulas f2 ON f2.id = fl2.formula_id AND f2.is_current = 1
                   WHERE fl2.raw_material_id = rm.id
               ))
             GROUP BY rmc.cas_number, rmc.chemical_name
             ORDER BY in_formula DESC, has_federal_data ASC, raw_material_count DESC, rmc.chemical_name ASC"
        );
    }

    /**
     * DB-free: the counts from the page's lists.
     *
     * @return array{needs:int, tsca:int, stot:int}
     */
    public static function fromLists(array $needsRows, array $tscaReview, array $stotRows): array
    {
        return [
            'needs' => count($needsRows),
            'tsca'  => count($tscaReview),
            'stot'  => count(array_filter($stotRows, [StotTargetOrgans::class, 'needsOrgans'])),
        ];
    }

    /**
     * Stored counts no older than TTL seconds, with 'at' (UTC Y-m-d H:i:s),
     * or null.
     *
     * @return array{needs:int, tsca:int, stot:int, at:string}|null
     */
    public static function read(?string $dir = null, ?int $now = null): ?array
    {
        $file = self::file($dir);
        $raw  = is_file($file) ? @file_get_contents($file) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['at'], $data['needs'], $data['tsca'], $data['stot'])) {
            return null;
        }
        $at = strtotime((string) $data['at'] . ' UTC');
        if ($at === false || $at + self::TTL < ($now ?? time())) {
            return null;
        }
        return ['needs' => (int) $data['needs'], 'tsca' => (int) $data['tsca'], 'stot' => (int) $data['stot'], 'at' => (string) $data['at']];
    }

    /**
     * Store counts stamped now (UTC); failures are ignored.
     *
     * @param array{needs:int, tsca:int, stot:int} $counts
     * @return array{needs:int, tsca:int, stot:int, at:string}
     */
    public static function store(array $counts, ?string $dir = null, ?int $now = null): array
    {
        $out = ['needs' => (int) $counts['needs'], 'tsca' => (int) $counts['tsca'], 'stot' => (int) $counts['stot'],
                'at' => gmdate('Y-m-d H:i:s', $now ?? time())];
        $file = self::file($dir);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($out)) !== false) {
            @rename($tmp, $file);   // atomic: a reader never sees half a file
        }
        return $out;
    }

    private static function file(?string $dir): string
    {
        $dir ??= (string) App::config('paths.cache', App::basePath() . '/storage/cache');
        return rtrim($dir, '/\\') . '/' . self::FILE;
    }
}
