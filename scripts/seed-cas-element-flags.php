#!/usr/bin/env php
<?php
/**
 * seed-cas-element-flags.php — seed the cas_master element flags
 * (has_nitrogen / has_sulfur / has_halogen) that drive the SDS Section 10
 * "Hazardous decomposition products" sentence (content audit #19).
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
 * Exit codes:
 *   0 = ok (dry-run or applied)
 *   1 = usage error
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\CasElementFlagger;
use SDS\Services\RegulatoryListBumper;

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

$db = Database::getInstance();

// ─── load registry + every name we know for each CAS ────────────────
$rows = $db->fetchAll(
    "SELECT cas_number, preferred_name, synonyms_json, molecular_formula,
            has_nitrogen, has_sulfur, has_halogen, element_flags_source
     FROM cas_master
     ORDER BY cas_number"
);

$namesByCas = [];
$nameSources = [
    "SELECT DISTINCT cas_number, chemical_name FROM raw_material_constituents
     WHERE cas_number <> '' AND chemical_name <> ''",
    "SELECT cas_number, chemical_name FROM prop65_list WHERE chemical_name <> ''",
    "SELECT cas_number, chemical_name FROM hap_list WHERE chemical_name <> ''",
];
foreach ($nameSources as $sql) {
    foreach ($db->fetchAll($sql) as $r) {
        $namesByCas[(string) $r['cas_number']][] = (string) $r['chemical_name'];
    }
}

// ─── classify ────────────────────────────────────────────────────────
$scanned = $changed = $unchanged = $skippedManual = 0;
$bumpCases = [];

foreach ($rows as $row) {
    $scanned++;
    $cas = (string) $row['cas_number'];

    $names = [(string) ($row['preferred_name'] ?? '')];
    $syn = $row['synonyms_json'] ?? null;
    if (is_string($syn) && $syn !== '') {
        $decoded = json_decode($syn, true);
        if (is_array($decoded)) {
            if (isset($decoded['synonyms']) && is_array($decoded['synonyms'])) {
                $decoded = $decoded['synonyms'];
            }
            foreach ($decoded as $s) {
                if (is_string($s)) {
                    $names[] = $s;
                }
            }
        }
    }
    foreach ($namesByCas[$cas] ?? [] as $n) {
        $names[] = $n;
    }

    $formula   = $row['molecular_formula'] ?? null;
    $inferred  = CasElementFlagger::infer(is_string($formula) ? $formula : null, $names);
    $new = [
        'has_nitrogen' => (int) $inferred['has_nitrogen'],
        'has_sulfur'   => (int) $inferred['has_sulfur'],
        'has_halogen'  => (int) $inferred['has_halogen'],
    ];
    $old = [
        'has_nitrogen' => (int) ($row['has_nitrogen'] ?? 0),
        'has_sulfur'   => (int) ($row['has_sulfur'] ?? 0),
        'has_halogen'  => (int) ($row['has_halogen'] ?? 0),
    ];

    if ($old === $new) {
        $unchanged++;
        continue;
    }
    if (($row['element_flags_source'] ?? null) === 'manual' && !$force) {
        $skippedManual++;
        continue;
    }

    $changed++;
    $bumpCases[] = $cas;

    $basis = CasElementFlagger::fromFormula(is_string($formula) ? $formula : null) !== null
        ? 'formula ' . $formula
        : 'names: ' . json_encode(CasElementFlagger::matchedKeywords($names), JSON_UNESCAPED_UNICODE);
    out(sprintf("%-14s N=%d S=%d X=%d  <- %s", $cas, $new['has_nitrogen'], $new['has_sulfur'], $new['has_halogen'], $basis), $quiet);

    if (!$dryRun) {
        $db->update('cas_master', $new + ['element_flags_source' => 'seed'], 'cas_number = ?', [$cas]);
    }
}

// ─── propagate: bump RMs (staleness) + queue SDS updates ─────────────
$bumpedRms = 0;
$queued    = 0;
if (!$dryRun && $bumpCases !== []) {
    $bumpedRms = RegulatoryListBumper::bumpByCasMany($bumpCases);
    if (!$noQueue) {
        $queued = RegulatoryListBumper::queueSdsUpdatesByCas($bumpCases, null, 'Section 10 element flags seeded (audit #19)');
    }
}

out('', $quiet);
out('=== Summary ===', $quiet);
out("  Rows scanned:    {$scanned}", $quiet);
out("  Changed:         {$changed}", $quiet);
out("  Unchanged:       {$unchanged}", $quiet);
out("  Skipped manual:  {$skippedManual}  (element_flags_source='manual'; use --force to rewrite)", $quiet);
out("  RMs bumped:      {$bumpedRms}  (constituents containing a changed CAS)", $quiet);
out("  SDSs queued:     {$queued}" . ($noQueue ? '  (--no-queue)' : ''), $quiet);
if ($dryRun) {
    out('', $quiet);
    out('DRY-RUN: no DB writes. Re-run with --confirm to apply.', $quiet);
}

exit(0);
