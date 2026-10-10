#!/usr/bin/env php
<?php
/**
 * FormulaCalcService::deriveFormulaProperties() — initial boiling point
 * roll-up (SDS content audit item #16).
 *
 * Rule: formula_props.boiling_point_c = the LOWEST boiling_point_c across all
 * enriched lines that carry one (enriched lines are already flattened through
 * sub-FG components, so this is recursive); wt% is ignored; lines with
 * null / '' are skipped; none at all → null. No weighted average.
 *
 * DB-free: the method is pure, so it is invoked through reflection with
 * synthetic enriched lines shaped like enrichFormulaLines() output.
 *
 * Run:  php tests/Services/FormulaCalcServiceBoilingPointTest.php
 * Exit: 0 = all cases passed, 1 = one or more failed
 */

declare(strict_types=1);

error_reporting(E_ALL);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SDS\Services\FormulaCalcService;

$svc = new FormulaCalcService();
$m   = new ReflectionMethod(FormulaCalcService::class, 'deriveFormulaProperties');
$m->setAccessible(true);

// $bp is mixed on purpose: float, null, '' and PDO-style numeric strings are all exercised.
$line = static function (int $rmId, float $pct, $bp = null, ?float $fp = null): array {
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
        'flash_point_greater_than' => 0,
        'boiling_point_c'          => $bp,
        'physical_state'           => null,
        'solubility'               => null,
        'appearance'               => null,
        'odor'                     => null,
        'constituents'             => [],
    ];
};

$cases = [
    '1. lowest wins regardless of pct (5% RM at 78.4 beats 95% RM at 150)' => [
        'lines' => [$line(1, 5.0, 78.4), $line(2, 95.0, 150.0)],
        'bp'    => 78.4,
        'fp'    => null,
    ],
    '2. raws without a value are skipped' => [
        'lines' => [$line(1, 50.0, null), $line(2, 50.0, 100.0)],
        'bp'    => 100.0,
        'fp'    => null,
    ],
    '3. empty string treated as no value' => [
        'lines' => [$line(1, 100.0, '')],
        'bp'    => null,
        'fp'    => null,
    ],
    '3b. empty string skipped, other RM value used' => [
        'lines' => [$line(1, 60.0, ''), $line(2, 40.0, 110.0)],
        'bp'    => 110.0,
        'fp'    => null,
    ],
    '4. none at all → null' => [
        'lines' => [$line(1, 100.0, null)],
        'bp'    => null,
        'fp'    => null,
    ],
    '5. no lines → null' => [
        'lines' => [],
        'bp'    => null,
        'fp'    => null,
    ],
    '6. same RM on several flattened sub-FG lines gives the same answer as once' => [
        'lines' => [$line(1, 30.0, 56.0), $line(1, 30.0, 56.0), $line(2, 40.0, 80.0)],
        'bp'    => 56.0,
        'fp'    => null,
    ],
    '7. PDO string numerics are cast' => [
        'lines' => [$line(1, 50.0, '78.4'), $line(2, 50.0, '120.0')],
        'bp'    => 78.4,
        'fp'    => null,
    ],
    '8a. negative value honoured as lowest' => [
        'lines' => [$line(1, 50.0, -0.5), $line(2, 50.0, 20.0)],
        'bp'    => -0.5,
        'fp'    => null,
    ],
    '8b. zero is a value, not "missing", and beats 20.0' => [
        'lines' => [$line(1, 50.0, 0.0), $line(2, 50.0, 20.0)],
        'bp'    => 0.0,
        'fp'    => null,
    ],
    '9. flash point roll-up independent of the boiling point (Q1 weighted average: 50% x 60 + 50% x 12 = 36)' => [
        'lines' => [$line(1, 50.0, 150.0, 60.0), $line(2, 50.0, 78.4, 12.0)],
        'bp'    => 78.4,
        'fp'    => 36.0,
    ],
];

$failed = 0;
foreach ($cases as $name => $c) {
    $props = $m->invoke($svc, $c['lines']);
    $ok = array_key_exists('boiling_point_c', $props)
        && $props['boiling_point_c'] === $c['bp']
        && array_key_exists('flash_point_c', $props)
        && $props['flash_point_c'] === $c['fp'];
    echo ($ok ? 'PASS' : 'FAIL') . ": {$name}\n";
    if (!$ok) {
        $failed++;
        echo '  expected bp=' . var_export($c['bp'], true) . ' fp=' . var_export($c['fp'], true) . "\n";
        echo '  got      bp=' . var_export($props['boiling_point_c'] ?? '(missing)', true)
            . ' fp=' . var_export($props['flash_point_c'] ?? '(missing)', true) . "\n";
    }
}

// Existing keys still present on the no-lines result (#17 / #11 contract unchanged)
$props = $m->invoke($svc, []);
foreach (['all_voc_less_than_one', 'flash_point_c', 'flash_point_greater_than', 'boiling_point_c', 'solubility_key', 'odor', 'appearance', 'dominant_raw_material_id'] as $k) { // #18(d): solubility -> solubility_key
    $ok = array_key_exists($k, $props);
    echo ($ok ? 'PASS' : 'FAIL') . ": no-lines result has key {$k}\n";
    if (!$ok) {
        $failed++;
    }
}

echo ($failed === 0 ? "ALL PASSED" : "{$failed} FAILED") . "\n";
exit($failed === 0 ? 0 : 1);
