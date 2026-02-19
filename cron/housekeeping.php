#!/usr/bin/env php
<?php
/**
 * Cron: Housekeeping — clean up old logs, temp files, expired sessions.
 *
 * Recommended schedule: daily at 4:00 AM
 *   0 4 * * * /usr/bin/php /path/to/sds-system/cron/housekeeping.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Core\Database;

echo "[" . date('Y-m-d H:i:s') . "] Starting housekeeping...\n";

$db = Database::getInstance();

// Purge old audit log entries beyond retention period
$retentionDays = (int) (App::config('cron.log_retention_days', 365));
$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

$purged = $db->delete('audit_log', 'timestamp < ?', [$cutoffDate]);
echo "Purged {$purged} audit log entries older than {$retentionDays} days.\n";

// Purge old dataset refresh logs (keep last 100)
$oldLogs = $db->fetchAll(
    "SELECT id FROM dataset_refresh_log ORDER BY started_at DESC LIMIT 100, 999999"
);
if (!empty($oldLogs)) {
    $ids = array_column($oldLogs, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->delete('dataset_refresh_log', "id IN ({$placeholders})", $ids);
    echo "Purged " . count($ids) . " old refresh log entries.\n";
}

// Clean temp directory
$tempDir = App::config('paths.temp', __DIR__ . '/../storage/temp');
if (is_dir($tempDir)) {
    $count = 0;
    foreach (glob($tempDir . '/*') as $file) {
        if (is_file($file) && filemtime($file) < time() - 86400) {
            unlink($file);
            $count++;
        }
    }
    echo "Cleaned {$count} temp files.\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Housekeeping complete.\n";
