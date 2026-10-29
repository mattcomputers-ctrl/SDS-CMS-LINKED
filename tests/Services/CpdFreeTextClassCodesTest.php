#!/usr/bin/env php
<?php
/**
 * Engine v1.12 (2026-10-29): the H-codes, pictograms and cut-off of a legacy
 * free-text CAS determination class the alias table does not map — DB-free.
 *
 * A CLP short name with its category ("Acute Tox. 3", "Carc. 1B", "STOT SE
 * 3", "Skin Irrit. 2", "Aquatic Chronic 2") or a bracketed category
 * ("Carcinogenicity (Category 2)") had no canonical class: it triggered at
 * the 1 % default cut-off, and the code filter dropped its own H-codes and
 * pictograms. A route-less "Acute Toxicity" is aliased to oral, so its dermal
 * and inhalation codes were dropped too. Now such a name is read for its
 * class and category (HazardEngine::readFreeTextClass()), applies at that
 * category's cut-off, keeps its class's codes (every route's for a route-less
 * acute name) and below its cut-off feeds the summation. Its entry keeps the
 * name and no canonical class, so its Section 2 line and the v1.11 signal word
 * are as before; a code kept only now sets its floor. Flammable Liquids
 * spellings stay ignored (Q3).
 *
 * Run: php tests/Services/CpdFreeTextClassCodesTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\GHSHazardClass as G;
use SDS\Services\HazardEngine;

// App statics without a DB connection (as CpdAutoSignalWordTest)
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp  = $ref->getProperty('basePath');
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
        echo '       actual: ' . json_encode($actual) . "\n";
    }
}

$parse = new ReflectionMethod(HazardEngine::class, 'parseDeterminationStructure');
$parse->setAccessible(true);
$sumBuf = new ReflectionProperty(HazardEngine::class, 'summationBuffer');
$sumBuf->setAccessible(true);
$engine = null;
// One component's contribution, on a fresh engine (kept in $engine for its summation buffer).
$run = static function (string $classes, string $hCodes, string $pictograms = '', string $stored = 'Danger', float $conc = 30.0, string $pCodes = '') use (&$engine, $parse): array {
    $engine = new HazardEngine();
    $out = $parse->invoke($engine, [
        'selected_hazards' => '[]', 'hazard_classes' => $classes, 'h_statements' => $hCodes,
        'p_statements' => $pCodes, 'pictograms' => $pictograms, 'signal_word' => $stored,
    ], '50-00-0', 'Test substance', $conc, 'CAS determination');
    $h = array_keys($out['h_statements']);
    sort($h);
    $pic = array_values(array_unique($out['pictograms']));
    sort($pic);
    $p = array_keys($out['p_statements']);
    sort($p);
    return ['h' => $h, 'pic' => $pic, 'p' => $p, 'sw' => $out['signal_word'], 'classes' => $out['hazard_classes']];
};
$h = static fn (string $classes, string $hCodes, float $conc = 30.0): array => $run($classes, $hCodes, '', 'Danger', $conc)['h'];
$cutoffOf = static function (array $res, string $class): ?float {
    foreach ($res['classes'] as $hc) {
        if ($hc['class'] === $class) {
            return (float) $hc['cutoff_pct'];
        }
    }
    return null;
};

echo "a. A short name keeps its own H-codes and pictograms (30 %)\n";
$r = $run('Eye Irritation, Acute Tox. 3', 'H301, H319', 'GHS06, GHS07, GHS08');
check($r['h'] === ['H301', 'H319'] && $r['pic'] === ['GHS06', 'GHS07'],
    '"Eye Irritation, Acute Tox. 3" (H301, H319; GHS06, GHS07, GHS08): H301 + H319, GHS06 + GHS07 (was H319 + GHS07; GHS08 is no class\'s here)', $r);
$r = $run('Eye Irritation, Carc. 1B', 'H319, H350', 'GHS07, GHS08');
check($r['h'] === ['H319', 'H350'] && $r['pic'] === ['GHS07', 'GHS08'], '"Eye Irritation, Carc. 1B": H350 + GHS08 kept', $r);
$r = $run('Skin Irrit. 2, STOT SE 3', 'H315, H335', 'GHS07', 'Warning');
check($r['h'] === ['H315', 'H335'] && $r['pic'] === ['GHS07'], '"Skin Irrit. 2, STOT SE 3": H315 + H335 kept', $r);
check($h('Aquatic Chronic 2', 'H411') === ['H411'] && $run('Aquatic Chronic 2', 'H411', 'GHS09', '')['pic'] === ['GHS09'], '"Aquatic Chronic 2": H411 + GHS09 kept, as "Aquatic Chronic" already did');
check($h('Carcinogenicity (Category 2), Eye Irritation', 'H351, H319') === ['H319', 'H351'], '"Carcinogenicity (Category 2)" (bracketed category): H351 kept');
check($h('Eye Irritation - Category 2A', 'H319') === ['H319'], '"Eye Irritation - Category 2A" (a GHS key): H319 kept');
check($h('Lact.', 'H362', 0.5) === ['H362'], '"Lact." at 0.5 %: H362 kept');
check($h('Carc. 2', 'H350, H351') === ['H350', 'H351'], '"Carc. 2" with H350: every Carcinogenicity code it declares is kept, as for "Carcinogenicity" (the filter is by class)');
check($h('Carc. 1B', 'H350, H319, H411') === ['H350'], '... not another class\'s (H319, H411)');

echo "\nb. ... at its own category's cut-off, not the 1 % default\n";
$r = $run('Carc. 1B', 'H350', 'GHS08', 'Danger', 0.5);
check($r['h'] === ['H350'] && $r['pic'] === ['GHS08'] && $r['sw'] === 'Danger' && $cutoffOf($r, 'Carc. 1B') === 0.1,
    '"Carc. 1B" alone at 0.5 % (0.1 % cut-off): H350, GHS08, Danger (was nothing)', $r);
check($run('Carc. 1B', 'H350', 'GHS08', 'Danger', 0.05)['classes'] === [], '... at 0.05 %: nothing');
$r = $run('STOT SE 3', 'H335', 'GHS07', 'Warning', 15.0);
check($r['classes'] === [] && $r['h'] === [] && $r['sw'] === null, '"STOT SE 3" alone at 15 % (20 % cut-off): nothing (was the class and Warning, without H335)', $r);
$r = $run('STOT SE 3', 'H335', 'GHS07', 'Warning', 20.0);
check($r['h'] === ['H335'] && $cutoffOf($r, 'STOT SE 3') === 20.0, '... at 20 %: H335, cut-off 20 %', $r);
check($run('Skin Irrit. 2', 'H315', '', 'Warning', 9.99)['classes'] === [] && $h('Skin Irrit. 2', 'H315', 10.0) === ['H315'], '"Skin Irrit. 2": from 10 %');
check($run('Eye Irrit. 2', 'H319', '', 'Warning', 9.99)['classes'] === [] && $h('Eye Irrit. 2', 'H319', 10.0) === ['H319'], '"Eye Irrit. 2" (CLP Category 2): from 10 %, as Category 2A');
check($run('Skin Corr. 1B', 'H314', '', 'Danger', 0.99)['classes'] === [] && $h('Skin Corr. 1B', 'H314', 1.0) === ['H314'], '"Skin Corr. 1B": from 1 %');
check($run('Asp. Tox. 1', 'H304', '', 'Danger', 9.99)['classes'] === [] && $h('Asp. Tox. 1', 'H304', 10.0) === ['H304'], '"Asp. Tox. 1": from 10 %');
check($run('Acute Tox. 4', 'H302', '', 'Warning', 0.5)['classes'] === [] && $h('Acute Tox. 4', 'H302', 1.0) === ['H302'], '"Acute Tox. 4": from 1 %');
check($h('Acute Tox. 2', 'H300', 0.1) === ['H300'], '"Acute Tox. 2": from 0.1 %');
check($h('Carcinogenicity (Category 2)', 'H351', 0.5) === ['H351'], '"Carcinogenicity (Category 2)" at 0.5 %: H351 (0.1 %)');
$r = $run('STOT SE', 'H335', 'GHS07', 'Warning', 15.0);
check($r['h'] === ['H335'] && $cutoffOf($r, 'STOT SE') === 1.0, 'a name the alias table maps, without a category ("STOT SE"), keeps the class\'s lowest cut-off at 15 %, as before (one with a category: CpdFreeTextMappedCutoffTest, v1.13)', $r);

echo "\nc. Acute toxicity routes\n";
check($h('Acute Tox. 3', 'H301, H311, H331') === ['H301', 'H311', 'H331'], '"Acute Tox. 3" (no route): every route\'s codes');
check($h('Acute Toxicity, Eye Irritation', 'H302, H331, H319') === ['H302', 'H319', 'H331'], '"Acute Toxicity, Eye Irritation" (H302, H331, H319): H331 kept (was H302 + H319)');
check($h('Acute Tox.', 'H302, H312, H332') === ['H302', 'H312', 'H332'], '"Acute Tox.": H312 + H332 kept');
check($run('Acute Toxicity', 'H331', 'GHS06')['pic'] === ['GHS06'], '... and its pictogram');
check($h('Acute Tox. 4 Inhalation', 'H302, H332') === ['H332'], '"Acute Tox. 4 Inhalation": the inhalation code only');
check($h('Acute Tox. 3 (Oral/Dermal)', 'H301, H311, H331') === ['H301', 'H311'], '"Acute Tox. 3 (Oral/Dermal)": oral and dermal');
check($h('Acute Toxicity Cat 4 (Inhalation)', 'H302, H332') === ['H302', 'H332'], '"Acute Toxicity Cat 4 (Inhalation)" (aliased to oral): H332 kept too');
check($h('Acute Toxicity (Dermal)', 'H311, H331') === ['H311'], '"Acute Toxicity (Dermal)": dermal only, as before');
check($h('Dermal toxicity', 'H311, H331') === ['H311'], '"Dermal toxicity": dermal only, as before');

echo "\nd. The entry keeps its name; Flammable Liquids stays ignored\n";
$r = $run('Carc. 1B', 'H350');
$e = $r['classes'][0] ?? [];
check(count($r['classes']) === 1 && $e['class'] === 'Carc. 1B' && $e['canonical'] === null && $e['category'] === '' && $e['category_canonical'] === '',
    '"Carc. 1B": entry class "Carc. 1B", no canonical class, no category (Section 2 line as before)', $e);
$r = $run('Flam. Liq. 2, Carc. 1B', 'H225, H350', 'GHS02, GHS08', 'Danger', 30.0, 'P210, P201');
check($r['h'] === ['H350'] && $r['pic'] === ['GHS08'] && $r['p'] === ['P201'] && array_column($r['classes'], 'class') === ['Carc. 1B'],
    '"Flam. Liq. 2, Carc. 1B": H225, GHS02 and P210 dropped (Q3), H350 kept', $r);
$r = $run('Flammable Liquids Cat 2', 'H225', 'GHS02');
check($r['classes'] === [] && $r['h'] === [], '"Flammable Liquids Cat 2" alone: nothing, as before', $r);
foreach (['Flam. Liq. 2', 'Flam. Liq. 3', 'Flammable Liquids - Category 2', 'Flammable Liquids (Category 2)', 'Liquides inflammables'] as $fl) {
    check($run($fl, 'H225, H226', 'GHS02', 'Danger', 50.0)['classes'] === [], "\"{$fl}\" stays ignored");
}

echo "\ne. Signal word\n";
check($run('Eye Irritation, Acute Tox. 3', 'H301, H319', '', 'Warning')['sw'] === 'Danger', 'no Flammable Liquids class, stored Warning, H301 kept only now: Danger (its floor)');
check($run('Eye Irritation, Skin Sens. 1', 'H319, H317', '', '')['sw'] === 'Warning', 'nothing stored, H317 of "Skin Sens. 1" kept only now: Warning');
check($run('Aquatic Chronic 2', 'H411', '', '')['sw'] === null, 'nothing stored, H411 (no word): none');
check($run('Eye Irritation, Acute Tox. 4', 'H302, H319', '', 'Danger')['sw'] === 'Danger', 'a stored word above the floor stands (only ever raised)');
check($run('Serious Eye Damage, Skin Sens. 1', 'H318, H317', '', 'Warning')['sw'] === 'Warning', 'a code that printed before ("Serious Eye Damage" H318) does not change the stored word, as before');
check($run('Acute Toxicity', 'H302, H331', '', 'Warning')['sw'] === 'Danger', 'route-less "Acute Toxicity", stored Warning, H331 kept only now: Danger');
check($run('Flammable Liquids, Eye Irritation, Acute Tox. 3', 'H225, H301, H319')['sw'] === 'Danger', 'with Flammable Liquids (v1.11 re-derivation): "Acute Tox. 3" next to Eye Irritation: Danger, as before');
check($run('Flammable Liquids, Eye Irrit. 2', 'H225, H319', '', 'Danger')['sw'] === 'Danger', '"Flammable Liquids, Eye Irrit. 2", stored Danger: Danger, as before (raise-only rule)');
check($run('Flammable Liquids, Reproductive Toxicity, Lact.', 'H225, H362', '', 'Danger', 0.5)['sw'] === null, '"Lact." now counting at 0.5 % (under 1 %) gives only the word its name needs: none, as before');
check($run('Flammable Liquids, Skin Sensitization, Carc. 1B', 'H225, H317, H350', '', 'Danger', 0.5)['sw'] === 'Danger', '"Carc. 1B" at 0.5 % next to Skin Sensitization: Danger, as before');

echo "\nf. Below its cut-off it feeds the summation, as a ticked category does\n";
$run('STOT SE 3', 'H335', '', 'Warning', 15.0);
$buf = $sumBuf->getValue($engine);
check(array_column($buf[G::STOT_SINGLE]['Cat 3'] ?? [], 'conc') === [15.0], '"STOT SE 3" at 15 %: STOT SE Category 3, 15 %', $buf);
$run('Eye Irrit. 2', 'H319', '', 'Warning', 5.0);
check(array_keys($sumBuf->getValue($engine)[G::EYE_DAMAGE_IRRITATION] ?? []) === ['Cat 2A'], '"Eye Irrit. 2" at 5 %: Category 2A', $sumBuf->getValue($engine));
$run('Skin Corr. 1B', 'H314', '', 'Danger', 0.5);
check(array_keys($sumBuf->getValue($engine)[G::SKIN_CORROSION_IRRITATION] ?? []) === ['Cat 1'], '"Skin Corr. 1B" at 0.5 %: Category 1', $sumBuf->getValue($engine));
$run('Eye Irrit. 2B', 'H320', '', 'Warning', 5.0);
check($sumBuf->getValue($engine) === [], '"Eye Irrit. 2B" (no summation rule, as a ticked 2B): nothing');
$run('STOT SE 3', 'H335', '', 'Warning', 25.0);
check($sumBuf->getValue($engine) === [], '"STOT SE 3" at 25 % (classified directly): not fed');
$run('STOT SE', 'H335', '', 'Warning', 0.5);
check($sumBuf->getValue($engine) === [], 'a name the alias table maps, without a category: not fed, as before');
// Two components at 12 % each classify the mixture.
$mix = new HazardEngine();
foreach (['50-00-0', '64-17-5'] as $mixCas) {
    $parse->invoke($mix, ['selected_hazards' => '[]', 'hazard_classes' => 'STOT SE 3', 'h_statements' => 'H335', 'signal_word' => 'Warning'],
        $mixCas, 'Component', 12.0, 'CAS determination');
}
$apply = new ReflectionMethod(HazardEngine::class, 'applySummationRules');
$apply->setAccessible(true);
$mc = $mh = $mp = $mpic = $mcas = [];
$msw = null;
$apply->invokeArgs($mix, [&$mc, &$mh, &$mp, &$mpic, &$msw, &$mcas]);
check(count($mc) === 1 && $mc[0]['canonical'] === G::STOT_SINGLE && $mc[0]['category_canonical'] === 'Cat 3' && isset($mh['H335']) && $msw === 'Warning'
    && array_keys($mcas) === ['50-00-0', '64-17-5'],
    'two components with "STOT SE 3" at 12 %: the mixture is STOT SE Category 3 (H335, Warning) by summation', ['classes' => $mc, 'h' => array_keys($mh), 'sw' => $msw]);

echo "\ng. Helpers\n";
$routes = new ReflectionMethod(HazardEngine::class, 'freeTextAcuteRoutes');
$routes->setAccessible(true);
$all = [G::ACUTE_TOXICITY_ORAL, G::ACUTE_TOXICITY_DERMAL, G::ACUTE_TOXICITY_INHALATION];
foreach ([
    ['Acute Toxicity', $all], ['Acute Tox.', $all], ['Acute Tox. 3', $all], ['acute toxicity category 4', $all], ['Acute Tox. 2 (Dust/Mist)', $all],
    ['Acute Tox. 4 Inhalation', [G::ACUTE_TOXICITY_INHALATION]], ['Acute Toxicity Cat 4 (Inhalation)', [G::ACUTE_TOXICITY_INHALATION]],
    ['Acute Toxicity (Dermal)', [G::ACUTE_TOXICITY_DERMAL]], ['Acute Tox. Oral', [G::ACUTE_TOXICITY_ORAL]],
    ['Acute Tox. 3 (Oral/Dermal)', [G::ACUTE_TOXICITY_ORAL, G::ACUTE_TOXICITY_DERMAL]],
    ['Dermal toxicity', null], ['Inhalation toxicity', null], ['Akute Toxizität (inhalativ)', null], ['Acute Toxicant', null],
] as [$class, $want]) {
    $got = $routes->invoke(null, $class);
    check($got === $want, sprintf('freeTextAcuteRoutes("%s")', $class), $got);
}
$sumCat = new ReflectionMethod(HazardEngine::class, 'freeTextSummationCategory');
$sumCat->setAccessible(true);
foreach ([
    [G::STOT_SINGLE, 'Cat 3', 'Cat 3'], [G::EYE_DAMAGE_IRRITATION, 'Cat 2', 'Cat 2A'], [G::EYE_DAMAGE_IRRITATION, 'Cat 2A', 'Cat 2A'],
    [G::EYE_DAMAGE_IRRITATION, 'Cat 2B', ''], [G::SKIN_CORROSION_IRRITATION, 'Cat 1B', 'Cat 1'], [G::CARCINOGENICITY, 'Cat 1', 'Cat 1A'],
    [G::REPRODUCTIVE_TOXICITY, 'Lactation', 'Lactation'], [G::REPRODUCTIVE_TOXICITY, 'Cat 1B', 'Cat 1'], [G::SKIN_CORROSION_IRRITATION, 'Cat 3', ''],
    [G::ACUTE_TOXICITY_ORAL, 'Cat 3', ''], [G::AQUATIC_CHRONIC, 'Cat 2', ''], [G::SKIN_SENSITIZATION, '', ''],
] as [$c, $k, $want]) {
    $got = $sumCat->invoke(null, $c, $k);
    check($got === $want, sprintf('freeTextSummationCategory(%s, "%s") = "%s"', $c, $k, $want), $got);
}

echo "\nh. Version\n";
check(preg_match('/^v(\d+\.\d+)/', HazardEngine::ENGINE_VERSION, $vm) === 1 && version_compare($vm[1], '1.12', '>=') && strlen(HazardEngine::ENGINE_VERSION) <= 30,
    'engine version v1.12 (free-text class codes) or later', HazardEngine::ENGINE_VERSION);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
