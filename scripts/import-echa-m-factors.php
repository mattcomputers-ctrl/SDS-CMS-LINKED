#!/usr/bin/env php
<?php
/**
 * import-echa-m-factors.php — import ECHA CLP Annex VI Table 3.1
 * M-factor values into hazard_classifications.
 *
 * For each CSV row the script:
 *   1. Finds existing hazard_classifications rows for the given CAS whose
 *      class_name matches an aquatic class (Aquatic Acute / Aquatic
 *      Chronic). If found, updates the m_factor_acute / m_factor_chronic
 *      column(s) as appropriate.
 *   2. If no matching aquatic row exists, creates a synthetic
 *      hazard_source_records row (source_name = 'echa_annex_vi') and a
 *      matching hazard_classifications row per category provided in the
 *      CSV. This gives the engine aquatic data to work with even when the
 *      CAS has no CPD and no federal-data row yet.
 *
 * Idempotent: re-running with the same CSV upserts without duplicating
 * rows. The uniqueness key is (cas_number, jurisdiction='US',
 * class_name_canonical, category_canonical).
 *
 * Usage:
 *   php scripts/import-echa-m-factors.php <path-to-csv>
 *   php scripts/import-echa-m-factors.php seeds/echa_m_factors.csv --dry-run
 *
 * Options:
 *   --dry-run   log what would change; make no DB writes
 *   --quiet     suppress per-row progress output
 *
 * CSV format:
 *   cas_number,m_factor_acute,m_factor_chronic,acute_category,chronic_category,source_note
 *   Lines starting with "#" are comments. Empty cells are "not
 *   specified" (engine default of 1.0 applies). See seeds/echa_m_factors.csv.example.
 *
 * Exit codes:
 *   0 = import complete
 *   1 = usage / IO error
 *   2 = CSV parse error or referenced CAS had no matching row and
 *       synthetic-row creation failed (see stderr for details)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\GHSHazardClass;
use SDS\Services\HazardClassAliases;

$csvPath = null;
$dryRun  = false;
$quiet   = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if ($arg === '--quiet')   { $quiet  = true; continue; }
    if (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    if ($csvPath === null) {
        $csvPath = $arg;
        continue;
    }
    fwrite(STDERR, "Unexpected argument: {$arg}\n");
    exit(1);
}

if ($csvPath === null || !is_file($csvPath) || !is_readable($csvPath)) {
    fwrite(STDERR, "Usage: php scripts/import-echa-m-factors.php <path-to-csv> [--dry-run] [--quiet]\n");
    fwrite(STDERR, "CSV file not found or unreadable: " . var_export($csvPath, true) . "\n");
    exit(1);
}

function out(string $msg, bool $quiet): void { if (!$quiet) echo $msg . "\n"; }

out("=== ECHA M-factor import ===", $quiet);
out("CSV:  {$csvPath}", $quiet);
out("Mode: " . ($dryRun ? 'dry-run' : 'apply'), $quiet);
out('', $quiet);

$db  = Database::getInstance();
$pdo = $db->getPdo();

// Sanity check: the M-factor columns must exist (migration 040 applied).
$col = $db->fetch(
    "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'hazard_classifications'
        AND COLUMN_NAME  = 'm_factor_acute'"
);
if ((int) ($col['c'] ?? 0) === 0) {
    fwrite(STDERR, "ERROR: hazard_classifications.m_factor_acute missing. Apply migration 040 first.\n");
    exit(1);
}

// Parse CSV -------------------------------------------------------------------

$fh = fopen($csvPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "Failed to open CSV.\n");
    exit(1);
}

$header = null;
$rows   = [];
$lineNo = 0;
while (($data = fgetcsv($fh)) !== false) {
    $lineNo++;
    if (empty($data) || (count($data) === 1 && ($data[0] === null || $data[0] === ''))) continue;
    // Comment lines (first column starts with "#") — skip.
    if (isset($data[0]) && str_starts_with(trim((string) $data[0]), '#')) continue;
    if ($header === null) {
        $header = array_map(fn($h) => trim(strtolower((string) $h)), $data);
        continue;
    }
    // Normalise row length to exactly match the header: truncate extra
    // columns, pad short rows with empty strings. Protects array_combine
    // from ragged rows caused by unescaped commas in vendor exports.
    $normalised = array_pad(
        array_slice($data, 0, count($header)),
        count($header),
        ''
    );
    $row = array_combine(
        $header,
        array_map(fn($v) => trim((string) $v), $normalised)
    );
    if (empty($row['cas_number'])) continue;
    $row['_line'] = $lineNo;
    $rows[] = $row;
}
fclose($fh);

out("Parsed " . count($rows) . " data row(s).", $quiet);

$requiredCols = ['cas_number', 'm_factor_acute', 'm_factor_chronic', 'acute_category', 'chronic_category'];
$missingCols = array_diff($requiredCols, $header ?? []);
if (!empty($missingCols)) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "CSV is missing required columns: " . implode(', ', $missingCols) . "\n");
    fwrite(STDERR, "Found columns in your CSV header:\n  " . implode("\n  ", $header ?? ['(none)']) . "\n\n");
    fwrite(STDERR, "This looks like the raw ECHA Annex VI export. The import script needs a\n");
    fwrite(STDERR, "reshaped CSV with these columns (case-insensitive):\n");
    fwrite(STDERR, "  cas_number, m_factor_acute, m_factor_chronic, acute_category, chronic_category\n\n");
    fwrite(STDERR, "In Excel: rename 'CAS No' to 'cas_number', split the combined\n");
    fwrite(STDERR, "'Specific Conc. Limits, M-factors and ATE' column into separate\n");
    fwrite(STDERR, "m_factor_acute / m_factor_chronic columns (extract values from text like\n");
    fwrite(STDERR, "'Aquatic Acute 1: M = 100' and 'Aquatic Chronic 1: M = 10'), fill in\n");
    fwrite(STDERR, "acute_category / chronic_category (usually 'Category 1'), save as CSV.\n");
    fwrite(STDERR, "See the CSV Requirements section on /admin/echa-import for the exact format.\n");
    exit(2);
}

// Upsert ----------------------------------------------------------------------

$updatedExisting = 0;
$createdSynthetic = 0;
$skipped          = 0;
$sourceId         = null;

foreach ($rows as $row) {
    $cas       = (string) $row['cas_number'];
    $mAcute    = $row['m_factor_acute']   !== '' ? (float) $row['m_factor_acute']   : null;
    $mChronic  = $row['m_factor_chronic'] !== '' ? (float) $row['m_factor_chronic'] : null;
    $catAcute   = $row['acute_category']   !== '' ? $row['acute_category']   : null;
    $catChronic = $row['chronic_category'] !== '' ? $row['chronic_category'] : null;
    $note      = (string) ($row['source_note'] ?? '');

    if ($mAcute === null && $mChronic === null) {
        $skipped++;
        out("  SKIP line {$row['_line']} ({$cas}): no M-factor values", $quiet);
        continue;
    }

    // Routes this row contributes to
    $routes = [];
    if ($mAcute   !== null) $routes[] = ['route' => 'acute',   'category' => $catAcute,   'm' => $mAcute,   'class_display' => 'Hazardous to the Aquatic Environment (Acute)',   'canonical' => GHSHazardClass::AQUATIC_ACUTE];
    if ($mChronic !== null) $routes[] = ['route' => 'chronic', 'category' => $catChronic, 'm' => $mChronic, 'class_display' => 'Hazardous to the Aquatic Environment (Chronic)', 'canonical' => GHSHazardClass::AQUATIC_CHRONIC];

    foreach ($routes as $r) {
        $canonicalCat = HazardClassAliases::normalizeCategory((string) ($r['category'] ?? 'Cat 1'));
        if ($canonicalCat === '') $canonicalCat = 'Cat 1';

        $col = $r['route'] === 'acute' ? 'm_factor_acute' : 'm_factor_chronic';

        // Look for an existing row first.
        $existing = $db->fetch(
            "SELECT hc.id
               FROM hazard_classifications hc
               JOIN hazard_source_records hsr ON hsr.id = hc.hazard_source_record_id
              WHERE hc.cas_number = ?
                AND hc.class_name_canonical = ?
                AND hc.category_canonical = ?
                AND hsr.is_current = 1
              LIMIT 1",
            [$cas, $r['canonical'], $canonicalCat]
        );

        if ($existing) {
            out("  UPDATE line {$row['_line']} ({$cas}) {$r['canonical']}/{$canonicalCat}: {$col}={$r['m']}", $quiet);
            if (!$dryRun) {
                $pdo->prepare("UPDATE hazard_classifications SET {$col} = ? WHERE id = ?")
                    ->execute([$r['m'], $existing['id']]);
            }
            $updatedExisting++;
            continue;
        }

        // No existing row — create synthetic source record + classification.
        // Shared source record reused across all rows in this CSV run.
        if ($sourceId === null) {
            if ($dryRun) {
                $sourceId = 'DRY-RUN-ID';
            } else {
                $sourceId = $db->insert('hazard_source_records', [
                    'cas_number'   => $cas,
                    'source_name'  => 'echa_annex_vi',
                    'source_ref'   => basename($csvPath) . ($note !== '' ? ' / ' . $note : ''),
                    'payload_json' => json_encode(['import_csv' => basename($csvPath), 'note' => $note]),
                    'is_current'   => 1,
                ]);
            }
        }

        out("  CREATE line {$row['_line']} ({$cas}) {$r['canonical']}/{$canonicalCat}: synthetic row with {$col}={$r['m']}", $quiet);
        if (!$dryRun) {
            $db->insert('hazard_classifications', [
                'hazard_source_record_id' => (int) $sourceId,
                'cas_number'              => $cas,
                'jurisdiction'            => 'US',
                'class_name'              => $r['class_display'],
                'class_name_canonical'    => $r['canonical'],
                'category'                => $r['category'] ?? 'Category 1',
                'category_canonical'      => $canonicalCat,
                'h_statements_json'       => json_encode(['H400']),  // placeholder — engine resolves real codes from GHSHazardData
                'p_statements_json'       => json_encode([]),
                'pictograms_json'         => json_encode(['GHS09']),
                'signal_word'             => 'Warning',
                $col                      => $r['m'],
            ]);
        }
        $createdSynthetic++;
    }
}

out('', $quiet);
out("=== Summary ===", $quiet);
out("Updated existing rows: {$updatedExisting}", $quiet);
out("Created synthetic rows: {$createdSynthetic}", $quiet);
out("Skipped (no values):   {$skipped}", $quiet);
if ($dryRun) {
    out("Dry run — no changes committed.", $quiet);
}

exit(0);
