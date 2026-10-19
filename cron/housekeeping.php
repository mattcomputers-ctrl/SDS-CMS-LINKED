#!/usr/bin/env php
<?php
/**
 * Cron: Housekeeping — purge old routine audit rows and refresh logs, and
 * clean up temp files, expired bulk exports and files left by killed bulk
 * publish runs.
 *
 * Recommended schedule: daily at 4:00 AM (server time)
 *   0 4 * * * cd /path/to/sds-system && /usr/bin/php cron/housekeeping.php >> storage/logs/cron-housekeeping.log 2>&1
 *
 *   --dry-run   report what each step would delete, delete nothing
 *
 * Before 2026-10-19 this script never got past its first database call (it
 * did not bootstrap App), so none of these steps had ever run. The rules
 * are in SDS\Services\HousekeepingService.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/cron-helpers.php';

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Services\BulkPublishQueue;
use SDS\Services\HousekeepingService;

$dryRun = in_array('--dry-run', $argv ?? [], true);
$failed = false;

$stamp = static fn (): string => '[' . date('Y-m-d H:i:s') . ']';

try {
    // App sets error_reporting(0) on production, so failures are caught and
    // echoed here rather than relying on PHP to print them.
    new App();
    $db = Database::getInstance();

    echo $stamp() . ' Starting housekeeping' . ($dryRun ? ' (dry run: nothing is deleted)' : '') . "...\n";

    if (cron_in_blackout($db)) {
        echo $stamp() . " In blackout window — skipping.\n";
        exit(0);
    }
} catch (\Throwable $e) {
    echo $stamp() . ' Housekeeping FAILED to start: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}

// List a dry run's files (at most 50 per step) so the log shows what would go.
$report = static function (string $what, array $files) use ($dryRun): void {
    if (!$dryRun) {
        return;
    }
    echo 'Would delete ' . count($files) . " {$what}.\n";
    foreach (array_slice($files, 0, 50) as $file) {
        echo '  ' . basename($file) . "\n";
    }
    if (count($files) > 50) {
        echo '  ... and ' . (count($files) - 50) . " more\n";
    }
};

$delete = static function (string $what, array $files) use ($dryRun, $report): void {
    if ($dryRun) {
        $report($what, $files);
        return;
    }
    [$deleted, $notDeleted] = HousekeepingService::unlinkAll($files);
    echo "Deleted {$deleted} {$what}." . ($notDeleted > 0 ? " Could not delete {$notDeleted}." : '') . "\n";
};

// Each step runs on its own, so one failure does not skip the rest.
$step = static function (string $name, callable $fn) use (&$failed): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        $failed = true;
        echo "Step '{$name}' FAILED: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
};

// 1. Routine audit rows older than the retention (HousekeepingService::AUDIT_PURGEABLE).
//    Review records, determinations and other history are never purged.
$step('audit log', static function () use ($db, $dryRun): void {
    // The longer of config.php and the Settings → Maintenance value wins, so
    // the page can only lengthen retention.
    $configured = App::config('cron.log_retention_days', 365);
    $setting    = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'cron.log_retention_days'")['value'] ?? null;
    $days = HousekeepingService::effectiveAuditRetentionDays($configured, $setting);
    if ($days === null) {
        echo 'Audit purge skipped: cron.log_retention_days is ' . var_export($configured, true)
            . ' in config.php and ' . var_export($setting, true) . ' in Settings (needs '
            . HousekeepingService::MIN_AUDIT_RETENTION_DAYS . " or more).\n";
        return;
    }

    if ($dryRun) {
        $n = HousekeepingService::purgeAuditLog($db, $days, true);
        echo "Would purge {$n} routine audit rows older than {$days} days.\n";
        return;
    }

    // Each batch commits on its own: record what was deleted even if a
    // later batch fails, then let the failure through.
    $deleted = 0;
    $error   = null;
    try {
        HousekeepingService::purgeAuditLog($db, $days, false, $deleted);
    } catch (\Throwable $e) {
        $error = $e;
    }
    echo "Purged {$deleted} routine audit rows older than {$days} days.\n";
    if ($deleted > 0) {
        $diff = ['deleted' => $deleted, 'retention_days' => $days];
        if ($error !== null) {
            $diff['incomplete'] = true;
            $diff['error']      = $error->getMessage();
        }
        try {
            $db->insert('audit_log', [
                'user_id'     => null,
                'entity_type' => 'system',
                'entity_id'   => 'housekeeping',
                'action'      => 'audit_purge',
                'diff_json'   => json_encode($diff),
            ]);
        } catch (\Throwable $ignored) {
            // Keep the original error, if any, as the reported failure.
        }
    }
    if ($error !== null) {
        throw $error;
    }
});

// 2. Dataset refresh log: keep the newest 100 rows.
$step('refresh log', static function () use ($db, $dryRun): void {
    $oldLogs = $db->fetchAll(
        "SELECT id FROM dataset_refresh_log ORDER BY started_at DESC, id DESC LIMIT 100, 999999"
    );
    if (empty($oldLogs)) {
        return;
    }
    $ids = array_column($oldLogs, 'id');
    if ($dryRun) {
        echo 'Would purge ' . count($ids) . " old refresh log entries.\n";
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->delete('dataset_refresh_log', "id IN ({$placeholders})", $ids);
    echo 'Purged ' . count($ids) . " old refresh log entries.\n";
});

// 3. Temp files older than a day, only in storage/temp itself.
$step('temp files', static function () use ($delete): void {
    $tempDir = App::config('paths.temp', App::basePath() . '/storage/temp');
    $files   = HousekeepingService::staleTempFiles($tempDir, App::basePath(), time());
    if ($files === null) {
        echo "Temp sweep skipped: paths.temp ({$tempDir}) is not storage/temp.\n";
        return;
    }
    $delete('temp files', $files);
});

// 4. Bulk export files older than 2 hours (only the export's own files), and
//    temp files of SDS Archive ZIP builds that were killed.
$step('exports', static function () use ($delete): void {
    $delete('expired SDS export files', \SDS\Controllers\ExportController::expiredExportFiles());
    $delete('unfinished SDS archive ZIPs', HousekeepingService::staleArchiveTempFiles(App::basePath(), time()));
});

// 5. Files left by bulk publish runs that were killed (a killed runner never
//    cleans up). Skipped entirely while a runner holds the lock; files of a
//    run whose workers are still alive are always kept.
$step('bulk publish leftovers', static function () use ($db, $delete): void {
    $row = $db->fetch('SELECT IS_USED_LOCK(?) AS holder', [BulkPublishQueue::LOCK_NAME]);
    if ($row !== null && $row['holder'] !== null) {
        echo "Bulk publish is running — leftover run files not checked.\n";
        return;
    }
    $live = (string) @shell_exec("pgrep -af 'publish-worker\\.php' 2>/dev/null");
    $delete('leftover bulk publish files', HousekeepingService::staleBulkPublishFiles(App::basePath(), $live, time()));
});

echo $stamp() . ' Housekeeping ' . ($failed ? 'finished with errors' : 'complete') . ".\n";
exit($failed ? 1 : 0);
