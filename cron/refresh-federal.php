#!/usr/bin/env php
<?php
/**
 * Cron: Refresh federal hazard data (PubChem, NIOSH, EPA, DOT).
 *
 * This refresh is NON-DESTRUCTIVE — if a data source is unreachable,
 * all existing cached data is preserved. Data is only added or updated,
 * never removed.
 *
 * Covers ALL CAS numbers in the system (from formulas, regulatory lists,
 * seed data, etc.) — not just those currently used in formulas.
 *
 * Recommended schedule: weekly (e.g., Sunday 2:00 AM)
 *   0 2 * * 0 /usr/bin/php /path/to/sds-system/cron/refresh-federal.php >> /var/log/sds-refresh.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\FederalData\Connectors\PubChemConnector;
use SDS\Services\FederalData\Connectors\NIOSHConnector;

echo "[" . date('Y-m-d H:i:s') . "] Starting federal data refresh (non-destructive)...\n";

$db = Database::getInstance();

// Gather ALL unique CAS numbers from every table — not just formulas.
// This ensures regulatory data is kept current for all known chemicals.
$allCas = [];

// From raw material constituents (used in formulas)
$rows = $db->fetchAll("SELECT DISTINCT cas_number FROM raw_material_constituents ORDER BY cas_number");
foreach ($rows as $r) {
    $allCas[$r['cas_number']] = true;
}

// From the CAS master identity cache
$rows = $db->fetchAll("SELECT cas_number FROM cas_master");
foreach ($rows as $r) {
    $allCas[$r['cas_number']] = true;
}

// From Prop 65 list
$rows = $db->fetchAll("SELECT cas_number FROM prop65_list");
foreach ($rows as $r) {
    $allCas[$r['cas_number']] = true;
}

// From carcinogen list
$rows = $db->fetchAll("SELECT DISTINCT cas_number FROM carcinogen_list");
foreach ($rows as $r) {
    $allCas[$r['cas_number']] = true;
}

// From SARA 313 list
$rows = $db->fetchAll("SELECT cas_number FROM sara313_list");
foreach ($rows as $r) {
    $allCas[$r['cas_number']] = true;
}

// From existing hazard source records
$rows = $db->fetchAll("SELECT DISTINCT cas_number FROM hazard_source_records");
foreach ($rows as $r) {
    $allCas[$r['cas_number']] = true;
}

// From DOT transport info
$rows = $db->fetchAll("SELECT DISTINCT cas_number FROM dot_transport_info");
foreach ($rows as $r) {
    $allCas[$r['cas_number']] = true;
}

$casList = array_keys($allCas);
sort($casList);

echo "Found " . count($casList) . " unique CAS numbers across all tables.\n";

$logId = $db->insert('dataset_refresh_log', [
    'source_name' => 'all',
    'started_at'  => date('Y-m-d H:i:s'),
    'status'      => 'running',
]);

$totalSuccess    = 0;
$totalFailed     = 0;
$sourcesReached  = 0;
$sourcesAttempted = 0;

// -----------------------------------------------------------------
// PubChem
// -----------------------------------------------------------------
echo "Checking PubChem availability...\n";
$sourcesAttempted++;
$pubchem = new PubChemConnector();

if ($pubchem->isAvailable()) {
    $sourcesReached++;
    echo "  PubChem is reachable. Refreshing GHS data...\n";

    $result = $pubchem->refreshAll($casList, function ($cas, $i, $total) {
        if ($i % 50 === 0) {
            echo "  PubChem: {$i}/{$total} ({$cas})\n";
        }
    });
    $totalSuccess += count($result['success']);
    $totalFailed  += count($result['failed']);
    echo "  PubChem done: " . count($result['success']) . " success, " . count($result['failed']) . " failed.\n";
} else {
    echo "  PubChem is NOT reachable. Existing data preserved.\n";
}

// -----------------------------------------------------------------
// NIOSH (from cache only — no live API)
// -----------------------------------------------------------------
echo "Checking NIOSH data (cache)...\n";
$sourcesAttempted++;
$niosh = new NIOSHConnector();

if ($niosh->isAvailable()) {
    $sourcesReached++;
    $result = $niosh->refreshAll($casList);
    echo "  NIOSH done: " . count($result['success']) . " cached.\n";
} else {
    echo "  NIOSH cache empty. Run load-seed-data.php to populate.\n";
}

// -----------------------------------------------------------------
// Reload seed data for SARA, EPA, DOT if CSV files exist
// (these don't have live APIs — only file-based import)
// -----------------------------------------------------------------
$seedDir = __DIR__ . '/../storage/data/seed';

if (file_exists($seedDir . '/sara313.csv')) {
    echo "Re-checking SARA 313 seed data...\n";
    $saraResult = \SDS\Services\SARA313Service::importFromCsv($seedDir . '/sara313.csv');
    echo "  SARA 313: {$saraResult['inserted']} new, {$saraResult['updated']} updated.\n";
}

// -----------------------------------------------------------------
// Summary
// -----------------------------------------------------------------
$status = 'success';
if ($sourcesReached === 0 && $sourcesAttempted > 0) {
    $status = 'error';
} elseif ($sourcesReached < $sourcesAttempted || $totalFailed > 0) {
    $status = 'partial';
}

$db->update('dataset_refresh_log', [
    'finished_at'       => date('Y-m-d H:i:s'),
    'status'            => $status,
    'records_processed' => count($casList),
    'records_updated'   => $totalSuccess,
    'details_json'      => json_encode([
        'sources_attempted' => $sourcesAttempted,
        'sources_reached'   => $sourcesReached,
        'total_cas'         => count($casList),
        'pubchem_success'   => $totalSuccess,
        'pubchem_failed'    => $totalFailed,
        'note'              => 'Non-destructive refresh. Existing data always preserved.',
    ]),
], 'id = ?', [$logId]);

echo "[" . date('Y-m-d H:i:s') . "] Federal data refresh complete. Status: {$status}\n";
if ($sourcesReached < $sourcesAttempted) {
    echo "NOTE: Some data sources were unreachable. Existing data has been preserved.\n";
    echo "      The next scheduled run will retry automatically.\n";
}
