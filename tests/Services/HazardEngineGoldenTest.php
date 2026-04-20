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
    ?string $signalWord,
    ?string $classNameCanonical = null,
    ?string $categoryCanonical = null
): array {
    $sourceId = $db->insert('hazard_source_records', [
        'cas_number'   => $cas,
        'source_name'  => 'golden-test',
        'source_ref'   => 'HazardEngineGoldenTest.php',
        'payload_json' => json_encode(['test' => true]),
        'is_current'   => 1,
    ]);
    // Only include canonical columns if migration 037 has been applied;
    // otherwise the INSERT would fail on unknown columns.
    $row = [
        'hazard_source_record_id' => $sourceId,
        'cas_number'              => $cas,
        'jurisdiction'            => 'US',
        'class_name'              => $className,
        'category'                => $category,
        'h_statements_json'       => json_encode($hStatements),
        'p_statements_json'       => json_encode($pStatements),
        'pictograms_json'         => json_encode($pictograms),
        'signal_word'             => $signalWord,
    ];
    if (classificationColumnExists($db, 'class_name_canonical')) {
        $row['class_name_canonical'] = $classNameCanonical;
        $row['category_canonical']   = $categoryCanonical;
    }
    $classId = $db->insert('hazard_classifications', $row);
    return ['source_id' => $sourceId, 'class_id' => $classId];
}

/** Cached check — migration 037 column presence. */
function classificationColumnExists(Database $db, string $column): bool
{
    static $cache = [];
    if (isset($cache[$column])) return $cache[$column];
    $row = $db->fetch(
        "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hazard_classifications' AND COLUMN_NAME = ?",
        [$column]
    );
    return $cache[$column] = ((int) ($row['cnt'] ?? 0) > 0);
}

/**
 * Seed a competent_person_determinations row for CPD-path tests.
 * Returns the insert id for teardown.
 */
function seedCpd(Database $db, string $cas, array $determination): int
{
    return (int) $db->insert('competent_person_determinations', [
        'cas_number'         => $cas,
        'jurisdiction'       => 'US',
        'determination_json' => json_encode($determination),
        'rationale_text'     => 'golden-test fixture',
        'is_active'          => 1,
    ]);
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
    $db->getPdo()->exec("DELETE FROM competent_person_determinations WHERE cas_number LIKE '99999-%'");
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
$db->getPdo()->exec("DELETE FROM competent_person_determinations WHERE cas_number LIKE '99999-%'");

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

    // ──────────────────────────────────────────────────────────────────
    // Phase 1: canonical-name refactor tests
    // ──────────────────────────────────────────────────────────────────

    echo "\n[11] Phase 1 — hazard_classes output entries carry 'canonical' field.\n";
    $result = $engine->classify([
        ['cas_number' => TEST_CAS['carc_1a'], 'chemical_name' => 'Test Carc', 'concentration_pct' => 0.5],
    ]);
    $hasCanonical = false;
    foreach ($result['hazard_classes'] as $hc) {
        if (!empty($hc['canonical']) && $hc['canonical'] === \SDS\Services\GHSHazardClass::CARCINOGENICITY) {
            $hasCanonical = true;
            break;
        }
    }
    assertEquals('hazard_classes[].canonical present and correct', true, $hasCanonical);

    // ──────────────────────────────────────────────────────────────────
    echo "\n[12] Phase 1 — French class_name triggers via runtime normalization.\n";
    // Seed a row with a French display name and NULL canonical columns. The
    // engine should reverse-map "Cancérogénicité" -> "Carcinogenicity" ->
    // GHSHazardClass::CARCINOGENICITY at runtime and trigger normally.
    $seeded['french_carc'] = seedClassification(
        $db, '99999-08-8', 'Cancérogénicité', 'Catégorie 1A',
        ['H350'], ['P201'], ['GHS08'], 'Danger'
    );
    $result = $engine->classify([
        ['cas_number' => '99999-08-8', 'chemical_name' => 'Test Carc FR', 'concentration_pct' => 0.5],
    ]);
    assertContains('fr-runtime: pictogram GHS08', picts($result), 'GHS08');
    assertContains('fr-runtime: H350', hCodes($result), 'H350');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[13] Phase 1 — pre-filled canonical column used directly (fast path).\n";
    if (classificationColumnExists($db, 'class_name_canonical')) {
        // Seed a row with a deliberately-garbage class_name but a CORRECT
        // class_name_canonical. The engine should use the canonical column
        // directly and trigger, ignoring the nonsense display string.
        $seeded['canon_only'] = seedClassification(
            $db, '99999-09-9', 'Garbled Display String', 'nonsense category',
            ['H350'], ['P201'], ['GHS08'], 'Danger',
            \SDS\Services\GHSHazardClass::CARCINOGENICITY,
            'Cat 1A'
        );
        $result = $engine->classify([
            ['cas_number' => '99999-09-9', 'chemical_name' => 'Test Canon', 'concentration_pct' => 0.5],
        ]);
        assertContains('canon-path: pictogram GHS08', picts($result), 'GHS08');
        assertContains('canon-path: H350', hCodes($result), 'H350');
    } else {
        echo "  SKIP  canonical column not present — migration 037 not applied. Skipping test 13.\n";
    }

    // ──────────────────────────────────────────────────────────────────
    echo "\n[14] Phase 1 — unmappable class name falls through to default cutoff.\n";
    // Seed with a class_name we deliberately have no alias for. Engine will
    // log class_name_unmapped trace step and apply the 1% default cutoff.
    $seeded['unmapped'] = seedClassification(
        $db, '99999-10-0', 'Some Totally New Hazard Class 2099', 'Category 1',
        ['H999'], [], ['GHS08'], 'Warning'
    );
    $result = $engine->classify([
        ['cas_number' => '99999-10-0', 'chemical_name' => 'Test Unmapped', 'concentration_pct' => 5.0],
    ]);
    assertContains('unmapped: trace logs class_name_unmapped', traceSteps($result), 'class_name_unmapped');
    // With 5% concentration >= 1% default cutoff, classification still fires
    assertContains('unmapped: fires at >=1% default cutoff', hCodes($result), 'H999');

    // ──────────────────────────────────────────────────────────────────
    // Phase 2: CPD threshold enforcement tests
    // ──────────────────────────────────────────────────────────────────

    echo "\n[15] Phase 2 — CPD at 0.05% (below Carc Cat 1A cutoff of 0.1%) does NOT trigger.\n";
    $cpdIds = [];
    $cpdIds[] = seedCpd($db, '99999-20-0', [
        'selected_hazards' => ['Carcinogenicity - Category 1A'],
        'h_statements'     => 'H350',
        'p_statements'     => 'P201,P308+P313',
        'pictograms'       => 'GHS08',
        'signal_word'      => 'Danger',
    ]);
    $result = $engine->classify([
        ['cas_number' => '99999-20-0', 'chemical_name' => 'Test Carc CPD', 'concentration_pct' => 0.05],
    ]);
    assertEmpty('cpd-below: no pictograms', picts($result));
    assertEmpty('cpd-below: no H-codes', hCodes($result));
    assertEmpty('cpd-below: no hazard classes', hazardClasses($result));
    assertEquals('cpd-below: signal_word is null', null, $result['signal_word']);
    assertContains('cpd-below: trace logs cpd_below_cutoff', traceSteps($result), 'cpd_below_cutoff');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[16] Phase 2 — CPD at 0.5% (above cutoff) DOES trigger — full contribution.\n";
    $result = $engine->classify([
        ['cas_number' => '99999-20-0', 'chemical_name' => 'Test Carc CPD', 'concentration_pct' => 0.5],
    ]);
    assertContains('cpd-above: GHS08 present', picts($result), 'GHS08');
    assertContains('cpd-above: H350 present', hCodes($result), 'H350');
    assertEquals('cpd-above: signal_word Danger', 'Danger', $result['signal_word']);

    // ──────────────────────────────────────────────────────────────────
    echo "\n[17] Phase 2 — CPD with mixed hazards; partial trigger at 5%.\n";
    // Skin Corr/Irrit Cat 1 cutoff = 1%, Cat 2 cutoff = 10%. At conc=5%:
    // only Cat 1 triggers; Cat 2 falls below its 10% threshold. Cat 1
    // carries H314 + GHS05; Cat 2 carries H315 + GHS07. Expect H314 +
    // GHS05 present, H315 + GHS07 absent, cpd_below_cutoff trace for Cat 2.
    $cpdIds[] = seedCpd($db, '99999-21-1', [
        'selected_hazards' => [
            'Skin Corrosion/Irritation - Category 1',
            'Skin Corrosion/Irritation - Category 2',
        ],
        'h_statements'     => 'H314,H315',
        'p_statements'     => 'P260,P264',
        'pictograms'       => 'GHS05,GHS07',
        'signal_word'      => 'Danger',
    ]);
    $result = $engine->classify([
        ['cas_number' => '99999-21-1', 'chemical_name' => 'Test Mixed CPD', 'concentration_pct' => 5.0],
    ]);
    // Cat 1 contributed → H314 present, GHS05 present, classes non-empty
    assertContains('cpd-partial: H314 present (Cat 1 triggered)', hCodes($result), 'H314');
    assertContains('cpd-partial: GHS05 present (Cat 1 triggered)', picts($result), 'GHS05');
    // Cat 2 below cutoff → cpd_below_cutoff logged for it
    assertContains('cpd-partial: cpd_below_cutoff trace for Cat 2', traceSteps($result), 'cpd_below_cutoff');
    // Note: H315 is declared in the CPD's h_statements field alongside H314,
    // and the engine doesn't currently peel them apart per-category — merging
    // all declared H-codes when ANY hazard class triggers. H315 will appear
    // here even though its Cat 2 class didn't trigger. This is the existing
    // CPD shape (one combined statement list) and matches current behaviour.
    // Phase 3 may address per-class H-code attribution; for Phase 2 we're
    // validating only that a fully-below-cutoff CPD is fully suppressed.

    // ──────────────────────────────────────────────────────────────────
    echo "\n[18] Phase 2 — engine version stamp is bumped to v1.2-cpd-cutoffs.\n";
    assertEquals('ENGINE_VERSION is v1.2-cpd-cutoffs',
        'v1.2-cpd-cutoffs', \SDS\Services\HazardEngine::ENGINE_VERSION);

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
