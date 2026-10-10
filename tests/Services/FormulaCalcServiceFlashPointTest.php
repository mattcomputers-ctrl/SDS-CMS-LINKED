#!/usr/bin/env php
<?php
/**
 * FormulaCalcService — product flash point (owner decision Q1).
 *
 * Rule: formula_props.flash_point_c = the wt%-weighted average of the flash
 * points of the raws that carry one: sum(wt% x FP) / sum(wt%), over enriched
 * lines (already flattened and scaled through sub-FGs) with a flash point
 * and wt% > 0, rounded to 0.1 °C. Raws without a flash point are left out.
 * A raw flagged ">" counts as its number and makes the product a "> n"
 * value. No raw with a flash point → null (Q2: no flash point).
 *
 * DB-free: weightedFlashPoint() is public static and pure;
 * deriveFormulaProperties() is invoked through reflection.
 *
 * Run:  php tests/Services/FormulaCalcServiceFlashPointTest.php
 * Exit: 0 = all cases passed, 1 = one or more failed
 */

declare(strict_types=1);

error_reporting(E_ALL);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SDS\Services\FormulaCalcService;

// $fp is mixed on purpose: float, null, '' and PDO-style numeric strings are all exercised.
$line = static function (int $rmId, float $pct, $fp, int $gt = 0): array {
    return [
        'raw_material_id'          => $rmId,
        'internal_code'            => 'RM' . $rmId,
        'supplier_product_name'    => 'RM ' . $rmId,
        'pct'                      => $pct,
        'voc_wt'                   => null,
        'voc_less_than_one'        => 0,
        'exempt_voc_wt'            => 0.0,
        'water_wt'                 => null,
        'specific_gravity'         => null,
        'solids_wt'                => null,
        'solids_vol'               => null,
        'flash_point_c'            => $fp,
        'flash_point_greater_than' => $gt,
        'boiling_point_c'          => null,
        'physical_state'           => null,
        'solubility'               => null,
        'appearance'               => null,
        'odor'                     => null,
        'constituents'             => [],
    ];
};

$cases = [
    '1. 30% @12 + 70% @100 → 73.6'                        => [[$line(1, 30.0, 12.0), $line(2, 70.0, 100.0)], 73.6, false],
    '2. raw without a flash point left out (50% @12)'     => [[$line(1, 50.0, 12.0), $line(2, 50.0, null)], 12.0, false],
    '3. empty string left out'                            => [[$line(1, 50.0, ''), $line(2, 50.0, 40.0)], 40.0, false],
    '4. ">" raw counts as its number and flags product'   => [[$line(1, 40.0, 30.0), $line(2, 60.0, 93.0, 1)], 67.8, true],
    '5. ">" flag on a raw without a value is ignored'     => [[$line(1, 50.0, null, 1), $line(2, 50.0, 40.0)], 40.0, false],
    '6. no raw with a flash point → null (Q2)'            => [[$line(1, 100.0, null)], null, false],
    '7. no lines → null'                                  => [[], null, false],
    '8. 0 wt% line left out'                              => [[$line(1, 0.0, -20.0), $line(2, 100.0, 80.0)], 80.0, false],
    '9. PDO numeric strings'                              => [[$line(1, 50.0, '12.0'), $line(2, 50.0, '40')], 26.0, false],
    '10. negative flash points'                           => [[$line(1, 50.0, -20.0), $line(2, 50.0, 40.0)], 10.0, false],
    '11. rounded to 0.1 °C'                               => [[$line(1, 33.3, 10.0), $line(2, 66.7, 40.0)], 30.0, false],
    '12. same raw on two lines (sub-FG flattening)'       => [[$line(1, 15.0, 12.0), $line(1, 15.0, 12.0), $line(2, 70.0, 100.0)], 73.6, false],
    '13. -0.0 normalised to 0.0'                          => [[$line(1, 50.0, -0.08), $line(2, 50.0, 0.0)], 0.0, false],
];

$failures = 0;
foreach ($cases as $label => [$lines, $fp, $gt]) {
    $r  = FormulaCalcService::weightedFlashPoint($lines);
    $ok = $r === ['fp' => $fp, 'gt' => $gt];
    if ($label === '13. -0.0 normalised to 0.0') {
        $ok = $ok && (string) $r['fp'] === '0';
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok ? '' : ' — got ' . var_export($r, true)) . "\n";
    if (!$ok) {
        $failures++;
    }
}

// deriveFormulaProperties() returns the same value under its existing keys.
$svc = new FormulaCalcService();
$m   = new ReflectionMethod(FormulaCalcService::class, 'deriveFormulaProperties');
$m->setAccessible(true);
foreach ([
    'derive: case 1' => [$cases['1. 30% @12 + 70% @100 → 73.6'][0], 73.6, false],
    'derive: case 4' => [$cases['4. ">" raw counts as its number and flags product'][0], 67.8, true],
] as $label => [$lines, $fp, $gt]) {
    $props = $m->invoke($svc, $lines);
    $ok    = $props['flash_point_c'] === $fp
        && $props['flash_point_greater_than'] === $gt
        && $props['boiling_point_c'] === null;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok ? '' : ' — got ' . var_export([
        'flash_point_c' => $props['flash_point_c'],
        'flash_point_greater_than' => $props['flash_point_greater_than'],
        'boiling_point_c' => $props['boiling_point_c'],
    ], true)) . "\n";
    if (!$ok) {
        $failures++;
    }
}

echo $failures === 0 ? "\nAll flash point cases passed.\n" : "\n{$failures} case(s) failed.\n";
exit($failures === 0 ? 0 : 1);
