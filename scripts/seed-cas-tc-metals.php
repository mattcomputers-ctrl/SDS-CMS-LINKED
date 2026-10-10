#!/usr/bin/env php
<?php
/**
 * seed-cas-tc-metals.php — seed cas_master.tc_metals (RCRA
 * toxicity-characteristic metals As/Ba/Cd/Cr/Pb/Hg/Se/Ag, audit #12) that
 * give metal compounds their Section 13 D004–D011 codes.
 *
 * Thin CLI wrapper over SDS\Services\CasTcMetalSeeder — the same
 * plan() / apply() the "Seed RCRA metal flags (preview)" page on
 * /determinations/tc-metals uses, so the dry-run here and the preview
 * there show identical rows and counts.
 *
 * For every cas_master row, CasElementFlagger::inferTcMetals() decides the
 * metals:
 *   1. a parseable Hill molecular_formula is authoritative;
 *   2. otherwise conservative name patterns (including Colour Index pigment
 *      names) over the preferred name, the synonyms_json entries and every
 *      constituent / Prop 65 / HAP list name recorded for that CAS.
 * The dry-run prints, per changed CAS, the current and proposed metals and
 * the basis (formula, or the patterns that fired) so false positives can be
 * reviewed and corrected later in the "RCRA metals" box on /determinations
 * (CAS Descriptions tab). Rows corrected there carry
 * tc_metals_source = 'manual' and are left alone unless --force.
 *
 * Usage:
 *   php scripts/seed-cas-tc-metals.php             # dry-run
 *   php scripts/seed-cas-tc-metals.php --confirm   # apply
 *
 * Options:
 *   --confirm    actually write to the DB (default is dry-run)
 *   --quiet      suppress per-row progress output
 *   --force      also rewrite rows whose tc_metals_source = 'manual'
 *   --no-queue   bump the affected raw materials but do not enqueue
 *                SDS-update review rows
 *
 * A metal change is SDS content: every raw material carrying a changed CAS
 * is bumped (raw_materials.updated_at — the bulk-publish staleness signal)
 * and the published SDSs that use those RMs are queued on the SDS Updates
 * page. NULL and '' both mean "none", so only CAS that really contain a TC
 * metal are written (expect barium sulfate and barium-lake pigments to add
 * a conditional D005 line to the products that contain them).
 *
 * Idempotent: a second run changes nothing (no bumps, no queue rows).
 *
 * The metal writes and the RM bumps are one transaction (a failure rolls
 * both back and the script dies with the exception — re-run it). SDS-update
 * queueing happens after the commit; if it fails the metals and bumps are
 * already in, the error is printed and the exit code is 2.
 *
 * Exit codes:
 *   0 = ok (dry-run or applied)
 *   1 = usage error
 *   2 = metals and RM bumps committed, but SDS-update queueing failed
 *       (run the SDS Updates scan to queue the affected products)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\CasTcMetalSeeder;

// ─── args ────────────────────────────────────────────────────────────
$dryRun  = true;
$quiet   = false;
$force   = false;
$noQueue = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--confirm')  { $dryRun  = false; continue; }
    if ($arg === '--dry-run')  { $dryRun  = true;  continue; }
    if ($arg === '--quiet')    { $quiet   = true;  continue; }
    if ($arg === '--force')    { $force   = true;  continue; }
    if ($arg === '--no-queue') { $noQueue = true;  continue; }
    if (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    fwrite(STDERR, "Unexpected argument: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/seed-cas-tc-metals.php [--confirm] [--quiet] [--force] [--no-queue]\n");
    exit(1);
}

function out(string $msg, bool $quiet): void { if (!$quiet) echo $msg . "\n"; }

out("=== cas_master RCRA metal flags seed (audit #12) ===", $quiet);
out("Mode: " . ($dryRun ? 'DRY-RUN (use --confirm to apply)' : 'APPLY')
    . ($force ? ' + force (manual rows rewritten)' : '')
    . ($noQueue ? ' + no-queue' : ''), $quiet);
out('', $quiet);

$seeder = new CasTcMetalSeeder(Database::getInstance());

// ─── plan (dry-run) or apply ─────────────────────────────────────────
$r = $dryRun
    ? $seeder->plan($force)
    : $seeder->apply(['force' => $force, 'queue' => !$noQueue, 'userId' => null]);

foreach ($r['rows'] as $row) {
    out(sprintf("%-14s %-12s -> %-12s <- %s", $row['cas'], implode(',', $row['current']) ?: '-', implode(',', $row['proposed']) ?: '-', $row['basis']), $quiet);
}

$bumpedRms = (int) ($r['rmsBumped'] ?? 0);
$queued    = (int) ($r['sdsQueued'] ?? 0);

out('', $quiet);
out('=== Summary ===', $quiet);
out("  Rows scanned:    {$r['scanned']}", $quiet);
out("  Changed:         {$r['changed']}", $quiet);
out("  Unchanged:       {$r['unchanged']}", $quiet);
out("  Skipped manual:  {$r['skippedManual']}  (tc_metals_source='manual'; use --force to rewrite)", $quiet);
out("  RMs bumped:      {$bumpedRms}  (constituents containing a changed CAS)", $quiet);
out("  SDSs queued:     {$queued}" . ($noQueue ? '  (--no-queue)' : ''), $quiet);
if ($dryRun) {
    out('', $quiet);
    out('DRY-RUN: no DB writes. Re-run with --confirm to apply.', $quiet);
}

$postError = $r['postCommitError'] ?? null;
if ($postError !== null) {
    fwrite(STDERR, "\nERROR: {$postError}\n");
    fwrite(STDERR, "The {$r['changed']} RCRA metal flag change(s) and {$bumpedRms} RM bump(s) ARE committed; a re-run will report Changed: 0.\n");
    fwrite(STDERR, "Run the SDS Updates scan to queue the affected products.\n");
    exit(2);
}

exit(0);
