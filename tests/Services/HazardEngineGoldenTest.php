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
    ?string $categoryCanonical = null,
    array $ateValues = []
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
    // Phase 3c: optional explicit ATE values. Only attach when migration 038
    // has been applied; otherwise the INSERT would fail on unknown columns.
    if (!empty($ateValues) && classificationColumnExists($db, 'ate_oral_mg_kg')) {
        foreach (['ate_oral_mg_kg', 'ate_dermal_mg_kg',
                  'ate_inhalation_vapor_mg_l_4h', 'ate_inhalation_dust_mg_l_4h',
                  'ate_inhalation_gas_ppm_4h'] as $col) {
            if (array_key_exists($col, $ateValues)) {
                $row[$col] = $ateValues[$col];
            }
        }
    }
    // Phase 4: optional M-factor columns (co-located in $ateValues for
    // a single optional payload arg).
    if (!empty($ateValues) && classificationColumnExists($db, 'm_factor_acute')) {
        foreach (['m_factor_acute', 'm_factor_chronic'] as $col) {
            if (array_key_exists($col, $ateValues)) {
                $row[$col] = $ateValues[$col];
            }
        }
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
 *
 * Reproduces the real CPD data shape from AdminController: the inner
 * `selected_hazards` and `exposure_limits` fields are themselves stored
 * as JSON-encoded strings within determination_json (double-encoded).
 * The engine's parseDeterminationStructure does an inner json_decode on
 * them, so the fixture must match or it TypeErrors.
 */
function seedCpd(Database $db, string $cas, array $determination): int
{
    if (isset($determination['selected_hazards']) && is_array($determination['selected_hazards'])) {
        $determination['selected_hazards'] = json_encode($determination['selected_hazards']);
    }
    if (isset($determination['exposure_limits']) && is_array($determination['exposure_limits'])) {
        $determination['exposure_limits'] = json_encode($determination['exposure_limits']);
    }
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
    // GHS05 present, cpd_below_cutoff trace for Cat 2.
    // Note: GHSHazardData keys these separately as 'Skin Corrosion - …'
    // and 'Skin Irritation - …' even though the 'class' field maps both
    // to 'Skin Corrosion/Irritation'.
    $cpdIds[] = seedCpd($db, '99999-21-1', [
        'selected_hazards' => [
            'Skin Corrosion - Category 1',
            'Skin Irritation - Category 2',
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
    // Phase 3: GHS summation / additivity rules
    // ──────────────────────────────────────────────────────────────────

    echo "\n[18] Phase 3 — Three Cat 1A carcinogens at 0.04% each sum to 0.12% > 0.1% threshold → TRIGGERS.\n";
    // Seed three distinct CASes all classified Carc Cat 1A. None alone clears
    // the per-component 0.1% cutoff, but together they clear the 0.1%
    // summation threshold for carcinogenicity Cat 1A.
    $seeded['sum_carc_a'] = seedClassification(
        $db, '99999-30-0', 'Carcinogenicity', 'Category 1A',
        ['H350'], ['P201'], ['GHS08'], 'Danger'
    );
    $seeded['sum_carc_b'] = seedClassification(
        $db, '99999-31-1', 'Carcinogenicity', 'Category 1A',
        ['H350'], ['P201'], ['GHS08'], 'Danger'
    );
    $seeded['sum_carc_c'] = seedClassification(
        $db, '99999-32-2', 'Carcinogenicity', 'Category 1A',
        ['H350'], ['P201'], ['GHS08'], 'Danger'
    );
    $result = $engine->classify([
        ['cas_number' => '99999-30-0', 'chemical_name' => 'Carc A', 'concentration_pct' => 0.04],
        ['cas_number' => '99999-31-1', 'chemical_name' => 'Carc B', 'concentration_pct' => 0.04],
        ['cas_number' => '99999-32-2', 'chemical_name' => 'Carc C', 'concentration_pct' => 0.04],
    ]);
    assertContains('sum-carc-trigger: GHS08 via summation', picts($result), 'GHS08');
    assertContains('sum-carc-trigger: H350 via summation', hCodes($result), 'H350');
    assertEquals('sum-carc-trigger: signal_word Danger', 'Danger', $result['signal_word']);
    assertContains('sum-carc-trigger: trace logs summation_triggered', traceSteps($result), 'summation_triggered');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[19] Phase 3 — Three Cat 1A carcinogens at 0.02% each (sum 0.06%) do NOT trigger.\n";
    $result = $engine->classify([
        ['cas_number' => '99999-30-0', 'chemical_name' => 'Carc A', 'concentration_pct' => 0.02],
        ['cas_number' => '99999-31-1', 'chemical_name' => 'Carc B', 'concentration_pct' => 0.02],
        ['cas_number' => '99999-32-2', 'chemical_name' => 'Carc C', 'concentration_pct' => 0.02],
    ]);
    assertEmpty('sum-carc-below: no pictograms', picts($result));
    assertEmpty('sum-carc-below: no hazard_classes', hazardClasses($result));

    // ──────────────────────────────────────────────────────────────────
    echo "\n[20] Phase 3 — Per-component trigger + summation contributors does not duplicate.\n";
    // One CAS at 0.5% (above cutoff, triggers directly) + two CASes at 0.04%
    // (sub-threshold summation contributors). The direct trigger should fire
    // once; summation sees already-classified and skips its add.
    $result = $engine->classify([
        ['cas_number' => '99999-30-0', 'chemical_name' => 'Carc A', 'concentration_pct' => 0.5],
        ['cas_number' => '99999-31-1', 'chemical_name' => 'Carc B', 'concentration_pct' => 0.04],
        ['cas_number' => '99999-32-2', 'chemical_name' => 'Carc C', 'concentration_pct' => 0.04],
    ]);
    // Count Carc Cat 1A entries in the output — should be exactly one
    $carcCount = 0;
    foreach ($result['hazard_classes'] as $hc) {
        if (($hc['canonical'] ?? '') === \SDS\Services\GHSHazardClass::CARCINOGENICITY
            && ($hc['category_canonical'] ?? '') === 'Cat 1A') {
            $carcCount++;
        }
    }
    assertEquals('sum-no-dup: exactly one Carc Cat 1A entry after consolidation', 1, $carcCount);
    assertContains('sum-no-dup: GHS08 present', picts($result), 'GHS08');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[21] Phase 3 — Mixed Skin Sens Cat 1 contributors sum to trigger (0.1% threshold).\n";
    // Two distinct Skin Sens Cat 1 CASes at 0.06% each → sum 0.12% > 0.1%
    $seeded['sum_sens_a'] = seedClassification(
        $db, '99999-33-3', 'Skin Sensitization', 'Category 1',
        ['H317'], ['P261'], ['GHS07'], 'Warning'
    );
    $seeded['sum_sens_b'] = seedClassification(
        $db, '99999-34-4', 'Skin Sensitization', 'Category 1',
        ['H317'], ['P261'], ['GHS07'], 'Warning'
    );
    $result = $engine->classify([
        ['cas_number' => '99999-33-3', 'chemical_name' => 'Sens A', 'concentration_pct' => 0.06],
        ['cas_number' => '99999-34-4', 'chemical_name' => 'Sens B', 'concentration_pct' => 0.06],
    ]);
    assertContains('sum-skin-sens: GHS07 via summation', picts($result), 'GHS07');
    assertContains('sum-skin-sens: H317 via summation', hCodes($result), 'H317');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[22] Phase 3 — CPD contributors participate in summation.\n";
    // Two CPDs each declaring Carcinogenicity Cat 1A at 0.06% → sum 0.12%.
    // Neither individually triggers (both below 0.1% cutoff per Phase 2),
    // but the summation rule fires.
    $cpdIds[] = seedCpd($db, '99999-35-5', [
        'selected_hazards' => ['Carcinogenicity - Category 1A'],
        'h_statements'     => 'H350',
        'p_statements'     => 'P201',
        'pictograms'       => 'GHS08',
        'signal_word'      => 'Danger',
    ]);
    $cpdIds[] = seedCpd($db, '99999-36-6', [
        'selected_hazards' => ['Carcinogenicity - Category 1A'],
        'h_statements'     => 'H350',
        'p_statements'     => 'P201',
        'pictograms'       => 'GHS08',
        'signal_word'      => 'Danger',
    ]);
    $result = $engine->classify([
        ['cas_number' => '99999-35-5', 'chemical_name' => 'CPD Carc A', 'concentration_pct' => 0.06],
        ['cas_number' => '99999-36-6', 'chemical_name' => 'CPD Carc B', 'concentration_pct' => 0.06],
    ]);
    assertContains('sum-cpd: GHS08 via summation', picts($result), 'GHS08');
    assertContains('sum-cpd: H350 via summation', hCodes($result), 'H350');
    assertEquals('sum-cpd: signal_word Danger', 'Danger', $result['signal_word']);
    assertContains('sum-cpd: summation_triggered in trace', traceSteps($result), 'summation_triggered');

    // ──────────────────────────────────────────────────────────────────
    // Phase 3b: cross-category summation
    // ──────────────────────────────────────────────────────────────────

    echo "\n[23] Phase 3b — Cat 1 Skin Corr contributor counts 10× toward Cat 2 summation.\n";
    // Seed two Cat 1 Skin Corrosion contributors. Neither alone reaches 1 %
    // (per-component Cat 1 cutoff), and together they sum to 1 % which is
    // below the 5 % Cat 1 summation threshold. But 10× weighting puts the
    // Cat 2 cross-category sum at 10 % — the exact threshold — triggering
    // a Cat 2 mixture classification (H315, GHS07).
    $seeded['xcat_skin_a'] = seedClassification(
        $db, '99999-40-0', 'Skin Corrosion/Irritation', 'Category 1',
        ['H314'], ['P260'], ['GHS05'], 'Danger'
    );
    $seeded['xcat_skin_b'] = seedClassification(
        $db, '99999-41-1', 'Skin Corrosion/Irritation', 'Category 1',
        ['H314'], ['P260'], ['GHS05'], 'Danger'
    );
    $result = $engine->classify([
        ['cas_number' => '99999-40-0', 'chemical_name' => 'Skin Corr A', 'concentration_pct' => 0.5],
        ['cas_number' => '99999-41-1', 'chemical_name' => 'Skin Corr B', 'concentration_pct' => 0.5],
    ]);
    // Expect Cat 2 fired via cross-category summation → H315, GHS07
    assertContains('xcat-skin: H315 (Cat 2) present', hCodes($result), 'H315');
    assertContains('xcat-skin: trace logs cross_category_summation_triggered',
        traceSteps($result), 'cross_category_summation_triggered');
    // Cat 1 did NOT fire (1 % Cat 1 sum < 5 % summation threshold AND neither
    // component individually reached 1 %)
    assertNotContains('xcat-skin: H314 absent (Cat 1 did not fire)', hCodes($result), 'H314');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[24] Phase 3b — Cross-category skipped when Cat 1 already fires.\n";
    // Same two CASes at 3 % and 3 % → Cat 1 sum 6 % > 5 % simple summation
    // threshold → Cat 1 fires. Cross-category Cat 2 would also be above
    // threshold (10×6 + 0 = 60 ≥ 10), but consolidation drops Cat 2 in
    // favour of Cat 1 anyway, so the cross-category pass short-circuits.
    $result = $engine->classify([
        ['cas_number' => '99999-40-0', 'chemical_name' => 'Skin Corr A', 'concentration_pct' => 3.0],
        ['cas_number' => '99999-41-1', 'chemical_name' => 'Skin Corr B', 'concentration_pct' => 3.0],
    ]);
    assertContains('xcat-skip: H314 (Cat 1) present', hCodes($result), 'H314');
    // Cross-category should not have fired — Cat 1 is already there
    assertNotContains('xcat-skip: cross_category_summation_triggered absent',
        traceSteps($result), 'cross_category_summation_triggered');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[25] Phase 3b — STOT-SE cross-category: Cat 1 alone at 1 % triggers Cat 2.\n";
    // A single STOT-SE Cat 1 contributor at exactly 1 %. Per-component Cat 1
    // cutoff is 1.0 %, so 1.0 % hits it exactly — Cat 1 fires directly. To
    // test the pure cross-category path we need the component below Cat 1
    // cutoff: use 0.9 % → 10× = 9 % — NOT enough. Use 1.0 % Cat 1 + 1 %
    // Cat 2 → 10 + 1 = 11 ≥ 10 → Cat 2 via cross-category. But Cat 1
    // triggers directly at 1.0 %. Use 0.95 % Cat 1 only → 10× 0.95 = 9.5 <
    // 10 → no trigger. So this cross-category rule is only reachable when
    // a Cat 2 contributor exists too, or multiple Cat 1 contributors sum
    // to ≥ 1 % without any single reaching 1 %. Test the latter:
    $seeded['xcat_stot_a'] = seedClassification(
        $db, '99999-42-2', 'STOT — Single Exposure', 'Category 1',
        ['H370'], ['P260'], ['GHS08'], 'Danger'
    );
    $seeded['xcat_stot_b'] = seedClassification(
        $db, '99999-43-3', 'STOT — Single Exposure', 'Category 1',
        ['H370'], ['P260'], ['GHS08'], 'Danger'
    );
    // Two STOT-SE Cat 1 at 0.6 % each → Cat 1 sum 1.2 %, cross-cat 10×1.2 =
    // 12 % ≥ 10 → Cat 2 fires. Cat 1 simple summation is 1.2 < 10, so
    // Cat 1 doesn't fire.
    $result = $engine->classify([
        ['cas_number' => '99999-42-2', 'chemical_name' => 'STOT A', 'concentration_pct' => 0.6],
        ['cas_number' => '99999-43-3', 'chemical_name' => 'STOT B', 'concentration_pct' => 0.6],
    ]);
    assertContains('xcat-stot: H371 or H370 via Cat 2 path', hCodes($result), 'H371');
    assertContains('xcat-stot: trace logs cross_category_summation_triggered',
        traceSteps($result), 'cross_category_summation_triggered');

    // ──────────────────────────────────────────────────────────────────
    // Phase 3c: ATE mixture classification
    // ──────────────────────────────────────────────────────────────────

    echo "\n[26] Phase 3c — Single Cat 1 Oral at 0.05% (sub-cutoff) → ATE yields Cat 4 mixture.\n";
    // Per-component Cat 1 Oral cutoff is 0.1%. At 0.05% no per-component
    // trigger. ATE: 0.05 / 0.5 (default Cat 1 oral ATE) = 0.1. ATE_mix =
    // 100 / 0.1 = 1000 → falls in Cat 4 range (upper 2000).
    $seeded['ate_cat1_oral'] = seedClassification(
        $db, '99999-50-0', 'Acute Toxicity (Oral)', 'Category 1',
        ['H300'], ['P264'], ['GHS06'], 'Danger'
    );
    $result = $engine->classify([
        ['cas_number' => '99999-50-0', 'chemical_name' => 'Tox A', 'concentration_pct' => 0.05],
    ]);
    assertContains('ate-single: trace logs ate_mixture_classified',
        traceSteps($result), 'ate_mixture_classified');
    // GHSHazardData 'Acute Toxicity (Oral) - Category 4' has h_codes ['H302']
    assertContains('ate-single: H302 from Cat 4 mixture', hCodes($result), 'H302');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[27] Phase 3c — Two sub-cutoff Cat 1 Oral components via ATE.\n";
    // Two Cat 1 Oral CASes at 0.05% each. Neither triggers per-component.
    // Reciprocal sum = 0.05/0.5 + 0.05/0.5 = 0.2. ATE_mix = 500 → Cat 4.
    $seeded['ate_cat1_oral_b'] = seedClassification(
        $db, '99999-51-1', 'Acute Toxicity (Oral)', 'Category 1',
        ['H300'], ['P264'], ['GHS06'], 'Danger'
    );
    $result = $engine->classify([
        ['cas_number' => '99999-50-0', 'chemical_name' => 'Tox A', 'concentration_pct' => 0.05],
        ['cas_number' => '99999-51-1', 'chemical_name' => 'Tox B', 'concentration_pct' => 0.05],
    ]);
    assertContains('ate-two: trace logs ate_mixture_classified',
        traceSteps($result), 'ate_mixture_classified');
    assertContains('ate-two: H302 (Cat 4) from ATE mixture', hCodes($result), 'H302');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[28] Phase 3c — Explicit vendor ATE overrides category default.\n";
    // Same two Cat 1 Oral CASes, but with EXPLICIT ATE values on the
    // hazard_classifications rows. Explicit ATE 10 mg/kg (not the 0.5
    // default). Reciprocal = 0.05/10 + 0.05/10 = 0.01. ATE_mix = 10,000.
    // Above Cat 5's 5000 upper → NOT classified → trace logs
    // ate_mixture_not_classified.
    // (We have to seed fresh rows because the previous Cat 1 seeds have
    // no explicit ATE; explicit-ATE path is tested with new CASes.)
    $seeded['ate_explicit_a'] = seedClassification(
        $db, '99999-52-2', 'Acute Toxicity (Oral)', 'Category 1',
        ['H300'], ['P264'], ['GHS06'], 'Danger',
        null, null,
        ['ate_oral_mg_kg' => 10.0]
    );
    $seeded['ate_explicit_b'] = seedClassification(
        $db, '99999-53-3', 'Acute Toxicity (Oral)', 'Category 1',
        ['H300'], ['P264'], ['GHS06'], 'Danger',
        null, null,
        ['ate_oral_mg_kg' => 10.0]
    );
    $result = $engine->classify([
        ['cas_number' => '99999-52-2', 'chemical_name' => 'Tox C', 'concentration_pct' => 0.05],
        ['cas_number' => '99999-53-3', 'chemical_name' => 'Tox D', 'concentration_pct' => 0.05],
    ]);
    assertContains('ate-explicit: trace logs ate_mixture_not_classified (mix above all categories)',
        traceSteps($result), 'ate_mixture_not_classified');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[29] Phase 3c — ATE skipped when per-component classifies a more-severe category.\n";
    // Cat 1 Oral at 0.2 % (above 0.1 % Cat 1 per-component cutoff → Cat 1
    // fires directly). ATE would compute 0.2/0.5 = 0.4, ATE_mix = 250
    // → Cat 3 range, but Cat 1 is already classified and would dominate
    // in consolidation. moreSevereAlreadyClassified detects this and
    // suppresses the whole ATE contribution, so the Cat 3 H-code H301
    // never leaks into the output.
    $result = $engine->classify([
        ['cas_number' => '99999-50-0', 'chemical_name' => 'Tox A', 'concentration_pct' => 0.2],
    ]);
    // Cat 1 per-component fires → H300 present
    assertContains('ate-dominated: H300 (Cat 1) from per-component', hCodes($result), 'H300');
    // Cat 3's H301 must NOT appear — ATE was dominated
    assertNotContains('ate-dominated: H301 (Cat 3) absent — ATE suppressed', hCodes($result), 'H301');
    // Trace should carry ate_mixture_dominated
    assertContains('ate-dominated: trace logs ate_mixture_dominated',
        traceSteps($result), 'ate_mixture_dominated');
    // Final consolidated classes should contain Cat 1 (most severe wins)
    $hasCat1 = false;
    foreach ($result['hazard_classes'] as $hc) {
        if (($hc['canonical'] ?? '') === \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL
            && ($hc['category_canonical'] ?? '') === 'Cat 1') {
            $hasCat1 = true; break;
        }
    }
    assertEquals('ate-dominated: Cat 1 retained after consolidation', true, $hasCat1);

    // ──────────────────────────────────────────────────────────────────
    // Phase 4: aquatic-hazard summation with M-factor weighting
    // ──────────────────────────────────────────────────────────────────

    echo "\n[30] Phase 4 — M=100 aquatic acute Cat 1 at 0.3 % triggers via M-weighted summation.\n";
    // 0.3 % × M=100 = 30 % ≥ 25 % → fires Aquatic Acute Cat 1. Contributor
    // is below the 1 % per-component cutoff, so only summation catches it.
    $seeded['aquatic_m100'] = seedClassification(
        $db, '99999-60-0', 'Hazardous to the Aquatic Environment (Acute)', 'Category 1',
        ['H400'], ['P273'], ['GHS09'], 'Warning',
        null, null,
        ['m_factor_acute' => 100.0]
    );
    $result = $engine->classify([
        ['cas_number' => '99999-60-0', 'chemical_name' => 'Aquatic Toxin', 'concentration_pct' => 0.3],
    ]);
    assertContains('aquatic-m100: GHS09 via aquatic summation', picts($result), 'GHS09');
    assertContains('aquatic-m100: H400 via aquatic summation', hCodes($result), 'H400');
    assertContains('aquatic-m100: trace logs aquatic_summation_triggered',
        traceSteps($result), 'aquatic_summation_triggered');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[31] Phase 4 — Same M=100 substance at 0.1 % (weighted 10) does NOT trigger.\n";
    $result = $engine->classify([
        ['cas_number' => '99999-60-0', 'chemical_name' => 'Aquatic Toxin', 'concentration_pct' => 0.1],
    ]);
    assertNotContains('aquatic-below: no GHS09', picts($result), 'GHS09');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[32] Phase 4 — Chronic Cat 2 cross-category: 10 × M × Cat1 alone ≥ 25 %.\n";
    // Single Aquatic Chronic Cat 1 CAS with M=10 at 0.3 %:
    //   - per-component:  0.3 % < 1 % default → no direct trigger
    //   - simple Cat 1 summation:  M × C = 10 × 0.3 = 3 < 25 → no
    //   - cross-category Cat 2:    10 × M × C + 0 = 10 × 3 = 30 ≥ 25 → FIRES
    // Leaves the Cat 2 bucket empty so the cross-category path is
    // unambiguously the trigger — avoids the per-component hazard the
    // previous seeded Cat 2 contributor at 5 % was introducing.
    $seeded['aquatic_chr1'] = seedClassification(
        $db, '99999-61-1', 'Hazardous to the Aquatic Environment (Chronic)', 'Category 1',
        ['H410'], ['P273'], ['GHS09'], 'Warning',
        null, null,
        ['m_factor_chronic' => 10.0]
    );
    $result = $engine->classify([
        ['cas_number' => '99999-61-1', 'chemical_name' => 'Chronic Cat 1', 'concentration_pct' => 0.3],
    ]);
    assertContains('aquatic-chr2: trace logs aquatic_summation_triggered',
        traceSteps($result), 'aquatic_summation_triggered');
    // GHSHazardData 'Hazardous to the Aquatic Environment (Chronic) - Category 2'
    // carries H411 and GHS09.
    assertContains('aquatic-chr2: H411 from Chronic Cat 2', hCodes($result), 'H411');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[33] Phase 4 — NULL M-factor defaults to 1.0 per GHS Annex I 4.1.3.4.\n";
    // Two Cat 1 Acute contributors at 15 % each, no explicit M-factors.
    // Default M=1 → weighted sum 15 + 15 = 30 ≥ 25 → triggers.
    $seeded['aquatic_defm_a'] = seedClassification(
        $db, '99999-63-3', 'Hazardous to the Aquatic Environment (Acute)', 'Category 1',
        ['H400'], ['P273'], ['GHS09'], 'Warning'
    );
    $seeded['aquatic_defm_b'] = seedClassification(
        $db, '99999-64-4', 'Hazardous to the Aquatic Environment (Acute)', 'Category 1',
        ['H400'], ['P273'], ['GHS09'], 'Warning'
    );
    $result = $engine->classify([
        ['cas_number' => '99999-63-3', 'chemical_name' => 'Defm A', 'concentration_pct' => 15.0],
        ['cas_number' => '99999-64-4', 'chemical_name' => 'Defm B', 'concentration_pct' => 15.0],
    ]);
    assertContains('aquatic-defm: GHS09 via default-M summation', picts($result), 'GHS09');

    // ──────────────────────────────────────────────────────────────────
    // Phase 5: finished-good hazard override
    // ──────────────────────────────────────────────────────────────────

    echo "\n[34] Phase 5 — Additive override adds physical-hazard class on top of computed.\n";
    // Start with a simple Cat 1A carc at 0.5 % → Cat 1 carc + GHS08 + H350
    // from per-component. Then additive override stamps Flammable Liquids Cat 3
    // (H226, GHS02). Both should appear.
    $override = [
        'mode' => 'additive',
        'hazards' => [
            'hazard_classes' => [
                ['class' => 'Flammable Liquids', 'category' => 'Category 3'],
            ],
            'h_statements' => 'H226',
            'p_statements' => 'P210',
            'pictograms'   => 'GHS02',
            'signal_word'  => 'Warning',
        ],
        'rationale' => 'Lab flash point 55 °C',
        'set_by'    => 1,
        'set_at'    => '2026-04-20 12:00:00',
    ];
    $result = $engine->classify(
        [['cas_number' => TEST_CAS['carc_1a'], 'chemical_name' => 'Carc', 'concentration_pct' => 0.5]],
        $override
    );
    assertContains('fg-additive: H350 from per-component', hCodes($result), 'H350');
    assertContains('fg-additive: H226 from override',      hCodes($result), 'H226');
    assertContains('fg-additive: GHS08 from per-component', picts($result), 'GHS08');
    assertContains('fg-additive: GHS02 from override',     picts($result), 'GHS02');
    assertEquals('fg-additive: signal_word stays Danger (per-comp higher than override Warning)',
        'Danger', $result['signal_word']);
    assertContains('fg-additive: trace logs fg_override_applied',
        traceSteps($result), 'fg_override_applied');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[35] Phase 5 — Replace override discards computed classification entirely.\n";
    $override = [
        'mode' => 'replace',
        'hazards' => [
            'hazard_classes' => [
                ['class' => 'Flammable Liquids', 'category' => 'Category 3'],
            ],
            'h_statements' => 'H226',
            'pictograms'   => 'GHS02',
            'signal_word'  => 'Warning',
        ],
        'rationale' => 'Product lab-tested non-carcinogenic per 2025 certification; only flammable.',
        'set_by'    => 1, 'set_at' => '2026-04-20 12:00:00',
    ];
    $result = $engine->classify(
        [['cas_number' => TEST_CAS['carc_1a'], 'chemical_name' => 'Carc', 'concentration_pct' => 0.5]],
        $override
    );
    // Per-component H350 / GHS08 DISCARDED by replace mode
    assertNotContains('fg-replace: H350 absent (replaced)', hCodes($result), 'H350');
    assertNotContains('fg-replace: GHS08 absent (replaced)', picts($result), 'GHS08');
    // Override content present
    assertContains('fg-replace: H226 from override', hCodes($result), 'H226');
    assertContains('fg-replace: GHS02 from override', picts($result), 'GHS02');
    assertEquals('fg-replace: signal_word Warning from override', 'Warning', $result['signal_word']);

    // ──────────────────────────────────────────────────────────────────
    echo "\n[36] Phase 5 — Mode 'none' or null override leaves classification unchanged.\n";
    $result = $engine->classify(
        [['cas_number' => TEST_CAS['carc_1a'], 'chemical_name' => 'Carc', 'concentration_pct' => 0.5]],
        ['mode' => 'none', 'hazards' => []]
    );
    assertContains('fg-none: H350 preserved', hCodes($result), 'H350');
    assertNotContains('fg-none: no override trace',
        traceSteps($result), 'fg_override_applied');

    // Null override (equivalent to mode=none, different code path)
    $resultNull = $engine->classify(
        [['cas_number' => TEST_CAS['carc_1a'], 'chemical_name' => 'Carc', 'concentration_pct' => 0.5]],
        null
    );
    assertContains('fg-null: H350 preserved with null override', hCodes($resultNull), 'H350');

    // ──────────────────────────────────────────────────────────────────
    // v1.6 correction: aquatic never fires per-component (summation only)
    // ──────────────────────────────────────────────────────────────────

    echo "\n[37] v1.6 — Aquatic Cat 2 at 5 %% alone does NOT trigger (summation-only).\n";
    // Previously: Cat 2 at 5 %% would clear the 1 %% default cutoff and
    // fire per-component — wrong per GHS Annex I 4.1.3. Now: per-component
    // trigger is skipped for aquatic, summation sum = 5 %% < 25 %%, no
    // classification.
    $seeded['aquatic_v16'] = seedClassification(
        $db, '99999-70-0', 'Hazardous to the Aquatic Environment (Chronic)', 'Category 2',
        ['H411'], ['P273'], ['GHS09'], null
    );
    $result = $engine->classify([
        ['cas_number' => '99999-70-0', 'chemical_name' => 'Chronic Cat 2', 'concentration_pct' => 5.0],
    ]);
    assertNotContains('v16-aquatic: H411 absent (summation only)', hCodes($result), 'H411');
    assertNotContains('v16-aquatic: GHS09 absent (summation only)', picts($result), 'GHS09');
    assertContains('v16-aquatic: trace logs aquatic_per_component_skipped',
        traceSteps($result), 'aquatic_per_component_skipped');

    // ──────────────────────────────────────────────────────────────────
    echo "\n[38] engine version stamp is v1.6.1-cpd-code-filter.\n";
    assertEquals('ENGINE_VERSION is v1.6.1-cpd-code-filter',
        'v1.6.1-cpd-code-filter', \SDS\Services\HazardEngine::ENGINE_VERSION);

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
