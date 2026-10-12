<?php
/**
 * DB-free checks of A10 (owner 2026-10-12): the formula (weighted-average)
 * flash point prints rounded to a whole degree with a translated
 * "(calculated)" marker in Sections 9 and 5 (one string, #11); a kept
 * Section 9 edit (measured value) prints exactly as entered, without it.
 *
 *   - Rounding never goes up onto or across 23 / 60 / 93 °C, and never down
 *     onto 60 / 93 (59.6 -> 59, 60.4 -> 61, 92.7 -> 92 ...); 23.4 -> 23, as
 *     Cat 3 starts at 23 inclusive;
 *     a "> n" value rounds down; °F comes from the rounded °C.
 *   - Sweep against the real HazardEngine: the printed value always gives
 *     the same flammable-liquid category, D001 (< 60) and DOT combustible
 *     (< 93) answer as the unrounded value.
 *   - #35 refresh of an operator Specific Hazards edit, D001 agreement,
 *     languages, helper guards.
 *
 * Same Reflection bootstrap as SDSGeneratorFlashPointTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorFlashPointRoundingTest.php
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

use SDS\Services\HazardEngine;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

$method = static function (SDSGenerator $g, string $name): ReflectionMethod {
    $m = new ReflectionMethod($g, $name);
    $m->setAccessible(true);
    return $m;
};

$hz = static fn (array $codes, array $classes = []): array => [
    'signal_word' => null, 'pictograms' => [], 'hazard_classes' => $classes, 'p_statements' => [], 'exposure_limits' => [],
    'h_statements' => array_map(static fn ($c) => ['code' => $c, 'text' => ''], $codes),
];

$calc = static fn (?float $fp, bool $gt = false, ?float $bp = null): array => [
    'formula'       => ['lines' => []],
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'boiling_point_c' => $bp, 'enriched_lines' => []],
    'voc'           => ['total_voc_wt_pct' => 0, 'mixture_sg' => 1.0, 'voc_lb_per_gal' => 0, 'voc_lb_per_gal_less_water_exempt' => 0, 'solids_wt_pct' => 0, 'solids_vol_pct' => null],
    'composition'   => [], 'warnings' => [],
];

$rcra = ['components' => [], 'has_matches' => false];
$fg   = ['description' => 'TEST INK', 'family' => null, 'physical_state' => 'Liquid', 'transport_product_type' => null, 'color' => ''];

// One generator per language
$T = $G = [];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $T[$lang] = new TranslationService($lang);
    $G[$lang] = new SDSGenerator($T[$lang]);
}
$t   = $T['en'];
$gen = $G['en'];

$s9 = static fn (string $lang, array $c, array $ov = []): string =>
    (string) $method($G[$lang], 'section9')->invoke($G[$lang], $fg, $c, $ov)['flash_point'];
$s5 = static fn (string $lang, array $c, array $h, array $ov = []): string =>
    (string) $method($G[$lang], 'section5')->invoke($G[$lang], $c, $h, $ov)['specific_hazards'];

$combustion = $t->get('section5.specific_hazards');
$cat3       = $t->get('section5.specific_hazards_flammable_cat3');
$cat4       = $t->get('section5.specific_hazards_flammable_cat4');

// ---------------------------------------------------------------------
echo "1. EN rounding table (Section 9)\n";
$table = [
    [73.6, false, '74 °C (165 °F) (calculated)'],
    [75.6, false, '76 °C (169 °F) (calculated)'],
    [38.0, false, '38 °C (100 °F) (calculated)'],
    [12.0, false, '12 °C (54 °F) (calculated)'],
    [-4.0, false, '-4 °C (25 °F) (calculated)'],
    [-0.4, false, '0 °C (32 °F) (calculated)'],
    [0.0, false, '0 °C (32 °F) (calculated)'],
    [22.6, false, '22 °C (72 °F) (calculated)'],
    // 23 is guarded from below only (Cat 3 / PG III start at 23 inclusive) → 23.4 prints 23
    [23.4, false, '23 °C (73 °F) (calculated)'],
    [23.6, false, '24 °C (75 °F) (calculated)'],
    [59.6, false, '59 °C (138 °F) (calculated)'],
    [60.0, false, '60 °C (140 °F) (calculated)'],
    [60.4, false, '61 °C (142 °F) (calculated)'],
    [92.7, false, '92 °C (198 °F) (calculated)'],
    [93.0, false, '93 °C (199 °F) (calculated)'],
    [93.2, false, '94 °C (201 °F) (calculated)'],
    [100.0, false, '100 °C (212 °F) (calculated)'],
    [67.8, true, '> 67 °C (152 °F) (calculated)'],
    [55.0, true, '> 55 °C (131 °F) (calculated)'],
    [59.9, true, '> 59 °C (138 °F) (calculated)'],
    [93.0, true, '> 93 °C (199 °F) (calculated)'],
];
foreach ($table as [$fp, $gt, $want]) {
    $got = $s9('en', $calc($fp, $gt));
    check($got === $want, ($gt ? '> ' : '') . "{$fp} -> {$want}", $got);
}
$got = $s9('en', $calc(null));
check($got === $t->get('labels.not_determined'), 'null -> Not determined', $got);

// ---------------------------------------------------------------------
echo "2. Sweep -10.0 .. 110.0 °C (0.1 steps, with and without \">\") against HazardEngine\n";
$bad = ['parse' => [], 'category' => [], 'd001' => [], 'dot' => [], 'error' => [], 'fahrenheit' => [], 'minus0' => []];
$n = 0;
for ($i = -100; $i <= 1100; $i++) {
    foreach ([false, true] as $gt) {
        $n++;
        $fp  = round($i / 10, 1);
        $out = $s9('en', $calc($fp, $gt));
        $tag = ($gt ? '> ' : '') . $fp . ' => ' . $out;
        if (str_contains($out, '-0 °C')) {
            $bad['minus0'][] = $tag;
        }
        if (!preg_match('/^(> )?(-?\d+) °C \((-?\d+) °F\) \(calculated\)$/', $out, $m) || ($m[1] === '> ') !== $gt) {
            $bad['parse'][] = $tag;
            continue;
        }
        $p = (int) $m[2];
        $f = (int) $m[3];
        if (HazardEngine::flammableLiquidCategory((float) $p, $gt, null, 'Liquid') !== HazardEngine::flammableLiquidCategory($fp, $gt, null, 'Liquid')) {
            $bad['category'][] = $tag;
        }
        if (($p < 60) !== ($fp < 60.0)) {
            $bad['d001'][] = $tag;
        }
        if (($p < 93) !== ($fp < 93.0)) {
            $bad['dot'][] = $tag;
        }
        if (!(abs($p - $fp) < 1.0)) {
            $bad['error'][] = $tag;
        }
        $fx = $p * 9 / 5 + 32;
        if ($f !== (int) ($gt ? floor($fx) : round($fx))) {
            $bad['fahrenheit'][] = $tag;
        }
    }
}
$show = static fn (array $l) => $l === [] ? null : array_slice($l, 0, 5);
check($bad['parse'] === [], "{$n} values all print \"[> ]n °C (n °F) (calculated)\"", $show($bad['parse']));
check($bad['category'] === [], 'printed value gives the same flammable-liquid category', $show($bad['category']));
check($bad['d001'] === [], 'printed value gives the same D001 (< 60 °C) answer', $show($bad['d001']));
check($bad['dot'] === [], 'printed value gives the same DOT combustible (< 93 °C) answer', $show($bad['dot']));
check($bad['error'] === [], 'printed °C within 1 °C of the weighted value', $show($bad['error']));
check($bad['fahrenheit'] === [], '°F computed from the printed °C', $show($bad['fahrenheit']));
check($bad['minus0'] === [], 'never prints "-0 °C"', $show($bad['minus0']));

// ---------------------------------------------------------------------
echo "3. Section 5 parity (EN)\n";
$c   = $calc(73.6);
$p9  = $s9('en', $c);
$p5  = $s5('en', $c, $hz(['H227']));
check($p5 === $cat4 . ' Flash point: 74 °C (165 °F) (calculated). ' . $combustion, 'Section 5 cat4 + rounded, marked flash point + combustion', $p5);
check(str_contains($p5, 'Flash point: ' . $p9 . '.'), 'Section 5 embeds the identical Section 9 string', ['s5' => $p5, 's9' => $p9]);

// ---------------------------------------------------------------------
echo "4. Languages (75.6 °C, H227)\n";
$wantLang = ['es' => '76 °C (169 °F) (calculado)', 'fr' => '76 °C (169 °F) (calculé)', 'de' => '76 °C (169 °F) (berechnet)'];
foreach ($wantLang as $lang => $want) {
    $c  = $calc(75.6);
    $p9 = $s9($lang, $c);
    check($p9 === $want, "{$lang}: Section 9 {$want}", $p9);
    $p5 = $s5($lang, $c, $hz(['H227']));
    $line = $T[$lang]->get('section5.flash_point_line', ['fp' => $p9]);
    check(str_contains($p5, $line), "{$lang}: Section 5 contains \"{$line}\"", $p5);
}
foreach ($T as $lang => $tl) {
    $v = $tl->get('section9.flash_point_calculated');
    check(is_string($v) && str_contains($v, ':fp') && $v !== 'section9.flash_point_calculated', "{$lang}: section9.flash_point_calculated has :fp", $v);
}

// ---------------------------------------------------------------------
echo "5. Measured Section 9 edit prints as entered\n";
$ov = [9 => ['flash_point' => '41 °C (105.8 °F)']];
$c  = $calc(38.0);
$p9 = $s9('en', $c, $ov);
$p5 = $s5('en', $c, $hz(['H226']), $ov);
check($p9 === '41 °C (105.8 °F)', 'Section 9 prints the edit verbatim', $p9);
check($p5 === $cat3 . ' Flash point: 41 °C (105.8 °F). ' . $combustion, 'Section 5 prints the edit verbatim', $p5);
check(!str_contains($p9 . $p5, '(calculated)'), 'no "(calculated)" marker on a measured value', [$p9, $p5]);
$p9 = $s9('es', $c, [9 => ['flash_point' => '41 °C']]);
check($p9 === '41 °C', 'ES edit "41 °C" printed without "(calculado)"', $p9);

// ---------------------------------------------------------------------
echo "6. Dropped or non-temperature edits fall back to the marked value\n";
$vet = $method($gen, 'vetFlashPointEdit');
$ov  = [9 => ['flash_point' => '> 95 °C']];
$c   = $calc(38.0);
$w   = $vet->invokeArgs($gen, [&$ov, $hz(['H226']), $c, $fg]);
check(is_string($w) && !isset($ov[9]['flash_point']), '"> 95 °C" contradicts Cat 3 → dropped with a warning', $w);
$p9 = $s9('en', $c, $ov);
check($p9 === '38 °C (100 °F) (calculated)', 'Section 9 falls back to the formula value', $p9);
$p9 = $s9('en', $c, [9 => ['flash_point' => 'None — water based']]);
check($p9 === '38 °C (100 °F) (calculated)', 'non-temperature edit ignored → formula value', $p9);

// ---------------------------------------------------------------------
echo "7. #35 refresh of an operator Specific Hazards edit\n";
$p5 = $s5('en', $calc(30.0), $hz(['H226']), [5 => ['specific_hazards' => 'X. Flash point: 12 °C (54 °F) (calculated). Y.']]);
check($p5 === 'X. Flash point: 30 °C (86 °F) (calculated). Y.', 'marked sentence replaced in place', $p5);
$again = $s5('en', $calc(30.0), $hz(['H226']), [5 => ['specific_hazards' => $p5]]);
check($again === $p5, 'refresh is idempotent', $again);
$p5 = $s5('fr', $calc(30.0), $hz(['H226']), [5 => ['specific_hazards' => 'A. Point d\'éclair : 12 °C (53.6 °F). B.']]);
check($p5 === 'A. Point d\'éclair : 30 °C (86 °F) (calculé). B.', 'FR sentence refreshed', $p5);
$p5 = $s5('de', $calc(30.0), $hz(['H226']), [5 => ['specific_hazards' => 'A. Flammpunkt: 12 °C (53.6 °F). B.']]);
check($p5 === 'A. Flammpunkt: 30 °C (86 °F) (berechnet). B.', 'DE sentence refreshed', $p5);

// ---------------------------------------------------------------------
echo "8. D001 agreement with the printed value\n";
$s13 = $method($gen, 'section13');
$c   = $calc(59.6);
$r13 = (string) $s13->invoke($gen, $hz(['H226']), $c, [], $rcra)['rcra_classification'];
check(str_contains($r13, $t->get('section13.rcra_reason_flash_point')), '59.6 °C: D001 (flash point below 60 °C)', $r13);
check($s9('en', $c) === '59 °C (138 °F) (calculated)', '59.6 °C prints 59, consistent with D001', $s9('en', $c));
$c   = $calc(60.4);
$r13 = (string) $s13->invoke($gen, $hz(['H227']), $c, [], $rcra)['rcra_classification'];
check(!str_contains($r13, 'D001'), '60.4 °C: no D001', $r13);
check($s9('en', $c) === '61 °C (142 °F) (calculated)', '60.4 °C prints 61, consistent with no D001', $s9('en', $c));

// ---------------------------------------------------------------------
echo "9. Helper guards\n";
$disp = new ReflectionMethod(SDSGenerator::class, 'flashPointDisplay');
$disp->setAccessible(true);
$v = $disp->invoke(null, ['flash_point_c' => 38.0]);
check($v === '38 °C (100 °F)', 'default template → bare value', $v);
$v = $disp->invoke(null, ['flash_point_c' => 38.0], 'no placeholder');
check($v === '38 °C (100 °F)', 'template without :fp → bare value', $v);
$v = $disp->invoke(null, ['flash_point_c' => '']);
check($v === null, "flash_point_c '' → null", $v);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
