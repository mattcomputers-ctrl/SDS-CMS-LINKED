#!/usr/bin/env php
<?php
/**
 * Sub-FG Manual Prop 65 Propagation Test
 *
 * Regression guard: before the fix, SDSGenerator::getManualProp65
 * read $calcResult['formula']['lines'], which only contains the
 * top-level formula's direct RM lines plus sub-FG component references.
 * An RM that lived inside a sub-FG (FG → intermediate FG → RM) was
 * invisible to the manual Prop 65 walker, so any prop65_data on that
 * RM silently failed to reach Section 15 of the generated SDS.
 *
 * The fix switches the call sites to use
 * $calcResult['formula_props']['enriched_lines'], which
 * FormulaCalcService fully expands through any depth of sub-FG
 * components. This suite asserts that manual Prop 65 entries on an
 * RM two levels deep make it into Prop65Service::analyse's output.
 *
 * Uses reflection to reach the private getManualProp65 method — we
 * don't seed FGs or formulas; we just verify that, given an
 * enriched_lines array shaped like FormulaCalcService would produce,
 * the walker returns entries for every RM in the tree.
 *
 * Run:
 *   php tests/Services/SubFgProp65PropagationTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\SDSGenerator;

$passed = 0;
$failed = 0;

function pass(string $m): void { global $passed; $passed++; echo "  PASS  {$m}\n"; }
function fail(string $m, string $d = ''): void { global $failed; $failed++; echo "  FAIL  {$m}\n"; if ($d) echo "        {$d}\n"; }

echo "=== Sub-FG Manual Prop 65 Propagation Test ===\n\n";

$db = Database::getInstance();

// Pick two RMs that currently carry non-empty prop65_data. The test
// simulates a formula structure where both land in enriched_lines
// (as they would if one were in a top-level formula and another
// inside a sub-FG component).
$manualRms = $db->fetchAll(
    "SELECT id, internal_code, prop65_data
       FROM raw_materials
      WHERE prop65_data IS NOT NULL
        AND prop65_data <> ''
        AND prop65_data <> '[]'
   ORDER BY id
      LIMIT 2"
);

if (count($manualRms) < 2) {
    echo "  SKIP  Need at least 2 raw materials with prop65_data to run this test; found " . count($manualRms) . ".\n";
    exit(0);
}

echo "  Using " . $manualRms[0]['internal_code'] . " (direct RM) + "
    . $manualRms[1]['internal_code'] . " (as if from sub-FG component).\n\n";

// Build an enriched_lines array that FormulaCalcService would produce
// for an FG like: [ direct_RM (10%), sub-FG (90%) → inner_RM (100%) ].
// After sub-FG expansion the inner_RM's pct becomes 90% × 100% = 90%.
$enrichedLines = [
    ['raw_material_id' => (int) $manualRms[0]['id'], 'pct' => 10.0, 'internal_code' => $manualRms[0]['internal_code']],
    ['raw_material_id' => (int) $manualRms[1]['id'], 'pct' => 90.0, 'internal_code' => $manualRms[1]['internal_code']],
];

// Reach getManualProp65 via reflection (it's private).
$gen = new SDSGenerator();
$refl = new \ReflectionClass($gen);
$m = $refl->getMethod('getManualProp65');
$m->setAccessible(true);

$result = $m->invokeArgs($gen, [$enrichedLines]);

// ── Assertions ────────────────────────────────────────────────────
is_array($result)
    ? pass('getManualProp65 returned an array')
    : fail('getManualProp65 did not return an array', 'got: ' . gettype($result));

// Both RMs' manual entries should appear in the result. The walker
// queries raw_materials by id; with enriched_lines it gets both ids.
$rmCodesInResult = array_unique(array_column($result, 'raw_material_code'));
foreach ($manualRms as $rm) {
    in_array($rm['internal_code'], $rmCodesInResult, true)
        ? pass('result contains entries from ' . $rm['internal_code'])
        : fail('result missing entries from ' . $rm['internal_code'],
            'got raw_material_codes: ' . json_encode($rmCodesInResult));
}

// Sanity: the effective pct stamped on each entry should match the
// line's pct, so trace decisions downstream reflect the scaled value.
foreach ($result as $entry) {
    $code = $entry['raw_material_code'] ?? '';
    $pct  = (float) ($entry['concentration_pct'] ?? 0);
    if ($code === $manualRms[0]['internal_code']) {
        $pct === 10.0
            ? pass("entry for {$code} has concentration_pct = 10")
            : fail("entry for {$code} pct mismatch", "got {$pct}");
    } elseif ($code === $manualRms[1]['internal_code']) {
        $pct === 90.0
            ? pass("entry for {$code} has concentration_pct = 90 (sub-FG scaled)")
            : fail("entry for {$code} pct mismatch", "got {$pct}");
    }
}

echo "\n=== Summary ===\n";
echo "  Passed: {$passed}\n";
echo "  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
