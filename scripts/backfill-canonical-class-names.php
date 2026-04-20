#!/usr/bin/env php
<?php
/**
 * SDS System — Phase 1 Canonical Class Name Backfill
 *
 * Populates the hazard_classifications.class_name_canonical and
 * .category_canonical columns added by migration 037 by running every row's
 * raw class_name / category through HazardClassAliases. Also scans
 * competent_person_determinations.determination_json for class strings that
 * would fail runtime normalisation — CPDs are normalised on-the-fly by the
 * engine rather than getting a schema column, so this check surfaces data-
 * quality issues the alias table needs to cover before Phase 1 lands.
 *
 * Idempotent: rows already carrying canonical values are skipped unless
 * --force is passed. Safe to rerun after alias additions.
 *
 * Usage:
 *   php scripts/backfill-canonical-class-names.php [options]
 *
 * Options:
 *   --force            Re-normalise every row, overwriting existing values.
 *                      Use after alias table changes that might have
 *                      reclassified previously-mapped rows.
 *   --dry-run          Log what would change without writing anything.
 *   --quiet            Suppress per-row progress output.
 *
 * Exit codes:
 *   0 = backfill complete, every row mapped successfully
 *   1 = configuration / IO error
 *   2 = backfill completed but one or more rows have unmapped class names
 *       (maintainer must add aliases and rerun before Phase 1 activates)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\HazardClassAliases;

$force   = false;
$dryRun  = false;
$quiet   = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    switch ($arg) {
        case '--force':    $force   = true; break;
        case '--dry-run':  $dryRun  = true; break;
        case '--quiet':    $quiet   = true; break;
        default:
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(1);
    }
}

function out(string $msg, bool $quiet): void
{
    if (!$quiet) {
        echo $msg . "\n";
    }
}

$db  = Database::getInstance();
$pdo = $db->getPdo();

// Sanity check: migration 037 must have been applied.
$colExists = (int) ($db->fetch(
    "SELECT COUNT(*) AS cnt
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'hazard_classifications'
        AND COLUMN_NAME  = 'class_name_canonical'"
)['cnt'] ?? 0);
if ($colExists === 0) {
    fwrite(STDERR, "ERROR: column class_name_canonical is missing. Apply migration 037 first.\n");
    exit(1);
}

out("=== Canonical Class Name Backfill ===", $quiet);
out("Mode:   " . ($dryRun ? 'dry-run' : 'apply'), $quiet);
out("Force:  " . ($force  ? 'yes'     : 'no (skipping already-populated rows)'), $quiet);
out("", $quiet);

// ── Pass 1: hazard_classifications ────────────────────────────────────

$totalStmt = $pdo->query(
    "SELECT COUNT(*) FROM hazard_classifications"
    . ($force ? "" : " WHERE class_name_canonical IS NULL OR category_canonical IS NULL")
);
$total = (int) $totalStmt->fetchColumn();
out("hazard_classifications rows to process: {$total}", $quiet);

$readSql = "SELECT id, class_name, category, class_name_canonical, category_canonical
              FROM hazard_classifications"
    . ($force ? "" : " WHERE class_name_canonical IS NULL OR category_canonical IS NULL")
    . " ORDER BY id";

$readStmt = $pdo->prepare($readSql);
$readStmt->execute();

$updateStmt = $pdo->prepare(
    "UPDATE hazard_classifications
        SET class_name_canonical = ?, category_canonical = ?
      WHERE id = ?"
);

$processed     = 0;
$updated       = 0;
$skipped       = 0;
$unmappedClass = 0;
$unmappedClassSamples = [];   // class_name → array of example row ids

while ($row = $readStmt->fetch(\PDO::FETCH_ASSOC)) {
    $processed++;
    if (!$quiet && $processed % 500 === 0) {
        out("  ...processed {$processed}/{$total}", $quiet);
    }

    $rawClass    = (string) ($row['class_name'] ?? '');
    $rawCategory = (string) ($row['category']   ?? '');

    $canonical = HazardClassAliases::normalize($rawClass);
    $catNorm   = HazardClassAliases::normalizeCategory($rawCategory);

    if ($canonical === null) {
        $unmappedClass++;
        if (!isset($unmappedClassSamples[$rawClass])) {
            $unmappedClassSamples[$rawClass] = [];
        }
        if (count($unmappedClassSamples[$rawClass]) < 3) {
            $unmappedClassSamples[$rawClass][] = (int) $row['id'];
        }
        // Leave the column NULL so the engine's runtime fallback kicks in
        // and the operator's fix-and-rerun cycle continues to highlight it.
        $canonical = null;
    }

    if ($canonical === (string) ($row['class_name_canonical'] ?? '__NULL__')
        && $catNorm === (string) ($row['category_canonical'] ?? '__NULL__')) {
        $skipped++;
        continue;
    }

    if (!$dryRun) {
        $updateStmt->execute([$canonical, $catNorm, (int) $row['id']]);
    }
    $updated++;
}

out("", $quiet);
out("hazard_classifications: processed={$processed}, "
  . "updated={$updated}" . ($dryRun ? ' (dry-run)' : '')
  . ", skipped={$skipped}, unmapped_class={$unmappedClass}", $quiet);

if (!empty($unmappedClassSamples)) {
    out("", $quiet);
    out("Unmapped class_name values (add aliases to HazardClassAliases::ALIASES):", $quiet);
    foreach ($unmappedClassSamples as $raw => $ids) {
        out(sprintf("  %-60s example row ids: %s", "'{$raw}'", implode(', ', $ids)), $quiet);
    }
}

// ── Pass 2: competent_person_determinations.determination_json ──────────

out("", $quiet);
out("Scanning competent_person_determinations for runtime-unmappable class strings...", $quiet);

$cpdRows = $db->fetchAll(
    "SELECT id, cas_number, determination_json FROM competent_person_determinations WHERE is_active = 1"
);

$cpdProcessed = 0;
$cpdUnmapped  = [];

foreach ($cpdRows as $cpd) {
    $cpdProcessed++;
    $det = json_decode((string) ($cpd['determination_json'] ?? '{}'), true);
    if (!is_array($det)) {
        continue;
    }

    // Free-text hazard_classes field: comma-separated class names
    $rawList = array_filter(array_map('trim', explode(',', (string) ($det['hazard_classes'] ?? ''))));
    foreach ($rawList as $raw) {
        if ($raw === '') continue;
        if (HazardClassAliases::normalize($raw) === null) {
            if (!isset($cpdUnmapped[$raw])) {
                $cpdUnmapped[$raw] = [];
            }
            if (count($cpdUnmapped[$raw]) < 3) {
                $cpdUnmapped[$raw][] = (int) $cpd['id'] . ' (' . $cpd['cas_number'] . ')';
            }
        }
    }

    // selected_hazards keys come from GHSHazardData — validated by the
    // admin form, so they're expected to map cleanly; skip the round-trip
    // check to avoid duplicate noise.
}

out("CPDs scanned: {$cpdProcessed}, unmapped class strings: " . count($cpdUnmapped), $quiet);
if (!empty($cpdUnmapped)) {
    out("", $quiet);
    out("Unmapped CPD class strings (the engine will fall through to default cutoff at runtime):", $quiet);
    foreach ($cpdUnmapped as $raw => $refs) {
        out(sprintf("  %-60s %s", "'{$raw}'", implode(', ', $refs)), $quiet);
    }
}

// ── Exit ────────────────────────────────────────────────────────────────

$totalUnmapped = $unmappedClass + array_sum(array_map('count', $cpdUnmapped));
if ($totalUnmapped > 0) {
    out("", $quiet);
    out("Backfill completed with {$totalUnmapped} unmapped class string(s).", $quiet);
    out("Add aliases for each to src/Services/HazardClassAliases.php and rerun with --force.", $quiet);
    exit(2);
}

out("", $quiet);
out("Backfill complete. Every class_name maps to a canonical code.", $quiet);
exit(0);
