#!/usr/bin/env php
<?php
/**
 * Engine v1.13 (2026-10-29): the cut-off of a legacy free-text CAS
 * determination class the alias table maps, with its category in the name
 * — DB-free.
 *
 * "STOT SE Category 3", "Eye Irritation Cat 2A" (the old form's placeholder
 * style) and "Skin Sensitization Cat 1" are mapped to their class by
 * HazardClassAliases, which drops the category, so they applied at their
 * class's lowest cut-off (1 %, 1 %, 0.1 %) whatever category they named.
 * Now, like the v1.12 short names, they apply at their category's cut-off
 * (20 %, 10 %, 1 %) and below it feed the summation as a ticked category
 * does. A name without a category ("Eye Irritation") keeps its class's
 * lowest cut-off. The entry, the codes it keeps and the signal word rules
 * are as in v1.12.
 *
 * Run: php tests/Services/CpdFreeTextMappedCutoffTest.php
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
$run = static function (string $classes, string $hCodes, string $stored = 'Warning', float $conc = 30.0) use (&$engine, $parse): array {
    $engine = new HazardEngine();
    $out = $parse->invoke($engine, [
        'selected_hazards' => '[]', 'hazard_classes' => $classes, 'h_statements' => $hCodes,
        'p_statements' => '', 'pictograms' => '', 'signal_word' => $stored,
    ], '50-00-0', 'Test substance', $conc, 'CAS determination');
    $h = array_keys($out['h_statements']);
    sort($h);
    return ['h' => $h, 'sw' => $out['signal_word'], 'classes' => $out['hazard_classes']];
};
$h = static fn (string $classes, string $hCodes, float $conc): array => $run($classes, $hCodes, 'Warning', $conc)['h'];
$none = static fn (string $classes, string $hCodes, float $conc): bool => $run($classes, $hCodes, 'Warning', $conc)['classes'] === [];

echo "a. A mapped name with its category applies at that category's cut-off\n";
check($none('STOT SE Category 3', 'H335', 15.0) && $h('STOT SE Category 3', 'H335', 20.0) === ['H335'], '"STOT SE Category 3": from 20 % (was 1 %)');
check($none('STOT — Single Exposure Category 3 (Respiratory Irritation)', 'H335', 15.0), '"STOT — Single Exposure Category 3 (Respiratory Irritation)": not at 15 %');
check($none('Eye Irritation Cat 2A', 'H319', 9.99) && $h('Eye Irritation Cat 2A', 'H319', 10.0) === ['H319'], '"Eye Irritation Cat 2A": from 10 % (was 1 %)');
check($none('Eye Irritation Category 2', 'H319', 5.0), '"Eye Irritation Category 2": not at 5 % (10 %)');
check($none('Skin Sensitization Cat 1', 'H317', 0.5) && $h('Skin Sensitization Cat 1', 'H317', 1.0) === ['H317'], '"Skin Sensitization Cat 1": from 1 % (was 0.1 %)');
check($h('Skin Sensitization Cat 1A', 'H317', 0.5) === ['H317'], '"Skin Sensitization Cat 1A": from 0.1 %');
check($none('Skin Corrosion/Irritation Cat 2', 'H315', 5.0) && $h('Skin Corrosion/Irritation Cat 2', 'H315', 10.0) === ['H315'], '"Skin Corrosion/Irritation Cat 2": from 10 %');
check($none('Acute Toxicity Cat 4', 'H302, H332', 0.5) && $h('Acute Toxicity Cat 4', 'H302, H332', 1.0) === ['H302', 'H332'], '"Acute Toxicity Cat 4": from 1 % (was 0.1 %), every route\'s codes (v1.12)');
check($none('Germ Cell Mutagenicity Category 2', 'H341', 0.5), '"Germ Cell Mutagenicity Category 2": not at 0.5 % (1 %)');
check($none('Respiratory Sensitization Cat 1', 'H334', 0.5) && $h('Respiratory Sensitization Cat 1A', 'H334', 0.5) === ['H334'], '"Respiratory Sensitization Cat 1": 1 %; "... Cat 1A": 0.1 %');
check($none('STOT RE Cat 2', 'H373', 5.0), '"STOT RE Cat 2": not at 5 % (10 %)');
$r = $run('STOT SE Category 3', 'H335', 'Warning', 25.0);
$e = $r['classes'][0] ?? [];
check(count($r['classes']) === 1 && $e['class'] === 'STOT SE Category 3' && $e['canonical'] === G::STOT_SINGLE && $e['category'] === '' && $e['category_canonical'] === ''
    && (float) $e['cutoff_pct'] === 20.0,
    'its entry is as before (name, canonical class, no category), with its own cut-off', $e);

echo "\nb. Unchanged\n";
check($h('Eye Irritation', 'H319', 5.0) === ['H319'], '"Eye Irritation" (no category): from 1 %, the class\'s lowest, as before');
check($h('Skin Sensitization', 'H317', 0.5) === ['H317'], '"Skin Sensitization" (no category): from 0.1 %, as before');
check($h('Carcinogenicity Category 2', 'H351', 0.5) === ['H351'], '"Carcinogenicity Category 2": 0.1 %, as before');
check($h('Acute Toxicity (Oral) Category 3', 'H301', 0.5) === ['H301'], '"Acute Toxicity (Oral) Category 3": 0.1 %, as before');
check($h('Eye Irritation Cat 3', 'H319', 5.0) === ['H319'], '"Eye Irritation Cat 3" (a category GHS data does not have): the class\'s lowest, as before');
check($h('Aquatic Chronic Cat 2', 'H411', 5.0) === ['H411'], '"Aquatic Chronic Cat 2": 1 %, as before');
check($h('Serious Eye Damage Cat 1', 'H318', 1.0) === ['H318'], '"Serious Eye Damage Cat 1": 1 %, as before');

echo "\nc. Below its cut-off it feeds the summation\n";
$run('Eye Irritation Cat 2A', 'H319', 'Warning', 5.0);
check(array_column($sumBuf->getValue($engine)[G::EYE_DAMAGE_IRRITATION]['Cat 2A'] ?? [], 'conc') === [5.0], '"Eye Irritation Cat 2A" at 5 %: Category 2A, 5 %', $sumBuf->getValue($engine));
$run('Eye Irritation Category 2', 'H319', 'Warning', 5.0);
check(array_keys($sumBuf->getValue($engine)[G::EYE_DAMAGE_IRRITATION] ?? []) === ['Cat 2A'], '"Eye Irritation Category 2" at 5 %: Category 2A', $sumBuf->getValue($engine));
$run('Skin Sensitization Cat 1', 'H317', 'Warning', 0.5);
check(array_keys($sumBuf->getValue($engine)[G::SKIN_SENSITIZATION] ?? []) === ['Cat 1'], '"Skin Sensitization Cat 1" at 0.5 %: Category 1', $sumBuf->getValue($engine));
$run('STOT SE Category 3', 'H335', 'Warning', 25.0);
check($sumBuf->getValue($engine) === [], '"STOT SE Category 3" at 25 % (classified directly): not fed');
$run('Eye Irritation', 'H319', 'Warning', 0.5);
check($sumBuf->getValue($engine) === [], '"Eye Irritation" (no category) under its cut-off: not fed, as before');
$mix = new HazardEngine();
foreach ([['50-00-0', 'Eye Irritation Cat 2A'], ['64-17-5', 'Eye Irrit. 2']] as [$mixCas, $mixClass]) {
    $parse->invoke($mix, ['selected_hazards' => '[]', 'hazard_classes' => $mixClass, 'h_statements' => 'H319', 'signal_word' => 'Warning'],
        $mixCas, 'Component', 6.0, 'CAS determination');
}
$apply = new ReflectionMethod(HazardEngine::class, 'applySummationRules');
$apply->setAccessible(true);
$mc = $mh = $mp = $mpic = $mcas = [];
$msw = null;
$apply->invokeArgs($mix, [&$mc, &$mh, &$mp, &$mpic, &$msw, &$mcas]);
check(count($mc) === 1 && $mc[0]['canonical'] === G::EYE_DAMAGE_IRRITATION && $mc[0]['category_canonical'] === 'Cat 2A' && isset($mh['H319']) && $msw === 'Warning',
    '"Eye Irritation Cat 2A" and "Eye Irrit. 2" at 6 % each: the mixture is Eye Irritation Category 2A (H319, Warning) by summation', ['classes' => $mc, 'h' => array_keys($mh), 'sw' => $msw]);

echo "\nd. Signal word\n";
check($run('Flammable Liquids, Eye Irritation Cat 2A', 'H225, H319', 'Danger', 30.0)['sw'] === 'Warning', '"Flammable Liquids, Eye Irritation Cat 2A" at 30 %, stored Danger: Warning, as before');
$r = $run('Flammable Liquids, Eye Irritation Cat 2A', 'H225, H319', 'Danger', 5.0);
check($r['classes'] === [] && $r['h'] === [] && $r['sw'] === null, '... at 5 %: nothing (was Warning with H319)', $r);
$r = $run('Eye Irritation Cat 2A, Skin Sensitization Cat 1', 'H319, H317', 'Warning', 5.0);
check(array_column($r['classes'], 'class') === ['Skin Sensitization Cat 1'] && $r['h'] === ['H317'] && $r['sw'] === 'Warning',
    '"Eye Irritation Cat 2A, Skin Sensitization Cat 1" at 5 %: Skin Sensitization, H317, the stored Warning', $r);
$r = $run('Acute Tox. 2, Acute Toxicity Cat 4', 'H301, H302', '', 0.5);
check(array_column($r['classes'], 'class') === ['Acute Tox. 2'] && $r['h'] === ['H301', 'H302'] && $r['sw'] === 'Danger',
    '"Acute Tox. 2, Acute Toxicity Cat 4" at 0.5 %, nothing stored: "Acute Toxicity Cat 4" drops, H301 is kept only through "Acute Tox. 2" and needs Danger (v1.12 floor)', $r);

echo "\ne. Version\n";
check(HazardEngine::ENGINE_VERSION === 'v1.13-free-text-cat-cutoffs' && strlen(HazardEngine::ENGINE_VERSION) <= 30, 'engine version v1.13-free-text-cat-cutoffs', HazardEngine::ENGINE_VERSION);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
