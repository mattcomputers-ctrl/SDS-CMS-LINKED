#!/usr/bin/env php
<?php
/**
 * VOCCalculator #71 normalisation and FormulaCalcService::dataGapWarnings()
 * (Q14 preview-only warnings for raw materials missing SG or VOC).
 *
 *   mixture wt% = SUM(line_pct x rm_wt%) / SUM(line_pct)   (not / 100)
 *
 * so a formula entered as 98 % is not understated. DB-free: VOCCalculator is
 * pure and dataGapWarnings() is a pure static helper.
 *
 * Run:  php tests/Services/VOCCalculatorNormalisationTest.php
 * Exit: 0 = all cases passed, 1 = one or more failed
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SDS\Services\FormulaCalcService;
use SDS\Services\VOCCalculator;

$failed = 0;
$passed = 0;
$check = static function (bool $ok, string $label, $expected = null, $actual = null) use (&$failed, &$passed): void {
    echo ($ok ? 'PASS' : 'FAIL') . ": {$label}\n";
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo '  expected ' . var_export($expected, true) . "\n";
    echo '  got      ' . var_export($actual, true) . "\n";
};

// Any PHP notice/warning is a failure.
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$failed): bool {
    $failed++;
    echo "FAIL: PHP error ({$errno}): {$errstr} at {$errfile}:{$errline}\n";
    return true;
});

/** Every key VOCCalculator::applyDefaults() reads. */
$line = static function (int $rmId, float $pct, $voc, $sg, $solids): array {
    return [
        'raw_material_id'       => $rmId,
        'internal_code'         => 'RM' . $rmId,
        'supplier_product_name' => 'RM ' . $rmId,
        'pct'                   => $pct,
        'voc_wt'                => $voc,
        'voc_less_than_one'     => 0,
        'exempt_voc_wt'         => null,
        'water_wt'              => null,
        'specific_gravity'      => $sg,
        'solids_wt'             => $solids,
        'solids_vol'            => null,
        'flash_point_c'         => null,
        'constituents'          => [],
    ];
};

$near = static fn (float $a, float $b): bool => abs($a - $b) < 1e-4;

// ---------------------------------------------------------------------
// 1. Formula entered as 98 %: values are normalised by the line total
// ---------------------------------------------------------------------
$r = (new VOCCalculator([$line(1, 49.0, 10.0, 1.0, 90.0), $line(2, 49.0, 0.0, 1.0, 100.0)]))->calculate();
$check($near((float) $r['total_voc_wt_pct'], 5.0), '(1) 49 % VOC 10 + 49 % VOC 0 -> VOC wt% 5.0 (was 4.9)', 5.0, $r['total_voc_wt_pct']);
$check($near((float) $r['solids_wt_pct'], 95.0), '(1) solids wt% 95.0 (normalised)', 95.0, $r['solids_wt_pct']);
$check($near((float) $r['voc_lb_per_gal'], 0.41725), '(1) VOC lb/gal 0.41725 (0.05 x 1.0 x 8.345)', 0.41725, $r['voc_lb_per_gal']);

// ---------------------------------------------------------------------
// 2. Formula summing to 100 %: unchanged
// ---------------------------------------------------------------------
$r = (new VOCCalculator([$line(1, 30.0, 20.0, 1.0, 80.0), $line(2, 70.0, 0.0, 1.0, 100.0)]))->calculate();
$check($near((float) $r['total_voc_wt_pct'], 6.0), '(2) 30 % VOC 20 + 70 % VOC 0 -> 6.0 (sum 100, unchanged)', 6.0, $r['total_voc_wt_pct']);

// ---------------------------------------------------------------------
// 3. Empty formula: zeros, no division by zero, no notices
// ---------------------------------------------------------------------
$r = (new VOCCalculator([]))->calculate();
$check($near((float) $r['total_voc_wt_pct'], 0.0), '(3) empty formula -> VOC wt% 0.0', 0.0, $r['total_voc_wt_pct']);
$check($near((float) $r['solids_wt_pct'], 0.0), '(3) empty formula -> solids wt% 0.0', 0.0, $r['solids_wt_pct']);

// ---------------------------------------------------------------------
// 4..8. dataGapWarnings()
// ---------------------------------------------------------------------
$w = FormulaCalcService::dataGapWarnings(['internal_code' => 'RM-100', 'specific_gravity' => null, 'voc_wt' => null, 'voc_less_than_one' => 0]);
$check(count($w) === 2 && strpos($w[0], 'RM-100') !== false && strpos($w[1], 'RM-100') !== false,
    '(4) SG and VOC missing -> 2 warnings naming RM-100', 2, $w);

$w = FormulaCalcService::dataGapWarnings(['internal_code' => 'RM-101', 'specific_gravity' => '0', 'voc_wt' => '5', 'voc_less_than_one' => 0]);
$check(count($w) === 1 && stripos($w[0], 'specific gravity') !== false, "(5) SG '0' -> SG warning", 1, $w);

$w = FormulaCalcService::dataGapWarnings(['internal_code' => 'RM-102', 'specific_gravity' => 1.1, 'voc_wt' => null, 'voc_less_than_one' => 1]);
$check($w === [], "(6) '<1% VOC' flag with blank VOC -> no VOC warning", [], $w);

$w = FormulaCalcService::dataGapWarnings(['internal_code' => 'RM-103', 'specific_gravity' => 1.05, 'voc_wt' => 0, 'voc_less_than_one' => 0]);
$check($w === [], '(7) SG 1.05 + VOC 0 (entered) -> no warnings', [], $w);

$w = FormulaCalcService::dataGapWarnings(['id' => 7, 'specific_gravity' => '', 'voc_wt' => '', 'voc_less_than_one' => 0]);
$check(count($w) === 2 && strpos($w[0], '#7') !== false, '(7b) blank code -> warnings name the raw by #id', 2, $w);

$all = implode(' ', FormulaCalcService::dataGapWarnings(['internal_code' => 'X', 'specific_gravity' => null, 'voc_wt' => null]));
$check(strpos($all, 'Not determined') === false && strpos($all, 'Not applicable') === false,
    '(8) warnings carry no "Not determined" / "Not applicable" sentinels', 'no sentinel', $all);

restore_error_handler();

echo "\n{$passed} passed, {$failed} failed\n";
echo ($failed === 0 ? "ALL PASSED" : "{$failed} FAILED") . "\n";
exit($failed === 0 ? 0 : 1);
