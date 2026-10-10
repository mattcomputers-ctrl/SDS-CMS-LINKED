<?php
/**
 * DB-free end-to-end checks of the product flash point through Sections 5,
 * 9, 13 and 14 (work unit T1a):
 *
 *   - Q1: the product flash point is the wt%-weighted average of the raws
 *     that carry one (FormulaCalcService::weightedFlashPoint), "> n" when any
 *     contributing raw is flagged; every section prints / classifies from it.
 *   - Q2: no flash point data → Section 9 "Not determined", not flammable,
 *     no D001, Section 14 by the other classes or "Not regulated" (no gate).
 *   - #8: a Section 9 Flash Point edit must read as a temperature (degree
 *     sign optional); one that does not is ignored everywhere and warned.
 *     Q3 (T1b): the edit is display only — Sections 13 / 14 follow the engine;
 *     an edit that contradicts the classification is dropped (vetFlashPointEdit).
 *   - #44(2): a Section 9 Initial Boiling Point edit is parsed the same way;
 *     Q3: display only, dropped when it contradicts Cat 1 / 2 (vetBoilingPointEdit).
 *   - #35: an operator Section 5 Specific Hazards edit keeps a current
 *     "Flash point: …" sentence.
 *
 * Same Reflection bootstrap as SDSGeneratorSection14Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorFlashPointTest.php
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

use SDS\Services\FormulaCalcService;
use SDS\Services\SDSReadinessService;

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);

$method = static function (\SDS\Services\SDSGenerator $g, string $name): ReflectionMethod {
    $m = new ReflectionMethod($g, $name);
    $m->setAccessible(true);
    return $m;
};

$s5    = $method($gen, 'section5');
$s9    = $method($gen, 'section9');
$s13   = $method($gen, 'section13');
$s14   = $method($gen, 'section14');
$warnM = $method($gen, 'temperatureOverrideWarnings');
$warn  = static fn (array $ov): array => $warnM->invoke(null, $ov);

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

$line = static fn (int $rmId, float $pct, $fp, bool $gt = false): array => [
    'raw_material_id' => $rmId, 'pct' => $pct, 'flash_point_c' => $fp, 'flash_point_greater_than' => $gt ? 1 : 0,
];

$fromLines = static function (array $lines) use ($calc): array {
    $w = FormulaCalcService::weightedFlashPoint($lines);
    return $calc($w['fp'], $w['gt']);
};

$fg = ['description' => 'WATER BASED INK', 'family' => null, 'physical_state' => 'Liquid', 'transport_product_type' => null, 'color' => ''];

$nd         = $t->get('labels.not_determined');
$nr         = $t->get('labels.not_regulated');
$na         = $t->get('labels.not_applicable');
$combustion = $t->get('section5.specific_hazards');
$cat4       = $t->get('section5.specific_hazards_flammable_cat4');
$rcraFp     = $t->get('section13.rcra_reason_flash_point');
$carrier    = $t->get('section14.note');

$s13Text = static fn (array $c, array $codes, array $ov = []): string =>
    (string) $s13->invoke($gen, $hz($codes), $c, $ov, $rcra)['rcra_classification'];

// ---------------------------------------------------------------------
echo "1. Q1 water-based ink: 30% raw @12 °C + 70% water @100 °C → 73.6 °C\n";
$c = $fromLines([$line(1, 30.0, 12.0), $line(2, 70.0, 100.0)]);
check($c['formula_props']['flash_point_c'] === 73.6 && $c['formula_props']['flash_point_greater_than'] === false, 'formula_props 73.6, no ">"', $c['formula_props']);
$r9 = $s9->invoke($gen, $fg, $c, []);
check($r9['flash_point'] === '73.6 °C (164.5 °F)', 'Section 9 prints the weighted average', $r9['flash_point']);
$r5 = $s5->invoke($gen, $c, $hz(['H227']), []);
check($r5['specific_hazards'] === $cat4 . ' Flash point: 73.6 °C (164.5 °F). ' . $combustion, 'Section 5 embeds the same value', $r5['specific_hazards']);
check(!str_contains($s13Text($c, ['H227']), 'D001'), 'Section 13: no D001 at 73.6 °C', $s13Text($c, ['H227']));
$r14 = $s14->invoke($gen, $fg, $c, $hz(['H227']), []);
check($r14['status'] === 'not_regulated', 'Section 14 not regulated', $r14['status']);
check($r14['note'] === $t->get('section14.note_combustible') . ' ' . $carrier, 'combustible note + carrier note', $r14['note']);

// ---------------------------------------------------------------------
echo "2. Q1 exclusion of blank raws and the \">\" flag\n";
$c = $fromLines([$line(1, 50.0, 12.0), $line(2, 50.0, null)]);
check($c['formula_props']['flash_point_c'] === 12.0, 'blank raw left out → 12.0', $c['formula_props']['flash_point_c']);
$r14 = $s14->invoke($gen, $fg, $c, $hz(['H225']), []);
check($r14['un_number'] === 'UN1210' && $r14['hazard_class'] === '3' && $r14['packing_group'] === 'II', 'UN1210 / 3 / PG II', $r14);
check(str_contains($s13Text($c, ['H225']), $rcraFp), 'Section 13 D001 (flash point below 60 °C)', $s13Text($c, ['H225']));
$c = $fromLines([$line(1, 40.0, 30.0), $line(2, 60.0, 93.0, true)]);
check($c['formula_props']['flash_point_c'] === 67.8 && $c['formula_props']['flash_point_greater_than'] === true, '> 67.8', $c['formula_props']);
$r9 = $s9->invoke($gen, $fg, $c, []);
check($r9['flash_point'] === '> 67.8 °C (154 °F)', 'Section 9 prints "> 67.8 °C (154 °F)"', $r9['flash_point']);
$r14 = $s14->invoke($gen, $fg, $c, $hz([]), []);
check($r14['status'] === 'not_regulated' && $r14['note'] === $carrier, 'not regulated, no combustible note for a "> n" value', $r14);
check(!str_contains($s13Text($c, []), 'D001'), 'no D001', $s13Text($c, []));

// ---------------------------------------------------------------------
echo "3. Q2: no flash point data\n";
$c = $fromLines([$line(1, 60.0, null), $line(2, 40.0, '')]);
check($c['formula_props']['flash_point_c'] === null, 'flash point null', $c['formula_props']['flash_point_c']);
$r9 = $s9->invoke($gen, $fg, $c, []);
check($r9['flash_point'] === $nd, 'Section 9 Not determined', $r9['flash_point']);
$r5 = $s5->invoke($gen, $c, $hz(['H319']), []);
check($r5['specific_hazards'] === $combustion, 'Section 5: combustion sentence only', $r5['specific_hazards']);
check(!str_contains($s13Text($c, []), 'D001'), 'Section 13: no D001', $s13Text($c, []));
foreach ([[], ['H317', 'H319']] as $codes) {
    $r14 = $s14->invoke($gen, $fg, $c, $hz($codes), []);
    $lbl = $codes === [] ? 'no codes' : implode('+', $codes);
    check($r14['status'] === 'not_regulated' && $r14['un_number'] === $nr && $r14['packing_group'] === $na, "Section 14 Not regulated ({$lbl})", $r14);
    check(SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'X'], 'sections' => [14 => $r14]]) === null, "no publish block ({$lbl})");
}
$r14 = $s14->invoke($gen, $fg, $c, $hz(['H411']), []);
check($r14['un_number'] === 'UN3082' && $r14['hazard_class'] === '9', 'H411 → UN3082 Class 9', $r14);

// ---------------------------------------------------------------------
echo "4. #8 Section 9 flash point edits (Q3: display only; a contradicting edit is dropped)\n";
$vetFp = $method($gen, 'vetFlashPointEdit');
foreach (['75 F', '75F', '75 °F', '75 deg F'] as $edit) {
    // Formula 24 °C / H226: the edit (23.9 °C, Cat 3, D001) agrees → kept and printed as typed.
    $ov = [9 => ['flash_point' => $edit]];
    $c  = $calc(24.0);
    $w  = $vetFp->invokeArgs($gen, [&$ov, $hz(['H226']), $c, $fg]);
    check($w === null && ($ov[9]['flash_point'] ?? null) === $edit, "\"{$edit}\" agrees with fp 24 / H226 → kept", $w);
    $r9 = $s9->invoke($gen, $fg, $c, $ov);
    check($r9['flash_point'] === $edit, "\"{$edit}\" printed as typed", $r9['flash_point']);
    // Formula "> 95" / not flammable: the edit would say Cat 3 → dropped; 13 / 14 never read it.
    $ov = [9 => ['flash_point' => $edit]];
    $c  = $calc(95.0, true);
    $w  = $vetFp->invokeArgs($gen, [&$ov, $hz([]), $c, $fg]);
    check(is_string($w) && !isset($ov[9]['flash_point']), "\"{$edit}\" contradicts \"> 95\" → dropped with a warning", $w);
    $r14 = $s14->invoke($gen, $fg, $c, $hz([]), [9 => ['flash_point' => $edit]]);
    check($r14['status'] === 'not_regulated', "\"{$edit}\" never drives Section 14", $r14['status']);
    check(!str_contains($s13Text($c, [], [9 => ['flash_point' => $edit]]), 'D001'), "\"{$edit}\" never drives D001", $s13Text($c, [], [9 => ['flash_point' => $edit]]));
}
$ov = [9 => ['flash_point' => 'None — water based']];
$c  = $calc(38.0);
$r9 = $s9->invoke($gen, $fg, $c, $ov);
check($r9['flash_point'] === '38 °C (100.4 °F)', 'non-temperature edit not printed; formula value instead', $r9['flash_point']);
$r5 = $s5->invoke($gen, $c, $hz(['H226']), $ov);
check(str_contains($r5['specific_hazards'], 'Flash point: 38 °C (100.4 °F).'), 'Section 5 uses the formula value', $r5['specific_hazards']);
$r14 = $s14->invoke($gen, $fg, $c, $hz(['H226']), $ov);
check($r14['hazard_class'] === '3' && $r14['packing_group'] === 'III', 'Section 14 classifies from the formula value', $r14);
$w = $warn($ov);
check(count($w) === 1 && str_contains($w[0], 'Flash Point') && str_contains($w[0], 'None — water based'), 'one operator warning naming the edit', $w);
check($warn([]) === [], 'no warning without edits');
check($warn([9 => ['flash_point' => '24 °C', 'boiling_point' => '> 150 °C']]) === [], 'no warning for valid edits');
check($warn([9 => ['flash_point' => '  ']]) === [], 'no warning for a blank edit');

// ---------------------------------------------------------------------
echo "5. #44(2) Section 9 boiling point edits (Q3: display only; a contradicting edit is dropped)\n";
$vetBp = $method($gen, 'vetBoilingPointEdit');
$c   = $calc(10.0, false, 120.0);
$r14 = $s14->invoke($gen, $fg, $c, $hz(['H225']), []);
check($r14['packing_group'] === 'II', 'IBP 120 °C → PG II', $r14['packing_group']);
foreach (['30 °C', '30C', '86 F'] as $edit) {
    $r14 = $s14->invoke($gen, $fg, $c, $hz(['H225']), [9 => ['boiling_point' => $edit]]);
    check($r14['packing_group'] === 'II', "boiling point edit \"{$edit}\" never drives Section 14 (engine H225 → PG II)", $r14['packing_group']);
    $ov = [9 => ['boiling_point' => $edit]];
    $w  = $vetBp->invokeArgs($gen, [&$ov, $hz(['H225'])]);
    check(is_string($w) && !isset($ov[9]['boiling_point']), "\"{$edit}\" would mean Cat 1 beside H225 → dropped with a warning", $w);
}
$ov = [9 => ['boiling_point' => '> 150 °C']];
check($vetBp->invokeArgs($gen, [&$ov, $hz(['H225'])]) === null && isset($ov[9]['boiling_point']), '"> 150 °C" agrees with Cat 2 → kept');
$ov = [9 => ['boiling_point' => '30 °C']];
check($vetBp->invokeArgs($gen, [&$ov, $hz(['H226'])]) === null && isset($ov[9]['boiling_point']), 'Cat 3 sheet: IBP does not change the category → kept');
$ov  = [9 => ['boiling_point' => 'x']];
$r14 = $s14->invoke($gen, $fg, $c, $hz(['H225']), $ov);
check($r14['packing_group'] === 'II', 'edit "x" ignored → PG II', $r14['packing_group']);
$r9 = $s9->invoke($gen, $fg, $c, $ov);
check($r9['boiling_point'] === '120 °C (248 °F)', 'edit "x" not printed', $r9['boiling_point']);
$w = $warn($ov);
check(count($w) === 1 && str_contains($w[0], 'Initial Boiling Point'), 'one operator warning for the boiling point', $w);

// ---------------------------------------------------------------------
echo "6. #35 Section 5 Specific Hazards edit keeps a current flash point\n";
$edit = 'Flammable liquid. Flash point: 12 °C (53.6 °F). Keep away from heat.';
$r5 = $s5->invoke($gen, $calc(30.0), $hz(['H226']), [5 => ['specific_hazards' => $edit]]);
check($r5['specific_hazards'] === 'Flammable liquid. Flash point: 30 °C (86 °F). Keep away from heat.', 'stale sentence replaced in place', $r5['specific_hazards']);
$r5 = $s5->invoke($gen, $calc(30.0), $hz(['H226']), [5 => ['specific_hazards' => 'Custom text.']]);
check($r5['specific_hazards'] === 'Custom text. Flash point: 30 °C (86 °F).', 'missing sentence appended', $r5['specific_hazards']);
$r5 = $s5->invoke($gen, $calc(30.0), $hz(['H226']), [5 => ['specific_hazards' => 'Custom text.'], 9 => ['flash_point' => '41 °C (105.8 °F)']]);
check($r5['specific_hazards'] === 'Custom text. Flash point: 41 °C (105.8 °F).', 'Section 9 edit used', $r5['specific_hazards']);
$r5 = $s5->invoke($gen, $calc(93.0, true), $hz([]), [5 => ['specific_hazards' => $edit]]);
check($r5['specific_hazards'] === 'Flammable liquid. Keep away from heat.', 'sentence removed when not flammable', $r5['specific_hazards']);
$genEs = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService('es'));
$s5Es  = $method($genEs, 'section5');
$r5 = $s5Es->invoke($genEs, $calc(30.0), $hz(['H226']), [5 => ['specific_hazards' => 'Líquido. Punto de inflamación: 12 °C (53.6 °F). Fin.']]);
check($r5['specific_hazards'] === 'Líquido. Punto de inflamación: 30 °C (86 °F). Fin.', 'ES sentence refreshed', $r5['specific_hazards']);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
