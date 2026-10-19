<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * Cleanup rules for cron/housekeeping.php.
 *
 * Housekeeping never ran before 2026-10-19 (it used the database without
 * bootstrapping App), so every rule here runs for the first time once that is
 * fixed. The rules live here, apart from the cron script, so they can be
 * tested without a database.
 */
final class HousekeepingService
{
    /** The audit purge never runs with a retention shorter than this (days). */
    public const MIN_AUDIT_RETENTION_DAYS = 90;

    /** Rows deleted per DELETE, so a backlog never locks audit_log for long. */
    public const AUDIT_PURGE_BATCH = 5000;

    /** A large backlog drains over several nights instead of one long run. */
    public const AUDIT_PURGE_MAX_PER_RUN = 500000;

    /** Files in the temp directory are removed after this many seconds. */
    public const TEMP_TTL = 86400;

    /** Leftover bulk publish run files (killed runs) are kept this long. */
    public const RUN_FILE_TTL = 3 * 86400;

    /** Bulk publish worker logs: empty ones after 3 days, all after 30. */
    public const EMPTY_WORKER_LOG_TTL = 3 * 86400;
    public const WORKER_LOG_TTL       = 30 * 86400;

    /**
     * The only audit rows the purge may delete: routine rows that nothing
     * reads back. Everything else is kept for good. In particular, raw_material
     * rows written by a user are the only record that a person reviewed the raw
     * material (SDSReadinessService::loadReviewedRmIds and the CMS import
     * worklists read them), so purging them would make long-reviewed raw
     * materials "unreviewed" and block publishing. Competent-person, hazard,
     * regulatory-list, permission and settings history is kept too.
     */
    public const AUDIT_PURGEABLE = [
        'sds_version'       => ['bulk_publish', 'bulk_publish_alias', 'bulk_publish_resale', 'bulk_publish_resale_alias', 'download'],
        'private_label_sds' => ['download'],
        'auth'              => ['login', 'logout', 'session_timeout', 'login_failed'],
    ];

    /** Bulk publish run files in storage/exports (cron/bulk-publish.php, BulkPublishController). */
    private const RUN_FILE_PATTERN = '/^publish_(?:batch|worker|stop|manifest|progress)_([a-f0-9]{16})(?:_\d+)?\.(?:json|flag)$/';

    /** Per-worker logs in storage/logs (cron/bulk-publish.php). */
    private const WORKER_LOG_PATTERN = '/^publish_worker_([a-f0-9]{16})_\d+\.log$/';

    /**
     * Retention to use for the audit purge, or null when the purge must not
     * run (unset, zero, negative or shorter than the minimum).
     */
    public static function auditRetentionDays(mixed $configured): ?int
    {
        $days = is_numeric($configured) ? (int) $configured : 0;
        return $days >= self::MIN_AUDIT_RETENTION_DAYS ? $days : null;
    }

    /**
     * The retention the purge uses: the LONGER of config.php's
     * cron.log_retention_days and the "Audit Log Retention (days)" value saved
     * on Settings → Maintenance, so the page can only keep rows longer. Then
     * the same floor as auditRetentionDays().
     */
    public static function effectiveAuditRetentionDays(mixed $configValue, mixed $settingValue): ?int
    {
        $config  = is_numeric($configValue) ? (int) $configValue : 0;
        $setting = is_numeric($settingValue) ? (int) $settingValue : 0;
        return self::auditRetentionDays(max($config, $setting));
    }

    /**
     * WHERE clause (and its bind values) for the audit rows older than
     * $days that the purge may delete. audit_log.timestamp is UTC (App sets
     * the session time zone), so the cutoff is computed in SQL.
     *
     * @return array{0: string, 1: array}
     */
    public static function auditPurgeWhere(int $days): array
    {
        $parts  = [];
        $params = [$days];
        foreach (self::AUDIT_PURGEABLE as $entityType => $actions) {
            $parts[] = '(`entity_type` = ? AND `action` IN (' . implode(', ', array_fill(0, count($actions), '?')) . '))';
            $params[] = $entityType;
            array_push($params, ...$actions);
        }

        return ['`timestamp` < UTC_TIMESTAMP() - INTERVAL ? DAY AND (' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * Delete (or, on a dry run, count) the purgeable audit rows older than
     * $days, in batches. Each batch commits on its own, so $deleted holds the
     * rows already gone even when a later batch throws; the caller records
     * that number either way.
     */
    public static function purgeAuditLog(Database $db, int $days, bool $dryRun, int &$deleted = 0): int
    {
        [$where, $params] = self::auditPurgeWhere($days);

        if ($dryRun) {
            $row = $db->fetch("SELECT COUNT(*) AS n FROM `audit_log` WHERE {$where}", $params);
            return (int) ($row['n'] ?? 0);
        }

        $deleted = 0;
        do {
            $n = $db->query(
                "DELETE FROM `audit_log` WHERE {$where} ORDER BY `timestamp` LIMIT " . self::AUDIT_PURGE_BATCH,
                $params
            )->rowCount();
            $deleted += $n;
        } while ($n === self::AUDIT_PURGE_BATCH && $deleted < self::AUDIT_PURGE_MAX_PER_RUN);

        return $deleted;
    }

    /**
     * Files directly in storage/temp older than TEMP_TTL, or null when
     * $tempDir (paths.temp) is not storage/temp itself: the sweep then refuses
     * to run, so a mistyped paths.temp can never point it at storage/backups,
     * storage/exports or any other directory.
     *
     * @return string[]|null
     */
    public static function staleTempFiles(string $tempDir, string $basePath, int $now): ?array
    {
        $real     = realpath($tempDir);
        $expected = realpath($basePath . '/storage/temp');
        if ($real === false || $expected === false || $real !== $expected || !is_dir($real)) {
            return null;
        }

        $out = [];
        foreach (glob($real . '/*') ?: [] as $file) {
            if (is_file($file) && $now - (int) @filemtime($file) >= self::TEMP_TTL) {
                $out[] = $file;
            }
        }
        return $out;
    }

    /**
     * Run files and worker logs left by bulk publish runs that ended long ago
     * (a killed runner never cleans up after itself). Files of a run whose
     * workers are still alive are always kept: $liveWorkers is the `pgrep -af`
     * listing of publish-worker processes, which carries each run's token.
     * The caller must also skip this whole step while a runner holds the
     * bulk_publish_runner lock.
     *
     * @return string[]
     */
    public static function staleBulkPublishFiles(string $basePath, string $liveWorkers, int $now): array
    {
        $out = [];

        foreach (glob($basePath . '/storage/exports/publish_*') ?: [] as $file) {
            if (!is_file($file) || !preg_match(self::RUN_FILE_PATTERN, basename($file), $m)) {
                continue;
            }
            if (str_contains($liveWorkers, $m[1])) {
                continue;
            }
            if ($now - (int) @filemtime($file) >= self::RUN_FILE_TTL) {
                $out[] = $file;
            }
        }

        foreach (glob($basePath . '/storage/logs/publish_worker_*.log') ?: [] as $file) {
            if (!is_file($file) || !preg_match(self::WORKER_LOG_PATTERN, basename($file), $m)) {
                continue;
            }
            if (str_contains($liveWorkers, $m[1])) {
                continue;
            }
            $age = $now - (int) @filemtime($file);
            if ($age >= self::WORKER_LOG_TTL || ((int) @filesize($file) === 0 && $age >= self::EMPTY_WORKER_LOG_TTL)) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * libzip's temporary files for SDS Archive ZIPs (sds-archive-*.zip.XXXXXX,
     * or .XXXXXX.part), left in storage/exports when a build is killed during
     * close(). Never the finished sds-archive-*.zip, which an admin deletes.
     * A build in progress keeps writing its temp file, so a day-old one is dead.
     *
     * @return string[]
     */
    public static function staleArchiveTempFiles(string $basePath, int $now): array
    {
        $out = [];
        foreach (glob($basePath . '/storage/exports/sds-archive-*') ?: [] as $file) {
            if (is_file($file) && preg_match('/^sds-archive-[\w-]+\.zip\.[A-Za-z0-9]{6}(?:\.part)?$/', basename($file))
                && $now - (int) @filemtime($file) >= self::TEMP_TTL) {
                $out[] = $file;
            }
        }
        return $out;
    }

    /**
     * Unlink each file; returns [deleted, failed] counts.
     *
     * @param string[] $files
     * @return array{0: int, 1: int}
     */
    public static function unlinkAll(array $files): array
    {
        $deleted = 0;
        $failed  = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        return [$deleted, $failed];
    }
}
