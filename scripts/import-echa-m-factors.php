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
    // Skip empty rows (all cells blank — ECHA inserts these between
    // the disclaimer preamble and the actual header).
    $nonEmpty = array_filter($data, fn($v) => $v !== null && trim((string) $v) !== '');
    if (empty($nonEmpty)) continue;
    // Comment lines (first column starts with "#") — skip.
    if (isset($data[0]) && str_starts_with(trim((string) $data[0]), '#')) continue;
    if ($header === null) {
        $candidate = array_map(fn($h) => trim(strtolower((string) $h)), $data);
        // Not all first-content rows are headers — the raw ECHA export
        // opens with a 2-3 line quoted disclaimer. A genuine header must
        // contain a CAS-ish column name.
        $hasCasCol = !empty(array_filter($candidate, fn($c) =>
            in_array($c, ['cas_number', 'cas no', 'cas number', 'cas_no', 'casno', 'cas'], true)
        ));
        if (!$hasCasCol) continue;
        $header = $candidate;
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
    $row['_line'] = $lineNo;
    $rows[] = $row;
}
fclose($fh);

out("Parsed " . count($rows) . " data row(s).", $quiet);

// ── ECHA native-format detection + auto-reshape ─────────────────────────────
// If the header looks like the raw ECHA Annex VI export (CAS No + a
// combined "specific conc. limits, m-factors and ate" column), reshape
// every row in place: extract M-factors via regex from the free-text
// column and derive Aquatic categories from the Classification column.
// Operators can upload the ECHA CSV as-downloaded and we pick out only
// the rows that have M-factor data (typically ~200 of ~4 000).

$casCol = findEchaColumn($header ?? [], ['cas_number', 'cas no', 'cas number', 'casno', 'cas_no']);
$scCol  = findEchaColumn($header ?? [], [
    // ECHA ATP23 current header
    'm, scl, ate',
    'm scl ate',
    // Older / alternative ECHA headers
    'specific conc. limits, m-factors and ate',
    'specific conc. limits m-factors and ate',
    'specific conc limits m-factors and ate',
    'specific concentration limits m-factors and ate',
    'm-factor',
    'm-factors',
]);
// Two separate "classification"-ish columns exist in ECHA ATP23: the
// compact class/category codes ("Aquatic Acute 1", "Aquatic Chronic 1")
// and the H-code list ("H400", "H410"). Both carry signal about whether
// a row's single M-factor should apply to acute or chronic — capture both.
$clsColA = findEchaColumn($header ?? [], [
    'hazard class and category code(s)',
    'hazard class and category codes',
    'hazard class and category',
    'hazard class',
    'classification',
]);
$clsColB = findEchaColumn($header ?? [], [
    'classification hazard statement code(s)',
    'classification hazard statement codes',
    'classification hazard statements',
    'labelling hazard statement code(s)',
    'labelling hazard statement codes',
]);

$hasMyFormat = in_array('cas_number', $header ?? [], true)
    && in_array('m_factor_acute', $header ?? [], true)
    && in_array('m_factor_chronic', $header ?? [], true);

if (!$hasMyFormat && $casCol !== null && $scCol !== null) {
    out("Detected raw ECHA Annex VI format — extracting M-factors from '{$scCol}'.", $quiet);
    $reshaped = [];
    $droppedNoM = 0;
    foreach ($rows as $row) {
        $cas = trim((string) ($row[$casCol] ?? ''));
        $cas = preg_replace('/\s+/', '', $cas);
        if ($cas === '' || $cas === '-') continue;
        // Some ECHA rows have multiple CAS values in one cell, separated by
        // "/" or ";". Take the first — that's the primary substance id.
        $cas = preg_split('/[\/;\s]/', $cas)[0] ?? '';
        if ($cas === '' || !preg_match('/^\d{1,7}-\d{2}-\d$/', $cas)) continue;

        $mText   = (string) ($row[$scCol]  ?? '');
        // Concatenate both classification-ish columns so extractEchaMFactors
        // can see both the short class codes ("Aquatic Chronic 1") and the
        // H-code list ("H410") when routing a single M-factor to acute vs
        // chronic.
        $clsText = ($clsColA !== null ? (string) ($row[$clsColA] ?? '') : '')
                 . "\n"
                 . ($clsColB !== null ? (string) ($row[$clsColB] ?? '') : '');
        $extracted = extractEchaMFactors($mText, $clsText);
        if ($extracted['m_acute'] === null && $extracted['m_chronic'] === null) {
            $droppedNoM++;
            continue;
        }

        $reshaped[] = [
            'cas_number'       => $cas,
            'm_factor_acute'   => $extracted['m_acute']   !== null ? (string) $extracted['m_acute']   : '',
            'm_factor_chronic' => $extracted['m_chronic'] !== null ? (string) $extracted['m_chronic'] : '',
            'acute_category'   => $extracted['cat_acute']   ?? '',
            'chronic_category' => $extracted['cat_chronic'] ?? '',
            'source_note'      => 'ECHA Annex VI — auto-extracted from raw export',
            '_line'            => $row['_line'] ?? 0,
        ];
    }
    $rows   = $reshaped;
    $header = ['cas_number', 'm_factor_acute', 'm_factor_chronic', 'acute_category', 'chronic_category', 'source_note'];
    out("Kept " . count($rows) . " row(s) with M-factor data (dropped {$droppedNoM} rows without).", $quiet);
}

$requiredCols = ['cas_number', 'm_factor_acute', 'm_factor_chronic', 'acute_category', 'chronic_category'];
$missingCols = array_diff($requiredCols, $header ?? []);
if (!empty($missingCols)) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "CSV is missing required columns and doesn't look like the raw ECHA format: "
        . implode(', ', $missingCols) . "\n");
    fwrite(STDERR, "Found columns in your CSV header:\n  " . implode("\n  ", $header ?? ['(none)']) . "\n\n");
    fwrite(STDERR, "Expected either the raw ECHA Annex VI export (auto-reshaped) or a CSV with\n");
    fwrite(STDERR, "columns: cas_number, m_factor_acute, m_factor_chronic, acute_category, chronic_category\n");
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


// ─── Helpers ───────────────────────────────────────────────────────────────

/**
 * Find the actual column name in $header that matches any of the candidate
 * names (case-insensitive, whitespace-trimmed). Returns the exact header
 * string so callers can look it up in the row array; returns null if no
 * candidate matches.
 */
function findEchaColumn(array $header, array $candidates): ?string
{
    $lowered = array_map(fn($c) => strtolower(trim($c)), $candidates);
    foreach ($header as $h) {
        $hl = strtolower(trim($h));
        if (in_array($hl, $lowered, true)) {
            return $h;
        }
    }
    return null;
}

/**
 * Extract acute and chronic aquatic M-factors (and implied Cat 1 categories)
 * from ECHA's free-text "M, SCL, ATE" column.
 *
 * ECHA ATP23 convention: M-factors are written "M = N" on one or two
 * lines inside that cell. With two lines, the first is acute and the
 * second is chronic; with one line, it's typically acute (Aquatic Acute 1)
 * unless the Classification column only names Aquatic Chronic — in that
 * case the single M applies to chronic.
 *
 * Returns [
 *   'm_acute'     => float|null,
 *   'm_chronic'   => float|null,
 *   'cat_acute'   => 'Category 1'|null,
 *   'cat_chronic' => 'Category 1'|null,
 * ]
 */
function extractEchaMFactors(string $mText, string $clsText): array
{
    $result = [
        'm_acute'     => null,
        'm_chronic'   => null,
        'cat_acute'   => null,
        'cat_chronic' => null,
    ];

    // Walk line-by-line. ECHA puts "M = 10" / "M = 100" on separate lines
    // when both acute and chronic M-factors apply. Same-line semicolons
    // sometimes appear too.
    $tokens = preg_split('/[\r\n;]+/', $mText) ?: [];
    $ms = [];
    foreach ($tokens as $tok) {
        if (preg_match('/M\s*=\s*([0-9]+(?:\.[0-9]+)?)/i', $tok, $m)) {
            $ms[] = (float) $m[1];
        }
    }

    if (empty($ms)) {
        return $result;
    }

    $hasChronicInCls = stripos($clsText, 'aquatic chronic 1') !== false
                    || preg_match('/\bH41[0-4]\b/i', $clsText) === 1;
    $hasAcuteInCls   = stripos($clsText, 'aquatic acute 1')   !== false
                    || preg_match('/\bH400\b/i', $clsText) === 1;

    if (count($ms) === 1) {
        // Single M value — route it by what the classification says.
        if ($hasAcuteInCls && !$hasChronicInCls) {
            $result['m_acute'] = $ms[0];
            $result['cat_acute'] = 'Category 1';
        } elseif ($hasChronicInCls && !$hasAcuteInCls) {
            $result['m_chronic'] = $ms[0];
            $result['cat_chronic'] = 'Category 1';
        } else {
            // Both or neither present in classification — default to acute
            // (the GHS assumption when only one M is given).
            $result['m_acute'] = $ms[0];
            $result['cat_acute'] = 'Category 1';
        }
    } else {
        // Two or more — ECHA convention puts acute first, chronic second.
        $result['m_acute']   = $ms[0];
        $result['m_chronic'] = $ms[1];
        $result['cat_acute']   = 'Category 1';
        $result['cat_chronic'] = 'Category 1';
    }

    return $result;
}
