#!/usr/bin/env php
<?php
/**
 * import-prop65-list.php — import the CA OEHHA Proposition 65 chemical
 * list from its published CSV into the local `prop65_list` table.
 *
 * The seeded `prop65_list` only covers ~30 common ink/coating chemicals;
 * the full OEHHA list has ~1,000. Running this against the official CSV
 * fills the gap so the RM edit page's Auto section correctly surfaces
 * any constituent CAS that appears on the list.
 *
 * Download the current CSV from:
 *   https://oehha.ca.gov/proposition-65/proposition-65-list
 * (click "Excel" or "CSV" — we accept either saved as .csv).
 *
 * Usage:
 *   php scripts/import-prop65-list.php <path-to-csv>            # dry-run
 *   php scripts/import-prop65-list.php <path-to-csv> --confirm  # apply
 *
 * Options:
 *   --confirm   actually write to the DB (default is dry-run)
 *   --quiet     suppress per-row progress output
 *
 * Handling notes:
 *   - Rows without a CAS (CSV value "---", e.g., "Aflatoxins",
 *     "Trimethylolpropane triacrylate, technical grade") are skipped —
 *     the table's unique key is cas_number. For those entries, add
 *     them via the /prop65 admin page or seeds/seed_regulatory.php
 *     with the industry CAS.
 *   - Rows marked "Delisted" in the Chemical name are skipped.
 *   - A chemical can appear on multiple rows (one per toxicity type) —
 *     those rows are merged into one prop65_list row with
 *     toxicity_type="cancer,developmental,…" and the earliest
 *     date_listed.
 *   - NSRL goes into nsrl_ug; any reproductive threshold goes into
 *     madl_ug. Parenthetical notes like "(inhalation)" are dropped;
 *     only the numeric value is stored.
 *   - Entries tagged source_ref='manual' (set when an operator saves
 *     through /prop65) are skipped entirely — the admin curated them
 *     and OEHHA's data shouldn't overwrite.
 *
 * Idempotent: re-running with the same CSV makes zero changes.
 *
 * Exit codes:
 *   0 = ok (dry-run or applied)
 *   1 = usage / IO error
 *   2 = CSV parse error (header not found)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;

// ─── args ────────────────────────────────────────────────────────────
$csvPath = null;
$dryRun  = true;
$quiet   = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--confirm') { $dryRun = false; continue; }
    if ($arg === '--dry-run') { $dryRun = true;  continue; }
    if ($arg === '--quiet')   { $quiet  = true;  continue; }
    if (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    if ($csvPath === null) { $csvPath = $arg; continue; }
    fwrite(STDERR, "Unexpected argument: {$arg}\n");
    exit(1);
}

if ($csvPath === null || !is_file($csvPath) || !is_readable($csvPath)) {
    fwrite(STDERR, "Usage: php scripts/import-prop65-list.php <path-to-csv> [--confirm] [--quiet]\n");
    fwrite(STDERR, "Download CSV from: https://oehha.ca.gov/proposition-65/proposition-65-list\n");
    fwrite(STDERR, "Dry-run by default; use --confirm to apply.\n");
    exit(1);
}

function out(string $msg, bool $quiet): void { if (!$quiet) echo $msg . "\n"; }

out("=== OEHHA Prop 65 list import ===", $quiet);
out("CSV:  {$csvPath}", $quiet);
out("Mode: " . ($dryRun ? 'DRY-RUN (use --confirm to apply)' : 'APPLY'), $quiet);
out('', $quiet);

// ─── normalization helpers ───────────────────────────────────────────
/**
 * OEHHA's "Type of Toxicity" column uses short forms: cancer,
 * developmental, male, female. Our DB stores: cancer, developmental,
 * male reproductive, female reproductive (comma-separated).
 */
$toxCanon = [
    'cancer'              => 'cancer',
    'developmental'       => 'developmental',
    'male'                => 'male reproductive',
    'female'              => 'female reproductive',
    'male reproductive'   => 'male reproductive',
    'female reproductive' => 'female reproductive',
];

function normalizeToxicity(string $raw, array $toxCanon): array {
    $parts = preg_split('/\s*,\s*/', trim($raw));
    $out = [];
    foreach ($parts as $p) {
        $p = strtolower(trim($p));
        if ($p === '') continue;
        $out[] = $toxCanon[$p] ?? $p;
    }
    return array_values(array_unique($out));
}

function normalizeDate(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '' || $raw === '---') return null;
    $ts = strtotime($raw);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}

function extractNumeric(string $raw): ?float {
    $raw = trim($raw);
    if ($raw === '' || $raw === '---') return null;
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $raw, $m)) {
        return (float) $m[1];
    }
    return null;
}

function toUtf8(string $s): string {
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    return (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

// ─── parse CSV ───────────────────────────────────────────────────────
$fh = fopen($csvPath, 'r');
if ($fh === false) { fwrite(STDERR, "Cannot open CSV\n"); exit(1); }

// Preamble rows precede the real header; scan until we hit the row
// whose first cell is "Chemical".
$header = null;
while (($row = fgetcsv($fh)) !== false) {
    if (!empty($row) && isset($row[0]) && trim((string) $row[0]) === 'Chemical') {
        $header = array_map('trim', $row);
        break;
    }
}
if ($header === null) {
    fwrite(STDERR, "Could not find header row (expected 'Chemical' in column A)\n");
    exit(2);
}

// Map header cells to our internal names; tolerant to trailing empty
// columns and to the µ/� encoding mismatch in "NSRL or MADL (µg/day)".
$colIdx = [];
foreach ($header as $i => $h) {
    $h_lc = strtolower($h);
    if     ($h_lc === 'chemical')          $colIdx['chemical']  = $i;
    elseif ($h_lc === 'type of toxicity')  $colIdx['toxicity']  = $i;
    elseif ($h_lc === 'listing mechanism') $colIdx['mechanism'] = $i;
    elseif ($h_lc === 'cas no.')           $colIdx['cas']       = $i;
    elseif ($h_lc === 'date listed')       $colIdx['date']      = $i;
    elseif (str_starts_with($h_lc, 'nsrl or madl')) $colIdx['level'] = $i;
}
foreach (['chemical', 'toxicity', 'cas', 'date'] as $req) {
    if (!isset($colIdx[$req])) {
        fwrite(STDERR, "Header missing required column: {$req}\n");
        fwrite(STDERR, "Found: " . implode(', ', $header) . "\n");
        exit(2);
    }
}

// Accumulate by CAS — a chemical can appear on multiple rows (one per
// toxicity type), e.g. Benzene has separate cancer vs developmental rows.
$byCas = [];
$skippedNoCas    = 0;
$skippedDelisted = 0;
$rowCount        = 0;

while (($row = fgetcsv($fh)) !== false) {
    $rowCount++;
    $chem  = toUtf8(trim((string) ($row[$colIdx['chemical']]  ?? '')));
    $tox   = (string) ($row[$colIdx['toxicity']] ?? '');
    $mech  = isset($colIdx['mechanism']) ? trim((string) ($row[$colIdx['mechanism']] ?? '')) : '';
    $cas   = trim((string) ($row[$colIdx['cas']] ?? ''));
    $date  = (string) ($row[$colIdx['date']] ?? '');
    $level = isset($colIdx['level']) ? (string) ($row[$colIdx['level']] ?? '') : '';

    if ($chem === '' && $cas === '') continue;

    if (stripos($chem, 'Delisted') !== false) {
        $skippedDelisted++;
        continue;
    }

    if ($cas === '' || $cas === '---' || !preg_match('/^\d{1,7}-\d{2}-\d$/', $cas)) {
        $skippedNoCas++;
        continue;
    }

    $toxNorm  = normalizeToxicity($tox, $toxCanon);
    $dateNorm = normalizeDate($date);
    $levelNum = extractNumeric($level);

    if (!isset($byCas[$cas])) {
        $byCas[$cas] = [
            'name'      => $chem,
            'toxicity'  => $toxNorm,
            'mechanism' => $mech,
            'date'      => $dateNorm,
            'nsrl_ug'   => null,
            'madl_ug'   => null,
        ];
    } else {
        $byCas[$cas]['toxicity'] = array_values(array_unique(array_merge(
            $byCas[$cas]['toxicity'], $toxNorm
        )));
        if ($dateNorm !== null &&
            ($byCas[$cas]['date'] === null || $dateNorm < $byCas[$cas]['date'])) {
            $byCas[$cas]['date'] = $dateNorm;
        }
    }

    if ($levelNum !== null) {
        // Cancer → NSRL; anything else → MADL. If both kinds of levels
        // exist across rows, each populates its own column.
        if (in_array('cancer', $toxNorm, true)) {
            $byCas[$cas]['nsrl_ug'] = $levelNum;
        } else {
            $byCas[$cas]['madl_ug'] = $levelNum;
        }
    }
}
fclose($fh);

out("Parsed {$rowCount} data rows → " . count($byCas) . " unique CAS", $quiet);
out("  Skipped no-CAS ('---' or invalid): {$skippedNoCas}", $quiet);
out("  Skipped delisted: {$skippedDelisted}", $quiet);
out('', $quiet);

// ─── upsert ──────────────────────────────────────────────────────────
$db = Database::getInstance();
$sourceRef = 'OEHHA ' . date('Y-m-d');

$inserted = $updated = $unchanged = $skippedManual = 0;
$bumpCases = [];  // CASes whose insert/update touched the regulatory list

foreach ($byCas as $cas => $d) {
    sort($d['toxicity']);
    $toxStr = implode(',', $d['toxicity']);

    $existing = $db->fetch(
        "SELECT id, chemical_name, toxicity_type, listing_mechanism,
                nsrl_ug, madl_ug, date_listed, source_ref
         FROM prop65_list WHERE cas_number = ?",
        [$cas]
    );

    // Rows edited through /prop65 (the admin UI) carry source_ref='manual'
    // and must survive OEHHA refreshes verbatim — the operator deliberately
    // curated them. Leave them alone.
    if ($existing !== null && ($existing['source_ref'] ?? null) === 'manual') {
        $skippedManual++;
        continue;
    }

    $newData = [
        'chemical_name'     => $d['name'],
        'toxicity_type'     => $toxStr,
        'listing_mechanism' => $d['mechanism'] !== '' ? $d['mechanism'] : null,
        'nsrl_ug'           => $d['nsrl_ug'],
        'madl_ug'           => $d['madl_ug'],
        'date_listed'       => $d['date'],
        'source_ref'        => $sourceRef,
    ];

    if ($existing === null) {
        $inserted++;
        $bumpCases[] = $cas;
        if (!$dryRun) {
            $db->insert('prop65_list', array_merge(['cas_number' => $cas], $newData));
        }
        continue;
    }

    // Never overwrite an existing non-null value with null — if OEHHA
    // omits a field that was populated by a prior seed, keep the seeded
    // value. (In practice OEHHA always has date + mechanism, but this
    // keeps the importer safe against formatting changes.)
    foreach (['listing_mechanism', 'date_listed', 'nsrl_ug', 'madl_ug'] as $f) {
        if ($newData[$f] === null && $existing[$f] !== null) {
            $newData[$f] = $existing[$f];
        }
    }

    $changed = false;
    foreach (['chemical_name', 'toxicity_type', 'listing_mechanism', 'date_listed'] as $f) {
        if ((string) ($existing[$f] ?? '') !== (string) ($newData[$f] ?? '')) {
            $changed = true;
            break;
        }
    }
    if (!$changed) {
        foreach (['nsrl_ug', 'madl_ug'] as $f) {
            $ev = $existing[$f];
            $nv = $newData[$f];
            if (($ev === null) !== ($nv === null)) { $changed = true; break; }
            if ($ev !== null && $nv !== null && abs((float) $ev - (float) $nv) > 1e-9) {
                $changed = true; break;
            }
        }
    }

    if ($changed) {
        $updated++;
        $bumpCases[] = $cas;
        if (!$dryRun) {
            $db->update('prop65_list', $newData, '`id` = ?', [$existing['id']]);
        }
    } else {
        $unchanged++;
    }
}

// Propagate list changes to RMs: any raw material whose constituents
// include a CAS we just inserted or updated is now stale for bulk
// publish. One batched UPDATE covers all of them.
$bumpedRms = 0;
if (!$dryRun && !empty($bumpCases)) {
    $bumpedRms = \SDS\Services\RegulatoryListBumper::bumpByCasMany($bumpCases);
}

out('=== Summary ===', $quiet);
out("  Inserted:        {$inserted}", $quiet);
out("  Updated:         {$updated}", $quiet);
out("  Unchanged:       {$unchanged}", $quiet);
out("  Skipped manual:  {$skippedManual}  (source_ref='manual', preserved verbatim)", $quiet);
out("  RMs bumped:      {$bumpedRms}  (constituents containing inserted/updated CAS)", $quiet);
if ($dryRun) {
    out('', $quiet);
    out('DRY-RUN: no DB writes. Re-run with --confirm to apply.', $quiet);
}

exit(0);
