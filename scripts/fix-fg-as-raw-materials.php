#!/usr/bin/env php
<?php
/**
 * fix-fg-as-raw-materials.php — one-time cleanup utility.
 *
 * Finds raw materials whose internal_code matches a finished good product_code
 * (with or without pack extension), converts formula_lines that reference them
 * to use finished_good_component_id instead, and removes the fake RM entries.
 *
 * Usage:
 *   php scripts/fix-fg-as-raw-materials.php              # Dry run — shows what would change
 *   php scripts/fix-fg-as-raw-materials.php --confirm     # Actually apply changes
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\Database;
use SDS\Core\App;

new App();
$db = Database::getInstance();
$dryRun = !in_array('--confirm', $argv, true);

if ($dryRun) {
    echo "=== DRY RUN — no changes will be made (pass --confirm to apply) ===\n\n";
}

// Load all finished good product codes
$fgRows = $db->fetchAll("SELECT id, product_code FROM finished_goods");
$fgByCode = [];
foreach ($fgRows as $row) {
    $fgByCode[$row['product_code']] = (int) $row['id'];
}

// Load all raw materials
$rmRows = $db->fetchAll("SELECT id, internal_code FROM raw_materials ORDER BY internal_code");

$matches = [];

foreach ($rmRows as $rm) {
    $code = $rm['internal_code'];
    $rmId = (int) $rm['id'];

    // Direct match
    if (isset($fgByCode[$code])) {
        $matches[] = [
            'rm_id'        => $rmId,
            'rm_code'      => $code,
            'fg_id'        => $fgByCode[$code],
            'fg_code'      => $code,
            'match_type'   => 'exact',
        ];
        continue;
    }

    // Pack extension match
    $baseCode = strip_pack_extension($code);
    if ($baseCode !== $code && isset($fgByCode[$baseCode])) {
        $matches[] = [
            'rm_id'        => $rmId,
            'rm_code'      => $code,
            'fg_id'        => $fgByCode[$baseCode],
            'fg_code'      => $baseCode,
            'match_type'   => 'pack_extension',
        ];
    }
}

if (empty($matches)) {
    echo "No raw materials found that match finished goods. Nothing to do.\n";
    exit(0);
}

echo sprintf("Found %d raw material(s) that are actually finished goods:\n\n", count($matches));
echo str_pad('RM Code', 20) . str_pad('RM ID', 8) . str_pad('FG Code', 15) . str_pad('FG ID', 8) . "Match\n";
echo str_repeat('-', 65) . "\n";

foreach ($matches as $m) {
    echo str_pad($m['rm_code'], 20)
        . str_pad((string) $m['rm_id'], 8)
        . str_pad($m['fg_code'], 15)
        . str_pad((string) $m['fg_id'], 8)
        . $m['match_type'] . "\n";
}
echo "\n";

$totalFormulaLines = 0;
$totalFormulas = 0;
$deletedRMs = 0;
$skippedRMs = 0;

foreach ($matches as $m) {
    $rmId = $m['rm_id'];
    $fgId = $m['fg_id'];
    $rmCode = $m['rm_code'];

    // Find formula_lines referencing this RM
    $lines = $db->fetchAll(
        "SELECT fl.id, fl.formula_id, fl.pct, fl.sort_order,
                f.finished_good_id, fg.product_code
         FROM formula_lines fl
         JOIN formulas f ON f.id = fl.formula_id
         JOIN finished_goods fg ON fg.id = f.finished_good_id
         WHERE fl.raw_material_id = ?",
        [$rmId]
    );

    if (!empty($lines)) {
        $formulaIds = array_unique(array_column($lines, 'formula_id'));
        echo sprintf("  RM %s (id=%d) → FG %s (id=%d): %d formula line(s) in %d formula(s)\n",
            $rmCode, $rmId, $m['fg_code'], $fgId, count($lines), count($formulaIds));

        foreach ($lines as $line) {
            echo sprintf("    - Formula #%d (%s): %.4f%%\n",
                $line['formula_id'], $line['product_code'], $line['pct']);
        }

        if (!$dryRun) {
            $db->query(
                "UPDATE formula_lines
                 SET raw_material_id = NULL, finished_good_component_id = ?
                 WHERE raw_material_id = ?",
                [$fgId, $rmId]
            );
            echo "    → Updated to finished_good_component_id={$fgId}\n";
        }

        $totalFormulaLines += count($lines);
        $totalFormulas += count($formulaIds);
    }

    // Check if the RM has constituents or other real data
    $constituentCount = $db->fetch(
        "SELECT COUNT(*) AS cnt FROM raw_material_constituents WHERE raw_material_id = ?",
        [$rmId]
    );
    $hasCons = ((int) ($constituentCount['cnt'] ?? 0)) > 0;

    if ($hasCons) {
        echo sprintf("  ⚠ RM %s has %d constituent(s) — skipping deletion (review manually)\n",
            $rmCode, (int) $constituentCount['cnt']);
        $skippedRMs++;
    } else {
        if (!$dryRun) {
            $db->query("DELETE FROM raw_materials WHERE id = ?", [$rmId]);
            echo sprintf("  RM %s deleted\n", $rmCode);
        } else {
            echo sprintf("  RM %s would be deleted (no constituents)\n", $rmCode);
        }
        $deletedRMs++;
    }
}

echo "\n=== Summary ===\n";
echo "Formula lines updated: {$totalFormulaLines}\n";
echo "Formulas affected: {$totalFormulas}\n";
echo "Raw materials " . ($dryRun ? 'to delete' : 'deleted') . ": {$deletedRMs}\n";
if ($skippedRMs > 0) {
    echo "Raw materials skipped (have constituents): {$skippedRMs}\n";
}

if ($dryRun) {
    echo "\nThis was a dry run. Pass --confirm to apply these changes.\n";
} else {
    echo "\nDone. All changes applied.\n";
}
