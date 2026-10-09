#!/usr/bin/env php
<?php
/**
 * import-tsca-inventory.php — load the EPA non-confidential TSCA Inventory
 * CSV into the local `tsca_inventory` table (audit #29).
 *
 * Section 15 of every SDS resolves each constituent CAS against this table
 * (plus the per-CAS override on /determinations > TSCA Review). A CAS that
 * is on the table resolves as "listed"; anything else prints the "TSCA
 * inventory status has not been verified for all components" sentence and
 * raises a publish warning.
 *
 * Download the current inventory from:
 *   https://www.epa.gov/tsca-inventory/how-access-tsca-inventory
 * (the "non-confidential" CSV inside the zip, e.g. TSCAINV_022025.csv).
 *
 * Usage:
 *   php scripts/import-tsca-inventory.php <path-to-csv>                 # dry-run
 *   php scripts/import-tsca-inventory.php <path-to-csv> --confirm       # apply
 *   php scripts/import-tsca-inventory.php <path-to-csv> --confirm --prune --version=TSCAINV_022025
 *
 * Options:
 *   --confirm           actually write to the DB (default is dry-run)
 *   --dry-run           parse + classify only (default)
 *   --quiet             suppress progress output
 *   --version=<label>   source_version stamped on every EPA row this run
 *                       touches (default: CSV basename without extension)
 *   --prune             with --confirm: after the upsert, delete EPA rows
 *                       whose source_version differs from this run's label
 *                       (CAS no longer in the file). Manual rows are never
 *                       pruned.
 *
 * Column assumptions (detected by regex on the header row; the header row
 * is the first row containing a cell that matches CASRN / CAS No / CAS
 * Number):
 *   - CAS column:   /^cas\s*(rn|#|no\.?|number)?$/i            (required)
 *   - name column:  /index\s*name|chemical\s*name|substance\s*name|^name$/i (required)
 *   - ACTIVITY:     /^activity$/i   (optional; ACTIVE / INACTIVE)
 *   - FLAG(S):      /^flags?$/i     (optional; S, XU, T, P, Y1, Y2 ...)
 *   If the CAS or name column is missing the script exits 2 and prints the
 *   headers it found so the regexes can be adjusted.
 *
 * Handling notes:
 *   - EPA sometimes zero-pads the first CAS segment (0000050-00-0);
 *     TSCAService::normaliseCas() strips that on import (and on lookup).
 *   - Rows whose CAS is not a valid CAS number (confidential-inventory
 *     accession numbers, blanks) are skipped and counted. Those substances
 *     are what the per-CAS "listed (crossover / confidential)" override on
 *     the TSCA Review tab is for.
 *   - Duplicate CAS rows: the first wins; the rest are counted.
 *   - Rows tagged source_ref='manual' (saved through /tsca) are never
 *     touched.
 *   - ACTIVITY blank or ACTIVE → is_active_inventory = 1; INACTIVE → 0
 *     (an INACTIVE substance is still on the inventory).
 *
 * Staleness: every CAS whose resolution changed (inserted ∪ pruned) that
 * is actually used by a raw material constituent is bumped through
 * RegulatoryListBumper::bumpByCasMany() (raw_materials.updated_at — the
 * bulk-publish signal) and queued on SDS Updates. Name / activity / flag
 * refreshes do not change the resolution and are not bumped.
 *
 * Idempotent: re-running with the same CSV and --version makes no row
 * changes (unchanged rows are only re-stamped with source_version /
 * imported_at so --prune can tell current rows apart).
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
use SDS\Services\TSCAService;

// ─── args ────────────────────────────────────────────────────────────
$csvPath = null;
$dryRun  = true;
$quiet   = false;
$prune   = false;
$version = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--confirm') { $dryRun = false; continue; }
    if ($arg === '--dry-run') { $dryRun = true;  continue; }
    if ($arg === '--quiet')   { $quiet  = true;  continue; }
    if ($arg === '--prune')   { $prune  = true;  continue; }
    if (str_starts_with($arg, '--version=')) {
        $version = trim(substr($arg, strlen('--version=')));
        continue;
    }
    if (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    if ($csvPath === null) { $csvPath = $arg; continue; }
    fwrite(STDERR, "Unexpected argument: {$arg}\n");
    exit(1);
}

if ($csvPath === null || !is_file($csvPath) || !is_readable($csvPath)) {
    fwrite(STDERR, "Usage: php scripts/import-tsca-inventory.php <path-to-csv> [--confirm] [--prune] [--version=LABEL] [--quiet]\n");
    fwrite(STDERR, "Download the non-confidential inventory CSV from: https://www.epa.gov/tsca-inventory/how-access-tsca-inventory\n");
    fwrite(STDERR, "Dry-run by default; use --confirm to apply.\n");
    exit(1);
}
if ($version === null || $version === '') {
    $version = pathinfo($csvPath, PATHINFO_FILENAME);
}
$version = mb_substr($version, 0, 100);

function out(string $msg, bool $quiet): void { if (!$quiet) echo $msg . "\n"; }

function toUtf8(string $s): string {
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    return (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

out("=== EPA TSCA Inventory import ===", $quiet);
out("CSV:     {$csvPath}", $quiet);
out("Version: {$version}", $quiet);
out("Mode:    " . ($dryRun ? 'DRY-RUN (use --confirm to apply)' : 'APPLY') . ($prune ? ' + PRUNE' : ''), $quiet);
out('', $quiet);

// ─── parse CSV ───────────────────────────────────────────────────────
$fh = fopen($csvPath, 'r');
if ($fh === false) { fwrite(STDERR, "Cannot open CSV\n"); exit(1); }

// Header row = first row with a CAS-like header cell (EPA files may carry
// preamble lines). Strip a UTF-8 BOM from the first cell.
$header     = null;
$headerScan = 0;
while (($row = fgetcsv($fh)) !== false) {
    $headerScan++;
    if ($headerScan > 50) break;
    if (empty($row)) continue;
    $cells = array_map(static fn($c): string => trim(toUtf8((string) $c)), $row);
    if (isset($cells[0])) {
        $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]) ?? $cells[0];
    }
    foreach ($cells as $c) {
        if (preg_match('/^cas\s*(rn|#|no\.?|number)?$/i', $c)) {
            $header = $cells;
            break 2;
        }
    }
}
if ($header === null) {
    fwrite(STDERR, "Could not find header row (expected a CASRN / CAS No / CAS Number column in the first 50 rows)\n");
    exit(2);
}

$colIdx = [];
foreach ($header as $i => $h) {
    if (!isset($colIdx['cas'])      && preg_match('/^cas\s*(rn|#|no\.?|number)?$/i', $h)) { $colIdx['cas'] = $i; continue; }
    if (!isset($colIdx['name'])     && preg_match('/index\s*name|chemical\s*name|substance\s*name|^name$/i', $h)) { $colIdx['name'] = $i; continue; }
    if (!isset($colIdx['activity']) && preg_match('/^activity$/i', $h)) { $colIdx['activity'] = $i; continue; }
    if (!isset($colIdx['flag'])     && preg_match('/^flags?$/i', $h)) { $colIdx['flag'] = $i; continue; }
    if (!isset($colIdx['uvcb'])     && preg_match('/^uvcb$/i', $h)) { $colIdx['uvcb'] = $i; continue; }
}
foreach (['cas', 'name'] as $req) {
    if (!isset($colIdx[$req])) {
        fwrite(STDERR, "Header missing required column: {$req}\n");
        fwrite(STDERR, "Found: " . implode(' | ', $header) . "\n");
        fwrite(STDERR, "Adjust the column regexes at the top of scripts/import-tsca-inventory.php to match this file.\n");
        exit(2);
    }
}
out('Columns: cas=' . $header[$colIdx['cas']] . ', name=' . $header[$colIdx['name']]
    . (isset($colIdx['activity']) ? ', activity=' . $header[$colIdx['activity']] : ', activity=(none; all ACTIVE)')
    . (isset($colIdx['flag']) ? ', flag=' . $header[$colIdx['flag']] : ', flag=(none)'), $quiet);

$parsed       = [];   // cas => [name, active, flags]
$rowCount     = 0;
$skippedNoCas = 0;
$dupes        = 0;

while (($row = fgetcsv($fh)) !== false) {
    if (empty($row) || (count($row) === 1 && trim((string) $row[0]) === '')) continue;
    $rowCount++;

    $cas = TSCAService::normaliseCas((string) ($row[$colIdx['cas']] ?? ''));
    if ($cas === '' || !preg_match(TSCAService::CAS_PATTERN, $cas)) {
        $skippedNoCas++;
        continue;
    }
    if (isset($parsed[$cas])) {
        $dupes++;
        continue;
    }

    $name = mb_substr(toUtf8(trim((string) ($row[$colIdx['name']] ?? ''))), 0, 500);
    $active = 1;
    if (isset($colIdx['activity'])) {
        $active = strtoupper(trim((string) ($row[$colIdx['activity']] ?? ''))) === 'INACTIVE' ? 0 : 1;
    }
    $flags = null;
    if (isset($colIdx['flag'])) {
        $f = mb_substr(toUtf8(trim((string) ($row[$colIdx['flag']] ?? ''))), 0, 50);
        $flags = $f !== '' ? $f : null;
    }

    $parsed[$cas] = ['name' => $name, 'active' => $active, 'flags' => $flags];
}
fclose($fh);

out("Parsed {$rowCount} data rows → " . count($parsed) . " unique CAS", $quiet);
out("  Skipped no-CAS / invalid CAS (confidential accession numbers, blanks): {$skippedNoCas}", $quiet);
out("  Duplicate CAS rows (first kept): {$dupes}", $quiet);
out('', $quiet);

if ($parsed === []) {
    fwrite(STDERR, "No valid CAS rows parsed — nothing to do.\n");
    exit(2);
}

// ─── classify against existing rows ──────────────────────────────────
$db = Database::getInstance();

$existing = [];
foreach ($db->fetchAll("SELECT cas_number, chemical_name, is_active_inventory, flags, source_ref FROM tsca_inventory") as $r) {
    $existing[$r['cas_number']] = $r;
}
out('Existing tsca_inventory rows: ' . count($existing), $quiet);

$toUpsert      = [];   // rows to INSERT ... ON DUPLICATE KEY UPDATE (new + changed)
$toRestamp     = [];   // unchanged EPA rows: only source_version / imported_at
$insertedCas   = [];
$inserted = $updated = $unchanged = $skippedManual = 0;

foreach ($parsed as $cas => $d) {
    $ex = $existing[$cas] ?? null;
    if ($ex === null) {
        $inserted++;
        $insertedCas[] = $cas;
        $toUpsert[] = [$cas, $d['name'], $d['active'], $d['flags']];
        continue;
    }
    if (($ex['source_ref'] ?? null) === 'manual') {
        $skippedManual++;
        continue;
    }
    $changed = (string) $ex['chemical_name'] !== $d['name']
        || (int) $ex['is_active_inventory'] !== $d['active']
        || (string) ($ex['flags'] ?? '') !== (string) ($d['flags'] ?? '');
    if ($changed) {
        $updated++;
        $toUpsert[] = [$cas, $d['name'], $d['active'], $d['flags']];
    } else {
        $unchanged++;
        $toRestamp[] = $cas;
    }
}

// ─── write ───────────────────────────────────────────────────────────
$importedAt = gmdate('Y-m-d H:i:s');
$pruned     = 0;
$prunedCas  = [];

if (!$dryRun) {
    $db->beginTransaction();
    try {
        foreach (array_chunk($toUpsert, 500) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as [$cas, $name, $active, $flags]) {
                $values[] = "(?, ?, ?, ?, 'EPA', ?, ?)";
                array_push($params, $cas, $name, $active, $flags, $version, $importedAt);
            }
            $db->query(
                "INSERT INTO tsca_inventory
                    (cas_number, chemical_name, is_active_inventory, flags, source_ref, source_version, imported_at)
                 VALUES " . implode(', ', $values) . "
                 ON DUPLICATE KEY UPDATE
                    chemical_name       = VALUES(chemical_name),
                    is_active_inventory = VALUES(is_active_inventory),
                    flags               = VALUES(flags),
                    source_ref          = 'EPA',
                    source_version      = VALUES(source_version),
                    imported_at         = VALUES(imported_at)",
                $params
            );
        }
        foreach (array_chunk($toRestamp, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $db->query(
                "UPDATE tsca_inventory SET source_version = ?, imported_at = ?
                 WHERE cas_number IN ({$ph}) AND (source_ref IS NULL OR source_ref <> 'manual')",
                array_merge([$version, $importedAt], $chunk)
            );
        }

        if ($prune) {
            $stale = $db->fetchAll(
                "SELECT cas_number FROM tsca_inventory
                 WHERE source_ref = 'EPA' AND (source_version IS NULL OR source_version <> ?)",
                [$version]
            );
            $prunedCas = array_map(static fn(array $r): string => (string) $r['cas_number'], $stale);
            foreach (array_chunk($prunedCas, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $db->query("DELETE FROM tsca_inventory WHERE source_ref = 'EPA' AND cas_number IN ({$ph})", $chunk);
            }
            $pruned = count($prunedCas);
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        fwrite(STDERR, "Import failed, rolled back: " . $e->getMessage() . "\n");
        exit(1);
    }
} elseif ($prune) {
    $stale = $db->fetchAll(
        "SELECT cas_number FROM tsca_inventory
         WHERE source_ref = 'EPA' AND (source_version IS NULL OR source_version <> ?)",
        [$version]
    );
    // Dry-run estimate: rows not in this file would be pruned (rows in the
    // file get re-stamped first on a real run).
    foreach ($stale as $r) {
        if (!isset($parsed[$r['cas_number']])) {
            $prunedCas[] = (string) $r['cas_number'];
        }
    }
    $pruned = count($prunedCas);
}

// ─── bump affected RMs (resolution changed: inserted ∪ pruned) ───────
$changedCas = array_values(array_unique(array_merge($insertedCas, $prunedCas)));
$affected   = [];
if ($changedCas !== []) {
    $inUse = [];
    foreach ($db->fetchAll("SELECT DISTINCT cas_number FROM raw_material_constituents WHERE cas_number <> ''") as $r) {
        $inUse[TSCAService::normaliseCas((string) $r['cas_number'])] = (string) $r['cas_number'];
    }
    foreach ($changedCas as $cas) {
        if (isset($inUse[$cas])) {
            $affected[] = $inUse[$cas];   // the stored spelling, for the bumper's IN list
        }
    }
}

$bumpedRms = 0;
$queued    = 0;
if (!$dryRun && $affected !== []) {
    $bumpedRms = \SDS\Services\RegulatoryListBumper::bumpByCasMany($affected);
    $queued    = \SDS\Services\RegulatoryListBumper::queueSdsUpdatesByCas($affected, null, 'TSCA inventory import ' . $version);
}

out('=== Summary ===', $quiet);
out("  Parsed rows:       {$rowCount}", $quiet);
out("  Unique CAS:        " . count($parsed), $quiet);
out("  Skipped no-CAS:    {$skippedNoCas}", $quiet);
out("  Duplicates:        {$dupes}", $quiet);
out("  Inserted:          {$inserted}", $quiet);
out("  Updated:           {$updated}  (name / activity / flags changed)", $quiet);
out("  Unchanged:         {$unchanged}  (re-stamped source_version only)", $quiet);
out("  Skipped manual:    {$skippedManual}  (source_ref='manual', preserved verbatim)", $quiet);
out("  Pruned:            {$pruned}" . ($prune ? '' : '  (--prune not set)'), $quiet);
out("  CAS in use changed: " . count($affected), $quiet);
out("  RMs bumped:        {$bumpedRms}", $quiet);
out("  SDSs queued:       {$queued}", $quiet);
if ($dryRun) {
    out('', $quiet);
    out('DRY-RUN: no DB writes. Re-run with --confirm to apply.', $quiet);
}

exit(0);
