#!/usr/bin/env php
<?php
/**
 * FormulaCalcService::deriveFormulaProperties() — formula-level solubility
 * band and physical state (SDS content audit items #18 (b)/(d), #42(1), Q7).
 *
 *   F = (soluble wt% + 0.5 x partially-soluble wt% + 0.03 x negligible wt%)
 *       / (wt% of raws that carry ANY solubility value)  -> soluble_fraction_pct
 *   F >= 90 soluble | 5 <= F < 90 partially_soluble | 1 <= F < 5 negligible
 *   | F < 1 not_soluble | no raw with a value -> null  -> solubility_key
 *   physical_state = physical_state of the highest summed-wt% raw material
 *   that HAS one (#42(1)); '' when none has.
 *
 * DB-free: the method is pure, so it is invoked through reflection with
 * synthetic enriched lines shaped like enrichFormulaLines() output.
 *
 * Run:  php tests/Services/FormulaCalcServiceSolubilityTest.php
 * Exit: 0 = all cases passed, 1 = one or more failed
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SDS\Services\FormulaCalcService;

$svc = new FormulaCalcService();
$m   = new ReflectionMethod(FormulaCalcService::class, 'deriveFormulaProperties');
$m->setAccessible(true);

$line = static function (int $rmId, float $pct, ?string $solubility = null, ?string $physicalState = null): array {
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
        'boiling_point_c'          => null,
        'physical_state'           => $physicalState,
        'solubility'               => $solubility,
        'appearance'               => null,
        'odor'                     => null,
        'constituents'             => [],
    ];
};

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

// ---------------------------------------------------------------------
// 1. Thresholds via the pure band helper
// ---------------------------------------------------------------------
$bands = [
    [100.0,  'soluble'],
    [90.0,   'soluble'],
    [89.99,  'partially_soluble'],
    [5.0,    'partially_soluble'],
    [4.99,   'negligible'],
    [1.0,    'negligible'],
    [0.99,   'not_soluble'],
    [0.0,    'not_soluble'],
];
foreach ($bands as [$f, $expected]) {
    $got = FormulaCalcService::solubilityKeyForFraction($f);
    $check($got === $expected, "solubilityKeyForFraction({$f}) = {$expected}", $expected, $got);
}
$check(FormulaCalcService::SOLUBLE_PCT === 90.0 && FormulaCalcService::PARTIAL_PCT === 5.0 && FormulaCalcService::NEGLIGIBLE_PCT === 1.0,
    'threshold constants are 90 / 5 / 1', [90.0, 5.0, 1.0],
    [FormulaCalcService::SOLUBLE_PCT, FormulaCalcService::PARTIAL_PCT, FormulaCalcService::NEGLIGIBLE_PCT]);

// ---------------------------------------------------------------------
// 2..11. Soluble fraction over the raws that have a value
// ---------------------------------------------------------------------
$S = 'Soluble in water';
$P = 'Partially soluble in water';
$N = 'Negligible solubility in water';
$I = 'Insoluble in water';

$cases = [
    'all raws Soluble (60/40) -> soluble, F=100' => [
        'lines' => [$line(1, 60.0, $S), $line(2, 40.0, $S)],
        'key'   => 'soluble', 'f' => 100.0,
    ],
    '50 Soluble + 50 Insoluble -> F=50 partially_soluble' => [
        'lines' => [$line(1, 50.0, $S), $line(2, 50.0, $I)],
        'key'   => 'partially_soluble', 'f' => 50.0,
    ],
    '10 Partially + 90 Insoluble -> F=5 partially_soluble (boundary inclusive)' => [
        'lines' => [$line(1, 10.0, $P), $line(2, 90.0, $I)],
        'key'   => 'partially_soluble', 'f' => 5.0,
    ],
    '2 Soluble + 98 Negligible -> F=4.94 negligible (Q7)' => [
        'lines' => [$line(1, 2.0, $S), $line(2, 98.0, $N)],
        'key'   => 'negligible', 'f' => 4.94,
    ],
    '60 Negligible + 40 Insoluble -> F=1.8 negligible (waterborne after migration 054)' => [
        'lines' => [$line(1, 60.0, $N), $line(2, 40.0, $I)],
        'key'   => 'negligible', 'f' => 1.8,
    ],
    '50 Soluble + 50 Negligible -> F=51.5 partially_soluble (Q7)' => [
        'lines' => [$line(1, 50.0, $S), $line(2, 50.0, $N)],
        'key'   => 'partially_soluble', 'f' => 51.5,
    ],
    '0.5 Soluble + 99.5 Insoluble -> F=0.5 not_soluble' => [
        'lines' => [$line(1, 0.5, $S), $line(2, 99.5, $I)],
        'key'   => 'not_soluble', 'f' => 0.5,
    ],
    'denominator excludes blanks: 1 Soluble + 99 null -> F=100 soluble' => [
        'lines' => [$line(1, 1.0, $S), $line(2, 99.0, null)],
        'key'   => 'soluble', 'f' => 100.0,
    ],
    'whitespace solubility is a blank (excluded from the denominator)' => [
        'lines' => [$line(1, 1.0, $S), $line(2, 99.0, '   ')],
        'key'   => 'soluble', 'f' => 100.0,
    ],
    'only Partially soluble raws -> F=50 partially_soluble' => [
        'lines' => [$line(1, 70.0, $P), $line(2, 30.0, $P)],
        'key'   => 'partially_soluble', 'f' => 50.0,
    ],
    'no raw has a value -> key null, fraction null' => [
        'lines' => [$line(1, 60.0, null), $line(2, 40.0, '')],
        'key'   => null, 'f' => null,
    ],
    'no lines -> key null, fraction null' => [
        'lines' => [],
        'key'   => null, 'f' => null,
    ],
    'sub-FG split: same RM on two lines (30+30 Soluble) + 40 Insoluble -> F=60' => [
        'lines' => [$line(1, 30.0, $S), $line(2, 40.0, $I), $line(1, 30.0, $S)],
        'key'   => 'partially_soluble', 'f' => 60.0,
    ],
    'unknown string counts in the denominator at weight 0: 50 Miscible + 50 Soluble -> F=50' => [
        'lines' => [$line(1, 50.0, 'Miscible'), $line(2, 50.0, $S)],
        'key'   => 'partially_soluble', 'f' => 50.0,
    ],
    'all Negligible -> F=3 negligible (Q7)' => [
        'lines' => [$line(1, 100.0, $N)],
        'key'   => 'negligible', 'f' => 3.0,
    ],
    'all Insoluble -> F=0 not_soluble' => [
        'lines' => [$line(1, 100.0, $I)],
        'key'   => 'not_soluble', 'f' => 0.0,
    ],
    'mixed: 45 Soluble + 10 Partially + 45 Insoluble -> F=50' => [
        'lines' => [$line(1, 45.0, $S), $line(2, 10.0, $P), $line(3, 45.0, $I)],
        'key'   => 'partially_soluble', 'f' => 50.0,
    ],
];

foreach ($cases as $name => $c) {
    $props = $m->invoke($svc, $c['lines']);
    // null is a legitimate value (no raw carries a solubility), so test key presence explicitly.
    $gotKey = array_key_exists('solubility_key', $props) ? $props['solubility_key'] : 'MISSING';
    $gotF   = array_key_exists('soluble_fraction_pct', $props) ? $props['soluble_fraction_pct'] : 'MISSING';
    $fOk = $c['f'] === null ? $gotF === null : (is_float($gotF) && abs($gotF - $c['f']) < 0.0001);
    $check($gotKey === $c['key'] && $fOk, $name, ['key' => $c['key'], 'f' => $c['f']], ['key' => $gotKey, 'f' => $gotF]);
}

// Q7: Negligible weighs 0.03.
$check(FormulaCalcService::SOLUBILITY_WEIGHTS['Negligible solubility in water'] === 0.03,
    'Q7: SOLUBILITY_WEIGHTS[Negligible] === 0.03', 0.03, FormulaCalcService::SOLUBILITY_WEIGHTS['Negligible solubility in water']);

// The legacy 'solubility' string is gone from formula_props (#18(d)).
$props = $m->invoke($svc, [$line(1, 100.0, $S)]);
$check(!array_key_exists('solubility', $props), 'formula_props no longer carries the legacy solubility string', false, array_key_exists('solubility', $props));

// ---------------------------------------------------------------------
// 12. physical_state = largest (summed wt%) raw material that HAS a state (#42(1))
// ---------------------------------------------------------------------
$psCases = [
    'dominant Powder (70) + Liquid (30) -> Powder' => [
        'lines' => [$line(1, 70.0, null, 'Powder'), $line(2, 30.0, null, 'Liquid')],
        'state' => 'Powder',
    ],
    'dominant Liquid (55) + Powder (45) -> Liquid' => [
        'lines' => [$line(2, 45.0, null, 'Powder'), $line(1, 55.0, null, 'Liquid')],
        'state' => 'Liquid',
    ],
    'same RM split across sub-FG lines is summed before ranking' => [
        'lines' => [$line(2, 35.0, null, 'Liquid'), $line(1, 30.0, null, 'Paste'), $line(1, 30.0, null, 'Paste')],
        'state' => 'Paste',
    ],
    'dominant blank -> next-largest raw with a state (#42)' => [
        'lines' => [$line(1, 70.0, null, '  '), $line(2, 30.0, null, 'Powder')],
        'state' => 'Powder',
    ],
    'all blank -> empty string' => [
        'lines' => [$line(1, 70.0, null, ' '), $line(2, 30.0, null, null)],
        'state' => '',
    ],
    'blank 60 + Liquid 25 + Powder 15 -> Liquid (largest with a state)' => [
        'lines' => [$line(1, 60.0, null, ''), $line(2, 15.0, null, 'Powder'), $line(3, 25.0, null, 'Liquid')],
        'state' => 'Liquid',
    ],
    'dominant null -> empty string' => [
        'lines' => [$line(1, 100.0, null, null)],
        'state' => '',
    ],
    'no lines -> empty string' => [
        'lines' => [],
        'state' => '',
    ],
];
foreach ($psCases as $name => $c) {
    $props = $m->invoke($svc, $c['lines']);
    $got = $props['physical_state'] ?? 'MISSING';
    $check($got === $c['state'], 'physical_state: ' . $name, $c['state'], $got);
}

echo "\n{$passed} passed, {$failed} failed\n";
echo ($failed === 0 ? "ALL PASSED" : "{$failed} FAILED") . "\n";
exit($failed === 0 ? 0 : 1);
