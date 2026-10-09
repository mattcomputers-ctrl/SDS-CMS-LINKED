<?php
/**
 * DB-free checks for the SDSGenerator Section 2 / 11 / 12 builders changed by
 * the SDS content audit (items #4, #8, #15, #20, #21, #22, #23, #28):
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
 *     #24: PBT line from sara_result is_pbt (>= 0.1 %, trade secret masked,
 *     EPA footnote markers stripped) on persistence + bioaccumulation;
 *     mobility line default + override.
 *   - Section 15: OSHA status sentence follows is_classified; override wins.
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
check(str_starts_with($s11['acute_toxicity'], 'Acute toxicity (oral): Category 4 — Harmful if swallowed (H302). ATEmix = 1250 mg/kg bw.'), 'acute oral line + ATE on the same line', $s11['acute_toxicity']);
check(str_contains($s11['acute_toxicity'], "\nAcute toxicity (dermal): Not classified based on available data.\n"), 'unclassified route prints the per-route sentence', $s11['acute_toxicity']);
check(str_ends_with($s11['acute_toxicity'], 'Acute toxicity (inhalation): Category 4 — Harmful if inhaled (H332).'), 'per-component inhalation line, no ATE when the engine computed none', $s11['acute_toxicity']);
check(substr_count($s11['acute_toxicity'], "\n") === 2, 'exactly three route lines');
check(!str_contains($s11['acute_toxicity'], 'criteria are not met'), 'old constant no longer printed');
$nc = "Acute toxicity (oral): Not classified based on available data.\nAcute toxicity (dermal): Not classified based on available data.\nAcute toxicity (inhalation): Not classified based on available data.";
check($s11m->invoke($gen, $hz0, $composition, ['has_carcinogens' => false, 'findings' => []], [])['acute_toxicity'] === $nc, 'unclassified product -> three not-classified lines');
check($s11m->invoke($gen, $hzA, $composition, $carcPos, [11 => ['acute_toxicity' => 'Manual']])['acute_toxicity'] === 'Manual', 'acute override wins');

// ate_results: per-component trigger + ATE at the same category -> ATEmix printed
$hzB = $hz0;
$hzB['h_statements']   = [['code' => 'H302', 'text' => 'Harmful if swallowed']];
$hzB['hazard_classes'] = [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 4', 'cas' => '111-76-2', 'concentration_pct' => 30.0, 'h_codes' => ['H302']]];
$hzB['ate_results']    = ['oral' => ['route' => 'oral', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL, 'ate_mix' => 1666.6667, 'category' => 'Cat 4', 'outcome' => 'already_classified']];
$bat = $method('buildAcuteToxicity');
check(str_starts_with($bat->invoke($gen, $hzB), 'Acute toxicity (oral): Category 4 — Harmful if swallowed (H302). ATEmix = 1667 mg/kg bw.'), 'ATEmix from ate_results (already_classified)', $bat->invoke($gen, $hzB));
// GHS Category 5 (not adopted by 29 CFR 1910.1200 App. A.1) prints as not classified, never H303/H313/H333
$hz5 = $hz0;
$hz5['h_statements']   = [['code' => 'H303', 'text' => 'May be harmful if swallowed']];
$hz5['hazard_classes'] = [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 5', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 5', 'source' => 'ate_mixture', 'ate_mix' => 4000.0, 'route' => 'oral', 'h_codes' => ['H303']]];
$hz5['ate_results']    = ['oral' => ['route' => 'oral', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL, 'ate_mix' => 4000.0, 'category' => 'Cat 5', 'outcome' => 'classified']];
$out5 = $bat->invoke($gen, $hz5);
check(str_starts_with($out5, "Acute toxicity (oral): Not classified based on available data.\n") && !str_contains($out5, 'H303') && !str_contains($out5, 'Category 5') && !str_contains($out5, '4000'), 'Cat 5 ate_mixture entry prints the oral route as not classified', $out5);
$hz5b = $hz0;
$hz5b['hazard_classes'] = [['class' => 'Acute Toxicity (Inhalation)', 'category' => 'Category 5']];
check(!str_contains($bat->invoke($gen, $hz5b), 'H333') && str_contains($bat->invoke($gen, $hz5b), 'Acute toxicity (inhalation): Not classified based on available data.'), 'Cat 5 override entry without h_codes has no H333 fallback', $bat->invoke($gen, $hz5b));
// dominated: ATE at Cat 3 while Cat 1 is printed -> no ATEmix
$hzC = $hzB;
$hzC['hazard_classes'][0]['category'] = 'Category 1'; $hzC['hazard_classes'][0]['category_canonical'] = 'Cat 1'; $hzC['hazard_classes'][0]['h_codes'] = ['H300'];
$hzC['h_statements'] = [['code' => 'H300', 'text' => 'Fatal if swallowed']];
$hzC['ate_results']  = ['oral' => ['route' => 'oral', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL, 'ate_mix' => 250.0, 'category' => 'Cat 3', 'outcome' => 'dominated']];
check(str_starts_with($bat->invoke($gen, $hzC), "Acute toxicity (oral): Category 1 — Fatal if swallowed (H300).\n"), 'dominated ATE result is not printed', $bat->invoke($gen, $hzC));
// inhalation dust unit + small-value formatting
$hzD = $hz0;
$hzD['h_statements']   = [['code' => 'H330', 'text' => 'Fatal if inhaled']];
$hzD['hazard_classes'] = [['class' => 'Acute Toxicity (Inhalation)', 'category' => 'Category 1', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_INHALATION, 'category_canonical' => 'Cat 1', 'source' => 'ate_mixture', 'ate_mix' => 0.0049, 'route' => 'inhalation_dust', 'h_codes' => ['H330']]];
check(str_ends_with($bat->invoke($gen, $hzD), 'Acute toxicity (inhalation): Category 1 — Fatal if inhaled (H330). ATEmix = 0.0049 mg/L (4 h, dusts/mists).'), 'inhalation dust unit + 3 s.f. formatting', $bat->invoke($gen, $hzD));
$fmt = new ReflectionMethod(\SDS\Services\SDSGenerator::class, 'formatAte'); $fmt->setAccessible(true);
check($fmt->invoke(null, 1250.4) === '1250' && $fmt->invoke(null, 325.67) === '326' && $fmt->invoke(null, 12.345) === '12.3' && $fmt->invoke(null, 2.5) === '2.5' && $fmt->invoke(null, 0.123456) === '0.123', 'formatAte significant figures');
// Spanish sheet: route label, ETAmezcla and unit come from es.php
$tEs = new \SDS\Services\TranslationService('es'); $genEs = new \SDS\Services\SDSGenerator($tEs);
$batEs = new ReflectionMethod($genEs, 'buildAcuteToxicity'); $batEs->setAccessible(true);
check(str_starts_with($batEs->invoke($genEs, $hzB), 'Toxicidad aguda (oral): Categoría 4 — ') && str_contains($batEs->invoke($genEs, $hzB), 'ETAmezcla = 1667 mg/kg pc.') && str_contains($batEs->invoke($genEs, $hzB), 'Toxicidad aguda (cutánea): No clasificado según los datos disponibles.'), 'ES acute block', $batEs->invoke($genEs, $hzB));

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

// #24: persistence / bioaccumulation / mobility — PBT line from the SARA 313 is_pbt flag
check($s12['persistence'] === $t->get('section12.persistence') && $s12['bioaccumulation'] === $t->get('section12.bioaccumulation') && $s12['mobility'] === $t->get('section12.mobility'),
    'S12 no SARA result -> all three lines "No data available."', [$s12['persistence'] ?? null, $s12['bioaccumulation'] ?? null, $s12['mobility'] ?? null]);
$saraPbt = [
    'reportable' => [
        ['cas_number' => '7439-92-1', 'chemical_name' => 'Lead powder', 'concentration_pct' => 2.0, 'threshold_pct' => 0.1, 'is_pbt' => true,  'category_code' => null, 'sara_name' => 'Lead ††', 'status' => 'reportable'],
        ['cas_number' => '108-88-3',  'chemical_name' => 'Toluene',     'concentration_pct' => 4.5, 'threshold_pct' => 1.0, 'is_pbt' => false, 'category_code' => null, 'sara_name' => 'Toluene', 'status' => 'reportable'],
    ],
    'below_threshold' => [
        ['cas_number' => '7439-97-6', 'chemical_name' => 'Mercury',  'concentration_pct' => 0.05, 'threshold_pct' => 0.1, 'is_pbt' => true, 'category_code' => null, 'sara_name' => 'Mercury', 'status' => 'below_threshold'],
        ['cas_number' => '2-2-2',     'chemical_name' => 'Secret Y', 'concentration_pct' => 0.5,  'threshold_pct' => 1.0, 'is_pbt' => true, 'category_code' => null, 'sara_name' => 'Hexachlorobenzene', 'status' => 'below_threshold'],
    ],
    'not_listed' => [], 'summary' => '',
];
$s12p = $s12m->invoke($gen, ['h_statements' => []], $aqComposition, [], $saraPbt);
$pbtExpected = 'Contains component(s) identified as persistent, bioaccumulative and toxic (PBT) under SARA 313 (40 CFR 372.28): Lead (CAS 7439-92-1); Proprietary dispersant (CAS TRADE SECRET).';
check($s12p['persistence'] === $pbtExpected, 'S12 PBT line on persistence (EPA name, daggers stripped, trade secret masked, >=0.1% below-threshold row kept)', $s12p['persistence']);
check($s12p['bioaccumulation'] === $t->get('section12.pbt_see_persistence') && $s12p['bioaccumulation'] === 'See Persistence and Degradability above.', 'S12 bioaccumulation cross-references the persistence PBT line (printed once)', $s12p['bioaccumulation']);
check(!str_contains($s12p['persistence'], 'Mercury') && !str_contains($s12p['persistence'], 'Toluene') && !str_contains($s12p['persistence'], '2.0') && !str_contains($s12p['persistence'], '†'),
    'S12 PBT line omits sub-0.1% PBT, non-PBT listed chemicals, percentages and footnote markers', $s12p['persistence']);
check($s12p['mobility'] === $t->get('section12.mobility') && $s12p['mobility'] === 'No data available.', 'S12 mobility default', $s12p['mobility']);
$s12po = $s12m->invoke($gen, ['h_statements' => []], $aqComposition, [12 => ['persistence' => 'Readily biodegradable (OECD 301B).', 'mobility' => 'Low']], $saraPbt);
check($s12po['persistence'] === 'Readily biodegradable (OECD 301B).' && $s12po['bioaccumulation'] === $pbtExpected && $s12po['mobility'] === 'Low', 'S12 overrides win per line; persistence overridden -> full PBT sentence moves to bioaccumulation', $s12po);
$s12pb = $s12m->invoke($gen, ['h_statements' => []], $aqComposition, [12 => ['bioaccumulation' => 'Log Kow 2.1']], $saraPbt);
check($s12pb['persistence'] === $pbtExpected && $s12pb['bioaccumulation'] === 'Log Kow 2.1', 'S12 bioaccumulation override wins over the cross-reference', $s12pb);
check($s12m->invoke($gen, ['h_statements' => []], [], [], ['reportable' => [['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'concentration_pct' => 4.5, 'is_pbt' => false, 'sara_name' => 'Toluene']], 'below_threshold' => []])['persistence'] === $t->get('section12.persistence'),
    'S12 SARA-listed but non-PBT component -> No data available');
$p->setValue(null, null);

// ---------------------------------------------------------------------
echo "e. Translation keys present in all four languages\n";
$keys11 = ['acute_route_oral', 'acute_route_dermal', 'acute_route_inhalation', 'acute_route_line', 'acute_ate',
           'acute_unit_oral', 'acute_unit_dermal', 'acute_unit_inhalation', 'carcinogenicity', 'carcinogenicity_listed_line',
           'acute_unit_inhalation_vapor', 'acute_unit_inhalation_dust', 'acute_route_not_classified'];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($keys11 as $k) {
        $v = $trFile['section11'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section11.{$k}", $v);
    }
    check(str_contains((string) ($trFile['section11']['acute_route_not_classified'] ?? ''), ':route'), "{$lang} section11.acute_route_not_classified has :route");
    check(isset($trFile['section16']['abbreviation_table'][in_array($lang, ['es', 'fr'], true) ? 'ETA' : 'ATE']), "{$lang} ATE/ETA abbreviation");
    check(str_contains((string) ($trFile['section11']['carcinogenicity'] ?? ''), ':threshold'), "{$lang} section11.carcinogenicity has :threshold");
    check(str_contains((string) ($trFile['section11']['carcinogenicity_listed_line'] ?? ''), ':range')
        && !str_contains((string) ($trFile['section11']['carcinogenicity_listed_line'] ?? ''), ':pct'), "{$lang} section11.carcinogenicity_listed_line uses :range");
    $v = $trFile['section12']['ecotoxicity_classified_no_table'] ?? null;
    check(is_string($v) && $v !== '', "{$lang} section12.ecotoxicity_classified_no_table", $v);
    // #24
    foreach (['mobility', 'pbt_components', 'pbt_see_persistence'] as $k) {
        $v = $trFile['section12'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section12.{$k}", $v);
    }
    check(str_contains((string) ($trFile['section12']['pbt_components'] ?? ''), ':components') && str_contains((string) ($trFile['section12']['pbt_components'] ?? ''), '40 CFR 372.28'), "{$lang} section12.pbt_components has :components + CFR cite");
    $v = $trFile['labels']['mobility'] ?? null;
    check(is_string($v) && $v !== '', "{$lang} labels.mobility", $v);
}

// ---------------------------------------------------------------------
echo "f. Section 15 OSHA status follows the classification (#28)\n";
$icm = new ReflectionMethod(\SDS\Services\SDSGenerator::class, 'isClassified');
$icm->setAccessible(true);
check($icm->invoke(null, $hz0) === false, 'isClassified: empty result -> false');
check($icm->invoke(null, $hz0 + ['exposure_limits' => [['cas_number' => '13463-67-7', 'limit_type' => 'PEL-TWA', 'value' => '15', 'units' => 'mg/m3']]]) === false, 'isClassified: OEL rows alone do not classify');
check($icm->invoke(null, ['signal_word' => 'Warning'] + $hz0) === true, 'isClassified: signal word');
check($icm->invoke(null, ['hazard_classes' => [['class' => 'Flammable Liquids', 'category' => 'Category 3']]] + $hz0) === true, 'isClassified: hazard class only');
check($icm->invoke(null, ['h_statements' => 'H226'] + $hz0) === true, 'isClassified: comma-string h_statements');

$osm = $method('resolveOshaStatus');
$notClassifiedEn = 'This product is not classified as hazardous under OSHA HazCom 2024 (29 CFR 1910.1200).';
check($osm->invoke($gen, $hz0, []) === $notClassifiedEn, 'S15 unclassified -> not-classified sentence', $osm->invoke($gen, $hz0, []));
check($osm->invoke($gen, $hz0, []) === $t->get('section15.osha_status_not_classified'), 'S15 unclassified sentence comes from the translation key');
$hzF = $hz0;
$hzF['signal_word'] = 'Warning';
$hzF['pictograms'] = ['GHS02'];
$hzF['hazard_classes'] = [['class' => 'Flammable Liquids', 'category' => 'Category 3']];
$hzF['h_statements'] = [['code' => 'H226', 'text' => 'Flammable liquid and vapour']];
check($osm->invoke($gen, $hzF, []) === $t->get('section15.osha_status'), 'S15 classified -> existing classified sentence', $osm->invoke($gen, $hzF, []));
check(str_contains($osm->invoke($gen, $hzF, []), 'is classified as hazardous') && str_contains($osm->invoke($gen, $hz0, []), 'is not classified as hazardous'), 'S15 the two sentences differ on classified / not classified');
check($osm->invoke($gen, $hz0, [15 => ['osha_status' => 'Manual OSHA text']]) === 'Manual OSHA text', 'S15 override wins when unclassified');
check($osm->invoke($gen, $hzF, [15 => ['osha_status' => 'Manual OSHA text']]) === 'Manual OSHA text', 'S15 override wins when classified');

// Sections 2 and 15 must agree for the same hazard result.
check($s2m->invoke($gen, $hz0, [])['is_classified'] === false && $osm->invoke($gen, $hz0, []) === $t->get('section15.osha_status_not_classified'), 'S2/S15 parity: unclassified');
check($s2m->invoke($gen, $hzI, [])['is_classified'] === true && $osm->invoke($gen, $hzI, []) === $t->get('section15.osha_status'), 'S2/S15 parity: classified (H315/H319 only)');

foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    $v = $trFile['section15']['osha_status_not_classified'] ?? null;
    check(is_string($v) && $v !== '' && str_contains($v, '29 CFR 1910.1200') && $v !== ($trFile['section15']['osha_status'] ?? ''), "{$lang} section15.osha_status_not_classified", $v);
    foreach (['osha_status', 'osha_status_not_classified'] as $k) {
        $v = (string) ($trFile['section15'][$k] ?? '');
        check(str_contains($v, '29 CFR 1910.1200') && str_contains($v, 'HazCom 2024') && !str_contains($v, '2012'), "{$lang} section15.{$k} cites HazCom 2024 (29 CFR 1910.1200), not 2012", $v);
    }
}

// ---------------------------------------------------------------------
echo "#37 Trade-secret placeholders in the sheet language (Sections 3 / 12)\n";
$inh = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'inhalationOnlyCas');
$inh->setAccessible(true);
$inhSaved = $inh->getValue();
$inh->setValue(null, ['1333-86-4' => 'Carbon Black']);   // keeps section3() DB-free
$tsComp = [
    ['cas_number' => '4-4-4', 'chemical_name' => 'Trade Secret', 'concentration_pct' => 4.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Trade Secret'],
    ['cas_number' => '5-5-5', 'chemical_name' => 'Hidden', 'concentration_pct' => 2.0, 'is_trade_secret' => true, 'trade_secret_description' => ''],
    ['cas_number' => '6-6-6', 'chemical_name' => 'Hidden 2', 'concentration_pct' => 1.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary resin'],
];
$tsHz = ['hazardous_cas' => ['4-4-4', '5-5-5', '6-6-6'], 'exposure_limits' => [], 'hazard_classes' => []];
foreach (['en' => ['TRADE SECRET', 'Trade Secret'], 'es' => ['SECRETO COMERCIAL', 'Secreto comercial'], 'fr' => ['SECRET COMMERCIAL', 'Secret commercial'], 'de' => ['GESCHÄFTSGEHEIMNIS', 'Geschäftsgeheimnis']] as $lang => [$tsCas, $tsName]) {
    $g = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService($lang));
    $m3 = new ReflectionMethod($g, 'section3'); $m3->setAccessible(true);
    $rows = $m3->invoke($g, $tsComp, $tsHz, [])['components'] ?? [];
    $byName = array_column($rows, 'cas_number', 'chemical_name');
    check(count($rows) === 3 && array_unique(array_column($rows, 'cas_number')) === [$tsCas], "{$lang}: S3 trade-secret CAS cell = {$tsCas}", $rows);
    check(isset($byName[$tsName]) && isset($byName['Proprietary resin']) && ($lang === 'en' || !isset($byName['Trade Secret'])), "{$lang}: S3 blank / 'Trade Secret' sentinel -> {$tsName}; operator description kept", array_keys($byName));
    $m12 = new ReflectionMethod($g, 'section12'); $m12->setAccessible(true);
    $r12 = $m12->invoke($g, $hz12, $aqComposition, [], $saraPbt);
    check(($r12['component_aquatic'][1]['cas_number'] ?? null) === $tsCas, "{$lang}: S12 aquatic trade-secret CAS = {$tsCas}", $r12['component_aquatic'][1] ?? null);
    check(str_contains($r12['persistence'], 'Proprietary dispersant (CAS ' . $tsCas . ')'), "{$lang}: S12 PBT line masks CAS as {$tsCas}", $r12['persistence']);
}
$inh->setValue(null, $inhSaved);
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    check(($trFile['labels']['trade_secret'] ?? '') !== '' && ($trFile['labels']['trade_secret_cas'] ?? '') !== '' && ($trFile['section12']['pbt_component_item'] ?? '') !== '', "{$lang}: labels.trade_secret / trade_secret_cas / section12.pbt_component_item defined");
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
