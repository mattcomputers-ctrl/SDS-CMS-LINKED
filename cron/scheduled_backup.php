#!/usr/bin/env php
<?php
/**
 * Cron: Scheduled Backup — creates a backup and optionally uploads to FTP.
 *
 * Recommended: configure this in crontab at the desired frequency.
 * The schedule_frequency setting controls which cron invocations actually run.
 *
 * Examples:
 *   Run every hour (let the script decide based on schedule_frequency setting):
 *     0 * * * * /usr/bin/php /path/to/sds-system/cron/scheduled_backup.php
 *
 *   Or run at a fixed schedule directly via cron:
 *     Daily at 2:00 AM:
 *       0 2 * * * /usr/bin/php /path/to/sds-system/cron/scheduled_backup.php --force
 *     Weekly Sunday at 3:00 AM:
 *       0 3 * * 0 /usr/bin/php /path/to/sds-system/cron/scheduled_backup.php --force
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/cron-helpers.php';

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Services\BackupService;

// Before 2026-10-19 this script used the database without bootstrapping App,
// so every run died on its first line and no scheduled backup was ever made.
// App sets error_reporting(0) on production, so every failure is caught and
// echoed below.
try {
    new App();

    echo "[" . date('Y-m-d H:i:s') . "] Scheduled backup check...\n";

    $config = BackupService::getFtpConfig();

    // Check if scheduled backups are enabled
    if (($config['schedule_enabled'] ?? '') !== '1') {
        echo "Scheduled backups are disabled. Exiting.\n";
        exit(0);
    }

    $db = Database::getInstance();
    if (cron_in_blackout($db)) {
        echo "[" . date('Y-m-d H:i:s') . "] In blackout window — skipping.\n";
        exit(0);
    }

    $force = in_array('--force', $argv ?? [], true);

    // Check if it's time to run based on schedule_frequency / schedule_time
    if (!$force) {
        $skip = BackupService::scheduleSkipReason(
            (string) (($config['schedule_frequency'] ?? '') ?: 'daily'),
            (string) (($config['schedule_time'] ?? '') ?: '02:00'),
            time()
        );
        if ($skip !== null) {
            echo $skip . " Exiting.\n";
            exit(0);
        }

        // A cron installed more often than hourly would hit the same hour
        // twice; one scheduled backup per window is enough. The lock covers a
        // backup still running (its backups row is written at the end); the
        // row check covers one that has finished.
        $lock = fopen(App::basePath() . '/storage/scheduled_backup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            echo "A scheduled backup is already running. Exiting.\n";
            exit(0);
        }
        $recent = $db->fetch(
            "SELECT COUNT(*) AS n FROM backups
             WHERE notes = 'Scheduled backup' AND created_at > UTC_TIMESTAMP() - INTERVAL 2 HOUR"
        );
        if ((int) ($recent['n'] ?? 0) > 0) {
            echo "A scheduled backup was already made in the last 2 hours. Exiting.\n";
            exit(0);
        }
    }

    echo "Running scheduled backup...\n";

    $result = BackupService::runScheduledBackup();

    $backup = $result['backup'];
    $sizeStr = number_format($backup['file_size'] / 1048576, 1) . ' MB';
    echo "Backup created: {$backup['filename']} ({$sizeStr})\n";

    if ($result['ftp'] !== null) {
        if ($result['ftp']['success']) {
            echo "FTP upload: {$result['ftp']['message']}\n";
        } else {
            echo "FTP upload FAILED: {$result['ftp']['message']}\n";
            // Log the failure
            $db->insert('audit_log', [
                'entity_type' => 'backup',
                'entity_id'   => (string) $backup['id'],
                'action'      => 'ftp_upload_failed',
                'diff_json'   => json_encode(['error' => $result['ftp']['message']]),
            ]);
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Scheduled backup complete.\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Scheduled backup FAILED: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}
