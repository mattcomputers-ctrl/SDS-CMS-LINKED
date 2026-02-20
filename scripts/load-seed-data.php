#!/usr/bin/env php
<?php
/**
 * SDS System — Seed Data Loader
 *
 * Loads pre-packaged federal regulatory data into the database so that the
 * system ships with a comprehensive baseline.  Data is loaded in an
 * additive / upsert manner — existing rows are never deleted.
 *
 * Datasets loaded:
 *   1. Prop 65 chemical list          (storage/data/seed/prop65.csv)
 *   2. Carcinogen registry (IARC/NTP/OSHA) (storage/data/seed/carcinogens.csv)
 *   3. SARA 313 / TRI chemical list   (storage/data/seed/sara313.csv)
 *   4. NIOSH exposure limits          (storage/data/seed/niosh.json)
 *   5. EPA regulatory data            (storage/data/seed/epa.csv)
 *   6. DOT transport classifications  (storage/data/seed/dot.csv)
 *
 * Usage:
 *   php scripts/load-seed-data.php [--quiet]
 *
 * This script is safe to run multiple times — it will not duplicate data.
 */

declare(strict_types=1);

// Allow running from any directory
$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

// Bootstrap the app for DB access
new \SDS\Core\App();

use SDS\Core\Database;

$quiet = in_array('--quiet', $argv ?? []);

function out(string $msg, bool $quiet): void {
    if (!$quiet) {
        echo $msg . "\n";
    }
}

$db = Database::getInstance();
$seedDir = $basePath . '/storage/data/seed';
$totalInserted = 0;
$totalUpdated  = 0;
$totalErrors   = 0;

out("=== SDS System Seed Data Loader ===", $quiet);
out("Seed directory: {$seedDir}", $quiet);
out("", $quiet);

// =====================================================================
// 1. Prop 65
// =====================================================================
$prop65File = $seedDir . '/prop65.csv';
if (file_exists($prop65File)) {
    out("[1/6] Loading Prop 65 data...", $quiet);
    $handle = fopen($prop65File, 'r');
    fgetcsv($handle); // skip header
    $inserted = 0;
    $updated  = 0;
    $errors   = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $cas = trim($row[0] ?? '');
        if ($cas === '' || !preg_match('/^\d+-\d+-\d+$/', $cas)) {
            continue;
        }

        $data = [
            'cas_number'       => $cas,
            'chemical_name'    => trim($row[1] ?? ''),
            'toxicity_type'    => trim($row[2] ?? ''),
            'listing_mechanism' => trim($row[3] ?? '') ?: null,
            'nsrl_ug'          => (isset($row[4]) && $row[4] !== '') ? (float) $row[4] : null,
            'madl_ug'          => (isset($row[5]) && $row[5] !== '') ? (float) $row[5] : null,
            'date_listed'      => (isset($row[6]) && $row[6] !== '') ? trim($row[6]) : null,
            'source_ref'       => 'OEHHA Prop 65 List (seed data)',
        ];

        try {
            $existing = $db->fetch("SELECT id FROM prop65_list WHERE cas_number = ?", [$cas]);
            if ($existing) {
                $updateData = $data;
                unset($updateData['cas_number']);
                $db->update('prop65_list', $updateData, 'cas_number = ?', [$cas]);
                $updated++;
            } else {
                $db->insert('prop65_list', $data);
                $inserted++;
            }
        } catch (\Throwable $e) {
            $errors++;
            if (!$quiet) {
                error_log("Prop65 {$cas}: " . $e->getMessage());
            }
        }
    }
    fclose($handle);

    out("  Prop 65: {$inserted} inserted, {$updated} updated, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalUpdated  += $updated;
    $totalErrors   += $errors;
} else {
    out("[1/6] Prop 65 seed file not found — skipping.", $quiet);
}

// =====================================================================
// 2. Carcinogens (IARC, NTP, OSHA)
// =====================================================================
$carcFile = $seedDir . '/carcinogens.csv';
if (file_exists($carcFile)) {
    out("[2/6] Loading carcinogen registry data...", $quiet);
    $handle = fopen($carcFile, 'r');
    fgetcsv($handle); // skip header
    $inserted = 0;
    $updated  = 0;
    $errors   = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $cas    = trim($row[0] ?? '');
        $agency = strtoupper(trim($row[2] ?? ''));
        if ($cas === '' || !preg_match('/^\d+-\d+-\d+$/', $cas) || $agency === '') {
            continue;
        }

        $data = [
            'cas_number'     => $cas,
            'chemical_name'  => trim($row[1] ?? ''),
            'agency'         => $agency,
            'classification' => trim($row[3] ?? ''),
            'description'    => trim($row[4] ?? '') ?: null,
            'source_ref'     => $agency . ' carcinogen listing (seed data)',
        ];

        try {
            $existing = $db->fetch(
                "SELECT id FROM carcinogen_list WHERE cas_number = ? AND agency = ?",
                [$cas, $agency]
            );
            if ($existing) {
                $updateData = $data;
                unset($updateData['cas_number'], $updateData['agency']);
                $db->update('carcinogen_list', $updateData, 'cas_number = ? AND agency = ?', [$cas, $agency]);
                $updated++;
            } else {
                $db->insert('carcinogen_list', $data);
                $inserted++;
            }
        } catch (\Throwable $e) {
            $errors++;
            if (!$quiet) {
                error_log("Carcinogen {$cas}/{$agency}: " . $e->getMessage());
            }
        }
    }
    fclose($handle);

    out("  Carcinogens: {$inserted} inserted, {$updated} updated, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalUpdated  += $updated;
    $totalErrors   += $errors;
} else {
    out("[2/6] Carcinogen seed file not found — skipping.", $quiet);
}

// =====================================================================
// 3. SARA 313
// =====================================================================
$saraFile = $seedDir . '/sara313.csv';
if (file_exists($saraFile)) {
    out("[3/6] Loading SARA 313 / TRI data...", $quiet);
    $handle = fopen($saraFile, 'r');
    fgetcsv($handle); // skip header
    $inserted = 0;
    $updated  = 0;
    $errors   = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $cas = trim($row[0] ?? '');
        if ($cas === '' || !preg_match('/^\d+-\d+-\d+$/', $cas)) {
            continue;
        }

        $data = [
            'cas_number'       => $cas,
            'chemical_name'    => trim($row[1] ?? ''),
            'category_code'    => trim($row[2] ?? '') ?: null,
            'deminimis_pct'    => (isset($row[3]) && $row[3] !== '') ? (float) $row[3] : 1.0,
            'is_pbt'           => (isset($row[4]) && strtolower(trim($row[4])) === 'yes') ? 1 : 0,
            'pbt_threshold_pct' => (isset($row[5]) && $row[5] !== '') ? (float) $row[5] : null,
            'source_ref'       => 'EPA TRI Program (seed data)',
            'last_updated_at'  => date('Y-m-d H:i:s'),
        ];

        try {
            $existing = $db->fetch("SELECT id FROM sara313_list WHERE cas_number = ?", [$cas]);
            if ($existing) {
                $updateData = $data;
                unset($updateData['cas_number']);
                $db->update('sara313_list', $updateData, 'cas_number = ?', [$cas]);
                $updated++;
            } else {
                $db->insert('sara313_list', $data);
                $inserted++;
            }
        } catch (\Throwable $e) {
            $errors++;
            if (!$quiet) {
                error_log("SARA313 {$cas}: " . $e->getMessage());
            }
        }
    }
    fclose($handle);

    out("  SARA 313: {$inserted} inserted, {$updated} updated, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalUpdated  += $updated;
    $totalErrors   += $errors;
} else {
    out("[3/6] SARA 313 seed file not found — skipping.", $quiet);
}

// =====================================================================
// 4. NIOSH Exposure Limits
// =====================================================================
$nioshFile = $seedDir . '/niosh.json';
if (file_exists($nioshFile)) {
    out("[4/6] Loading NIOSH exposure limit data...", $quiet);

    $niosh = new \SDS\Services\FederalData\Connectors\NIOSHConnector($db);
    $result = $niosh->importFromJson($nioshFile);

    $inserted = $result['imported'] ?? 0;
    $errors   = count($result['errors'] ?? []);

    out("  NIOSH: {$inserted} imported, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalErrors   += $errors;
} else {
    out("[4/6] NIOSH seed file not found — skipping.", $quiet);
}

// =====================================================================
// 5. EPA Regulatory Data
// =====================================================================
$epaFile = $seedDir . '/epa.csv';
if (file_exists($epaFile)) {
    out("[5/6] Loading EPA regulatory data...", $quiet);

    $epa = new \SDS\Services\FederalData\Connectors\EPAConnector($db);
    $result = $epa->importFromCsv($epaFile);

    $inserted = $result['imported'] ?? 0;
    $errors   = count($result['errors'] ?? []);

    out("  EPA: {$inserted} imported, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalErrors   += $errors;
} else {
    out("[5/6] EPA seed file not found — skipping.", $quiet);
}

// =====================================================================
// 6. DOT Transport Data
// =====================================================================
$dotFile = $seedDir . '/dot.csv';
if (file_exists($dotFile)) {
    out("[6/6] Loading DOT transport classification data...", $quiet);

    $dot = new \SDS\Services\FederalData\Connectors\DOTConnector($db);
    $result = $dot->importFromCsv($dotFile);

    $inserted = $result['imported'] ?? 0;
    $errors   = count($result['errors'] ?? []);

    out("  DOT: {$inserted} imported, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalErrors   += $errors;
} else {
    out("[6/6] DOT seed file not found — skipping.", $quiet);
}

// =====================================================================
// Summary
// =====================================================================
out("", $quiet);
out("=== Seed Data Load Complete ===", $quiet);
out("Total inserted: {$totalInserted}", $quiet);
out("Total updated:  {$totalUpdated}", $quiet);
out("Total errors:   {$totalErrors}", $quiet);

// Log the seed load
try {
    $db->insert('dataset_refresh_log', [
        'source_name'       => 'seed-data',
        'started_at'        => date('Y-m-d H:i:s'),
        'finished_at'       => date('Y-m-d H:i:s'),
        'status'            => $totalErrors === 0 ? 'success' : 'partial',
        'records_processed' => $totalInserted + $totalUpdated,
        'records_updated'   => $totalInserted,
        'details_json'      => json_encode([
            'inserted' => $totalInserted,
            'updated'  => $totalUpdated,
            'errors'   => $totalErrors,
            'source'   => 'Pre-packaged seed data',
        ]),
    ]);
} catch (\Throwable $e) {
    // Non-fatal
}

exit($totalErrors > 0 ? 1 : 0);
