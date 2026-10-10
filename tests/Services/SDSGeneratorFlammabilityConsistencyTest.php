#!/usr/bin/env php
<?php
/**
 * DB-free end-to-end check of owner decision Q3 (work unit T1b): ONE
 * flammability source. HazardEngine derives the Flammable Liquids category
 * from the product flash point / initial boiling point / physical state; the
 * hazard result built from that engine output then drives Sections 5, 7, 10,
 * 13 (D001) and 14 (Class 3, PG, combustible note), which must all agree with
 * Section 2 (audit #9, #5, #10, #44(1)).
 *
 * The hazard result is produced by the engine's own
 * applyFlammableLiquidsFromFlashPoint() (Reflection) — the real output shape
 * (canonical 'flammable_liquids', MIXTURE entry, 'flammability' block) — not
 * a hand-built list of codes.
 *
 * Same App-reflection bootstrap as SDSGeneratorSection14Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorFlammabilityConsistencyTest.php
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
use SDS\Services\HazardEngine;

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);

$m = static function (object $o, string $name): ReflectionMethod {
    $r = new ReflectionMethod($o, $name);
    $r->setAccessible(true);
    return $r;
};

$engine = new HazardEngine();
$apply  = $m($engine, 'applyFlammableLiquidsFromFlashPoint');
$flamP  = new ReflectionProperty($engine, 'flammability');         $flamP->setAccessible(true);
$ingP   = new ReflectionProperty($engine, 'flammableIngredients'); $ingP->setAccessible(true);

/**
 * Engine-built hazard result for a product flash point / IBP / state.
 * $seedH: codes that reached the buffers from ingredients (e.g. a leaked H225).
 */
$hazard = static function (?float $fp, bool $gt, ?float $ibp, string $state = 'Liquid', array $seedH = [], array $seedPic = [])
    use ($engine, $apply, $flamP, $ingP): array {
    $ingP->setValue($engine, []);
    $classes = [];
    $h       = [];
    foreach ($seedH as $code) {
        $h[$code] = ['code' => $code, 'text' => ''];
    }
    $p   = [];
    $pic = [];
    foreach ($seedPic as $pc) {
        $pic[$pc] = true;
    }
    $sw  = null;
    $haz = [];
    $in  = ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'boiling_point_c' => $ibp, 'physical_state' => $state];
    $apply->invokeArgs($engine, [$in, &$classes, &$h, &$p, &$pic, &$sw, &$haz]);
    return [
        'hazard_classes'  => $classes,
        'h_statements'    => array_values($h),
        'p_statements'    => array_values($p),
        'pictograms'      => array_keys($pic),
        'signal_word'     => $sw,
        'exposure_limits' => [],
        'flammability'    => $flamP->getValue($engine),
    ];
};

$calc = static fn (?float $fp, bool $gt = false, ?float $ibp = null, string $state = 'Liquid'): array => [
    'formula'       => ['lines' => []],
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'boiling_point_c' => $ibp, 'physical_state' => $state, 'enriched_lines' => []],
    'voc'           => ['total_voc_wt_pct' => 0, 'mixture_sg' => 1.0, 'voc_lb_per_gal' => 0, 'voc_lb_per_gal_less_water_exempt' => 0, 'solids_wt_pct' => 0, 'solids_vol_pct' => null],
    'composition'   => [], 'warnings' => [],
];

$fgFor = static fn (string $state = 'Liquid'): array =>
    ['description' => 'PROCESS INK', 'family' => null, 'physical_state' => $state, 'transport_product_type' => null];

$s5   = $m($gen, 'section5');
$s7   = $m($gen, 'section7');
$s10  = $m($gen, 'section10');
$s13  = $m($gen, 'section13');
$s14  = $m($gen, 'section14');
$vet  = $m($gen, 'vetFlashPointEdit');
$warn = $m($gen, 'flammabilityWarnings');
$rcra = ['components' => [], 'has_matches' => false];

$codes = static fn (array $hr): array => array_column($hr['h_statements'], 'code');
$flCls = static fn (array $hr): ?array => array_values(array_filter($hr['hazard_classes'], static fn ($c) => ($c['canonical'] ?? null) === GHSHazardClass::FLAMMABLE_LIQUIDS))[0] ?? null;

/** Every section for one product. */
$sheet = static function (array $hr, array $c, array $fg) use ($gen, $s5, $s7, $s10, $s13, $s14, $rcra): array {
    return [
        5  => $s5->invoke($gen, $c, $hr, []),
        7  => $s7->invoke($gen, $hr, []),
        10 => $s10->invoke($gen, $hr, [], false, []),
        13 => (string) $s13->invoke($gen, $hr, $c, [], $rcra)['rcra_classification'],
        14 => $s14->invoke($gen, $fg, $c, $hr, []),
    ];
};

$noteCarrier = $t->get('section14.note');
$noteComb    = $t->get('section14.note_combustible');
$noteVisc    = $t->get('section14.note_viscous');

// ---------------------------------------------------------------------
echo "A. Water-based ink: 30 % raw @ 12 °C + 70 % water @ 100 °C → 75.6 °C → Cat 4 (H227)\n";
$hr = $hazard(75.6, false, 78.0);
$c  = $calc(75.6, false, 78.0);
$s  = $sheet($hr, $c, $fgFor());
$cl = $flCls($hr);
check(($cl['category_canonical'] ?? null) === 'Cat 4' && $codes($hr) === ['H227'], 'Section 2: Flam. Liq. 4, H227 only', [$cl, $codes($hr)]);
check($hr['pictograms'] === [] && $hr['signal_word'] === 'Warning', 'no pictogram, Warning', $hr);
check(str_starts_with($s[5]['specific_hazards'], $t->get('section5.specific_hazards_flammable_cat4')) && str_contains($s[5]['specific_hazards'], 'Flash point: '), 'S5: Cat 4 sentence + flash point', $s[5]['specific_hazards']);
check(str_contains($s[7]['handling'], $t->get('section7.handling_ignition')) && !str_contains($s[7]['handling'], $t->get('section7.handling_flammable_static')), 'S7: ignition, no static / explosion-proof sentence', $s[7]['handling']);
check(str_contains($s[10]['conditions_avoid'], $t->get('section10.cond_ignition')), 'S10: ignition sources in conditions to avoid', $s[10]['conditions_avoid']);
check(!str_contains($s[13], 'D001'), 'S13: no D001', $s[13]);
check($s[14]['status'] === 'not_regulated' && str_starts_with($s[14]['note'], $noteComb), 'S14: not regulated + combustible note', $s[14]);

// ---------------------------------------------------------------------
echo "B. fp 60.0 °C, bp 100 → Cat 3: Class 3 PG III (#44(1)), no D001 (#10)\n";
$hr = $hazard(60.0, false, 100.0);
$c  = $calc(60.0, false, 100.0);
$s  = $sheet($hr, $c, $fgFor());
check($codes($hr) === ['H226'], 'H226', $codes($hr));
check($s[14]['un_number'] === 'UN1210' && $s[14]['hazard_class'] === '3' && $s[14]['packing_group'] === 'III', 'S14 UN1210 / 3 / III', $s[14]);
check(!str_contains($s[13], 'D001'), 'S13: no D001 at exactly 60 °C', $s[13]);

// ---------------------------------------------------------------------
echo "C. fp 40 °C → Cat 3: D001 below 60, PG III\n";
$hrC = $hazard(40.0, false, 100.0);
$cC  = $calc(40.0, false, 100.0);
$s   = $sheet($hrC, $cC, $fgFor());
check($codes($hrC) === ['H226'] && in_array('GHS02', $hrC['pictograms'], true), 'H226 + GHS02', $hrC);
check(str_contains($s[13], 'flash point below 60 °C (140 °F)'), 'S13: D001 below 60', $s[13]);
check($s[14]['packing_group'] === 'III', 'S14 PG III', $s[14]['packing_group']);
check(str_contains($s[7]['handling'], $t->get('section7.handling_flammable_static')), 'S7: flammable static sentence', $s[7]['handling']);

// ---------------------------------------------------------------------
echo "D. 85 % raw @ 10 °C + 15 % raw @ 40 °C → 14.5 °C, bp 78 → Cat 2: PG II + viscous note (#5)\n";
$hr = $hazard(14.5, false, 78.0);
$c  = $calc(14.5, false, 78.0);
$s  = $sheet($hr, $c, $fgFor());
check($codes($hr) === ['H225'] && $hr['signal_word'] === 'Danger', 'H225, Danger', $hr);
check($s[14]['packing_group'] === 'II' && str_starts_with($s[14]['note'], $noteVisc), 'S14 PG II + viscous note', $s[14]);
check(str_contains($s[13], 'D001'), 'S13: D001', $s[13]);

// ---------------------------------------------------------------------
echo "E. fp 12 °C, no boiling point on any raw → Cat 2 assumed + operator warning\n";
$hr = $hazard(12.0, false, null);
check(!empty($hr['flammability']['ibp_assumed']) && $codes($hr) === ['H225'], 'ibp_assumed, H225', $hr['flammability']);
$w = $warn->invoke($gen, $hr, null);
check(count($w) === 1 && str_contains($w[0], 'initial boiling point'), 'one warning naming the initial boiling point', $w);
check($warn->invoke($gen, $hazard(14.5, false, 78.0), null) === [], 'no warning with a boiling point');

// ---------------------------------------------------------------------
echo "F. fp 12 °C, bp 30 °C → Cat 1: PG I\n";
$hr = $hazard(12.0, false, 30.0);
$s  = $sheet($hr, $calc(12.0, false, 30.0), $fgFor());
check($codes($hr) === ['H224'], 'H224', $codes($hr));
check($s[14]['packing_group'] === 'I', 'S14 PG I', $s[14]['packing_group']);

// ---------------------------------------------------------------------
echo "G. UV flexo ink: 95 % acrylates \"> 100\" + 5 % IPA @ 12 °C → \"> 95.6\" → not flammable (#9)\n";
$hr = $hazard(95.6, true, 82.0);
$c  = $calc(95.6, true, 82.0);
$s  = $sheet($hr, $c, $fgFor());
check(array_intersect($codes($hr), HazardEngine::FLAMMABLE_LIQUID_H_CODES) === [] && !in_array('GHS02', $hr['pictograms'], true), 'no H22x, no GHS02', $hr);
check($s[5]['specific_hazards'] === $t->get('section5.specific_hazards'), 'S5: default specific hazards', $s[5]['specific_hazards']);
check(!str_contains($s[7]['handling'], $t->get('section7.handling_ignition')), 'S7: no ignition sentence', $s[7]['handling']);
check(!str_contains($s[13], 'D001'), 'S13: no D001', $s[13]);
check($s[14]['status'] === 'not_regulated' && $s[14]['note'] === $noteCarrier, 'S14: not regulated, carrier note only', $s[14]);

// ---------------------------------------------------------------------
echo "H. Ingredient H225 leaked into the buffers, product fp 100 → stripped everywhere\n";
$hr = $hazard(100.0, false, 100.0, 'Liquid', ['H225', 'H319'], ['GHS02', 'GHS07']);
$s  = $sheet($hr, $calc(100.0, false, 100.0), $fgFor());
check(!in_array('H225', $codes($hr), true) && in_array('H319', $codes($hr), true), 'H225 gone, H319 kept', $codes($hr));
check(!in_array('GHS02', $hr['pictograms'], true), 'GHS02 gone', $hr['pictograms']);
check(!str_contains($s[13], 'D001') && $s[14]['hazard_class'] !== '3', 'no D001, not Class 3', [$s[13], $s[14]['hazard_class']]);

// ---------------------------------------------------------------------
echo "I. No flash point on any raw (Q2) → not flammable\n";
$hrI = $hazard(null, false, null);
$cI  = $calc(null);
$s   = $sheet($hrI, $cI, $fgFor());
check(array_intersect($codes($hrI), HazardEngine::FLAMMABLE_LIQUID_H_CODES) === [], 'no H22x', $codes($hrI));
check(!str_contains($s[13], 'D001') && $s[14]['hazard_class'] !== '3', 'no D001, not Class 3', [$s[13], $s[14]['hazard_class']]);

// ---------------------------------------------------------------------
echo "J. Paste UV ink at fp 30 → never a flammable liquid\n";
$hr = $hazard(30.0, false, 100.0, 'Paste');
$s  = $sheet($hr, $calc(30.0, false, 100.0, 'Paste'), $fgFor('Paste'));
check(array_intersect($codes($hr), HazardEngine::FLAMMABLE_LIQUID_H_CODES) === [] && $hr['flammability']['basis'] === HazardEngine::FLAMMABILITY_NOT_LIQUID, 'no H22x, basis not_liquid', $hr['flammability']);
check(!str_contains($s[13], 'D001'), 'S13: no D001', $s[13]);

// ---------------------------------------------------------------------
echo "K. vetFlashPointEdit(): a Section 9 edit that contradicts the classification is dropped\n";
$vetRun = static function (string $edit, array $hr, array $c, array $fg) use ($gen, $vet): array {
    $ov = [9 => ['flash_point' => $edit]];
    $w  = $vet->invokeArgs($gen, [&$ov, $hr, $c, $fg]);
    return [$w, $ov];
};
[$w, $ov] = $vetRun('> 93 °C', $hrC, $cC, $fgFor());
check(is_string($w) && !isset($ov[9]['flash_point']) && str_contains($w, '> 93 °C'), 'C + "> 93 °C" → dropped, warning names the edit', $w);
[$w, $ov] = $vetRun('41 °C (105.8 °F)', $hrC, $cC, $fgFor());
check($w === null && ($ov[9]['flash_point'] ?? null) === '41 °C (105.8 °F)', 'C + "41 °C" (same Cat 3 / D001) → kept', $w);
[$w, $ov] = $vetRun('None — water based', $hrC, $cC, $fgFor());
check(is_string($w) && !isset($ov[9]['flash_point']), 'C + "None — water based" → dropped', $w);
[$w, $ov] = $vetRun('Not applicable', $hrI, $cI, $fgFor());
check($w === null && isset($ov[9]['flash_point']), 'I + "Not applicable" (no raw flash point) → kept', $w);
[$w, $ov] = $vetRun('38 °C', $hrI, $cI, $fgFor());
check(is_string($w) && !isset($ov[9]['flash_point']), 'I + "38 °C" → dropped (would mean Cat 3)', $w);
$hrB = $hazard(60.0, false, 100.0);
[$w, $ov] = $vetRun('59 °C', $hrB, $calc(60.0, false, 100.0), $fgFor());
check(is_string($w) && !isset($ov[9]['flash_point']), 'B + "59 °C" → dropped (same Cat 3, but D001 differs)', $w);
foreach ([$w] as $txt) {
    foreach (['Not determined', 'Not regulated', 'Not applicable'] as $sentinel) {
        check(!str_contains((string) $txt, $sentinel), "warning text free of \"{$sentinel}\"");
    }
}

// ---------------------------------------------------------------------
echo "L. Flam. Liq. from a finished-good override (no flammability block, no flash point) → hazard-based D001 reason\n";
$hrL = [
    'signal_word' => 'Warning', 'pictograms' => ['GHS02'], 'hazard_classes' => [], 'p_statements' => [], 'exposure_limits' => [],
    'h_statements' => [['code' => 'H226', 'text' => '']],
];
$txt = (string) $s13->invoke($gen, $hrL, $calc(null), [], $rcra)['rcra_classification'];
check(str_contains($txt, $t->get('section13.rcra_reason_flammable_liquid')), 'S13: rcra_reason_flammable_liquid', $txt);

// ---------------------------------------------------------------------
echo "M. Every language renders the new D001 reason\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tl = new \SDS\Services\TranslationService($lang);
    $v  = $tl->get('section13.rcra_reason_flammable_liquid');
    check($v !== 'section13.rcra_reason_flammable_liquid' && trim($v) !== '', "{$lang}: section13.rcra_reason_flammable_liquid", $v);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
