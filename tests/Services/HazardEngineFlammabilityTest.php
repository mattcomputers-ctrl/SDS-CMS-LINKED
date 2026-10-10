#!/usr/bin/env php
<?php
/**
 * HazardEngine Q3 flammability unit test (work unit T1b, DB-free).
 *
 * Owner decision Q3 (follow-up): the mixture Flammable Liquids category is
 * derived from the product flash point (formula_props, Q1 wt%-weighted) and
 * initial boiling point; ingredient H224-H227 / Flammable Liquids rows never
 * classify the mixture (audit #9, #5, #10, #44(1)).
 *
 *   - flammableLiquidCategory(): the GHS Rev. 7 Table 2.6.1 / HazCom App. B.6
 *     bounds, "> n" read as just above n, Solid / Powder / Paste never.
 *   - flammabilityFromProps(): the result's 'flammability' block.
 *   - applyFlammableLiquidsFromFlashPoint() (Reflection): MIXTURE class entry,
 *     H/P-codes, GHS02, signal word, contributors; stray ingredient H22x and
 *     GHS02 stripped.
 *   - parseDeterminationStructure() (Reflection): CPD / trade-secret Flammable
 *     Liquids entries ignored; FL P-codes and signal word not left behind.
 *
 * Same App-reflection bootstrap as HazardEngineAteResultsTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/HazardEngineFlammabilityTest.php
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

use SDS\Services\GHSHazardClass;
use SDS\Services\HazardEngine as HE;

$FL = GHSHazardClass::FLAMMABLE_LIQUIDS;

// ---------------------------------------------------------------------
echo "1. flammableLiquidCategory()\n";
$rows = [
    // [fp, gt, ibp, state, expected, label]
    [12.0, false, 80.0, 'Liquid', 2, 'fp 12 / bp 80 → Cat 2'],
    [12.0, false, 30.0, 'Liquid', 1, 'fp 12 / bp 30 → Cat 1'],
    [12.0, false, 35.0, 'Liquid', 1, 'fp 12 / bp 35 → Cat 1 (<= 35)'],
    [12.0, false, null, 'Liquid', 2, 'fp 12 / bp unknown → Cat 2'],
    [22.9, false, 80.0, 'Liquid', 2, 'fp 22.9 → Cat 2'],
    [23.0, false, 80.0, 'Liquid', 3, 'fp 23 → Cat 3'],
    [60.0, false, 80.0, 'Liquid', 3, 'fp 60 → Cat 3 (#44(1))'],
    [60.1, false, 80.0, 'Liquid', 4, 'fp 60.1 → Cat 4'],
    [93.0, false, 80.0, 'Liquid', 4, 'fp 93 → Cat 4'],
    [93.1, false, 80.0, 'Liquid', 0, 'fp 93.1 → not classified'],
    [null, false, 80.0, 'Liquid', 0, 'no flash point → not classified (Q2)'],
    [12.0, false, 80.0, 'Paste', 0, 'Paste → never a flammable liquid'],
    [12.0, false, 80.0, 'Solid', 0, 'Solid → never'],
    [12.0, false, 80.0, 'powder', 0, 'powder (lower case) → never'],
    [12.0, false, 80.0, 'liquid', 2, 'liquid (lower case) → classified'],
    [12.0, false, 80.0, 'Gel', 2, 'Gel → classified'],
    [12.0, false, 80.0, '', 2, 'blank state → classified'],
    [20.0, true, 80.0, 'Liquid', 2, '"> 20" → Cat 2'],
    [59.0, true, 80.0, 'Liquid', 3, '"> 59" → Cat 3'],
    [60.0, true, 80.0, 'Liquid', 4, '"> 60" → Cat 4 (just above 60)'],
    [92.5, true, 80.0, 'Liquid', 4, '"> 92.5" → Cat 4'],
    [93.0, true, 80.0, 'Liquid', 0, '"> 93" → not classified'],
    [100.0, true, 80.0, 'Liquid', 0, '"> 100" (water) → not classified'],
];
foreach ($rows as [$fp, $gt, $ibp, $state, $exp, $label]) {
    $got = HE::flammableLiquidCategory($fp, $gt, $ibp, $state);
    check($got === $exp, $label, $got);
}

// ---------------------------------------------------------------------
echo "2. flammabilityFromProps()\n";
$f = HE::flammabilityFromProps(null);
check($f['basis'] === HE::FLAMMABILITY_NOT_EVALUATED && $f['category'] === 0, 'null → not_evaluated, category 0', $f);
$f = HE::flammabilityFromProps(['flash_point_c' => null, 'physical_state' => 'Liquid']);
check($f['basis'] === HE::FLAMMABILITY_NO_FLASH_POINT && $f['category'] === 0, 'fp null → no_flash_point', $f);
$f = HE::flammabilityFromProps(['flash_point_c' => 12.0, 'physical_state' => 'Paste']);
check($f['basis'] === HE::FLAMMABILITY_NOT_LIQUID && $f['category'] === 0, 'Paste → not_liquid, category 0', $f);
$f = HE::flammabilityFromProps(['flash_point_c' => '75.6', 'boiling_point_c' => '78', 'physical_state' => 'Liquid']);
check($f['flash_point_c'] === 75.6 && $f['boiling_point_c'] === 78.0 && $f['category'] === 4 && $f['basis'] === HE::FLAMMABILITY_FLASH_POINT, 'numeric strings → floats, Cat 4', $f);
$f = HE::flammabilityFromProps(['flash_point_c' => 12.0, 'boiling_point_c' => null, 'physical_state' => 'Liquid']);
check($f['category'] === 2 && $f['ibp_assumed'] === true, 'fp 12 / bp null → Cat 2, ibp_assumed', $f);
$f = HE::flammabilityFromProps(['flash_point_c' => 12.0, 'boiling_point_c' => 80.0, 'physical_state' => 'Liquid']);
check($f['ibp_assumed'] === false, 'fp 12 / bp 80 → ibp_assumed false', $f);
$f = HE::flammabilityFromProps(['flash_point_c' => null, 'flash_point_greater_than' => true]);
check($f['flash_point_greater_than'] === false, '">" flag without a flash point → false', $f);
$f = HE::flammabilityFromProps(['flash_point_c' => 95.6, 'flash_point_greater_than' => 1, 'physical_state' => 'Liquid']);
check($f['flash_point_greater_than'] === true && $f['category'] === 0, '"> 95.6" → flag kept, not classified', $f);

// ---------------------------------------------------------------------
echo "3. applyFlammableLiquidsFromFlashPoint()\n";
$engine = new HE();
$apply  = new ReflectionMethod($engine, 'applyFlammableLiquidsFromFlashPoint'); $apply->setAccessible(true);
$ingP   = new ReflectionProperty($engine, 'flammableIngredients'); $ingP->setAccessible(true);
$flamP  = new ReflectionProperty($engine, 'flammability');         $flamP->setAccessible(true);
$traceP = new ReflectionProperty($engine, 'trace');                $traceP->setAccessible(true);

$run = static function (?array $inputs, array $seed = [], array $ingredients = []) use ($engine, $apply, $ingP, $traceP): array {
    $ingP->setValue($engine, $ingredients);
    $traceP->setValue($engine, []);
    $classes = $seed['classes'] ?? [];
    $h       = $seed['h'] ?? [];
    $p       = $seed['p'] ?? [];
    $pic     = $seed['pic'] ?? [];
    $sw      = $seed['sw'] ?? null;
    $haz     = $seed['haz'] ?? [];
    $apply->invokeArgs($engine, [$inputs, &$classes, &$h, &$p, &$pic, &$sw, &$haz]);
    return ['classes' => $classes, 'h' => $h, 'p' => $p, 'pic' => $pic, 'sw' => $sw, 'haz' => $haz,
            'steps' => array_column($traceP->getValue($engine), 'step')];
};
$liquid = static fn (?float $fp, ?float $bp = 78.0, string $state = 'Liquid', bool $gt = false): array =>
    ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'boiling_point_c' => $bp, 'physical_state' => $state];

// (a) water-based ink: 30 % raw @ 12 °C + 70 % water @ 100 °C → 75.6 °C → Cat 4
$r = $run($liquid(75.6));
$cls = $r['classes'][0] ?? [];
check(count($r['classes']) === 1 && ($cls['canonical'] ?? null) === $FL, 'one class, canonical flammable_liquids', $r['classes']);
check(($cls['category_canonical'] ?? null) === 'Cat 4' && ($cls['cas'] ?? null) === 'MIXTURE' && ($cls['source'] ?? null) === 'flash_point', 'Cat 4, MIXTURE, source flash_point', $cls);
check(($cls['h_codes'] ?? null) === ['H227'], 'h_codes [H227]', $cls['h_codes'] ?? null);
check(isset($r['h']['H227']) && !isset($r['pic']['GHS02']) && $r['sw'] === 'Warning', 'H227, no GHS02, Warning', $r);
check(isset($r['p']['P210']), 'P210 from the Cat 4 defaults', array_keys($r['p']));

// (b) stray ingredient H225 + GHS02 at a non-flammable product flash point
$r = $run($liquid(100.0), ['h' => ['H225' => ['code' => 'H225', 'text' => ''], 'H319' => ['code' => 'H319', 'text' => '']], 'pic' => ['GHS02' => true, 'GHS07' => true]]);
check(!isset($r['h']['H225']) && isset($r['h']['H319']), 'H225 removed, H319 kept', array_keys($r['h']));
check(!isset($r['pic']['GHS02']) && isset($r['pic']['GHS07']), 'GHS02 removed, GHS07 kept', $r['pic']);
check($r['classes'] === [] && $r['sw'] === null, 'no FL class, signal word unchanged', $r);
check(in_array('flammable_liquid_ingredient_codes_dropped', $r['steps'], true), 'trace flammable_liquid_ingredient_codes_dropped', $r['steps']);

// (c) GHS02 kept when another flame H-code needs it (H228 flammable solid)
$r = $run($liquid(null), ['h' => ['H228' => ['code' => 'H228', 'text' => '']], 'pic' => ['GHS02' => true]]);
check(isset($r['pic']['GHS02']) && isset($r['h']['H228']), 'H228 keeps GHS02', $r);

// (d) solvent ink: fp 14.5 / bp 78 → Cat 2; contributors by concentration
$r = $run($liquid(14.5), [], ['64-17-5' => 30.0, '67-63-0' => 50.0]);
$cls = $r['classes'][0] ?? [];
check(($cls['category_canonical'] ?? null) === 'Cat 2' && isset($r['h']['H225']) && isset($r['pic']['GHS02']) && $r['sw'] === 'Danger', 'Cat 2, H225, GHS02, Danger', $r);
check(($cls['contributors'] ?? null) === ['67-63-0', '64-17-5'], 'contributors sorted by concentration', $cls['contributors'] ?? null);
check(isset($r['haz']['67-63-0']) && isset($r['haz']['64-17-5']), 'contributors marked hazardous (Section 3)', $r['haz']);

// (e) paste → no class
$r = $run($liquid(12.0, 78.0, 'Paste'));
check($r['classes'] === [] && array_intersect(array_keys($r['h']), HE::FLAMMABLE_LIQUID_H_CODES) === [], 'Paste: no class, no H22x', $r);

// (f) unmapped vendor class name removed
$r = $run($liquid(100.0), ['classes' => [
    ['class' => 'Flam. Liq. 2', 'canonical' => null, 'category_canonical' => 'Cat 2', 'cas' => '64-17-5'],
    ['class' => 'Eye Irritation', 'canonical' => GHSHazardClass::EYE_DAMAGE_IRRITATION, 'category_canonical' => 'Cat 2A', 'cas' => '64-17-5'],
]]);
check(count($r['classes']) === 1 && ($r['classes'][0]['class'] ?? null) === 'Eye Irritation', '"Flam. Liq. 2" (canonical null) removed, eye class kept', $r['classes']);

// (g) the flammability property mirrors flammabilityFromProps()
$in = $liquid(40.0, 100.0);
$r  = $run($in);
check($flamP->getValue($engine) === HE::flammabilityFromProps($in), '$flammability === flammabilityFromProps(inputs)', $flamP->getValue($engine));
check(in_array('flammable_liquids_from_flash_point', $r['steps'], true), 'trace flammable_liquids_from_flash_point', $r['steps']);
check(($r['classes'][0]['category_canonical'] ?? null) === 'Cat 3' && isset($r['h']['H226']) && $r['sw'] === 'Warning', 'fp 40 → Cat 3, H226, Warning', $r);

// (h) not evaluated (null inputs) → no class, block basis not_evaluated
$r = $run(null);
check($r['classes'] === [] && $flamP->getValue($engine)['basis'] === HE::FLAMMABILITY_NOT_EVALUATED, 'null inputs → no class, not_evaluated', $flamP->getValue($engine));

// ---------------------------------------------------------------------
echo "4. parseDeterminationStructure(): CPD / trade-secret Flammable Liquids entries ignored\n";
$parse = new ReflectionMethod($engine, 'parseDeterminationStructure'); $parse->setAccessible(true);
$reset = static function () use ($engine, $ingP, $traceP): void {
    $ingP->setValue($engine, []);
    $traceP->setValue($engine, []);
};

$reset();
$det = [
    'selected_hazards' => json_encode(['Flammable Liquids - Category 2', 'Skin Irritation - Category 2']),
    'h_statements'     => 'H225,H315',
    'p_statements'     => 'P210,P233,P264,P280',
    'pictograms'       => 'GHS02,GHS07',
    'signal_word'      => 'Danger',
];
$out = $parse->invoke($engine, $det, '64-17-5', 'Ethanol', 50.0, 'CAS determination');
check(array_column($out['hazard_classes'], 'canonical') === ['skin_corrosion_irritation'], 'only the skin irritation class triggers', array_column($out['hazard_classes'], 'canonical'));
check(array_keys($out['h_statements']) === ['H315'], 'H225 dropped, H315 kept', array_keys($out['h_statements']));
check(array_values($out['pictograms']) === ['GHS07'], 'GHS02 dropped', $out['pictograms']);
check(isset($out['p_statements']['P264']) && isset($out['p_statements']['P280']), 'P264 / P280 kept', array_keys($out['p_statements']));
check(!isset($out['p_statements']['P210']) && !isset($out['p_statements']['P233']), 'P210 / P233 dropped', array_keys($out['p_statements']));
check($out['signal_word'] === 'Warning', 'signal word re-derived: Warning', $out['signal_word']);
check(($ingP->getValue($engine)['64-17-5'] ?? null) === 50.0, 'ethanol recorded as a flammable ingredient (50 %)', $ingP->getValue($engine));
check(in_array('flammable_liquid_ingredient_not_classified', array_column($traceP->getValue($engine), 'step'), true), 'trace flammable_liquid_ingredient_not_classified');

$reset();
$det = [
    'selected_hazards' => json_encode(['Flammable Liquids - Category 3']),
    'h_statements'     => 'H226',
    'p_statements'     => 'P210',
    'pictograms'       => 'GHS02',
    'signal_word'      => 'Warning',
];
$out = $parse->invoke($engine, $det, '64742-48-9', 'Naphtha', 40.0, 'CAS determination');
check($out['hazard_classes'] === [] && $out['h_statements'] === [] && $out['p_statements'] === [] && $out['pictograms'] === [] && $out['signal_word'] === null, 'FL-only CPD contributes nothing', $out);
check(($ingP->getValue($engine)['64742-48-9'] ?? null) === 40.0, 'naphtha recorded as contributor', $ingP->getValue($engine));

$reset();
$out = $parse->invoke($engine, $det, 'TRADE_SECRET', 'Trade Secret', 100.0, 'manual (trade secret)');
check($out['hazard_classes'] === [] && $ingP->getValue($engine) === [], 'trade-secret FL entry: nothing classified, never named', $ingP->getValue($engine));

$reset();
$out = $parse->invoke($engine, $det, '64742-48-9', 'Naphtha', 0.5, 'CAS determination');
check($ingP->getValue($engine) === [], 'below 1 % → not a contributor', $ingP->getValue($engine));

$reset();
$det = ['hazard_classes' => 'Flammable liquids', 'h_statements' => 'H225', 'pictograms' => 'GHS02', 'signal_word' => 'Danger'];
$out = $parse->invoke($engine, $det, '67-63-0', 'Isopropanol', 20.0, 'CAS determination');
check($out['hazard_classes'] === [] && $out['h_statements'] === [] && $out['signal_word'] === null, 'free-text "Flammable liquids" ignored', $out);
check(($ingP->getValue($engine)['67-63-0'] ?? null) === 20.0, 'free-text entry recorded as contributor', $ingP->getValue($engine));

// ---------------------------------------------------------------------
echo "5. Engine version\n";
check(str_contains(HE::ENGINE_VERSION, 'flash-point-flammability'), 'ENGINE_VERSION carries flash-point-flammability', HE::ENGINE_VERSION);
check(strlen(HE::ENGINE_VERSION) <= 30, 'ENGINE_VERSION fits sds_generation_trace.engine_version VARCHAR(30)', strlen(HE::ENGINE_VERSION));

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
