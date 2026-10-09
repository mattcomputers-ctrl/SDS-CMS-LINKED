#!/usr/bin/env php
<?php
/**
 * import-tsca-inventory.php — load the EPA non-confidential TSCA Inventory
 * CSV into the local `tsca_inventory` table (audit #29).
 *
 * Thin CLI wrapper around SDS\Services\TSCAInventoryImporter — the same
 * class the "Import EPA TSCA inventory" card on /tsca uses, so the web
 * upload and this script parse, classify and write identically. The
 * header regexes, CAS handling, manual-row protection, UTC bump and
 * SDS-update queueing are documented on the class.
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
 * If the CAS or name column is missing the script exits 2 and prints the
 * headers it found so the regexes (TSCAInventoryImporter::*_HEADER_RE)
 * can be adjusted.
 *
 * Exit codes:
 *   0 = ok (dry-run or applied)
 *   1 = usage / IO error / write failure (rolled back)
 *   2 = CSV parse error (header not found / no valid rows)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\TSCAInventoryImporter;

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
$version = TSCAInventoryImporter::normaliseVersion($version, $csvPath);

function out(string $msg, bool $quiet): void { if (!$quiet) echo $msg . "\n"; }

out("=== EPA TSCA Inventory import ===", $quiet);
out("CSV:     {$csvPath}", $quiet);
out("Version: {$version}", $quiet);
out("Mode:    " . ($dryRun ? 'DRY-RUN (use --confirm to apply)' : 'APPLY') . ($prune ? ' + PRUNE' : ''), $quiet);
out('', $quiet);

// ─── parse CSV ───────────────────────────────────────────────────────
$importer = new TSCAInventoryImporter(Database::getInstance());
$p = $importer->parse($csvPath);

if ($p['headerError'] !== null) {
    if (str_starts_with($p['headerError'], 'Cannot open')) {
        fwrite(STDERR, "Cannot open CSV\n");
        exit(1);
    }
    fwrite(STDERR, $p['headerError'] . "\n");
    if (str_starts_with($p['headerError'], 'Header missing')) {
        fwrite(STDERR, "Found: " . implode(' | ', $p['headerFound']) . "\n");
        fwrite(STDERR, "Adjust the column regexes on src/Services/TSCAInventoryImporter.php to match this file.\n");
    }
    exit(2);
}

out('Columns: cas=' . $p['columns']['cas'] . ', name=' . $p['columns']['name']
    . ($p['columns']['activity'] !== null ? ', activity=' . $p['columns']['activity'] : ', activity=(none; all ACTIVE)')
    . ($p['columns']['flag'] !== null ? ', flag=' . $p['columns']['flag'] : ', flag=(none)'), $quiet);

out("Parsed {$p['rows']} data rows → {$p['uniqueCas']} unique CAS", $quiet);
out("  Skipped no-CAS / invalid CAS (confidential accession numbers, blanks): {$p['skippedNoCas']}", $quiet);
out("  Duplicate CAS rows (first kept): {$p['dupes']}", $quiet);
out('', $quiet);

if (!$p['ok']) {
    fwrite(STDERR, "No valid CAS rows parsed — nothing to do.\n");
    exit(2);
}

// ─── classify against existing rows ──────────────────────────────────
$existing = $importer->fetchExisting($version);
out('Existing tsca_inventory rows: ' . count($existing), $quiet);
$r = $importer->plan($p['parsed'], $existing, $importer->fetchInUseCas(), $version, $prune);

// ─── write ───────────────────────────────────────────────────────────
if (!$dryRun) {
    try {
        $r = $importer->apply($p['parsed'], ['version' => $version, 'prune' => $prune, 'queue' => true, 'userId' => null]);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

$bumpedRms = (int) ($r['rmsBumped'] ?? 0);
$queued    = (int) ($r['sdsQueued'] ?? 0);

out('=== Summary ===', $quiet);
out("  Parsed rows:       {$p['rows']}", $quiet);
out("  Unique CAS:        {$p['uniqueCas']}", $quiet);
out("  Skipped no-CAS:    {$p['skippedNoCas']}", $quiet);
out("  Duplicates:        {$p['dupes']}", $quiet);
out("  Inserted:          {$r['inserted']}", $quiet);
out("  Updated:           {$r['updated']}  (name / activity / flags changed)", $quiet);
out("  Unchanged:         {$r['unchanged']}  (re-stamped source_version only)", $quiet);
out("  Skipped manual:    {$r['skippedManual']}  (source_ref='manual', preserved verbatim)", $quiet);
out("  Pruned:            {$r['pruned']}" . ($prune ? '' : '  (--prune not set)'), $quiet);
out("  CAS in use changed: {$r['affectedCount']}", $quiet);
out("  RMs bumped:        {$bumpedRms}", $quiet);
out("  SDSs queued:       {$queued}", $quiet);
if ($dryRun) {
    out('', $quiet);
    out('DRY-RUN: no DB writes. Re-run with --confirm to apply.', $quiet);
}

exit(0);
