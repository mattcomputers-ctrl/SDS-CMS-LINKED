#!/usr/bin/env php
<?php
/**
 * Inhalation-only CAS (carbon black / TiO2) + replace override test
 * (audit #18, #41(2), #67) — DB-free.
 *
 * HazardEngine::classify() runs against an in-memory Database stand-in
 * (anonymous subclass swapped into Database::$instance by reflection)
 * serving hazard_classifications / exposure_limits rows in the real column
 * shape, so classification, consolidation, signal word and P-codes are the
 * engine's own output. SDSGenerator::$inhalationOnlyCas is preset so no
 * settings query runs.
 *
 * Run: php tests/Services/InhalationOnlyCarcinogenTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath'); $bp->setAccessible(true); $bp->setValue(null, $basePath);
$cfg = $ref->getProperty('config');  $cfg->setAccessible(true); $cfg->setValue(null, ['paths' => []]);

use SDS\Core\Database;
use SDS\Services\CarcinogenService;
use SDS\Services\GHSHazardClass;
use SDS\Services\HazardEngine;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

$failures = 0; $checks = 0;
function check(bool $ok, string $label, $actual = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) { echo "  ok   {$label}\n"; return; }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) { echo '       actual: ' . var_export($actual, true) . "\n"; }
}

// ── In-memory DB stand-in ─────────────────────────────────────────────
$fakeDb = new class extends Database {
    public array $hazardRows = [];
    public array $limitRows  = [];
    public function __construct() {}
    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = str_contains($sql, 'FROM hazard_classifications') ? $this->hazardRows
              : (str_contains($sql, 'FROM exposure_limits') ? $this->limitRows : []);
        return array_values(array_filter($rows, fn($r) => in_array($r['cas_number'], $params, true)));
    }
    public function fetch(string $sql, array $params = []): ?array { return null; } // no CPDs
};
$dbProp = new ReflectionProperty(Database::class, 'instance'); $dbProp->setAccessible(true);
$dbSaved = $dbProp->getValue();
$dbProp->setValue(null, $fakeDb);

$inh = new ReflectionProperty(SDSGenerator::class, 'inhalationOnlyCas'); $inh->setAccessible(true);
$inhSaved = $inh->getValue();
$inh->setValue(null, ['1333-86-4' => 'Carbon Black', '13463-67-7' => 'Titanium Dioxide']);

$CB = '1333-86-4'; $TIO2 = '13463-67-7'; $CARC2 = '100-41-4';
$P_CARC = ['P201', 'P202', 'P280', 'P308+P313', 'P405', 'P501'];
$carcRow = fn(string $cas) => [
    'cas_number' => $cas, 'class_name' => 'Carcinogenicity', 'category' => 'Category 2',
    'class_name_canonical' => GHSHazardClass::CARCINOGENICITY, 'category_canonical' => 'Cat 2',
    'signal_word' => 'Warning', 'h_statements_json' => '["H351"]',
    'p_statements_json' => json_encode($P_CARC), 'pictograms_json' => '["GHS08"]',
];
$fakeDb->limitRows = [['cas_number' => $CB, 'limit_type' => 'OSHA PEL-TWA', 'value' => '3.5', 'units' => 'mg/m3', 'notes' => null]];

$gen = new SDSGenerator();
$pm  = function (string $name) use ($gen): ReflectionMethod { $m = new ReflectionMethod($gen, $name); $m->setAccessible(true); return $m; };
$exclude = fn(array $calc) => $pm('inhalationOnlyCasToExclude')->invoke(null, $calc);
$post = function (array &$hz, array $calc, array $carc, ?array $ov) use ($pm, $gen): void {
    $pm('applyPostClassifyCarcinogenSteps')->invokeArgs($gen, [&$hz, $calc, $carc, $ov]);
};
$codes = fn(array $hz, string $k) => array_column($hz[$k] ?? [], 'code');
$line  = fn(string $cas, string $state) => ['physical_state' => $state, 'constituents' => [['cas_number' => $cas]]];
$noCarc = ['findings' => [], 'has_carcinogens' => false];

echo "#18 Case A: carbon-black-only water-based ink\n";
$compA = [
    ['cas_number' => '7732-18-5', 'chemical_name' => 'Water',        'concentration_pct' => 70.0],
    ['cas_number' => $CB,         'chemical_name' => 'Carbon black', 'concentration_pct' => 20.0],
    ['cas_number' => '9003-01-4', 'chemical_name' => 'Acrylic resin','concentration_pct' => 10.0],
];
$calcA = ['composition' => $compA, 'formula_props' => ['enriched_lines' => [
    $line('7732-18-5', 'Liquid'), $line($CB, 'Powder'), $line('9003-01-4', 'Liquid'),
]]];
$fakeDb->hazardRows = [$carcRow($CB)];
$hzA0 = (new HazardEngine())->classify($compA, null);
check(in_array('H351', $codes($hzA0, 'h_statements'), true) && $hzA0['signal_word'] === 'Warning', 'control: without the exclusion the fixture classifies carbon black (H351, Warning)', $hzA0['signal_word']);
check($exclude($calcA) === [$CB => 'Carbon Black'], 'bound product -> carbon black excluded', $exclude($calcA));
$hzA = (new HazardEngine())->excludeInhalationOnlyCas($exclude($calcA))->classify($compA, null);
$post($hzA, $calcA, $noCarc, null);
check(empty($hzA['hazard_classes']) && empty($hzA['h_statements']) && empty($hzA['pictograms']), 'no class, H-code or pictogram', $hzA['hazard_classes']);
check($hzA['signal_word'] === null, 'signal word re-derived: none (was Warning)', $hzA['signal_word']);
check(empty($hzA['p_statements']), 'P-codes re-derived: none (were P201/P202/P280/P308+P313/P405/P501)', $codes($hzA, 'p_statements'));
check(empty($hzA['exposure_limits']) && !in_array($CB, $hzA['hazardous_cas'], true), 'no carbon black exposure limit, not hazardous_cas', $hzA['exposure_limits']);
check(in_array('inhalation_only_excluded', array_column($hzA['trace'], 'step'), true), 'trace records the exclusion');
check($pm('isClassified')->invoke(null, $hzA) === false, 'isClassified false');
$s2A = $pm('section2')->invoke($gen, $hzA, []);
check($s2A['is_classified'] === false && $s2A['signal_word'] === null && $s2A['p_statements'] === [], 'Section 2 not classified, no signal word, no P-codes', $s2A['signal_word']);

echo "\n#18 Case B: carbon black listed first + a genuine Carc. 2 ingredient\n";
$compB = [
    ['cas_number' => $CB,         'chemical_name' => 'Carbon black', 'concentration_pct' => 5.0],
    ['cas_number' => $CARC2,      'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 2.0],
    ['cas_number' => '7732-18-5', 'chemical_name' => 'Water',        'concentration_pct' => 93.0],
];
$calcB = ['composition' => $compB, 'formula_props' => ['enriched_lines' => [
    $line($CB, 'Powder'), $line($CARC2, 'Liquid'), $line('7732-18-5', 'Liquid'),
]]];
$fakeDb->hazardRows = [$carcRow($CB), $carcRow($CARC2)];
$hzB = (new HazardEngine())->excludeInhalationOnlyCas($exclude($calcB))->classify($compB, null);
$post($hzB, $calcB, $noCarc, null);
$carcB = array_values(array_filter($hzB['hazard_classes'], fn($hc) => ($hc['canonical'] ?? '') === GHSHazardClass::CARCINOGENICITY));
check(count($carcB) === 1 && $carcB[0]['cas'] === $CARC2 && ($carcB[0]['h_codes'] ?? []) === ['H351'], 'the other ingredient keeps Carcinogenicity Cat 2 (not deleted by consolidation order)', $carcB);
check(in_array('H351', $codes($hzB, 'h_statements'), true) && in_array('GHS08', $hzB['pictograms'], true) && $hzB['signal_word'] === 'Warning', 'H351 + GHS08 + Warning kept');
check(array_values(array_intersect($P_CARC, $codes($hzB, 'p_statements'))) === $P_CARC, 'Carc. 2 P-codes kept', $codes($hzB, 'p_statements'));
check(!in_array($CB, $hzB['hazardous_cas'], true) && in_array($CARC2, $hzB['hazardous_cas'], true), 'hazardous_cas: ethylbenzene only', $hzB['hazardous_cas']);

echo "\n#18 Case C: all-powder product (dry particulate)\n";
$compC = [
    ['cas_number' => $CB,         'chemical_name' => 'Carbon black',      'concentration_pct' => 10.0],
    ['cas_number' => '471-34-1',  'chemical_name' => 'Calcium carbonate', 'concentration_pct' => 90.0],
];
$calcC = ['composition' => $compC, 'formula_props' => ['enriched_lines' => [$line($CB, 'Powder'), $line('471-34-1', 'Solid')]]];
$fakeDb->hazardRows = [];
check($exclude($calcC) === [], 'all-powder -> nothing excluded');
$hzC = (new HazardEngine())->excludeInhalationOnlyCas($exclude($calcC))->classify($compC, null);
$post($hzC, $calcC, $noCarc, null);
$cC = $hzC['hazard_classes'][0] ?? [];
check(($cC['canonical'] ?? null) === GHSHazardClass::CARCINOGENICITY && ($cC['category_canonical'] ?? null) === 'Cat 2' && ($cC['cas'] ?? null) === $CB && ($cC['h_codes'] ?? null) === ['H351'] && (float) ($cC['concentration_pct'] ?? 0) === 10.0, 'powder rule adds Carc. 2 in the real engine shape', $cC);
check($hzC['signal_word'] === 'Warning' && in_array('GHS08', $hzC['pictograms'], true) && $codes($hzC, 'p_statements') === $P_CARC, 'Warning + GHS08 + class-default P-codes');
check(in_array($CB, $hzC['hazardous_cas'], true), 'carbon black in hazardous_cas (Section 3 lists it)');
$compC2 = [['cas_number' => $CB, 'chemical_name' => 'Carbon black', 'concentration_pct' => 0.05], ['cas_number' => '471-34-1', 'chemical_name' => 'Calcium carbonate', 'concentration_pct' => 99.95]];
$calcC2 = ['composition' => $compC2, 'formula_props' => $calcC['formula_props']];
$hzC2 = (new HazardEngine())->classify($compC2, null);
$post($hzC2, $calcC2, $noCarc, null);
check(empty($hzC2['hazard_classes']) && $hzC2['signal_word'] === null, 'below the 0.1 % cut-off: no H351', $hzC2['hazard_classes']);

echo "\n#41(2) Case D: bound carbon black (powder RM) + TiO2 (liquid dispersion)\n";
$compD = [
    ['cas_number' => '7732-18-5', 'chemical_name' => 'Water',            'concentration_pct' => 80.0],
    ['cas_number' => $CB,         'chemical_name' => 'Carbon black',     'concentration_pct' => 15.0],
    ['cas_number' => $TIO2,       'chemical_name' => 'Titanium dioxide', 'concentration_pct' => 5.0],
];
$calcD = ['composition' => $compD, 'formula_props' => ['enriched_lines' => [
    $line('7732-18-5', 'Liquid'), $line($CB, 'Powder'),
    ['physical_state' => 'Liquid', 'constituents' => [['cas_number' => $TIO2], ['cas_number' => '7732-18-5']]],
]]];
$fakeDb->hazardRows = [$carcRow($CB)];
$hzD = (new HazardEngine())->excludeInhalationOnlyCas($exclude($calcD))->classify($compD, null);
$carcD = ['findings' => [
    ['cas_number' => $CB,   'chemical_name' => 'Carbon black',     'concentration_pct' => 15.0, 'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => '']]],
    ['cas_number' => $TIO2, 'chemical_name' => 'Titanium dioxide', 'concentration_pct' => 5.0,  'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => '']]],
]];
CarcinogenService::resummarise($carcD);
$p65D = [
    'listed_chemicals' => [['cas_number' => $CB], ['cas_number' => $TIO2]],
    'cancer_chemicals' => ['Carbon black (airborne, unbound particles of respirable size)', 'Titanium dioxide (airborne, unbound particles of respirable size)'],
    'repro_chemicals'  => [], 'requires_warning' => true, 'warning_text' => 'x',
];
$pm('applySolidPowderLiquidFiltering')->invokeArgs($gen, [&$carcD, &$hzD, &$p65D, $calcD]);
check(count($carcD['findings']) === 2 && !empty($carcD['findings'][0]['inhalable_dust_only']) && !empty($carcD['findings'][1]['inhalable_dust_only']) && $carcD['has_carcinogens'] === true, 'both findings kept and flagged inhalable_dust_only', $carcD['findings']);
check($p65D['requires_warning'] === false && $p65D['cancer_chemicals'] === [], 'Prop 65 still suppressed', $p65D['cancer_chemicals']);
$post($hzD, $calcD, $carcD, null);
check(empty($hzD['hazard_classes']) && $hzD['signal_word'] === null && empty($hzD['h_statements']), 'flagged listings never classify the mixture (TiO2 has no hard-coded skip)', $codes($hzD, 'h_statements'));
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $t = new TranslationService($lang);
    $g = new SDSGenerator($t);
    $m11 = new ReflectionMethod($g, 'section11'); $m11->setAccessible(true);
    $s11 = $m11->invoke($g, $hzD, $compD, $carcD, []);
    $note = $t->get('section11.carcinogenicity_inhalable_dust_note');
    check($note !== '' && $note !== 'section11.carcinogenicity_inhalable_dust_note', "{$lang}: note key translated", $note);
    check(substr_count($s11['carcinogenicity'], $note) === 2 && !str_contains($s11['carcinogenicity'], $t->get('section11.carcinogenicity', ['threshold' => '0.1'])), "{$lang}: S11 lists both with the note, no negative sentence", $s11['carcinogenicity']);
    $cbTox = array_values(array_filter($s11['component_toxicology'], fn($e) => $e['cas_number'] === $CB))[0] ?? [];
    check(($cbTox['carcinogen_note'] ?? null) === $note, "{$lang}: component block carries the same note", $cbTox);
}

echo "\n#67 Case E: replace override on an all-powder product with carbon black\n";
$compE = [
    ['cas_number' => $CB,        'chemical_name' => 'Carbon black',      'concentration_pct' => 10.0],
    ['cas_number' => '50-00-0',  'chemical_name' => 'Formaldehyde',      'concentration_pct' => 0.5],
    ['cas_number' => '471-34-1', 'chemical_name' => 'Calcium carbonate', 'concentration_pct' => 89.5],
];
$calcE = ['composition' => $compE, 'formula_props' => ['enriched_lines' => [$line($CB, 'Powder'), $line('50-00-0', 'Powder'), $line('471-34-1', 'Powder')]]];
$fakeDb->hazardRows = [];
$carcE = ['findings' => [['cas_number' => '50-00-0', 'chemical_name' => 'Formaldehyde', 'concentration_pct' => 0.5, 'agencies' => [['agency' => 'IARC', 'classification' => 'Group 1', 'description' => '']]]]];
CarcinogenService::resummarise($carcE);
$replace = ['mode' => 'replace', 'hazards' => [
    'hazard_classes' => [['class' => 'Eye Irritation', 'category' => 'Category 2A']],
    'h_statements' => ['H319'], 'pictograms' => ['GHS07'], 'signal_word' => 'Warning',
]];
$hzE = (new HazardEngine())->excludeInhalationOnlyCas($exclude($calcE))->classify($compE, $replace);
$beforeE = $hzE;
$post($hzE, $calcE, $carcE, $replace);
check($hzE === $beforeE, 'replace mode: nothing added back (no H350/H351, GHS08, Danger, P-codes)', array_diff($codes($hzE, 'h_statements'), $codes($beforeE, 'h_statements')));
check($codes($hzE, 'h_statements') === ['H319'] && $hzE['pictograms'] === ['GHS07'] && $hzE['signal_word'] === 'Warning', 'only the override content remains');
$additive = ['mode' => 'additive'] + $replace;
$hzE2 = (new HazardEngine())->classify($compE, $additive);
$post($hzE2, $calcE, $carcE, $additive);
check(in_array('H351', $codes($hzE2, 'h_statements'), true) && in_array('H350', $codes($hzE2, 'h_statements'), true) && $hzE2['signal_word'] === 'Danger', 'control: additive mode still runs both steps', $codes($hzE2, 'h_statements'));

$inh->setValue(null, $inhSaved);
$dbProp->setValue(null, $dbSaved);
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
