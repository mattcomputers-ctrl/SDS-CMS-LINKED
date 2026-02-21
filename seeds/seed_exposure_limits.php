<?php
/**
 * Seed exposure limit data from OSHA PEL, NIOSH REL, and ACGIH TLV JSON files.
 *
 * Populates:
 *   - hazard_source_records  (one per chemical per source)
 *   - exposure_limits        (individual limit rows linked to source records)
 *   - cas_master             (ensures every CAS number is resolvable by name)
 *
 * Run: php seeds/seed_exposure_limits.php
 *
 * Safe to re-run: skips chemicals that already have a source record for the
 * same source_name. To force a full refresh, delete existing rows first.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$app = new SDS\Core\App();
$db  = SDS\Core\Database::getInstance();

$dataDir = __DIR__ . '/../storage/data/seed';

echo "=== Seeding Exposure Limits ===\n\n";

// ── Helper: upsert a CAS number into cas_master ──────────────────────
function upsertCasMaster(\SDS\Core\Database $db, string $cas, string $name): void
{
    if ($cas === '' || $name === '') {
        return;
    }
    try {
        $existing = $db->fetch(
            "SELECT cas_number, preferred_name FROM cas_master WHERE cas_number = ?",
            [$cas]
        );
        if (!$existing) {
            $db->insert('cas_master', [
                'cas_number'     => $cas,
                'preferred_name' => $name,
            ]);
        } elseif (empty($existing['preferred_name'])) {
            $db->update('cas_master', ['preferred_name' => $name], 'cas_number = ?', [$cas]);
        }
    } catch (\Throwable $e) {
        // Non-fatal — duplicate or constraint issue
    }
}

// ── Helper: check if source record already exists ────────────────────
function sourceRecordExists(\SDS\Core\Database $db, string $cas, string $sourceName): bool
{
    $row = $db->fetch(
        "SELECT id FROM hazard_source_records WHERE cas_number = ? AND source_name = ? AND is_current = 1",
        [$cas, $sourceName]
    );
    return $row !== null && $row !== false;
}

// ── 1. OSHA PEL ─────────────────────────────────────────────────────

$oshaFile = $dataDir . '/osha_pel.json';
if (!file_exists($oshaFile)) {
    echo "  SKIP: osha_pel.json not found\n";
} else {
    $oshaData = json_decode(file_get_contents($oshaFile), true);
    if (!is_array($oshaData)) {
        echo "  ERROR: osha_pel.json is not valid JSON\n";
    } else {
        echo "  Loading OSHA PEL ({$oshaFile})...\n";
        $inserted = 0;
        $skipped  = 0;

        foreach ($oshaData as $entry) {
            $cas  = trim($entry['cas_number'] ?? '');
            $name = trim($entry['chemical_name'] ?? '');
            if ($cas === '') {
                continue;
            }

            // Skip if already seeded
            if (sourceRecordExists($db, $cas, 'osha_pel')) {
                $skipped++;
                continue;
            }

            // Create hazard_source_records entry
            $sourceId = (int) $db->insert('hazard_source_records', [
                'cas_number'   => $cas,
                'source_name'  => 'osha_pel',
                'source_ref'   => $entry['cfr_ref'] ?? 'OSHA Permissible Exposure Limits',
                'source_url'   => 'https://www.osha.gov/annotated-pels',
                'payload_hash' => hash('sha256', json_encode($entry)),
                'payload_json' => json_encode($entry),
                'is_current'   => 1,
            ]);

            // Insert individual exposure limit rows
            $limitMap = [
                'PEL-TWA'     => ['pel_twa_ppm', 'pel_twa_mgm3'],
                'PEL-STEL'    => ['pel_stel_ppm', 'pel_stel_mgm3'],
                'PEL-Ceiling' => ['pel_ceiling_ppm', 'pel_ceiling_mgm3'],
                'Action Level' => ['action_level_ppm', 'action_level_mgm3'],
            ];

            foreach ($limitMap as $limitType => [$ppmKey, $mgKey]) {
                $ppmVal = $entry[$ppmKey] ?? null;
                $mgVal  = $entry[$mgKey] ?? null;

                if ($ppmVal !== null && $ppmVal !== '') {
                    $db->insert('exposure_limits', [
                        'hazard_source_record_id' => $sourceId,
                        'cas_number'              => $cas,
                        'limit_type'              => $limitType,
                        'value'                   => (string) $ppmVal,
                        'units'                   => 'ppm',
                        'notes'                   => $entry['notation'] ?? null,
                    ]);
                }
                if ($mgVal !== null && $mgVal !== '') {
                    $db->insert('exposure_limits', [
                        'hazard_source_record_id' => $sourceId,
                        'cas_number'              => $cas,
                        'limit_type'              => $limitType,
                        'value'                   => (string) $mgVal,
                        'units'                   => 'mg/m3',
                        'notes'                   => $entry['notation'] ?? null,
                    ]);
                }
            }

            // Ensure CAS is in cas_master
            upsertCasMaster($db, $cas, $name);
            $inserted++;
        }

        echo "    OSHA PEL: {$inserted} inserted, {$skipped} skipped (already present)\n";
    }
}

// ── 2. NIOSH REL ────────────────────────────────────────────────────

$nioshFile = $dataDir . '/niosh.json';
if (!file_exists($nioshFile)) {
    echo "  SKIP: niosh.json not found\n";
} else {
    $nioshData = json_decode(file_get_contents($nioshFile), true);
    if (!is_array($nioshData)) {
        echo "  ERROR: niosh.json is not valid JSON\n";
    } else {
        echo "  Loading NIOSH REL ({$nioshFile})...\n";
        $inserted = 0;
        $skipped  = 0;

        foreach ($nioshData as $entry) {
            $cas  = trim($entry['cas_number'] ?? '');
            $name = trim($entry['chemical_name'] ?? '');
            if ($cas === '') {
                continue;
            }

            if (sourceRecordExists($db, $cas, 'niosh')) {
                $skipped++;
                continue;
            }

            $sourceId = (int) $db->insert('hazard_source_records', [
                'cas_number'   => $cas,
                'source_name'  => 'niosh',
                'source_ref'   => 'NIOSH Pocket Guide to Chemical Hazards',
                'source_url'   => 'https://www.cdc.gov/niosh/npg/',
                'payload_hash' => hash('sha256', json_encode($entry)),
                'payload_json' => json_encode($entry),
                'is_current'   => 1,
            ]);

            $limitMap = [
                'REL-TWA'     => ['rel_twa_ppm', 'rel_twa_mgm3'],
                'REL-STEL'    => ['rel_stel_ppm', 'rel_stel_mgm3'],
                'REL-Ceiling' => ['rel_ceiling_ppm', 'rel_ceiling_mgm3'],
                'IDLH'        => ['idlh_ppm', 'idlh_mgm3'],
            ];

            foreach ($limitMap as $limitType => [$ppmKey, $mgKey]) {
                $ppmVal = $entry[$ppmKey] ?? null;
                $mgVal  = $entry[$mgKey] ?? null;

                if ($ppmVal !== null && $ppmVal !== '') {
                    $db->insert('exposure_limits', [
                        'hazard_source_record_id' => $sourceId,
                        'cas_number'              => $cas,
                        'limit_type'              => $limitType,
                        'value'                   => (string) $ppmVal,
                        'units'                   => 'ppm',
                        'notes'                   => null,
                    ]);
                }
                if ($mgVal !== null && $mgVal !== '') {
                    $db->insert('exposure_limits', [
                        'hazard_source_record_id' => $sourceId,
                        'cas_number'              => $cas,
                        'limit_type'              => $limitType,
                        'value'                   => (string) $mgVal,
                        'units'                   => 'mg/m3',
                        'notes'                   => null,
                    ]);
                }
            }

            // NIOSH also carries PEL-TWA from the employer's obligation column
            $pelPpm = $entry['pel_twa_ppm'] ?? null;
            $pelMg  = $entry['pel_twa_mgm3'] ?? null;
            if ($pelPpm !== null && $pelPpm !== '') {
                $db->insert('exposure_limits', [
                    'hazard_source_record_id' => $sourceId,
                    'cas_number'              => $cas,
                    'limit_type'              => 'PEL-TWA',
                    'value'                   => (string) $pelPpm,
                    'units'                   => 'ppm',
                    'notes'                   => 'From NIOSH Pocket Guide',
                ]);
            }
            if ($pelMg !== null && $pelMg !== '') {
                $db->insert('exposure_limits', [
                    'hazard_source_record_id' => $sourceId,
                    'cas_number'              => $cas,
                    'limit_type'              => 'PEL-TWA',
                    'value'                   => (string) $pelMg,
                    'units'                   => 'mg/m3',
                    'notes'                   => 'From NIOSH Pocket Guide',
                ]);
            }

            upsertCasMaster($db, $cas, $name);
            $inserted++;
        }

        echo "    NIOSH REL: {$inserted} inserted, {$skipped} skipped (already present)\n";
    }
}

// ── 3. ACGIH TLV ───────────────────────────────────────────────────

$acgihFile = $dataDir . '/acgih_tlv.json';
if (!file_exists($acgihFile)) {
    echo "  SKIP: acgih_tlv.json not found\n";
} else {
    $acgihData = json_decode(file_get_contents($acgihFile), true);
    if (!is_array($acgihData)) {
        echo "  ERROR: acgih_tlv.json is not valid JSON\n";
    } else {
        echo "  Loading ACGIH TLV ({$acgihFile})...\n";
        $inserted = 0;
        $skipped  = 0;

        foreach ($acgihData as $entry) {
            $cas  = trim($entry['cas_number'] ?? '');
            $name = trim($entry['chemical_name'] ?? '');
            if ($cas === '') {
                continue;
            }

            if (sourceRecordExists($db, $cas, 'acgih_tlv')) {
                $skipped++;
                continue;
            }

            $sourceId = (int) $db->insert('hazard_source_records', [
                'cas_number'   => $cas,
                'source_name'  => 'acgih_tlv',
                'source_ref'   => 'ACGIH TLVs and BEIs',
                'source_url'   => 'https://www.acgih.org/tlv-bei-guidelines/',
                'payload_hash' => hash('sha256', json_encode($entry)),
                'payload_json' => json_encode($entry),
                'is_current'   => 1,
            ]);

            $limitMap = [
                'TLV-TWA'     => ['tlv_twa_ppm', 'tlv_twa_mgm3'],
                'TLV-STEL'    => ['tlv_stel_ppm', 'tlv_stel_mgm3'],
                'TLV-Ceiling' => ['tlv_ceiling_ppm', 'tlv_ceiling_mgm3'],
            ];

            foreach ($limitMap as $limitType => [$ppmKey, $mgKey]) {
                $ppmVal = $entry[$ppmKey] ?? null;
                $mgVal  = $entry[$mgKey] ?? null;

                if ($ppmVal !== null && $ppmVal !== '') {
                    $db->insert('exposure_limits', [
                        'hazard_source_record_id' => $sourceId,
                        'cas_number'              => $cas,
                        'limit_type'              => $limitType,
                        'value'                   => (string) $ppmVal,
                        'units'                   => 'ppm',
                        'notes'                   => $entry['notation'] ?? null,
                    ]);
                }
                if ($mgVal !== null && $mgVal !== '') {
                    $db->insert('exposure_limits', [
                        'hazard_source_record_id' => $sourceId,
                        'cas_number'              => $cas,
                        'limit_type'              => $limitType,
                        'value'                   => (string) $mgVal,
                        'units'                   => 'mg/m3',
                        'notes'                   => $entry['notation'] ?? null,
                    ]);
                }
            }

            upsertCasMaster($db, $cas, $name);
            $inserted++;
        }

        echo "    ACGIH TLV: {$inserted} inserted, {$skipped} skipped (already present)\n";
    }
}

// ── Summary ─────────────────────────────────────────────────────────

$totalSources = $db->fetch("SELECT COUNT(*) AS cnt FROM hazard_source_records WHERE source_name IN ('osha_pel','niosh','acgih_tlv')");
$totalLimits  = $db->fetch("SELECT COUNT(*) AS cnt FROM exposure_limits");
$totalCas     = $db->fetch("SELECT COUNT(*) AS cnt FROM cas_master");

echo "\n=== Summary ===\n";
echo "  Hazard source records: {$totalSources['cnt']}\n";
echo "  Exposure limit rows:   {$totalLimits['cnt']}\n";
echo "  CAS master entries:    {$totalCas['cnt']}\n";
echo "\nExposure limit seed complete!\n";
