#!/usr/bin/env php
<?php
/**
 * SDS System — Federal Data Live Refresh (Non-destructive)
 *
 * Attempts to update federal data from live online sources.
 * If any source is unreachable, the existing data is preserved.
 * This script NEVER deletes data — it only adds or updates.
 *
 * Usage:
 *   php scripts/refresh-federal-data.php [--quiet]
 *
 * Called by the installers after seed data is loaded.
 * Also used by cron/refresh-federal.php for periodic updates.
 *
 * Exit codes:
 *   0 = all sources reached successfully
 *   1 = partial success (some sources unreachable — existing data preserved)
 *   2 = all sources unreachable (existing data preserved)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\FederalData\Connectors\PubChemConnector;
use SDS\Services\FederalData\Connectors\NIOSHConnector;

$quiet = in_array('--quiet', $argv ?? []);

function out(string $msg, bool $quiet): void {
    if (!$quiet) {
        echo $msg . "\n";
    }
}

$db = Database::getInstance();

out("=== SDS System Federal Data Refresh ===", $quiet);
out("Date: " . date('Y-m-d H:i:s'), $quiet);
out("Mode: Non-destructive (existing data is never removed)", $quiet);
out("", $quiet);

$logId = $db->insert('dataset_refresh_log', [
    'source_name' => 'live-refresh',
    'started_at'  => date('Y-m-d H:i:s'),
    'status'      => 'running',
]);

$sourcesAttempted = 0;
$sourcesReached   = 0;
$totalSuccess     = 0;
$totalFailed      = 0;

// =====================================================================
// 1. PubChem — Live GHS data for all known CAS numbers
// =====================================================================
out("[1/2] Checking PubChem availability...", $quiet);
$sourcesAttempted++;

$pubchem = new PubChemConnector();

if ($pubchem->isAvailable()) {
    $sourcesReached++;
    out("  PubChem is reachable. Fetching GHS data for all known CAS numbers...", $quiet);

    // Get all CAS numbers from multiple sources — not just those in formulas
    $allCas = [];

    // From raw_material_constituents
    $rows = $db->fetchAll("SELECT DISTINCT cas_number FROM raw_material_constituents");
    foreach ($rows as $r) {
        $allCas[$r['cas_number']] = true;
    }

    // From cas_master
    $rows = $db->fetchAll("SELECT cas_number FROM cas_master");
    foreach ($rows as $r) {
        $allCas[$r['cas_number']] = true;
    }

    // From prop65_list
    $rows = $db->fetchAll("SELECT cas_number FROM prop65_list");
    foreach ($rows as $r) {
        $allCas[$r['cas_number']] = true;
    }

    // From carcinogen_list
    $rows = $db->fetchAll("SELECT DISTINCT cas_number FROM carcinogen_list");
    foreach ($rows as $r) {
        $allCas[$r['cas_number']] = true;
    }

    // From sara313_list
    $rows = $db->fetchAll("SELECT cas_number FROM sara313_list");
    foreach ($rows as $r) {
        $allCas[$r['cas_number']] = true;
    }

    $casList = array_keys($allCas);
    $total   = count($casList);
    out("  Found {$total} unique CAS numbers across all tables.", $quiet);

    if ($total > 0) {
        // For large lists at install time, limit to avoid excessive API time
        // PubChem rate limit is 5 req/sec, so 1000 CAS = ~3-4 minutes
        $maxAtInstall = 500;
        if ($total > $maxAtInstall) {
            out("  Limiting to first {$maxAtInstall} CAS numbers to avoid long install time.", $quiet);
            out("  Remaining CAS numbers will be fetched by the weekly cron job.", $quiet);
            $casList = array_slice($casList, 0, $maxAtInstall);
        }

        $result = $pubchem->refreshAll($casList, function ($cas, $i, $total) use ($quiet) {
            if (!$quiet && $i % 25 === 0) {
                echo "  PubChem: {$i}/{$total} ({$cas})\n";
            }
        });

        $totalSuccess += count($result['success']);
        $totalFailed  += count($result['failed']);
        out("  PubChem: " . count($result['success']) . " updated, " . count($result['failed']) . " failed.", $quiet);
    }
} else {
    out("  PubChem is NOT reachable. Existing data preserved.", $quiet);
}

// =====================================================================
// 2. NIOSH — Check for updated cached data
// =====================================================================
out("[2/2] Checking NIOSH data...", $quiet);
$sourcesAttempted++;

$niosh = new NIOSHConnector();
if ($niosh->isAvailable()) {
    $sourcesReached++;
    out("  NIOSH data is available in local cache.", $quiet);
} else {
    out("  No NIOSH data in cache (load seed data first).", $quiet);
}

// =====================================================================
// Summary
// =====================================================================
$status = 'success';
if ($sourcesReached === 0 && $sourcesAttempted > 0) {
    $status = 'error';
} elseif ($sourcesReached < $sourcesAttempted) {
    $status = 'partial';
} elseif ($totalFailed > 0) {
    $status = 'partial';
}

$db->update('dataset_refresh_log', [
    'finished_at'       => date('Y-m-d H:i:s'),
    'status'            => $status,
    'records_processed' => $totalSuccess + $totalFailed,
    'records_updated'   => $totalSuccess,
    'details_json'      => json_encode([
        'sources_attempted' => $sourcesAttempted,
        'sources_reached'   => $sourcesReached,
        'success'           => $totalSuccess,
        'failed'            => $totalFailed,
        'note'              => 'Non-destructive refresh. Existing data preserved on failure.',
    ]),
], 'id = ?', [$logId]);

out("", $quiet);
out("=== Refresh Complete ===", $quiet);
out("Sources reached: {$sourcesReached}/{$sourcesAttempted}", $quiet);
out("Records updated: {$totalSuccess}", $quiet);
out("Records failed:  {$totalFailed}", $quiet);
out("Status: {$status}", $quiet);

if ($sourcesReached < $sourcesAttempted) {
    out("", $quiet);
    out("NOTE: Some data sources were unreachable. All existing data has been", $quiet);
    out("preserved. The weekly cron job will retry automatically.", $quiet);
}

$exitCode = match ($status) {
    'success' => 0,
    'partial' => 1,
    'error'   => 2,
    default   => 1,
};

exit($exitCode);
