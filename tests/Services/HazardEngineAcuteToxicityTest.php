#!/usr/bin/env php
<?php
/**
 * HazardEngine acute toxicity / ATE unit test (T2c, DB-free)
 *
 *   #23 vapour category defaults 0.05 / 0.5 / 3 / 11 mg/L (GHS Rev. 7
 *       Table 3.1.2); inhalation dust/mist route for Solid / Powder products
 *       (the physical state classify() receives in its Q3 flammability
 *       inputs) or dust-only ATE rows.
 *   #15 trade-secret declarations: 100 % only for the cut-off bypass; the
 *       ATE and aquatic buffers take the RM's real contribution
 *       (_contribution_pct); one declaration feeds each ATE route once.
 *   #66 FG replace mode clears ate_results; acute Category 5 entries and
 *       H303 / H313 / H333 are dropped (29 CFR 1910.1200 App. A.1).
 *
 * Private methods are driven through Reflection (no DB). Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/HazardEngineAcuteToxicityTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\GHSHazardClass as G;
use SDS\Services\HazardEngine;

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

$near = static fn($a, $b, float $eps = 1e-6): bool => is_numeric($a) && abs((float) $a - $b) < $eps;

$prop = static function (object $obj, string $name): ReflectionProperty {
    $p = new ReflectionProperty($obj, $name);
    $p->setAccessible(true);
    return $p;
};
$meth = static function (object $obj, string $name): ReflectionMethod {
    $m = new ReflectionMethod($obj, $name);
    $m->setAccessible(true);
    return $m;
};

$e = new HazardEngine();

$reset = function () use ($e, $prop): void {
    foreach (['ateBuffer', 'ateResults', 'aquaticBuffer', 'summationBuffer', 'trace'] as $n) {
        $prop($e, $n)->setValue($e, []);
    }
};
// The product physical state reaches the engine through classify()'s Q3
// flammability inputs; classify() seeds $flammability from them before the
// main loop. Tests set the same block directly (classify() needs the DB).
$setState = function (?string $state) use ($e, $prop): void {
    $prop($e, 'flammability')->setValue($e, HazardEngine::flammabilityFromProps($state === null ? null : ['physical_state' => $state]));
};
$runAte = function (array $classes = []) use ($e, $meth): array {
    $h = []; $p = []; $pic = []; $sw = null; $haz = [];
    $meth($e, 'applyATECalculation')->invokeArgs($e, [&$classes, &$h, &$p, &$pic, &$sw, &$haz]);
    return $classes;
};
$c = fn(string $cas, float $conc, string $cat, ?float $ate = null) => [
    'cas' => $cas, 'name' => $cas, 'conc' => $conc, 'category' => $cat,
    'ate' => $ate, 'ate_source' => $ate === null ? 'unknown' : 'vendor', 'source' => 'hazard_classification',
];

// ---------------------------------------------------------------------
echo "1. #23 category-default ATEs\n";
$defaults = (new ReflectionClassConstant(HazardEngine::class, 'CATEGORY_DEFAULT_ATES'))->getValue();
check($defaults['inhalation_vapor'] === ['Cat 1' => 0.05, 'Cat 2' => 0.5, 'Cat 3' => 3.0, 'Cat 4' => 11.0], 'vapour defaults 0.05 / 0.5 / 3 / 11', $defaults['inhalation_vapor']);
check($defaults['inhalation_dust'] === ['Cat 1' => 0.005, 'Cat 2' => 0.05, 'Cat 3' => 0.5, 'Cat 4' => 1.5], 'dust defaults unchanged', $defaults['inhalation_dust']);

$reset();
$prop($e, 'ateBuffer')->setValue($e, ['inhalation_vapor' => [$c('A', 60.0, 'Cat 4'), $c('B', 40.0, 'Cat 4')]]);
$cls = $runAte();
$res = $prop($e, 'ateResults')->getValue($e)['inhalation_vapor'] ?? null;
check($res !== null && $near($res['ate_mix'] ?? null, 11.0) && ($res['category'] ?? null) === 'Cat 4', '100 % Cat 4 vapour -> ATEmix 11 -> Cat 4 (was 10 -> Cat 3 / H331)', $res);
check(count($cls) === 1 && ($cls[0]['category_canonical'] ?? null) === 'Cat 4', 'stamped class is Cat 4', $cls);

// ---------------------------------------------------------------------
echo "2. #23 inhalation route\n";
$route = $meth($e, 'canonicalToAteRoute');
$INH = G::ACUTE_TOXICITY_INHALATION;
$setState('Liquid');
check($route->invoke($e, $INH, []) === 'inhalation_vapor', 'Liquid, no ATE data -> vapour');
check($route->invoke($e, $INH, ['ate_inhalation_dust_mg_l_4h' => '1.2']) === 'inhalation_dust', 'Liquid, dust-only ATE -> dust');
check($route->invoke($e, $INH, ['ate_inhalation_dust_mg_l_4h' => '1.2', 'ate_inhalation_vapor_mg_l_4h' => '4.0']) === 'inhalation_vapor', 'Liquid, dust + vapour ATE -> vapour');
$setState('Powder');
check($route->invoke($e, $INH, []) === 'inhalation_dust', 'Powder -> dust');
$setState('solid');
check($route->invoke($e, $INH, ['ate_inhalation_vapor_mg_l_4h' => '4.0']) === 'inhalation_dust', 'solid (any case) -> dust');
$setState('Paste');
check($route->invoke($e, $INH, []) === 'inhalation_vapor', 'Paste -> vapour');
$setState(null);
check($route->invoke($e, $INH, []) === 'inhalation_vapor', 'unknown state -> vapour');
check($route->invoke($e, G::ACUTE_TOXICITY_ORAL, []) === 'oral' && $route->invoke($e, G::ACUTE_TOXICITY_DERMAL, []) === 'dermal', 'oral / dermal routes');
check($route->invoke($e, G::SKIN_CORROSION_IRRITATION, []) === null, 'non-acute class -> null');

$src = (string) file_get_contents($basePath . '/src/Services/HazardEngine.php');
$seedPos = strpos($src, '$this->flammability = self::flammabilityFromProps($flammabilityInputs);');
$loopPos = strpos($src, 'foreach ($composition as $component) {');
check($seedPos !== false && $loopPos !== false && $seedPos < $loopPos, 'classify() seeds the physical state from its flammability inputs before the main loop');

$setState('Powder');
$reset();
$prop($e, 'ateBuffer')->setValue($e, ['inhalation_dust' => [$c('P', 60.0, 'Cat 4')]]);
$runAte([['canonical' => $INH, 'category_canonical' => 'Cat 4', 'class' => 'Acute Toxicity (Inhalation)', 'category' => 'Category 4']]);
$res = $prop($e, 'ateResults')->getValue($e)['inhalation_dust'] ?? null;
check($res !== null && $near($res['ate_mix'] ?? null, 2.5) && ($res['category'] ?? null) === 'Cat 4'
    && ($res['outcome'] ?? null) === 'already_classified' && ($res['unit'] ?? null) === 'mg/L/4h', 'Powder 60 % Cat 4 -> dust ATEmix 2.5, Cat 4, already_classified', $res);

// ---------------------------------------------------------------------
echo "3. #15 trade-secret ATE / aquatic at the real contribution\n";
$pds = $meth($e, 'parseDeterminationStructure');
$setState('Liquid');
$reset();
$detOral4 = ['selected_hazards' => json_encode(['Acute Toxicity Oral - Category 4']), 'h_statements' => 'H302', 'p_statements' => 'P264,P270,P301+P312,P330,P501', 'pictograms' => 'GHS07', 'signal_word' => 'Warning'];
$parsed = $pds->invoke($e, $detOral4, 'TRADE_SECRET', 'Trade Secret', 100.0, 'manual (trade secret)', 5.0);
check(count($parsed['hazard_classes']) === 1 && ($parsed['hazard_classes'][0]['canonical'] ?? null) === G::ACUTE_TOXICITY_ORAL
    && ($parsed['hazard_classes'][0]['category_canonical'] ?? null) === 'Cat 4', 'cut-off bypass kept: Oral Cat 4 class', $parsed['hazard_classes']);
$oralBuf = $prop($e, 'ateBuffer')->getValue($e)['oral'] ?? [];
check(count($oralBuf) === 1 && $near($oralBuf[0]['conc'] ?? null, 5.0), 'ATE buffer row at 5 % (not 100 %)', $oralBuf);
$runAte($parsed['hazard_classes']);
$res = $prop($e, 'ateResults')->getValue($e)['oral'] ?? null;
check($res !== null && $near($res['ate_mix'] ?? null, 10000.0) && ($res['outcome'] ?? null) === 'not_classified', 'ATEmix 10000 -> not_classified (was 500)', $res);

$reset();
$pds->invoke($e, $detOral4, '1-2-3', 'X', 2.0, 'CAS determination');
$oralBuf = $prop($e, 'ateBuffer')->getValue($e)['oral'] ?? [];
check(count($oralBuf) === 1 && $near($oralBuf[0]['conc'] ?? null, 2.0), 'legacy call (no bufferConc) uses $conc', $oralBuf);

$reset();
$pds->invoke($e, ['selected_hazards' => json_encode(['Acute Toxicity Oral - Category 1', 'Acute Toxicity Oral - Category 2']), 'h_statements' => 'H300'], 'TRADE_SECRET', 'Trade Secret', 100.0, 'manual (trade secret)', 1.0);
$oralBuf = $prop($e, 'ateBuffer')->getValue($e)['oral'] ?? [];
check(count($oralBuf) === 1 && ($oralBuf[0]['category'] ?? null) === 'Cat 1' && $near($oralBuf[0]['conc'] ?? null, 1.0), 'H300 JSON (Cat 1 + Cat 2) feeds the oral ATE once, at Cat 1', $oralBuf);

$reset();
$pds->invoke($e, ['selected_hazards' => json_encode(['Aquatic Chronic - Category 1', 'Aquatic Acute - Category 1']), 'h_statements' => 'H400,H410', 'pictograms' => 'GHS09', 'signal_word' => 'Warning'], 'TRADE_SECRET', 'Trade Secret', 100.0, 'manual (trade secret)', 5.0);
$aq = $prop($e, 'aquaticBuffer')->getValue($e);
check($near($aq['chronic'][0]['conc'] ?? null, 5.0) && $near($aq['acute'][0]['conc'] ?? null, 5.0), 'aquatic buffer rows at 5 %', $aq);
$acls = []; $ah = []; $ap = []; $apic = []; $asw = null; $ahaz = [];
$meth($e, 'applyAquaticSummation')->invokeArgs($e, [&$acls, &$ah, &$ap, &$apic, &$asw, &$ahaz]);
$aqKeys = array_map(fn($hc) => ($hc['canonical'] ?? '') . ' ' . ($hc['category_canonical'] ?? ''), $acls);
check($aqKeys === [G::AQUATIC_CHRONIC . ' Cat 2'], '5 % Chronic 1 + Acute 1 (M = 1) -> Aquatic Chronic 2 only (was Chronic 1 + Acute 1)', $aqKeys);

$tsp = new ReflectionMethod(HazardEngine::class, 'tradeSecretContributionPct');
$tsp->setAccessible(true);
check($tsp->invoke(null, ['_contribution_pct' => 2.5], ['concentration_pct' => 7.0]) === 2.5, 'stamped contribution used');
check($tsp->invoke(null, ['h_statements' => 'H302'], ['concentration_pct' => 7.0]) === 7.0, 'no stamp -> TRADE_SECRET bucket %');
check($tsp->invoke(null, ['_contribution_pct' => 0], ['concentration_pct' => 7.0]) === 7.0, 'zero stamp -> bucket %');
check($tsp->invoke(null, ['_contribution_pct' => 'x'], ['concentration_pct' => 7.0]) === 7.0, 'non-numeric stamp -> bucket %');

// ---------------------------------------------------------------------
echo "4. #66 FG replace mode clears ate_results\n";
$fgo = $meth($e, 'applyFinishedGoodOverride');
$ateSeed = ['oral' => ['route' => 'oral', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'ate_mix' => 1666.7, 'category' => 'Cat 4', 'outcome' => 'already_classified']];
$fgHazards = ['hazard_classes' => [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4']]];
$reset();
$prop($e, 'ateResults')->setValue($e, $ateSeed);
$cls = []; $h = []; $p = []; $pic = []; $sw = null;
$fgo->invokeArgs($e, [['mode' => 'replace', 'hazards' => $fgHazards], &$cls, &$h, &$p, &$pic, &$sw]);
check($prop($e, 'ateResults')->getValue($e) === [], 'replace -> ate_results cleared', $prop($e, 'ateResults')->getValue($e));
check(count($cls) === 1 && ($cls[0]['source'] ?? null) === 'fg_override', 'replace -> one fg_override class', $cls);
$prop($e, 'ateResults')->setValue($e, $ateSeed);
$cls = []; $h = []; $p = []; $pic = []; $sw = null;
$fgo->invokeArgs($e, [['mode' => 'additive', 'hazards' => $fgHazards], &$cls, &$h, &$p, &$pic, &$sw]);
check(count($prop($e, 'ateResults')->getValue($e)) === 1, 'additive -> ate_results kept');

// ---------------------------------------------------------------------
echo "5. #66 acute Category 5 dropped\n";
$drop = $meth($e, 'dropUnadoptedAcuteCategories');
$oral5 = ['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 5', 'canonical' => G::ACUTE_TOXICITY_ORAL, 'category_canonical' => 'Cat 5', 'cas' => '1-1-1'];
$eye2a = ['class' => 'Serious Eye Damage/Eye Irritation', 'category' => 'Category 2A', 'canonical' => G::EYE_DAMAGE_IRRITATION, 'category_canonical' => 'Cat 2A', 'cas' => '2-2-2'];
$st = fn(array $codes): array => array_combine($codes, array_map(fn($c) => ['code' => $c, 'text' => ''], $codes));

// (a) Cat 5 only
$reset();
$cls = [$oral5]; $h = $st(['H303']); $p = $st(['P312']); $sw = 'Warning'; $haz = ['1-1-1' => true];
$drop->invokeArgs($e, [&$cls, &$h, &$p, &$sw, &$haz, false]);
check($cls === [] && $h === [] && $p === [] && $sw === null && $haz === [], '(a) Cat 5 only -> nothing left, no signal word', [$cls, $h, $p, $sw, $haz]);

// (b) Cat 5 + Eye 2A
$cls = [$oral5, $eye2a]; $h = $st(['H303+H313', 'H313', 'H319']); $p = $st(['P312', 'P280', 'P305+P351+P338']); $sw = 'Warning'; $haz = ['1-1-1' => true, '2-2-2' => true];
$drop->invokeArgs($e, [&$cls, &$h, &$p, &$sw, &$haz, false]);
check(count($cls) === 1 && ($cls[0]['canonical'] ?? null) === G::EYE_DAMAGE_IRRITATION, '(b) eye class kept', $cls);
check(array_keys($h) === ['H319'], '(b) H303+H313 / H313 dropped, H319 kept', array_keys($h));
check(!isset($p['P312']) && isset($p['P280']), '(b) P312 removed, P280 kept', array_keys($p));
check($sw === 'Warning', '(b) Warning stays (Eye 2A carries it)', $sw);
check(array_keys($haz) === ['2-2-2'], '(b) Cat 5-only CAS leaves hazardous_cas', $haz);

// (c) Cat 5 + Dermal Cat 4 keeps P312
$cls = [$oral5, ['class' => 'Acute Toxicity (Dermal)', 'category' => 'Category 4', 'canonical' => G::ACUTE_TOXICITY_DERMAL, 'category_canonical' => 'Cat 4', 'cas' => '3-3-3']];
$h = $st(['H303', 'H312']); $p = $st(['P312', 'P280']); $sw = 'Warning'; $haz = ['1-1-1' => true, '3-3-3' => true];
$drop->invokeArgs($e, [&$cls, &$h, &$p, &$sw, &$haz, false]);
check(isset($p['P312']) && array_keys($h) === ['H312'] && $sw === 'Warning', '(c) P312 kept (Dermal Cat 4 default)', [array_keys($p), array_keys($h), $sw]);

// (d) Cat 5 + Aquatic Chronic 3: Warning cleared unless replace mode
$aqc3 = ['class' => 'Hazardous to the Aquatic Environment (Chronic)', 'category' => 'Category 3', 'canonical' => G::AQUATIC_CHRONIC, 'category_canonical' => 'Cat 3', 'cas' => '4-4-4'];
foreach ([false => null, true => 'Warning'] as $replace => $expected) {
    $cls = [$oral5, $aqc3]; $h = $st(['H303', 'H412']); $p = $st(['P312', 'P273']); $sw = 'Warning'; $haz = ['1-1-1' => true, '4-4-4' => true];
    $drop->invokeArgs($e, [&$cls, &$h, &$p, &$sw, &$haz, (bool) $replace]);
    check($sw === $expected && count($cls) === 1 && array_keys($h) === ['H412'], '(d) replace=' . ($replace ? 'true' : 'false') . ' -> signal word ' . var_export($expected, true), [$sw, $cls]);
}

// (e) nothing Cat 5 -> unchanged, no trace step
$reset();
$cls = [$eye2a]; $h = $st(['H319']); $p = $st(['P280', 'P312']); $sw = 'Warning'; $haz = ['2-2-2' => true];
$before = [$cls, $h, $p, $sw, $haz];
$drop->invokeArgs($e, [&$cls, &$h, &$p, &$sw, &$haz, false]);
check([$cls, $h, $p, $sw, $haz] === $before, '(e) no Cat 5 -> every array unchanged', [$cls, $h, $p, $sw, $haz]);
check($prop($e, 'trace')->getValue($e) === [], '(e) nothing traced', $prop($e, 'trace')->getValue($e));

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
