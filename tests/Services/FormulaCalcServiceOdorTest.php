#!/usr/bin/env php
<?php
/**
 * FormulaCalcService::deriveFormulaProperties() — dominant-RM odor/appearance
 * derivation (SDS content audit item #17).
 *
 * DB-free: the method is pure, so it is invoked through reflection with
 * synthetic enriched lines shaped like enrichFormulaLines() output.
 *
 * Run:  php tests/Services/FormulaCalcServiceOdorTest.php
 * Exit: 0 = all cases passed, 1 = one or more failed
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SDS\Services\FormulaCalcService;

$svc = new FormulaCalcService();
$m   = new ReflectionMethod(FormulaCalcService::class, 'deriveFormulaProperties');
$m->setAccessible(true);

$line = static function (int $rmId, float $pct, ?string $odor, ?string $appearance = null): array {
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
        'flash_point_c'            => null,
        'flash_point_greater_than' => 0,
        'physical_state'           => null,
        'solubility'               => null,
        'appearance'               => $appearance,
        'odor'                     => $odor,
        'constituents'             => [],
    ];
};

$cases = [
    'dominant RM odor and appearance win' => [
        'lines'      => [$line(1, 60.0, 'Mild acrylic', 'Clear viscous liquid'), $line(2, 40.0, 'Strong solvent', 'Clear liquid')],
        'odor'       => 'Mild acrylic',
        'appearance' => 'Clear viscous liquid',
        'dominant'   => 1,
    ],
    'same RM split across sub-FG lines is summed before ranking' => [
        'lines'      => [$line(2, 35.0, 'Strong solvent'), $line(1, 30.0, 'Mild acrylic'), $line(1, 30.0, 'Mild acrylic')],
        'odor'       => 'Mild acrylic',
        'appearance' => '',
        'dominant'   => 1,
    ],
    'dominant RM with blank odor yields empty string (generator prints Not determined)' => [
        'lines'      => [$line(1, 70.0, '  '), $line(2, 30.0, 'Fishy')],
        'odor'       => '',
        'appearance' => '',
        'dominant'   => 1,
    ],
    'null odor is treated as blank' => [
        'lines'      => [$line(1, 100.0, null)],
        'odor'       => '',
        'appearance' => '',
        'dominant'   => 1,
    ],
    'tie keeps the first-seen RM' => [
        'lines'      => [$line(5, 50.0, 'Odorless'), $line(6, 50.0, 'Ammoniacal')],
        'odor'       => 'Odorless',
        'appearance' => '',
        'dominant'   => 5,
    ],
    'no lines' => [
        'lines'      => [],
        'odor'       => '',
        'appearance' => '',
        'dominant'   => null,
    ],
];

$failed = 0;
foreach ($cases as $name => $c) {
    $props = $m->invoke($svc, $c['lines']);
    $ok = ($props['odor'] ?? null) === $c['odor']
        && ($props['appearance'] ?? null) === $c['appearance']
        && ($props['dominant_raw_material_id'] ?? null) === $c['dominant'];
    echo ($ok ? 'PASS' : 'FAIL') . ": {$name}\n";
    if (!$ok) {
        $failed++;
        echo '  expected odor=' . var_export($c['odor'], true)
            . ' appearance=' . var_export($c['appearance'], true)
            . ' dominant=' . var_export($c['dominant'], true) . "\n";
        echo '  got      odor=' . var_export($props['odor'] ?? null, true)
            . ' appearance=' . var_export($props['appearance'] ?? null, true)
            . ' dominant=' . var_export($props['dominant_raw_material_id'] ?? null, true) . "\n";
    }
}

echo ($failed === 0 ? "ALL PASSED" : "{$failed} FAILED") . "\n";
exit($failed === 0 ? 0 : 1);
