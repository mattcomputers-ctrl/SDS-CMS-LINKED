#!/usr/bin/env php
<?php
/**
 * seed-cas-element-flags.php — seed the cas_master element flags
 * (has_nitrogen / has_sulfur / has_halogen) that drive the SDS Section 10
 * "Hazardous decomposition products" sentence (content audit #19).
 *
 * Thin CLI wrapper over SDS\Services\CasElementFlagSeeder — the same
 * plan() / apply() the "Seed element flags (preview)" page on
 * /determinations/element-flags uses, so the dry-run here and the preview
 * there show identical rows and counts.
 *
 * For every cas_master row, CasElementFlagger::infer() decides the flags:
 *   1. a parseable Hill molecular_formula is authoritative (N / S / F,Cl,Br,I);
 *   2. otherwise a conservative keyword scan of the preferred name, the
 *      synonyms_json entries and every constituent / Prop 65 / HAP list
 *      name recorded for that CAS.
 * The dry-run prints, per changed CAS, the new flags and the basis
 * (formula, or the keywords that fired) so false positives can be reviewed
 * and corrected later with the "Flags" button on /determinations
 * (CAS Descriptions tab). Rows corrected there carry
 * element_flags_source = 'manual' and are left alone unless --force.
 *
 * Usage:
 *   php scripts/seed-cas-element-flags.php             # dry-run
 *   php scripts/seed-cas-element-flags.php --confirm   # apply
 *
 * Options:
 *   --confirm    actually write to the DB (default is dry-run)
 *   --quiet      suppress per-row progress output
 *   --force      also rewrite rows whose element_flags_source = 'manual'
 *   --no-queue   bump the affected raw materials but do not enqueue
 *                SDS-update review rows
 *
 * A flag change is SDS content: every raw material carrying a changed CAS
 * is bumped (raw_materials.updated_at — the bulk-publish staleness signal)
 * and the published SDSs that use those RMs are queued on the SDS Updates
 * page. NOTE: the FIRST run on an existing catalog therefore bumps every
 * RM that carries a flagged CAS (Section 10 text changes for those
 * products) — run it before the next bulk publish, in a maintenance
 * window, and consider --no-queue if the SDS Updates page would be flooded.
 *
 * Idempotent: a second run changes nothing (no bumps, no queue rows).
 *
 * The flag writes and the RM bumps are one transaction (a failure rolls
 * both back and the script dies with the exception — re-run it). SDS-update
 * queueing happens after the commit; if it fails the flags and bumps are
 * already in, the error is printed and the exit code is 2.
 *
 * Exit codes:
 *   0 = ok (dry-run or applied)
 *   1 = usage error
 *   2 = flags and RM bumps committed, but SDS-update queueing failed
 *       (run the SDS Updates scan to queue the affected products)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\CasElementFlagSeeder;

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
    fwrite(STDERR, "Usage: php scripts/seed-cas-element-flags.php [--confirm] [--quiet] [--force] [--no-queue]\n");
    exit(1);
}

function out(string $msg, bool $quiet): void { if (!$quiet) echo $msg . "\n"; }

out("=== cas_master element flags seed (audit #19) ===", $quiet);
out("Mode: " . ($dryRun ? 'DRY-RUN (use --confirm to apply)' : 'APPLY')
    . ($force ? ' + force (manual rows rewritten)' : '')
    . ($noQueue ? ' + no-queue' : ''), $quiet);
out('', $quiet);

$seeder = new CasElementFlagSeeder(Database::getInstance());

// ─── plan (dry-run) or apply ─────────────────────────────────────────
$r = $dryRun
    ? $seeder->plan($force)
    : $seeder->apply(['force' => $force, 'queue' => !$noQueue, 'userId' => null]);

foreach ($r['rows'] as $row) {
    $p = $row['proposed'];
    out(sprintf("%-14s N=%d S=%d X=%d  <- %s", $row['cas'], $p['has_nitrogen'], $p['has_sulfur'], $p['has_halogen'], $row['basis']), $quiet);
}

$bumpedRms = (int) ($r['rmsBumped'] ?? 0);
$queued    = (int) ($r['sdsQueued'] ?? 0);

out('', $quiet);
out('=== Summary ===', $quiet);
out("  Rows scanned:    {$r['scanned']}", $quiet);
out("  Changed:         {$r['changed']}", $quiet);
out("  Unchanged:       {$r['unchanged']}", $quiet);
out("  Skipped manual:  {$r['skippedManual']}  (element_flags_source='manual'; use --force to rewrite)", $quiet);
out("  RMs bumped:      {$bumpedRms}  (constituents containing a changed CAS)", $quiet);
out("  SDSs queued:     {$queued}" . ($noQueue ? '  (--no-queue)' : ''), $quiet);
if ($dryRun) {
    out('', $quiet);
    out('DRY-RUN: no DB writes. Re-run with --confirm to apply.', $quiet);
}

$postError = $r['postCommitError'] ?? null;
if ($postError !== null) {
    fwrite(STDERR, "\nERROR: {$postError}\n");
    fwrite(STDERR, "The {$r['changed']} flag change(s) and {$bumpedRms} RM bump(s) ARE committed; a re-run will report Changed: 0.\n");
    fwrite(STDERR, "Run the SDS Updates scan to queue the affected products.\n");
    exit(2);
}

exit(0);
