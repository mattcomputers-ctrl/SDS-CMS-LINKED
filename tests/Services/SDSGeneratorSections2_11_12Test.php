<?php
/**
 * DB-free checks for the SDSGenerator Section 2 / 11 / 12 builders changed by
 * the SDS content audit (items #4, #8, #15, #20, #21, #22, #23):
 *
 *   - Section 2: "Other hazards" default + override; unclassified product gets
 *     no PPE; Section 2 PPE equals the Section 8 resolved PPE.
 *   - Section 11: chronic-effects fragments and "none" fallback; the acute
 *     toxicity line is derived per route from the classification; the
 *     carcinogenicity line prints the Section 3 band, never the exact %,
 *     and the negative sentence carries the 0.1 % qualifier.
 *   - Section 12: aquatic H-statements echoed; component table rows carry the
 *     Section 3 band (no concentration_pct); "listed below" lead-in only
 *     when a table follows; trade-secret rows are masked.
 *
 * Same Reflection bootstrap as SDSGeneratorSection4Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSections2_11_12Test.php
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

// ---------------------------------------------------------------------
echo "a. Section 2 (#4 other hazards, #15 PPE)\n";
$s2m = $method('section2');
$s8m = $method('section8');

$s2 = $s2m->invoke($gen, $hz0, []);
check($s2['other_hazards'] === $t->get('section2.other_hazards') && $s2['has_other_hazards'] === false, 'S2 default other hazards', $s2['other_hazards']);
check($s2m->invoke($gen, $hz0, [2 => ['other_hazards' => 'Custom']])['other_hazards'] === 'Custom', 'S2 other hazards override');
check(array_filter($s2['ppe_recommendations']) === [], 'S2 unclassified -> no PPE', $s2['ppe_recommendations']);

$hzI = $hz0;
$hzI['h_statements'] = [['code' => 'H315', 'text' => ''], ['code' => 'H319', 'text' => '']];
$s2i = $s2m->invoke($gen, $hzI, []);
$s8i = $s8m->invoke($gen, $hzI, [], []);
check($s2i['ppe_recommendations']['eye_protection'] === $s8i['eye_protection'] && $s2i['ppe_recommendations']['respiratory'] === null, 'S2/S8 PPE parity', [$s2i['ppe_recommendations'], $s8i]);

// ---------------------------------------------------------------------
echo "b. Section 11 chronic effects (#21)\n";
$ce = $method('buildChronicEffects');
check($ce->invoke($gen, $hz0, ['has_carcinogens' => false]) === $t->get('section11.chronic_none'), 'chronic none fallback');
check($ce->invoke($gen, ['h_statements' => [['code' => 'H317']]], []) === $t->get('section11.chronic_skin_sens'), 'chronic H317 fragment');
check($ce->invoke($gen, $hz0, ['has_carcinogens' => true]) === $t->get('section11.chronic_carc_listed'), 'chronic registry-only listing');

// ---------------------------------------------------------------------
echo "c. Section 11 acute toxicity (#20) + carcinogenicity banding (#8/#22)\n";
$s11m = $method('section11');
$composition = [
    ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 1.5],
    ['cas_number' => '67-63-0',  'chemical_name' => 'Isopropanol',  'concentration_pct' => 40.0, 'concentration_min' => 35.0, 'concentration_max' => 45.0],
];
$hzA = $hz0;
$hzA['h_statements'] = [['code' => 'H302', 'text' => 'Harmful if swallowed'], ['code' => 'H332', 'text' => 'Harmful if inhaled']];
$hzA['hazard_classes'] = [
    ['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL,
     'category_canonical' => 'Cat 4', 'source' => 'ate_mixture', 'ate_mix' => 1250.0, 'route' => 'oral', 'h_codes' => ['H302']],
    ['class' => 'Acute Toxicity (Inhalation)', 'category' => 'Category 4', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_INHALATION,
     'category_canonical' => 'Cat 4', 'cas' => '100-41-4', 'concentration_pct' => 1.5],
];
$carcPos = ['has_carcinogens' => true, 'findings' => [
    ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 1.5,
     'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => '']]],
]];
$s11 = $s11m->invoke($gen, $hzA, $composition, $carcPos, []);
check(str_starts_with($s11['acute_toxicity'], 'Acute toxicity (oral): Category 4 — Harmful if swallowed (H302).'), 'acute oral line from classification', $s11['acute_toxicity']);
check(str_contains($s11['acute_toxicity'], 'ATEmix = 1250 mg/kg bw.'), 'acute oral ATE value', $s11['acute_toxicity']);
check(str_contains($s11['acute_toxicity'], 'Acute toxicity (inhalation): Category 4 — Harmful if inhaled (H332).'), 'acute inhalation line (per-component trigger)', $s11['acute_toxicity']);
check(!str_contains($s11['acute_toxicity'], 'criteria are not met'), 'no "criteria are not met" when classified', $s11['acute_toxicity']);
check($s11m->invoke($gen, $hz0, $composition, ['has_carcinogens' => false, 'findings' => []], [])['acute_toxicity'] === $t->get('section11.acute_toxicity'), 'acute fallback when no acute route classified');
check($s11m->invoke($gen, $hzA, $composition, $carcPos, [11 => ['acute_toxicity' => 'Manual']])['acute_toxicity'] === 'Manual', 'acute override wins');

check(str_contains($s11['carcinogenicity'], 'Ethylbenzene (CAS 100-41-4, 1 - 5%) — IARC: Group 2B'), 'carcinogenicity line prints the Section 3 band', $s11['carcinogenicity']);
check(!str_contains($s11['carcinogenicity'], '1.5%'), 'carcinogenicity line never prints the exact %', $s11['carcinogenicity']);
$s11n = $s11m->invoke($gen, $hz0, $composition, ['has_carcinogens' => false, 'findings' => []], []);
check($s11n['carcinogenicity'] === 'No components present at or above 0.1% are listed as carcinogens by IARC, NTP, or OSHA.', 'negative carcinogenicity sentence carries the threshold', $s11n['carcinogenicity']);

$hzOel = $hz0;
$hzOel['exposure_limits'] = [['cas_number' => '67-63-0', 'limit_type' => 'PEL-TWA', 'value' => '400', 'units' => 'ppm', 'concentration_pct' => 40.0]];
$s11c = $s11m->invoke($gen, $hzOel, $composition, ['has_carcinogens' => false, 'findings' => []], []);
check(count($s11c['component_toxicology']) === 1
    && ($s11c['component_toxicology'][0]['concentration_range'] ?? null) === '30 - 60%'
    && !array_key_exists('concentration_pct', $s11c['component_toxicology'][0]), 'component tox entry carries the supplier-range band, no exact %', $s11c['component_toxicology']);

// ---------------------------------------------------------------------
echo "d. Section 12 (#23) ecotoxicity + component table\n";
// section12() calls ghsSectionNote(), which reads settings via Database::getInstance() unless its static cache is pre-set.
$p = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'showGhsSectionNote');
$p->setAccessible(true);
$p->setValue(null, false);
$s12m = $method('section12');

$aqComposition = [
    ['cas_number' => '1-1-1', 'chemical_name' => 'X', 'concentration_pct' => 2.35],
    ['cas_number' => '2-2-2', 'chemical_name' => 'Secret Y', 'concentration_pct' => 3.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary dispersant'],
];
$hz12 = ['h_statements' => [['code' => 'H411', 'text' => 'Toxic to aquatic life with long lasting effects']],
    'aquatic_components' => [
        ['cas' => '1-1-1', 'name' => 'X', 'conc' => 2.35, 'acute_category' => 'Cat 1', 'acute_m_factor' => 10.0],
        ['cas' => '2-2-2', 'name' => 'Secret Y', 'conc' => 3.0, 'chronic_category' => 'Cat 2'],
        ['cas' => '3-3-3', 'name' => 'Z', 'conc' => 0.05, 'chronic_category' => 'Cat 3'],
    ]];
$s12 = $s12m->invoke($gen, $hz12, $aqComposition, []);
check($s12['ecotoxicity'] === $t->get('section12.ecotoxicity_classified') . ' H411: Toxic to aquatic life with long lasting effects. ' . $t->get('section12.environmental_warning'), 'S12 ecotox lead-in + echoed H411 + warning', $s12['ecotoxicity']);
check(($s12['component_aquatic'][0]['concentration_range'] ?? null) === '1 - 5%' && !array_key_exists('concentration_pct', $s12['component_aquatic'][0]), 'S12 row carries the Section 3 band, no exact %', $s12['component_aquatic'][0]);
check($s12['component_aquatic'][0]['acute'] === 'Category 1 (M = 10)' && $s12['component_aquatic'][0]['chronic'] === '', 'S12 Cat 1 M-factor formatting');
check($s12['component_aquatic'][1]['cas_number'] === 'TRADE SECRET' && $s12['component_aquatic'][1]['chemical_name'] === 'Proprietary dispersant', 'S12 trade-secret row masked like Section 3', $s12['component_aquatic'][1]);
check($s12['component_aquatic'][2]['concentration_range'] === '<0.1%', 'S12 sub-0.1% buffer row prints <0.1%', $s12['component_aquatic'][2]);
check(!array_key_exists('ghs_note', $s12), 'S12 does not carry the shared footnote (printed once on Section 15)');

$s12nt = $s12m->invoke($gen, ['h_statements' => [['code' => 'H411', 'text' => 'Toxic to aquatic life with long lasting effects']]], [], []);
check(str_starts_with($s12nt['ecotoxicity'], $t->get('section12.ecotoxicity_classified_no_table')) && !str_contains($s12nt['ecotoxicity'], 'listed below'), 'S12 no component table -> neutral lead-in', $s12nt['ecotoxicity']);

$s12n = $s12m->invoke($gen, ['h_statements' => [], 'aquatic_components' => [['cas' => '1-1-1', 'name' => 'X', 'conc' => 2.0, 'chronic_category' => 'Cat 3']]], $aqComposition, []);
check($s12n['ecotoxicity'] === $t->get('section12.ecotoxicity_not_classified') && $s12n['component_aquatic'][0]['chronic'] === 'Category 3', 'S12 components but no mixture class');
check($s12m->invoke($gen, ['h_statements' => []], [], [])['ecotoxicity'] === $t->get('section12.ecotoxicity'), 'S12 no data default');
$p->setValue(null, null);

// ---------------------------------------------------------------------
echo "e. Translation keys present in all four languages\n";
$keys11 = ['acute_route_oral', 'acute_route_dermal', 'acute_route_inhalation', 'acute_route_line', 'acute_ate',
           'acute_unit_oral', 'acute_unit_dermal', 'acute_unit_inhalation', 'carcinogenicity', 'carcinogenicity_listed_line'];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($keys11 as $k) {
        $v = $trFile['section11'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section11.{$k}", $v);
    }
    check(str_contains((string) ($trFile['section11']['carcinogenicity'] ?? ''), ':threshold'), "{$lang} section11.carcinogenicity has :threshold");
    check(str_contains((string) ($trFile['section11']['carcinogenicity_listed_line'] ?? ''), ':range')
        && !str_contains((string) ($trFile['section11']['carcinogenicity_listed_line'] ?? ''), ':pct'), "{$lang} section11.carcinogenicity_listed_line uses :range");
    $v = $trFile['section12']['ecotoxicity_classified_no_table'] ?? null;
    check(is_string($v) && $v !== '', "{$lang} section12.ecotoxicity_classified_no_table", $v);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
