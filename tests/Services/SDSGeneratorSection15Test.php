<?php
/**
 * DB-free checks for audit item #42 — Section 15 regulatory detail as bands
 * and snapshot slimming:
 *
 *   - bandRegulatoryEntries(): SARA 313 / HAP entries carry the Section 3
 *     prescribed-range band for their CAS and no exact percentage.
 *   - buildProp65ListedLines(): name (+ CAS) and OEHHA listing type(s) in a
 *     fixed order, duplicates merged, unknown types skipped, no NSRL / MADL /
 *     listing date.
 *   - section15(): the Section 15 copies are banded; the HAP total stays
 *     exact; the Prop 65 warning text is kept verbatim; an empty composition
 *     keeps analyseSnur() DB-free.
 *   - section5() / section16(): no numeric flash_point_c, no voc_assumptions.
 *   - The new translation keys exist in all four languages.
 *
 * Same Reflection bootstrap as SDSGeneratorSections2_11_12Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection15Test.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

// Bootstrap App static properties without a DB connection
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);

$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, ['paths' => []]);

$failures = 0;
$checks   = 0;

function check(bool $ok, string $label, $actual = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok   {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) {
        echo '       actual: ' . var_export($actual, true) . "\n";
    }
}

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);

$method = static function (string $name) use ($gen): ReflectionMethod {
    $m = new ReflectionMethod($gen, $name);
    $m->setAccessible(true);
    return $m;
};

$hz0 = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'exposure_limits' => []];

// section15() calls ghsSectionNote(), which reads settings via Database::getInstance() unless its static cache is pre-set.
$ghsNoteProp = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'showGhsSectionNote');
$ghsNoteProp->setAccessible(true);
$ghsNoteProp->setValue(null, false);

// ---------------------------------------------------------------------
echo "a. bandRegulatoryEntries (#42)\n";
$band = $method('bandRegulatoryEntries');
$compByCas = ['108-88-3' => ['cas_number' => '108-88-3', 'concentration_pct' => 4.5, 'concentration_min' => 4.0, 'concentration_max' => 6.0]];
$rows = $band->invoke($gen, [
    ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'concentration_pct' => 4.5, 'threshold_pct' => 1.0],
    ['cas_number' => '1330-20-7', 'chemical_name' => 'Xylenes', 'concentration_pct' => 12.0, 'threshold_pct' => 1.0],
    ['cas_number' => '', 'chemical_name' => 'Glycol ethers (manual)', 'concentration_pct' => 0.3],
], $compByCas);
check($rows[0]['concentration_range'] === '3 - 7%' && !array_key_exists('concentration_pct', $rows[0]), 'supplier min/max from composition drives the band, exact % removed', $rows[0]);
check($rows[1]['concentration_range'] === '10 - 30%', 'CAS absent from compByCas bands from its own pct', $rows[1]);
check($rows[2]['concentration_range'] === '0.1 - 1%', 'manual no-CAS row banded from its own pct', $rows[2]);
check($rows[0]['threshold_pct'] === 1.0, 'threshold_pct carried through');

// ---------------------------------------------------------------------
echo "b. buildProp65ListedLines (#42)\n";
$p65 = $method('buildProp65ListedLines');
$lines = $p65->invoke($gen, ['listed_chemicals' => [
    ['cas_number' => '7439-92-1', 'chemical_name' => 'Lead', 'concentration_pct' => 0.2, 'toxicity_type' => ['male reproductive', 'cancer', 'developmental', 'female reproductive'], 'nsrl_ug' => 15, 'madl_ug' => 0.5, 'date_listed' => '1987-10-01'],
    ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'concentration_pct' => 4.5, 'toxicity_type' => ['developmental']],
    ['cas_number' => '108-88-3', 'chemical_name' => 'toluene', 'concentration_pct' => 1.0, 'toxicity_type' => 'developmental'],
    ['cas_number' => '', 'chemical_name' => 'Benzophenone', 'concentration_pct' => 2.0, 'toxicity_type' => ['cancer'], 'source' => 'manual'],
    ['cas_number' => '0-0-0', 'chemical_name' => 'Mystery', 'toxicity_type' => ['bogus']],
]]);
check($lines === [
    'Lead (CAS 7439-92-1) — cancer, developmental toxicity, female reproductive toxicity, male reproductive toxicity',
    'Toluene (CAS 108-88-3) — developmental toxicity',
    'Benzophenone — cancer',
], 'listing lines: fixed type order, duplicates merged, no-CAS form, unknown type skipped', $lines);
check(!preg_match('/15|0\.5|1987/', implode("\n", $lines)), 'no NSRL / MADL / date on any line');
check($p65->invoke($gen, ['listed_chemicals' => []]) === [], 'empty listing');

// ---------------------------------------------------------------------
echo "c. section15 copies (#42)\n";
$s15 = $method('section15')->invoke($gen,
    $hz0,
    ['reportable' => [['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'concentration_pct' => 4.5, 'threshold_pct' => 1.0, 'is_pbt' => false, 'sara_name' => 'Toluene', 'status' => 'reportable']],
     'below_threshold' => [['cas_number' => '7439-97-6', 'chemical_name' => 'Mercury', 'concentration_pct' => 0.05, 'threshold_pct' => 0.1, 'is_pbt' => true, 'sara_name' => 'Mercury', 'status' => 'below_threshold']]],
    ['requires_warning' => true, 'warning_text' => 'WARNING: ...', 'cancer_chemicals' => [], 'repro_chemicals' => ['Toluene'],
     'listed_chemicals' => [['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'concentration_pct' => 4.5, 'toxicity_type' => ['developmental']]]],
    ['has_haps' => true, 'total_hap_pct' => 4.5, 'hap_chemicals' => [['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'hap_name' => 'Toluene', 'concentration_pct' => 4.5]]],
    ['composition' => []],   // empty composition keeps analyseSnur() DB-free
    []);
check($s15['sara_313']['reportable'][0]['concentration_range'] === '1 - 5%' && !isset($s15['sara_313']['reportable'][0]['concentration_pct']), 'S15 SARA row carries the band, no exact %', $s15['sara_313']);
check(array_keys($s15['sara_313']) === ['reportable'], 'S15 SARA copy holds only reportable (below_threshold is consumed by section12() at generation time and not persisted)', array_keys($s15['sara_313']));
check($s15['hap']['hap_chemicals'][0]['concentration_range'] === '1 - 5%' && !isset($s15['hap']['hap_chemicals'][0]['concentration_pct']), 'S15 HAP row carries the band, no exact %', $s15['hap']);
check($s15['hap']['total_hap_pct'] === 4.5, 'S15 HAP total kept exact');
check($s15['prop65']['listed_lines'] === ['Toluene (CAS 108-88-3) — developmental toxicity'] && !array_key_exists('listed_chemicals', $s15['prop65']), 'S15 Prop 65 lines built, raw listing dropped from the section copy', $s15['prop65']);
check($s15['prop65']['warning_text'] === 'WARNING: ...', 'warning text kept verbatim');
check($s15['snur'] === ['has_snur' => false, 'listed_chemicals' => []], 'empty composition -> no SNUR, no DB');
check(substr_count(json_encode($s15), '4.5') === 1, 'exact 4.5 appears in Section 15 only once (the HAP total)', json_encode($s15));

$ghsNoteProp->setValue(null, null);

// ---------------------------------------------------------------------
echo "d. Section 5 / 16 payload slimming (#42)\n";
// section5() reads the recursive formula_props flash point (audit #11); 20 °C with H225 drives the low-flash fragment.
$calc = [
    'formula'       => ['lines' => [['flash_point_c' => 20.0]], 'version' => 7],
    'formula_props' => ['flash_point_c' => 20.0, 'flash_point_greater_than' => false, 'enriched_lines' => []],
    'voc'           => ['assumptions' => [['rm_code' => 'X', 'message' => 'SG assumed 1.0']]],
    'composition'   => [], 'warnings' => [],
];
$hzFlam = $hz0;
$hzFlam['h_statements'] = [['code' => 'H225', 'text' => '']];
$s5 = $method('section5')->invoke($gen, $calc, $hzFlam, []);
check(!array_key_exists('flash_point_c', $s5), 'S5 numeric flash point not carried', array_keys($s5));
check(str_contains((string) $s5['specific_hazards'], '20 °C'), 'S5 flash point still embedded as text in specific_hazards', $s5['specific_hazards']);
$s16 = $method('section16')->invoke($gen, $calc, []);
check(!array_key_exists('voc_assumptions', $s16), 'S16 voc_assumptions not carried', array_keys($s16));
check(!str_contains(json_encode($s16), 'SG assumed'), 'no assumption text anywhere in S16');
check(!str_contains(json_encode($s16), 'formula_version') && !str_contains(json_encode($s16), '"7"'), 'no formula version in S16');

// ---------------------------------------------------------------------
echo "e. Translation keys present in all four languages\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach (['prop65_listed_line', 'prop65_listed_line_no_cas', 'prop65_type_cancer', 'prop65_type_developmental', 'prop65_type_female_reproductive', 'prop65_type_male_reproductive', 'prop65_type_reproductive'] as $k) {
        $v = $trFile['section15'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section15.{$k}", $v);
    }
    foreach (['prop65_listed', 'sara_313_range_note'] as $k) {
        $v = $trFile['labels'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} labels.{$k}", $v);
    }
    check(str_contains((string) ($trFile['section15']['prop65_listed_line'] ?? ''), ':types'), "{$lang} prop65_listed_line has :types");
}

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
