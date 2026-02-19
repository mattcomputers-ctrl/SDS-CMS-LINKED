#!/usr/bin/env php
<?php
/**
 * Cron: Refresh federal hazard data (PubChem, NIOSH, EPA, DOT).
 *
 * Recommended schedule: weekly (e.g., Sunday 2:00 AM)
 *   0 2 * * 0 /usr/bin/php /path/to/sds-system/cron/refresh-federal.php >> /var/log/sds-refresh.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SDS\Core\Database;
use SDS\Services\FederalData\Connectors\PubChemConnector;
use SDS\Services\FederalData\Connectors\NIOSHConnector;

echo "[" . date('Y-m-d H:i:s') . "] Starting federal data refresh...\n";

$db = Database::getInstance();

// Get all unique CAS numbers in the system
$casList = array_column(
    $db->fetchAll("SELECT DISTINCT cas_number FROM raw_material_constituents ORDER BY cas_number"),
    'cas_number'
);

echo "Found " . count($casList) . " unique CAS numbers.\n";

$logId = $db->insert('dataset_refresh_log', [
    'source_name' => 'all',
    'status'      => 'running',
]);

$totalSuccess = 0;
$totalFailed  = 0;

// PubChem
echo "Refreshing PubChem data...\n";
$pubchem = new PubChemConnector();
$result  = $pubchem->refreshAll($casList, function($cas, $i, $total) {
    if ($i % 50 === 0) {
        echo "  PubChem: {$i}/{$total} ({$cas})\n";
    }
});
$totalSuccess += count($result['success']);
$totalFailed  += count($result['failed']);
echo "  PubChem done: " . count($result['success']) . " success, " . count($result['failed']) . " failed.\n";

// NIOSH (from cache only — no live API)
echo "Refreshing NIOSH data (cache check)...\n";
$niosh  = new NIOSHConnector();
$result = $niosh->refreshAll($casList);
echo "  NIOSH done: " . count($result['success']) . " cached.\n";

// Update log
$status = $totalFailed === 0 ? 'success' : ($totalSuccess > 0 ? 'partial' : 'error');
$db->update('dataset_refresh_log', [
    'finished_at'       => date('Y-m-d H:i:s'),
    'status'            => $status,
    'records_processed' => count($casList),
    'records_updated'   => $totalSuccess,
], 'id = ?', [$logId]);

echo "[" . date('Y-m-d H:i:s') . "] Federal data refresh complete. Status: {$status}\n";
