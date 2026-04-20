#!/usr/bin/env php
<?php
/**
 * HazardEngine Golden Test Suite
 *
 * Curated classification scenarios with hand-verified expected outputs.
 * The suite acts as a regression safety net across the Hazard Engine
 * refactor (see docs/hazard-engine-refactor-plan.md) — each phase
 * expands the set with new scenarios, and every case must continue to
 * pass through every subsequent phase (unless intentionally updated).
 *
 * Run:
 *   php tests/Services/HazardEngineGoldenTest.php
 *
 * Exit code:
 *   0 = all cases passed
 *   1 = one or more cases failed
 *
 * Isolation:
 *   The suite inserts test rows into hazard_classifications and
 *   hazard_source_records using a reserved CAS range (99999-XX-X) that
 *   will never collide with real substances. Rows are removed at end of
 *   run via a finally block, even on failure. No live data is touched.
 *
 * Phase 0 baseline: 10 cases exercising current engine behavior.
 * Future phases add cases for summation, ATE, M-factor, CPD thresholds,
 * canonical-name normalization, and FG-level overrides.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\HazardEngine;

$db = Database::getInstance();

// --- Test fixture helpers ---------------------------------------------------

/** CAS numbers reserved for this test suite. */
const TEST_CAS = [
    'carc_1a'            => '99999-01-1',
    'skin_irrit_2'       => '99999-02-2',
    'mutagen_2'          => '99999-03-3',
    'skin_sens_1'        => '99999-04-4',
    'acute_tox_oral_3'   => '99999-05-5',
    'eye_dam_1'          => '99999-06-6',   // Will produce GHS05 -> forces GHS07 precedence
    'inert'              => '99999-07-7',   // No hazard data; bypasses classification
];

/**
 * Seed one hazard_classifications row plus the hazard_source_records parent
 * that the engine's JOIN requires. Returns the classification row id for
 * teardown.
 */
function seedClassification(
    Database $db,
    string $cas,
    string $className,
    string $category,
    array $hStatements,
    array $pStatements,
    array $pictograms,
    ?string $signalWord
): array {
    $sourceId = $db->insert('hazard_source_records', [
        'cas_number'   => $cas,
        'source_name'  => 'golden-test',
        'source_ref'   => 'HazardEngineGoldenTest.php',
        'payload_json' => json_encode(['test' => true]),
        'is_current'   => 1,
    ]);
    $classId = $db->insert('hazard_classifications', [
        'hazard_source_record_id' => $sourceId,
        'cas_number'              => $cas,
        'jurisdiction'            => 'US',
        'class_name'              => $className,
        'category'                => $category,
        'h_statements_json'       => json_encode($hStatements),
        'p_statements_json'       => json_encode($pStatements),
        'pictograms_json'         => json_encode($pictograms),
        'signal_word'             => $signalWord,
    ]);
    return ['source_id' => $sourceId, 'class_id' => $classId];
}

function teardown(Database $db, array $seededIds): void
{
    // Delete classifications first (FK to source record)
    foreach ($seededIds as $ids) {
        try {
            $db->getPdo()->prepare("DELETE FROM hazard_classifications WHERE id = ?")->execute([$ids['class_id']]);
        } catch (\Throwable) { /* best-effort */ }
    }
    foreach ($seededIds as $ids) {
        try {
            $db->getPdo()->prepare("DELETE FROM hazard_source_records WHERE id = ?")->execute([$ids['source_id']]);
        } catch (\Throwable) { /* best-effort */ }
    }
    // Safety-net cleanup: anything with the reserved CAS range.
    $db->getPdo()->exec("DELETE FROM hazard_classifications WHERE cas_number LIKE '99999-%'");
    $db->getPdo()->exec("DELETE FROM hazard_source_records WHERE cas_number LIKE '99999-%' OR source_name = 'golden-test'");
}

// --- Assertion helpers ------------------------------------------------------

$passed = 0;
$failed = 0;
$failures = [];

function assertContains(string $desc, array $haystack, string $needle): bool
{
    global $passed, $failed, $failures;
    if (in_array($needle, $haystack, true)) {
        $passed++;
        echo "  PASS  {$desc}\n";
        return true;
    }
    $failed++;
    $failures[] = $desc;
    echo "  FAIL  {$desc}\n";
    echo "        Expected to contain: {$needle}\n";
    echo "        Got: " . json_encode($haystack) . "\n";
    return false;
}

function assertNotContains(string $desc, array $haystack, string $needle): bool
{
    global $passed, $failed, $failures;
    if (!in_array($needle, $haystack, true)) {
        $passed++;
        echo "  PASS  {$desc}\n";
        return true;
    }
    $failed++;
    $failures[] = $desc;
    echo "  FAIL  {$desc}\n";
    echo "        Expected NOT to contain: {$needle}\n";
    echo "        Got: " . json_encode($haystack) . "\n";
    return false;
}

function assertEquals(string $desc, $expected, $actual): bool
{
    global $passed, $failed, $failures;
    if ($expected === $actual) {
        $passed++;
        echo "  PASS  {$desc}\n";
        return true;
    }
    $failed++;
    $failures[] = $desc;
    echo "  FAIL  {$desc}\n";
    echo "        Expected: " . var_export($expected, true) . "\n";
    echo "        Got:      " . var_export($actual, true) . "\n";
    return false;
}

function assertEmpty(string $desc, array $actual): bool
{
    global $passed, $failed, $failures;
    if (empty($actual)) {
        $passed++;
        echo "  PASS  {$desc}\n";
        return true;
    }
    $failed++;
    $failures[] = $desc;
    echo "  FAIL  {$desc}\n";
    echo "        Expected empty array; got: " . json_encode($actual) . "\n";
    return false;
}

// Extract pictogram / H-code / hazard-class identifiers from the engine output
// for comparison in assertions.
function picts(array $result): array
{
    return array_values($result['pictograms'] ?? []);
}
function hCodes(array $result): array
{
    return array_map(fn($s) => $s['code'] ?? '', $result['h_statements'] ?? []);
}
function hazardClasses(array $result): array
{
    return array_map(
        fn($hc) => ($hc['class'] ?? '') . '|' . ($hc['category'] ?? ''),
        $result['hazard_classes'] ?? []
    );
}
function traceSteps(array $result): array
{
    return array_column($result['trace'] ?? [], 'step');
}

// --- Suite ------------------------------------------------------------------

echo "=== HazardEngine Golden Test Suite ===\n";
echo "Engine version: " . HazardEngine::ENGINE_VERSION . "\n\n";

// Pre-clean any stale test data from previous aborted runs.
$db->getPdo()->exec("DELETE FROM hazard_classifications WHERE cas_number LIKE '99999-%'");
$db->getPdo()->exec("DELETE FROM hazard_source_records WHERE cas_number LIKE '99999-%' OR source_name = 'golden-test'");

$seeded = [];

try {
    // Seed once — all test cases share the same classification universe.
    $seeded['carc_1a'] = seedClassification(
        $db, TEST_CAS['carc_1a'], 'Carcinogenicity', 'Category 1A',
        ['H350'], ['P201','P308+P313'], ['GHS08'], 'Danger'
    );
    $seeded['skin_irrit_2'] = seedClassification(
        $db, TEST_CAS['skin_irrit_2'], 'Skin Corrosion/Irritation', 'Category 2',
        ['H315'], ['P264','P280'], ['GHS07'], 'Warning'
    );
    $seeded['mutagen_2'] = seedClassification(
        $db, TEST_CAS['mutagen_2'], 'Germ Cell Mutagenicity', 'Category 2',
        ['H341'], ['P201'], ['GHS08'], 'Warning'
    );
    $seeded['skin_sens_1'] = seedClassification(
        $db, TEST_CAS['skin_sens_1'], 'Skin Sensitization', 'Category 1',
        ['H317'], ['P261'], ['GHS07'], 'Warning'
    );
    $seeded['acute_tox_oral_3'] = seedClassification(
        $db, TEST_CAS['acute_tox_oral_3'], 'Acute Toxicity', 'Category 3',
        ['H301'], ['P264','P301+P310'], ['GHS06'], 'Danger'
    );
    $seeded['eye_dam_1'] = seedClassification(
        $db, TEST_CAS['eye_dam_1'], 'Serious Eye Damage/Irritation', 'Category 1',
        ['H318'], ['P280','P305+P351+P338'], ['GHS05'], 'Danger'
    );

    // ──────────────────────────────────────────────────────────────────
    echo "[1] Empty composition produces no hazards.\n";
    $engine = new HazardEngine();
    $result = $engine->classify([]);
    assertEmpty('empty: pictograms', picts($result));
    assertEmpty('empty: h_statements', hCodes($result));
    assertEmpty('empty: hazard_classes', hazardClasses($result));
    assertEquals('empty: signal_word is null', null, $result['signal_word']);

    // ──────────────────────────────────────────────────────────────────
    echo "\n[2] Concentration below 0.01% is skipped as trace.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['carc_1a'], 'chemical_name' => 'Test Carc', 'concentration_pct' => 0.005],
    ]);
    assertEmpty('trace: pictograms', picts($result));
    assertEmpty('trace: hazard_classes', hazardClasses($result));

    // ──────────────────────────────────────────────────────────────────
    echo "\n[3] Carcinogen Cat 1A at 0.05% is below cutoff (0.1%) — no trigger.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['carc_1a'], 'chemical_name' => 'Test Carc', 'concentration_pct' => 0.05],
    ]);
    assertEmpty('below-cutoff: pictograms', picts($result));
    assertEmpty('below-cutoff: hazard_classes', hazardClasses($result));
    assertContains('below-cutoff: trace logs below_cutoff step', traceSteps($result), 'below_cutoff');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[4] Carcinogen Cat 1A at 0.5% triggers — GHS08, H350, Danger.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['carc_1a'], 'chemical_name' => 'Test Carc', 'concentration_pct' => 0.5],
    ]);
    assertContains('carc: pictogram GHS08', picts($result), 'GHS08');
    assertContains('carc: H350', hCodes($result), 'H350');
    assertEquals('carc: signal_word Danger', 'Danger', $result['signal_word']);

    // ──────────────────────────────────────────────────────────────────
    echo "\n[5] Skin Irritation Cat 2 at 15% triggers — GHS07, H315, Warning.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['skin_irrit_2'], 'chemical_name' => 'Test Irritant', 'concentration_pct' => 15.0],
    ]);
    assertContains('skin-irrit: pictogram GHS07', picts($result), 'GHS07');
    assertContains('skin-irrit: H315', hCodes($result), 'H315');
    assertEquals('skin-irrit: signal_word Warning', 'Warning', $result['signal_word']);

    // ──────────────────────────────────────────────────────────────────
    echo "\n[6] Signal word escalation: Warning upgraded to Danger when Cat 1A fires alongside Cat 2.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['skin_irrit_2'], 'chemical_name' => 'Test Irritant', 'concentration_pct' => 15.0],
        ['cas_number' => TEST_CAS['carc_1a'],      'chemical_name' => 'Test Carc',     'concentration_pct' => 0.5],
    ]);
    assertEquals('escalation: final signal_word is Danger', 'Danger', $result['signal_word']);
    assertContains('escalation: both pictograms present (GHS07)', picts($result), 'GHS07');
    assertContains('escalation: both pictograms present (GHS08)', picts($result), 'GHS08');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[7] Pictogram precedence: GHS06 present removes GHS07.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['acute_tox_oral_3'], 'chemical_name' => 'Test Acute Tox', 'concentration_pct' => 5.0],
        ['cas_number' => TEST_CAS['skin_irrit_2'],     'chemical_name' => 'Test Irritant',  'concentration_pct' => 15.0],
    ]);
    assertContains('precedence: GHS06 retained', picts($result), 'GHS06');
    assertNotContains('precedence: GHS07 removed by GHS06', picts($result), 'GHS07');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[8] Pictogram precedence: GHS05 also removes GHS07.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['eye_dam_1'],    'chemical_name' => 'Test Eye Dam',  'concentration_pct' => 5.0],
        ['cas_number' => TEST_CAS['skin_irrit_2'], 'chemical_name' => 'Test Irritant', 'concentration_pct' => 15.0],
    ]);
    assertContains('precedence-ghs05: GHS05 retained', picts($result), 'GHS05');
    assertNotContains('precedence-ghs05: GHS07 removed by GHS05', picts($result), 'GHS07');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[9] Unknown CAS (no hazard data) produces no classification, logs no_data.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['inert'], 'chemical_name' => 'Unknown Substance', 'concentration_pct' => 50.0],
    ]);
    assertEmpty('no-data: pictograms', picts($result));
    assertEmpty('no-data: hazard_classes', hazardClasses($result));
    assertContains('no-data: trace logs no_data step', traceSteps($result), 'no_data');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[10] Mutagen Cat 2 at exactly 1.0% — right at cutoff — triggers.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['mutagen_2'], 'chemical_name' => 'Test Mutagen', 'concentration_pct' => 1.0],
    ]);
    assertContains('at-cutoff: pictogram GHS08', picts($result), 'GHS08');
    assertContains('at-cutoff: H341', hCodes($result), 'H341');

} catch (\Throwable $e) {
    echo "\n!!! EXCEPTION DURING TEST SUITE !!!\n";
    echo "  Class:   " . get_class($e) . "\n";
    echo "  Message: " . $e->getMessage() . "\n";
    echo "  File:    " . $e->getFile() . ':' . $e->getLine() . "\n";
    $failed++;
} finally {
    teardown($db, $seeded);
}

// --- Summary ---------------------------------------------------------------

echo "\n=== Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "All golden cases passed.\n";
exit(0);
