#!/usr/bin/env php
<?php
/**
 * seed-cas-acrylate-flags.php — seed cas_master.is_acrylate (A12a), the acrylate flag UV sheets use for the Section 4 acrylate names.
 *
 * Thin CLI wrapper over SDS\Services\CasAcrylateFlagSeeder — the same
 * plan() / apply() the "Seed acrylate flags (preview)" page on
 * /determinations/acrylate-flags uses, so the dry-run here and the preview
 * there show identical rows and counts.
 *
 * For every cas_master row the flag is set (1) when the CAS is on the legacy
 * 14-CAS list or any name known for it (preferred name, synonyms_json
 * entries, constituent / Prop 65 / HAP list names) passes the acrylate name
 * rule ("...acrylate", (meth)acrylic acid; acrylic polymers and resins never
 * count). The seed never writes 0 ("not an acrylate" is a manual decision in
 * the "Acrylate" box on /determinations, CAS Descriptions tab). NULL keeps
 * today's detection (legacy list / name rule). The dry-run prints, per
 * changed CAS, the current and proposed flag, whether the sheet text changes
 * and the basis, so false positives can be reviewed first. Rows set there
 * carry acrylate_source = 'manual' and are left alone unless --force.
 *
 * Usage:
 *   php scripts/seed-cas-acrylate-flags.php             # dry-run
 *   php scripts/seed-cas-acrylate-flags.php --confirm   # apply
 *
 * Options:
 *   --confirm    actually write to the DB (default is dry-run)
 *   --quiet      suppress per-row progress output
 *   --force      also rewrite rows whose acrylate_source = 'manual'
 *   --no-queue   bump the affected raw materials but do not enqueue
 *                SDS-update review rows
 *
 * Only CAS whose detection changes are SDS content: the raw materials
 * carrying them are bumped (raw_materials.updated_at — the bulk-publish
 * staleness signal) and the published SDSs that use those RMs are queued on
 * the SDS Updates page. A flag that confirms what the sheets already detect
 * is written without any RM bump.
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
use SDS\Services\CasAcrylateFlagSeeder;

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
    fwrite(STDERR, "Usage: php scripts/seed-cas-acrylate-flags.php [--confirm] [--quiet] [--force] [--no-queue]\n");
    exit(1);
}

function out(string $msg, bool $quiet): void { if (!$quiet) echo $msg . "\n"; }

out("=== cas_master acrylate flags seed (A12a) ===", $quiet);
out("Mode: " . ($dryRun ? 'DRY-RUN (use --confirm to apply)' : 'APPLY')
    . ($force ? ' + force (manual rows rewritten)' : '')
    . ($noQueue ? ' + no-queue' : ''), $quiet);
out('', $quiet);

$seeder = new CasAcrylateFlagSeeder(Database::getInstance());

// ─── plan (dry-run) or apply ─────────────────────────────────────────
$r = $dryRun
    ? $seeder->plan($force)
    : $seeder->apply(['force' => $force, 'queue' => !$noQueue, 'userId' => null]);

$fmtFlag = static fn (?int $v): string => $v === null ? '-' : ($v === 1 ? 'yes' : 'no');
foreach ($r['rows'] as $row) {
    out(sprintf("%-14s %-3s -> %-3s %-4s %-10s <- %s",
        $row['cas'], $fmtFlag($row['current']), $fmtFlag($row['proposed']),
        $row['bump'] ? 'TEXT' : 'meta', $row['nameCheck'] ? 'CHECK-NAME' : '', $row['basis']), $quiet);
}

$bumpedRms = (int) ($r['rmsBumped'] ?? 0);
$queued    = (int) ($r['sdsQueued'] ?? 0);

out('', $quiet);
out('=== Summary ===', $quiet);
out("  Rows scanned:    {$r['scanned']}", $quiet);
out("  Changed:         {$r['changed']}", $quiet);
out("  Sheet text:      {$r['textChanges']}  (detection changes; only these bump RMs / queue SDSs)", $quiet);
out("  Unchanged:       {$r['unchanged']}", $quiet);
out("  Skipped manual:  {$r['skippedManual']}  (acrylate_source='manual'; use --force to rewrite)", $quiet);
out("  RMs bumped:      {$bumpedRms}  (constituents containing a CAS whose detection changes)", $quiet);
out("  SDSs queued:     {$queued}" . ($noQueue ? '  (--no-queue)' : ''), $quiet);
$missing = $r['legacyNotInRegistry'] ?? [];
out('  Legacy CAS not in registry: ' . ($missing === [] ? 'none' : implode(', ', $missing)) . '  (detected through the old list for one more release only)', $quiet);
if ($dryRun) {
    out('', $quiet);
    out('DRY-RUN: no DB writes. Re-run with --confirm to apply.', $quiet);
}

$postError = $r['postCommitError'] ?? null;
if ($postError !== null) {
    fwrite(STDERR, "\nERROR: {$postError}\n");
    fwrite(STDERR, "The {$r['changed']} acrylate flag change(s) and {$bumpedRms} RM bump(s) ARE committed; a re-run will report Changed: 0.\n");
    fwrite(STDERR, "Run the SDS Updates scan to queue the affected products.\n");
    exit(2);
}

exit(0);
