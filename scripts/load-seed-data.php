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
 *   3. HAP list (CAA Section 112(b))  (storage/data/seed/hap.csv)
 *   4. SARA 313 / TRI chemical list   (storage/data/seed/sara313.csv)
 *   5. NIOSH exposure limits          (storage/data/seed/niosh.json)
 *   6. EPA regulatory data            (storage/data/seed/epa.csv)
 *   7. DOT transport classifications  (storage/data/seed/dot.csv)
 *   8. CAS Master (chemical identity)  (storage/data/seed/cas_master.csv)
 *   9. ACGIH TLV exposure limits      (storage/data/seed/acgih_tlv.json)
 *  10. OSHA PEL exposure limits       (storage/data/seed/osha_pel.json)
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

/**
 * Import a JSON exposure-limit seed file into hazard_source_records + exposure_limits.
 *
 * Generic helper that supports ACGIH TLV, OSHA PEL, or any source that
 * ships limit data in a JSON array.
 *
 * @param Database $db
 * @param string   $filePath     Absolute path to JSON file
 * @param string   $sourceName   e.g. 'ACGIH', 'OSHA'
 * @param string   $sourceRef    Human-readable source reference
 * @param array    $fieldMap     Maps JSON field base names to limit_type strings.
 *                               Each key is the base field name (without _ppm/_mgm3),
 *                               and the value is the limit_type to store.
 * @param bool     $quiet
 * @return array{inserted:int, errors:int}
 */
function importExposureLimitsJson(
    Database $db,
    string $filePath,
    string $sourceName,
    string $sourceRef,
    array $fieldMap,
    bool $quiet
): array {
    $json = file_get_contents($filePath);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['inserted' => 0, 'errors' => 1];
    }

    $inserted = 0;
    $errors   = 0;

    foreach ($data as $entry) {
        $cas = trim($entry['cas_number'] ?? '');
        if ($cas === '' || !preg_match('/^\d+-\d+-\d+$/', $cas)) {
            continue;
        }

        try {
            // Build the exposure limits from the field map
            $limits = [];
            foreach ($fieldMap as $baseField => $limitType) {
                $mgm3Key = $baseField . '_mgm3';
                $ppmKey  = $baseField . '_ppm';

                if (!empty($entry[$mgm3Key])) {
                    $limits[] = [
                        'type'  => $limitType,
                        'value' => (string) $entry[$mgm3Key],
                        'units' => 'mg/m3',
                    ];
                } elseif (!empty($entry[$ppmKey])) {
                    $limits[] = [
                        'type'  => $limitType,
                        'value' => (string) $entry[$ppmKey],
                        'units' => 'ppm',
                    ];
                }
            }

            if (empty($limits)) {
                continue; // No limit values — skip
            }

            // Build payload for hazard_source_records
            $payload = [
                'source'          => $sourceName,
                'cas'             => $cas,
                'chemical_name'   => $entry['chemical_name'] ?? '',
                'exposure_limits' => $limits,
                'notation'        => $entry['notation'] ?? null,
                'retrieved_at'    => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $payloadHash = hash('sha256', $payloadJson);

            // Check for identical existing record
            $existing = $db->fetch(
                "SELECT id FROM hazard_source_records WHERE cas_number = ? AND source_name = ? AND payload_hash = ?",
                [$cas, $sourceName, $payloadHash]
            );
            if ($existing) {
                continue; // Already loaded — skip
            }

            // Mark old records as non-current
            $db->query(
                "UPDATE hazard_source_records SET is_current = 0 WHERE cas_number = ? AND source_name = ?",
                [$cas, $sourceName]
            );

            $db->beginTransaction();

            $recordId = $db->insert('hazard_source_records', [
                'cas_number'   => $cas,
                'source_name'  => $sourceName,
                'source_ref'   => $sourceRef,
                'retrieved_at' => gmdate('Y-m-d H:i:s'),
                'payload_hash' => $payloadHash,
                'payload_json' => $payloadJson,
                'is_current'   => 1,
            ]);

            foreach ($limits as $limit) {
                $notes = $entry['notation'] ?? null;
                if (isset($entry['cfr_ref'])) {
                    $notes = ($notes ? $notes . '; ' : '') . $entry['cfr_ref'];
                }

                $db->insert('exposure_limits', [
                    'hazard_source_record_id' => $recordId,
                    'cas_number'  => $cas,
                    'limit_type'  => $limit['type'],
                    'value'       => $limit['value'],
                    'units'       => $limit['units'],
                    'notes'       => $notes,
                ]);
            }

            $db->commit();

            // Also ensure the CAS is in cas_master
            $casMaster = $db->fetch("SELECT cas_number FROM cas_master WHERE cas_number = ?", [$cas]);
            if (!$casMaster && !empty($entry['chemical_name'])) {
                try {
                    $db->insert('cas_master', [
                        'cas_number'     => $cas,
                        'preferred_name' => $entry['chemical_name'],
                    ]);
                } catch (\Throwable $e) {
                    // Non-fatal — might already exist from another thread
                }
            }

            $inserted++;
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $_) {}
            $errors++;
            if (!$quiet) {
                error_log("{$sourceName} {$cas}: " . $e->getMessage());
            }
        }
    }

    return ['inserted' => $inserted, 'errors' => $errors];
}

out("=== SDS System Seed Data Loader ===", $quiet);
out("Seed directory: {$seedDir}", $quiet);
out("", $quiet);

// =====================================================================
// 1. Prop 65
// =====================================================================
$prop65File = $seedDir . '/prop65.csv';
if (file_exists($prop65File)) {
    out("[1/10] Loading Prop 65 data...", $quiet);
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
    out("[1/10] Prop 65 seed file not found — skipping.", $quiet);
}

// =====================================================================
// 2. Carcinogens (IARC, NTP, OSHA)
// =====================================================================
$carcFile = $seedDir . '/carcinogens.csv';
if (file_exists($carcFile)) {
    out("[2/10] Loading carcinogen registry data...", $quiet);
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
    out("[2/10] Carcinogen seed file not found — skipping.", $quiet);
}

// =====================================================================
// 3. Hazardous Air Pollutants (CAA Section 112(b))
// =====================================================================
$hapFile = $seedDir . '/hap.csv';
if (file_exists($hapFile)) {
    out("[3/10] Loading Hazardous Air Pollutant (HAP) data...", $quiet);
    $handle = fopen($hapFile, 'r');
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
            'cas_number'      => $cas,
            'chemical_name'   => trim($row[1] ?? ''),
            'category'        => trim($row[2] ?? '') ?: null,
            'source_ref'      => 'EPA Clean Air Act Section 112(b) (seed data)',
            'last_updated_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $existing = $db->fetch("SELECT id FROM hap_list WHERE cas_number = ?", [$cas]);
            if ($existing) {
                $updateData = $data;
                unset($updateData['cas_number']);
                $db->update('hap_list', $updateData, 'cas_number = ?', [$cas]);
                $updated++;
            } else {
                $db->insert('hap_list', $data);
                $inserted++;
            }
        } catch (\Throwable $e) {
            $errors++;
            if (!$quiet) {
                error_log("HAP {$cas}: " . $e->getMessage());
            }
        }
    }
    fclose($handle);

    out("  HAPs: {$inserted} inserted, {$updated} updated, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalUpdated  += $updated;
    $totalErrors   += $errors;
} else {
    out("[3/10] HAP seed file not found — skipping.", $quiet);
}

// =====================================================================
// 4. SARA 313
// =====================================================================
$saraFile = $seedDir . '/sara313.csv';
if (file_exists($saraFile)) {
    out("[4/10] Loading SARA 313 / TRI data...", $quiet);
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
    out("[4/10] SARA 313 seed file not found — skipping.", $quiet);
}

// =====================================================================
// 5. NIOSH Exposure Limits
// =====================================================================
$nioshFile = $seedDir . '/niosh.json';
if (file_exists($nioshFile)) {
    out("[5/10] Loading NIOSH exposure limit data...", $quiet);

    $niosh = new \SDS\Services\FederalData\Connectors\NIOSHConnector($db);
    $result = $niosh->importFromJson($nioshFile);

    $inserted = $result['imported'] ?? 0;
    $errors   = count($result['errors'] ?? []);

    out("  NIOSH: {$inserted} imported, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalErrors   += $errors;
} else {
    out("[5/10] NIOSH seed file not found — skipping.", $quiet);
}

// =====================================================================
// 6. EPA Regulatory Data
// =====================================================================
$epaFile = $seedDir . '/epa.csv';
if (file_exists($epaFile)) {
    out("[6/10] Loading EPA regulatory data...", $quiet);

    $epa = new \SDS\Services\FederalData\Connectors\EPAConnector($db);
    $result = $epa->importFromCsv($epaFile);

    $inserted = $result['imported'] ?? 0;
    $errors   = count($result['errors'] ?? []);

    out("  EPA: {$inserted} imported, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalErrors   += $errors;
} else {
    out("[6/10] EPA seed file not found — skipping.", $quiet);
}

// =====================================================================
// 7. DOT Transport Data
// =====================================================================
$dotFile = $seedDir . '/dot.csv';
if (file_exists($dotFile)) {
    out("[7/10] Loading DOT transport classification data...", $quiet);

    $dot = new \SDS\Services\FederalData\Connectors\DOTConnector($db);
    $result = $dot->importFromCsv($dotFile);

    $inserted = $result['imported'] ?? 0;
    $errors   = count($result['errors'] ?? []);

    out("  DOT: {$inserted} imported, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalErrors   += $errors;
} else {
    out("[7/10] DOT seed file not found — skipping.", $quiet);
}

// =====================================================================
// 8. CAS Master (Chemical Identity Database)
// =====================================================================
$casMasterFile = $seedDir . '/cas_master.csv';
if (file_exists($casMasterFile)) {
    out("[8/10] Loading CAS master chemical identity data...", $quiet);
    $handle = fopen($casMasterFile, 'r');
    fgetcsv($handle); // skip header
    $inserted = 0;
    $updated  = 0;
    $errors   = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $cas = trim($row[0] ?? '');
        if ($cas === '' || !preg_match('/^\d+-\d+-\d+$/', $cas)) {
            continue;
        }

        $preferredName = trim($row[1] ?? '');
        if ($preferredName === '') {
            continue;
        }

        $formula = (isset($row[2]) && trim($row[2]) !== '') ? trim($row[2]) : null;
        $weight  = (isset($row[3]) && trim($row[3]) !== '') ? (float) $row[3] : null;

        try {
            $existing = $db->fetch("SELECT cas_number, preferred_name FROM cas_master WHERE cas_number = ?", [$cas]);
            if ($existing) {
                // Only update if the existing preferred_name is empty
                if (empty($existing['preferred_name'])) {
                    $db->update('cas_master', [
                        'preferred_name'    => $preferredName,
                        'molecular_formula' => $formula,
                        'molecular_weight'  => $weight,
                    ], 'cas_number = ?', [$cas]);
                    $updated++;
                }
            } else {
                $db->insert('cas_master', [
                    'cas_number'        => $cas,
                    'preferred_name'    => $preferredName,
                    'molecular_formula' => $formula,
                    'molecular_weight'  => $weight,
                ]);
                $inserted++;
            }
        } catch (\Throwable $e) {
            $errors++;
            if (!$quiet) {
                error_log("CAS Master {$cas}: " . $e->getMessage());
            }
        }
    }
    fclose($handle);

    out("  CAS Master: {$inserted} inserted, {$updated} updated, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalUpdated  += $updated;
    $totalErrors   += $errors;
} else {
    out("[8/10] CAS Master seed file not found — skipping.", $quiet);
}

// =====================================================================
// 9. ACGIH TLV Exposure Limits
// =====================================================================
$acgihFile = $seedDir . '/acgih_tlv.json';
if (file_exists($acgihFile)) {
    out("[9/10] Loading ACGIH TLV exposure limit data...", $quiet);

    $acgihFieldMap = [
        'tlv_twa'     => 'TLV-TWA',
        'tlv_stel'    => 'TLV-STEL',
        'tlv_ceiling' => 'TLV-Ceiling',
    ];

    $result = importExposureLimitsJson(
        $db, $acgihFile, 'ACGIH', 'ACGIH TLVs and BEIs',
        $acgihFieldMap, $quiet
    );

    $inserted = $result['inserted'];
    $errors   = $result['errors'];

    out("  ACGIH TLV: {$inserted} imported, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalErrors   += $errors;
} else {
    out("[9/10] ACGIH TLV seed file not found — skipping.", $quiet);
}

// =====================================================================
// 10. OSHA PEL Exposure Limits (supplemental — STEL, Ceiling, Action Levels)
// =====================================================================
$oshaFile = $seedDir . '/osha_pel.json';
if (file_exists($oshaFile)) {
    out("[10/10] Loading OSHA PEL exposure limit data...", $quiet);

    $oshaFieldMap = [
        'pel_twa'      => 'PEL-TWA',
        'pel_stel'     => 'PEL-STEL',
        'pel_ceiling'  => 'PEL-Ceiling',
        'action_level' => 'Action Level',
    ];

    $result = importExposureLimitsJson(
        $db, $oshaFile, 'OSHA', 'OSHA Permissible Exposure Limits',
        $oshaFieldMap, $quiet
    );

    $inserted = $result['inserted'];
    $errors   = $result['errors'];

    out("  OSHA PEL: {$inserted} imported, {$errors} errors", $quiet);
    $totalInserted += $inserted;
    $totalErrors   += $errors;
} else {
    out("[10/10] OSHA PEL seed file not found — skipping.", $quiet);
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
