#!/usr/bin/env php
<?php
/**
 * Carbon Black Prop 65 Golden Test
 *
 * Locks in the interaction between two independent rules that both
 * touch Carbon Black (CAS 1333-86-4) in Section 15 of the generated
 * SDS:
 *
 *   1. The new Prop 65 auto-trace logic in Prop65Service::analyse.
 *      A CAS-matched chemical whose composition concentration is
 *      below the admin-configured threshold gets the "(trace)"
 *      suffix appended to its name in the warning text.
 *
 *   2. The pre-existing Carbon Black "only-dangerous-as-powder" rule
 *      in SDSGenerator::removeCarbonBlackFromResults. When any RM in
 *      the formula is a non-solid-non-powder state (liquid, paste,
 *      gel, gas), Carbon Black is scrubbed from Prop 65 and Section
 *      11 carcinogen findings — regardless of which RM carries it.
 *
 * The filter in (2) matches Carbon Black by name via stripos(). The
 * new "(trace)" suffix from (1) must not break that match. This
 * suite asserts both paths:
 *
 *   Case 1 — Carbon Black at 5 %            → flagged, NOT trace
 *   Case 2 — Carbon Black at 0.05 %         → flagged, trace suffix applied
 *   Case 3 — Carbon Black at 0.05 % + liquid RM
 *                                           → suppressed even with trace suffix
 *   Case 4 — Carbon Black at 5 % + all solids
 *                                           → NOT suppressed (dry particulate hazard)
 *
 * Run:
 *   php tests/Services/CarbonBlackProp65Test.php
 *
 * Exit code:
 *   0 = all passed
 *   1 = any failure
 *
 * No DB fixtures are seeded or torn down — the test uses only the
 * already-present prop65_list entry for 1333-86-4 (seed data). If
 * that entry is missing from this environment the suite exits 0 with
 * a skip notice.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\Prop65Service;
use SDS\Services\SDSGenerator;

$passed = 0;
$failed = 0;

function pass(string $msg): void {
    global $passed;
    $passed++;
    echo "  PASS  {$msg}\n";
}

function fail(string $msg, string $detail = ''): void {
    global $failed;
    $failed++;
    echo "  FAIL  {$msg}\n";
    if ($detail !== '') {
        echo "        {$detail}\n";
    }
}

echo "=== Carbon Black Prop 65 Suppression Test ===\n\n";

// ── Precondition: 1333-86-4 must be seeded in prop65_list ───────────
$db = Database::getInstance();
$cbSeed = $db->fetch(
    "SELECT chemical_name, toxicity_type FROM prop65_list WHERE cas_number = ?",
    ['1333-86-4']
);
if ($cbSeed === null) {
    echo "  SKIP  prop65_list has no row for CAS 1333-86-4 — seed data missing.\n";
    echo "        Run scripts/load-seed-data.php before this test, or check the Prop 65 seed file.\n";
    exit(0);
}
echo "  Precondition: prop65_list[1333-86-4] = " . $cbSeed['chemical_name']
    . " (" . $cbSeed['toxicity_type'] . ")\n\n";

// Shared: invoke the private applySolidPowderLiquidFiltering via
// reflection so the test covers the CB name-match even with the
// "(trace)" suffix, and the "liquid mix present → CB removed" rule.
function invokeSolidPowderFilter(array &$carcinogen, array &$hazard, array &$prop65, array $calcResult): void
{
    $gen = new SDSGenerator();
    $refl = new \ReflectionClass($gen);
    $m = $refl->getMethod('applySolidPowderLiquidFiltering');
    $m->setAccessible(true);
    $m->invokeArgs($gen, [&$carcinogen, &$hazard, &$prop65, $calcResult]);
}

// ── Case 1: Carbon Black at 5 % — above threshold, not trace ────────
echo "Case 1: Carbon Black at 5 % (above 0.1 % threshold)\n";
$r1 = Prop65Service::analyse([
    ['cas_number' => '1333-86-4', 'chemical_name' => 'Carbon Black', 'concentration_pct' => 5.0],
]);
$listed1 = array_column($r1['listed_chemicals'] ?? [], 'cas_number');
in_array('1333-86-4', $listed1, true)
    ? pass('listed_chemicals includes 1333-86-4')
    : fail('listed_chemicals missing 1333-86-4', 'got: ' . json_encode($listed1));

$cancerNames1 = $r1['cancer_chemicals'] ?? [];
$hasCbNoTrace1 = false;
foreach ($cancerNames1 as $n) {
    if (stripos($n, 'carbon black') !== false && stripos($n, '(trace)') === false) {
        $hasCbNoTrace1 = true;
        break;
    }
}
$hasCbNoTrace1
    ? pass('cancer_chemicals contains "Carbon Black" without "(trace)" at 5 %')
    : fail('Carbon Black at 5 % should not be trace', 'got: ' . json_encode($cancerNames1));

$r1['requires_warning']
    ? pass('requires_warning = true at 5 %')
    : fail('requires_warning should be true at 5 %');

// ── Case 2: Carbon Black at 0.05 % — below threshold, trace ─────────
echo "\nCase 2: Carbon Black at 0.05 % (below 0.1 % threshold)\n";
$r2 = Prop65Service::analyse([
    ['cas_number' => '1333-86-4', 'chemical_name' => 'Carbon Black', 'concentration_pct' => 0.05],
]);

$cancerNames2 = $r2['cancer_chemicals'] ?? [];
$hasCbWithTrace2 = false;
foreach ($cancerNames2 as $n) {
    if (stripos($n, 'carbon black') !== false && stripos($n, '(trace)') !== false) {
        $hasCbWithTrace2 = true;
        break;
    }
}
$hasCbWithTrace2
    ? pass('cancer_chemicals contains "Carbon Black (trace)" at 0.05 %')
    : fail('Carbon Black at 0.05 % should have (trace) suffix', 'got: ' . json_encode($cancerNames2));

// ── Case 3: CB at 0.05 % + liquid — should be suppressed entirely ───
// This is the key regression guard: the name-match filter
// (stripos 'carbon black') must still fire when the name has
// the new "(trace)" suffix appended. If the suffix broke the
// filter, Carbon Black (trace) would leak into Section 15.
echo "\nCase 3: Carbon Black at 0.05 % + a liquid RM — filter must remove it\n";
$prop65For3 = Prop65Service::analyse([
    ['cas_number' => '1333-86-4', 'chemical_name' => 'Carbon Black', 'concentration_pct' => 0.05],
]);
$hazardFor3    = ['h_statements' => [], 'hazard_classes' => [], 'exposure_limits' => []];
$carcinogenFor3 = [
    'findings' => [], 'has_carcinogens' => false,
    'summary_text' => '', 'component_texts' => []
];
$calcResult3 = [
    'composition' => [
        ['cas_number' => '1333-86-4', 'concentration_pct' => 0.05],
    ],
    'formula_props' => [
        'enriched_lines' => [
            ['raw_material_id' => 1, 'internal_code' => 'CB-001', 'physical_state' => 'Powder', 'pct' => 10],
            ['raw_material_id' => 2, 'internal_code' => 'RESIN-01', 'physical_state' => 'Liquid', 'pct' => 90],
        ],
    ],
];

invokeSolidPowderFilter($carcinogenFor3, $hazardFor3, $prop65For3, $calcResult3);

$listed3 = array_column($prop65For3['listed_chemicals'] ?? [], 'cas_number');
!in_array('1333-86-4', $listed3, true)
    ? pass('listed_chemicals no longer includes 1333-86-4 after filter')
    : fail('Carbon Black still in listed_chemicals', 'got: ' . json_encode($listed3));

$cancer3 = $prop65For3['cancer_chemicals'] ?? [];
$stillHasCb3 = false;
foreach ($cancer3 as $n) {
    if (stripos($n, 'carbon black') !== false) {
        $stillHasCb3 = true;
        break;
    }
}
!$stillHasCb3
    ? pass('cancer_chemicals stripped of Carbon Black (trace)')
    : fail('Carbon Black (trace) still in cancer_chemicals', 'got: ' . json_encode($cancer3));

empty($prop65For3['cancer_chemicals']) && empty($prop65For3['repro_chemicals'])
    && !$prop65For3['requires_warning'] && $prop65For3['warning_text'] === ''
    ? pass('requires_warning cleared, warning_text empty')
    : fail('requires_warning/warning_text not cleared',
        'got: requires=' . ($prop65For3['requires_warning'] ? 'true' : 'false')
        . ' warning=' . json_encode($prop65For3['warning_text']));

// ── Case 4: CB at 5 % + all solids — should NOT be suppressed ──────
echo "\nCase 4: Carbon Black at 5 % in an all-solid formula — filter must NOT fire\n";
$prop65For4 = Prop65Service::analyse([
    ['cas_number' => '1333-86-4', 'chemical_name' => 'Carbon Black', 'concentration_pct' => 5.0],
]);
$hazardFor4    = ['h_statements' => [], 'hazard_classes' => [], 'exposure_limits' => []];
$carcinogenFor4 = [
    'findings' => [], 'has_carcinogens' => false,
    'summary_text' => '', 'component_texts' => []
];
$calcResult4 = [
    'composition' => [
        ['cas_number' => '1333-86-4', 'concentration_pct' => 5.0],
    ],
    'formula_props' => [
        'enriched_lines' => [
            ['raw_material_id' => 1, 'internal_code' => 'CB-001', 'physical_state' => 'Powder', 'pct' => 10],
            ['raw_material_id' => 2, 'internal_code' => 'FILLER-1', 'physical_state' => 'Solid',  'pct' => 90],
        ],
    ],
];

invokeSolidPowderFilter($carcinogenFor4, $hazardFor4, $prop65For4, $calcResult4);

$listed4 = array_column($prop65For4['listed_chemicals'] ?? [], 'cas_number');
in_array('1333-86-4', $listed4, true)
    ? pass('listed_chemicals still contains 1333-86-4 (pure solid mix)')
    : fail('Carbon Black was suppressed in pure-solid mix — filter too aggressive',
        'got: ' . json_encode($listed4));

$prop65For4['requires_warning']
    ? pass('requires_warning still true in pure-solid mix')
    : fail('requires_warning should stay true when no liquid present');

// ── Summary ────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "  Passed: {$passed}\n";
echo "  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
