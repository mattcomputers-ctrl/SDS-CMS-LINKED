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

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Services\BackupService;

echo "[" . date('Y-m-d H:i:s') . "] Scheduled backup check...\n";

$config = BackupService::getFtpConfig();

// Check if scheduled backups are enabled
if (($config['schedule_enabled'] ?? '') !== '1') {
    echo "Scheduled backups are disabled. Exiting.\n";
    exit(0);
}

$force = in_array('--force', $argv ?? [], true);

// Check if it's time to run based on schedule_frequency
if (!$force) {
    $frequency = $config['schedule_frequency'] ?? 'daily';
    $scheduleTime = $config['schedule_time'] ?? '02:00';
    $currentHour = (int) date('H');
    $currentMinute = (int) date('i');
    $scheduleParts = explode(':', $scheduleTime);
    $scheduleHour = (int) ($scheduleParts[0] ?? 2);
    $scheduleMinute = (int) ($scheduleParts[1] ?? 0);

    // Only run if current time matches schedule time (within 30-minute window)
    $currentMinutes = $currentHour * 60 + $currentMinute;
    $scheduleMinutes = $scheduleHour * 60 + $scheduleMinute;
    if (abs($currentMinutes - $scheduleMinutes) > 30) {
        echo "Not scheduled to run at this time (schedule: {$scheduleTime}, current: " . date('H:i') . "). Exiting.\n";
        exit(0);
    }

    // For weekly, only run on Sunday
    if ($frequency === 'weekly' && (int) date('w') !== 0) {
        echo "Weekly backup only runs on Sunday. Exiting.\n";
        exit(0);
    }

    // For monthly, only run on the 1st
    if ($frequency === 'monthly' && (int) date('j') !== 1) {
        echo "Monthly backup only runs on the 1st. Exiting.\n";
        exit(0);
    }
}

echo "Running scheduled backup...\n";

try {
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
            $db = Database::getInstance();
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
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
