<?php
/**
 * DB-free checks for SDS Section 11 (T2c):
 *
 *   - Q6 / #41(1): the acute-toxicity line prints "ATEmix = n" only when the
 *     ATEmix produced the printed category; otherwise "Classified based on
 *     ingredient concentration." (translated); a category set only by the
 *     Finished-good hazard override gets neither.
 *   - Q8: Likely Routes of Exposure (from H-codes / exposure limits) and the
 *     Section 4(b) symptoms line (incl. its override) on Section 11; label
 *     and renderer parity (PDF, HTML preview, abbreviation scan).
 *   - #66: "See Carcinogenicity below" only when that paragraph lists
 *     registry components.
 *
 * Same Reflection bootstrap as SDSGeneratorSections2_11_12Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection11AcuteRoutesTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\GHSHazardClass as G;

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
$phrase = 'Classified based on ingredient concentration.';

// ---------------------------------------------------------------------
echo "1. Q6 / #41(1) acute toxicity basis\n";
$bat = $method('buildAcuteToxicity');
check($t->get('section11.acute_basis_concentration') === $phrase, 'EN phrase');

$hzCut = $hz0;
$hzCut['h_statements']   = [['code' => 'H301', 'text' => 'Toxic if swallowed']];
$hzCut['hazard_classes'] = [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 3', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 3', 'cas' => '1-1-1', 'concentration_pct' => 0.2, 'h_codes' => ['H301']]];
$hzCut['ate_results']    = ['oral' => ['route' => 'oral', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'ate_mix' => 50000.0, 'category' => null, 'outcome' => 'not_classified']];
$out = $bat->invoke($gen, $hzCut);
check(str_starts_with($out, "Acute toxicity (oral): Category 3 — Toxic if swallowed (H301). {$phrase}\n") && !str_contains($out, '50000') && !str_contains($out, 'ATEmix'), 'cut-off Cat 3, ATEmix not classified -> phrase, no number', $out);

$hzMix = $hz0;
$hzMix['h_statements']   = [['code' => 'H302', 'text' => 'Harmful if swallowed']];
$hzMix['hazard_classes'] = [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 4', 'source' => 'ate_mixture', 'ate_mix' => 1250.0, 'route' => 'oral', 'h_codes' => ['H302']]];
$out = $bat->invoke($gen, $hzMix);
check(str_contains($out, 'ATEmix = 1250 mg/kg bw.') && !str_contains($out, $phrase), 'ate_mixture entry -> ATEmix, no phrase', $out);

$hzAc = $hz0;
$hzAc['h_statements']   = [['code' => 'H302', 'text' => 'Harmful if swallowed']];
$hzAc['hazard_classes'] = [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 4', 'cas' => '111-76-2', 'concentration_pct' => 30.0, 'h_codes' => ['H302']]];
$hzAc['ate_results']    = ['oral' => ['route' => 'oral', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'ate_mix' => 1666.67, 'category' => 'Cat 4', 'outcome' => 'already_classified']];
$out = $bat->invoke($gen, $hzAc);
check(str_contains($out, 'ATEmix = 1667 mg/kg bw.') && !str_contains($out, $phrase), 'per-component Cat 4 + ATEmix at Cat 4 -> ATEmix', $out);

$hzDom = $hzAc;
$hzDom['h_statements'] = [['code' => 'H301', 'text' => 'Toxic if swallowed']];
$hzDom['hazard_classes'][0]['category'] = 'Category 3'; $hzDom['hazard_classes'][0]['category_canonical'] = 'Cat 3'; $hzDom['hazard_classes'][0]['h_codes'] = ['H301'];
$hzDom['ate_results']['oral']['outcome'] = 'dominated';
$out = $bat->invoke($gen, $hzDom);
check(str_contains($out, $phrase) && !str_contains($out, 'ATEmix'), 'per-component Cat 3, ATEmix Cat 4 (dominated) -> phrase', $out);

$hzFg = $hz0;
$hzFg['h_statements']   = [['code' => 'H302', 'text' => 'Harmful if swallowed']];
$hzFg['hazard_classes'] = [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 4', 'cas' => 'FG_OVERRIDE', 'source' => 'fg_override', 'h_codes' => ['H302']]];
$out = $bat->invoke($gen, $hzFg);
check(str_starts_with($out, "Acute toxicity (oral): Category 4 — Harmful if swallowed (H302).\n"), 'FG override only -> neither ATEmix nor phrase', $out);

$hzFg2 = $hzFg;
$hzFg2['hazard_classes'][] = ['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 4', 'cas' => '111-76-2', 'concentration_pct' => 30.0, 'h_codes' => ['H302']];
check(str_contains($bat->invoke($gen, $hzFg2), $phrase), 'FG override + per-component entry at the same category -> phrase', $bat->invoke($gen, $hzFg2));

$hzTs = $hz0;
$hzTs['h_statements']   = [['code' => 'H302', 'text' => 'Harmful if swallowed']];
$hzTs['hazard_classes'] = [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 4', 'cas' => 'TRADE_SECRET', 'source' => 'manual (trade secret)', 'concentration_pct' => 100.0, 'h_codes' => ['H302']]];
$hzTs['ate_results']    = ['oral' => ['route' => 'oral', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'ate_mix' => 10000.0, 'category' => null, 'outcome' => 'not_classified']];
$out = $bat->invoke($gen, $hzTs);
check(str_contains($out, $phrase) && !str_contains($out, '10000'), 'trade-secret Cat 4, ATEmix not classified -> phrase, no number', $out);

$hzH = $hz0;
$hzH['h_statements'] = [['code' => 'H302', 'text' => 'Harmful if swallowed']];
check(str_starts_with($bat->invoke($gen, $hzH), "Acute toxicity (oral): Category 4 — Harmful if swallowed (H302).\n"), 'route known only from an H-code (#40) -> no basis claimed', $bat->invoke($gen, $hzH));

foreach (['es' => 'Clasificado en función de la concentración de los ingredientes.', 'fr' => 'Classé sur la base de la concentration des ingrédients.', 'de' => 'Eingestuft auf Grundlage der Konzentration der Bestandteile.'] as $lang => $expected) {
    $g = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService($lang));
    $m = new ReflectionMethod($g, 'buildAcuteToxicity'); $m->setAccessible(true);
    check(str_contains($m->invoke($g, $hzCut), $expected), "{$lang}: translated basis phrase", $m->invoke($g, $hzCut));
}

// ---------------------------------------------------------------------
echo "2. Q8 likely routes of exposure\n";
$rt = $method('deriveRoutesOfExposure');
check($rt->invoke($gen, $hz0) === 'Skin Contact, Eye Contact.', 'unclassified, no OEL -> skin + eye', $rt->invoke($gen, $hz0));
check($rt->invoke($gen, ['h_statements' => [['code' => 'H332'], ['code' => 'H302']]] + $hz0) === 'Inhalation, Skin Contact, Eye Contact, Ingestion.', 'H332 + H302 -> all four', $rt->invoke($gen, ['h_statements' => [['code' => 'H332'], ['code' => 'H302']]] + $hz0));
$hzOel = $hz0;
$hzOel['exposure_limits'] = [['cas_number' => '67-63-0', 'limit_type' => 'PEL-TWA', 'value' => '400', 'units' => 'ppm']];
check($rt->invoke($gen, $hzOel) === 'Inhalation, Skin Contact, Eye Contact.', 'exposure limit -> inhalation', $rt->invoke($gen, $hzOel));
check($rt->invoke($gen, ['h_statements' => [['code' => 'H304']]] + $hz0) === 'Skin Contact, Eye Contact, Ingestion.', 'H304 -> ingestion');
$comb = $rt->invoke($gen, ['h_statements' => [['code' => 'H302+H332']]] + $hz0);
check(str_contains($comb, 'Inhalation') && str_contains($comb, 'Ingestion'), 'combined H302+H332 -> inhalation + ingestion', $comb);
$genEs = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService('es'));
$rtEs = new ReflectionMethod($genEs, 'deriveRoutesOfExposure'); $rtEs->setAccessible(true);
check($rtEs->invoke($genEs, $hz0) === 'Contacto con la Piel, Contacto con los Ojos.', 'ES route names', $rtEs->invoke($genEs, $hz0));

// ---------------------------------------------------------------------
echo "3. Q8 symptoms on Section 11 (same as Section 4(b))\n";
$s11m = $method('section11');
$carcNone = ['has_carcinogens' => false, 'findings' => []];
$hzS = $hz0;
$hzS['h_statements'] = [['code' => 'H319', 'text' => 'Causes serious eye irritation'], ['code' => 'H317', 'text' => 'May cause an allergic skin reaction']];
$s = $s11m->invoke($gen, $hzS, [], $carcNone, []);
check($s['symptoms'] === 'Acute: Causes serious eye irritation. Delayed: May cause an allergic skin reaction.', 'symptoms acute / delayed', $s['symptoms']);
check($s['symptoms'] === $method('deriveSymptoms')->invoke($gen, $hzS['h_statements']), 'identical to Section 4(b) deriveSymptoms');
check($s['routes_of_exposure'] === 'Skin Contact, Eye Contact.', 'routes on section11()', $s['routes_of_exposure']);
check($s11m->invoke($gen, $hzS, [], $carcNone, [4 => ['symptoms' => 'Custom S4']])['symptoms'] === 'Custom S4', 'Section 4 symptoms override also prints in Section 11');
check($s11m->invoke($gen, $hz0, [], $carcNone, [])['symptoms'] === $t->get('section4.symptoms_none'), 'unclassified -> section4.symptoms_none');

// ---------------------------------------------------------------------
echo "4. #66 Carcinogenicity cross-reference\n";
$ce = $method('buildChronicEffects');
check($ce->invoke($gen, ['h_statements' => [['code' => 'H351']]], ['has_carcinogens' => false]) === $t->get('section11.chronic_carc_2_no_ref'), 'H351, no registry listing -> no cross-reference');
check($ce->invoke($gen, ['h_statements' => [['code' => 'H351']]], ['has_carcinogens' => true]) === $t->get('section11.chronic_carc_2'), 'H351 + registry listing -> cross-reference');
check($ce->invoke($gen, ['h_statements' => [['code' => 'H350']]], ['has_carcinogens' => true], false) === $t->get('section11.chronic_carc_1_no_ref'), 'H350, listing not printed below -> no cross-reference');
check(!str_contains($t->get('section11.chronic_carc_2_no_ref'), 'See Carcinogenicity'), 'no_ref text has no cross-reference');

$composition = [
    ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 1.5],
    ['cas_number' => '67-63-0',  'chemical_name' => 'Isopropanol',  'concentration_pct' => 40.0, 'concentration_min' => 35.0, 'concentration_max' => 45.0],
];
$carcPos = ['has_carcinogens' => true, 'findings' => [
    ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 1.5,
     'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => '']]],
]];
$s = $s11m->invoke($gen, $hz0, $composition, $carcPos, []);
check($s['chronic_effects'] === $t->get('section11.chronic_carc_listed'), 'registry listing printed -> cross-reference kept', $s['chronic_effects']);
$s = $s11m->invoke($gen, $hz0, $composition, $carcPos, [11 => ['carcinogenicity' => 'Operator text']]);
check($s['chronic_effects'] === $t->get('section11.chronic_carc_listed_no_ref') && $s['carcinogenicity'] === 'Operator text', 'operator Carcinogenicity override -> cross-reference dropped', [$s['chronic_effects'], $s['carcinogenicity']]);
$hz351 = $hz0;
$hz351['h_statements'] = [['code' => 'H351', 'text' => 'Suspected of causing cancer']];
$s = $s11m->invoke($gen, $hz351, $composition, $carcNone, []);
check(!str_contains($s['chronic_effects'], 'See Carcinogenicity below') && str_starts_with($s['carcinogenicity'], 'No components present at or above 0.1%'), 'vendor H351 not in registry -> no dangling cross-reference', [$s['chronic_effects'], $s['carcinogenicity']]);

// ---------------------------------------------------------------------
echo "5. Labels and renderer parity\n";
check(($method('getLabels')->invoke($gen)['routes_of_exposure'] ?? null) === 'Likely Routes of Exposure', 'EN label');
$glEs = new ReflectionMethod($genEs, 'getLabels'); $glEs->setAccessible(true);
check(($glEs->invoke($genEs)['routes_of_exposure'] ?? null) === 'Vías Probables de Exposición', 'ES label');
$pdfSrc  = (string) file_get_contents($basePath . '/src/Services/PDFService.php');
$viewSrc = (string) file_get_contents($basePath . '/src/Views/sds/preview.php');
$abbSrc  = (string) file_get_contents($basePath . '/src/Services/AbbreviationService.php');
check(str_contains($pdfSrc, "label('routes_of_exposure')") && str_contains($pdfSrc, "\$s['symptoms']"), 'PDF renders routes + symptoms');
check(str_contains($viewSrc, "\$l('routes_of_exposure')") && str_contains($viewSrc, "\$section['symptoms']"), 'HTML preview renders routes + symptoms');
check(str_contains($abbSrc, "'routes_of_exposure', 'symptoms'"), 'abbreviation scan covers the new Section 11 fields');
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tr = require $basePath . '/templates/translations/' . $lang . '.php';
    $ok = ($tr['labels']['routes_of_exposure'] ?? '') !== '';
    foreach (['acute_basis_concentration', 'chronic_carc_1_no_ref', 'chronic_carc_2_no_ref', 'chronic_carc_listed_no_ref'] as $k) {
        $ok = $ok && ($tr['section11'][$k] ?? '') !== '';
    }
    check($ok, "{$lang}: new Section 11 keys defined");
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
