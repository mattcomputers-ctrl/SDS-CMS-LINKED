<?php
/**
 * DB-free checks for composition bounds and prescribed-range bands
 * (SDS data-source audit #16 and #38):
 *
 *   1. Formula::constituentBounds() per row shape; its upper bound always
 *      equals Formula::resolveConstituentPct().
 *   2. SDSGenerator::formatConcentration(): widest band containing [min, max],
 *      else the band containing max with the lowest lower end (the printed
 *      upper end is never below the real maximum).
 *   3. Formula::getExpandedComposition() with a fake Database: exact, ranged,
 *      nested finished-good and trade-secret contributions all reach
 *      concentration_min / concentration_max (the finding's example printed
 *      '<0.1%' for a CAS at 27-31 %).
 *   4. FormulaCalcService::buildResaleComposition(): range MAXIMUM (was the
 *      midpoint), min/max carried, trade-secret / element flags merged.
 *   5. #38 parity: a resale RM and a finished good that is that RM at 100 %
 *      give the same rows and bands.
 *
 * Same Reflection bootstrap as tests/smoke_pdf.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/CompositionBandsTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Models\Formula;

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

function near(float $a, float $b, float $eps = 1e-6): bool
{
    return abs($a - $b) < $eps;
}

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);
$fmtM = new ReflectionMethod($gen, 'formatConcentration');
$fmtM->setAccessible(true);
$band = static fn(array $row): string => $fmtM->invoke($gen, $row);

// ---------------------------------------------------------------------
echo "1. Formula::constituentBounds\n";
$rowOf = static fn($exact, $min, $max): array => ['pct_exact' => $exact, 'pct_min' => $min, 'pct_max' => $max];
$cases = [
    'exact 5'    => [$rowOf('5.0000', null, null), [5.0, 5.0]],
    'range 10-30' => [$rowOf(null, '10.0000', '30.0000'), [10.0, 30.0]],
    'min-only 2' => [$rowOf(null, '2.0000', null), [2.0, 2.0]],
    'max-only 5' => [$rowOf(null, null, '5.0000'), [0.0, 5.0]],
    'none'       => [$rowOf(null, null, null), [0.0, 0.0]],
];
foreach ($cases as $label => [$row, $want]) {
    $got = Formula::constituentBounds($row);
    check($got === $want, "bounds {$label}", $got);
    check($got[1] === Formula::resolveConstituentPct($row), "upper bound == resolveConstituentPct ({$label})");
}

// ---------------------------------------------------------------------
echo "2. SDSGenerator::formatConcentration\n";
$mm = static fn(float $min, float $max): array => ['concentration_pct' => $max, 'concentration_min' => $min, 'concentration_max' => $max];
check($band($mm(25.01, 25.03)) === '15 - 40%', '[25.01, 25.03] -> 15 - 40%', $band($mm(25.01, 25.03)));
check($band(['concentration_pct' => 25.03]) === '15 - 40%', 'pct-only 25.03 -> 15 - 40%');
check($band($mm(0.1, 100)) === '80 - 100%', '[0.1, 100] -> 80 - 100% (was 30 - 60%)', $band($mm(0.1, 100)));
check($band($mm(10, 30)) === '10 - 30%', '[10, 30] -> 10 - 30%');
check($band($mm(0, 5)) === '1 - 5%', '[0, 5] -> 1 - 5% (max-only <5 %)', $band($mm(0, 5)));
check($band($mm(5, 50)) === '30 - 60%', '[5, 50] -> 30 - 60%', $band($mm(5, 50)));
check($band($mm(0.05, 0.5)) === '0.1 - 1%', '[0.05, 0.5] -> 0.1 - 1%', $band($mm(0.05, 0.5)));
check($band($mm(0.01, 0.03)) === '<0.1%', '[0.01, 0.03] -> <0.1%');
check($band(['concentration_pct' => 20, 'concentration_min' => 30, 'concentration_max' => 10]) === '10 - 30%', 'swapped [30, 10] -> 10 - 30%');
check($band($mm(99, 100.0001)) === '80 - 100%', '[99, 100.0001] -> 80 - 100%');

$grid = [0, 0.05, 0.1, 0.5, 1, 2.5, 5, 9, 12, 25, 33, 50, 64, 79, 90, 100];
$sweepBad = [];
foreach ($grid as $gMin) {
    foreach ($grid as $gMax) {
        if ($gMin > $gMax) {
            continue;
        }
        $out = $band($mm((float) $gMin, (float) $gMax));
        if ($out === '<0.1%') {
            if (!($gMax < 0.1)) {
                $sweepBad[] = "[{$gMin},{$gMax}] => {$out}";
            }
            continue;
        }
        if (!preg_match('/^([\d.]+) - ([\d.]+)%$/', $out, $m)) {
            $sweepBad[] = "[{$gMin},{$gMax}] => unparsable {$out}";
            continue;
        }
        if (!((float) $m[2] >= $gMax - 1e-9 && (float) $m[1] <= $gMax)) {
            $sweepBad[] = "[{$gMin},{$gMax}] => {$out}";
        }
    }
}
check($sweepBad === [], 'sweep: band upper end >= max, lower end <= max, <0.1% only below 0.1', $sweepBad);

// ---------------------------------------------------------------------
// Fake Database (routes queries by SQL substring)
// ---------------------------------------------------------------------
$constRow = static function (int $rmId, string $linePct, string $cas, string $name, $exact, $min, $max, array $extra = []): array {
    return array_merge([
        'raw_material_id'          => $rmId,
        'line_pct'                 => $linePct,
        'internal_code'            => 'RM' . $rmId,
        'cas_number'               => $cas,
        'chemical_name'            => $name,
        'pct_exact'                => $exact,
        'pct_min'                  => $min,
        'pct_max'                  => $max,
        'is_trade_secret'          => 0,
        'trade_secret_description' => null,
        'trade_secret_h_codes'     => null,
        'has_nitrogen'             => 0,
        'has_sulfur'               => 0,
        'has_halogen'              => 0,
    ], $extra);
};

$makeFake = static function (array $fx): \SDS\Core\Database {
    return new class($fx) extends \SDS\Core\Database {
        private array $fx;
        public function __construct(array $fx)
        {
            $this->fx = $fx;
        }
        public function fetch(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'SELECT finished_good_id FROM formulas WHERE id')) {
                $fg = $this->fx['formula_fg'][(int) $params[0]] ?? null;
                return $fg === null ? null : ['finished_good_id' => $fg];
            }
            return null;
        }
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'JOIN raw_material_constituents')) {
                return $this->fx['constituents'][(int) $params[0]] ?? [];
            }
            if (str_contains($sql, 'rm.hazardous_no_cas = 1')) {
                return $this->fx['ts_rms'][(int) $params[0]] ?? [];
            }
            if (str_contains($sql, 'JOIN finished_goods fg')) {
                return $this->fx['fg_lines'][(int) $params[0]] ?? [];
            }
            if (str_contains($sql, 'WHERE finished_good_id IN')) {
                $out = [];
                foreach ($params as $fgId) {
                    if (isset($this->fx['current_formula'][(int) $fgId])) {
                        $out[] = ['id' => $this->fx['current_formula'][(int) $fgId], 'finished_good_id' => (int) $fgId];
                    }
                }
                return $out;
            }
            return [];
        }
    };
};

$inst = new ReflectionProperty(\SDS\Core\Database::class, 'instance');
$inst->setAccessible(true);
$savedDb = $inst->getValue();

// ---------------------------------------------------------------------
echo "3. Formula::getExpandedComposition (#16 example)\n";
$fx = [
    'formula_fg' => [1 => 101, 2 => 102],
    'constituents' => [
        1 => [
            $constRow(10, '1.0000', '111-76-2', '2-Butoxyethanol', null, '1.0000', '3.0000'),
            $constRow(11, '50.0000', '111-76-2', '2-Butoxyethanol', '50.0000', null, null),
        ],
        2 => [
            $constRow(12, '100.0000', '111-76-2', '2-Butoxyethanol', null, '10.0000', '30.0000'),
        ],
    ],
    'ts_rms' => [
        1 => [['raw_material_id' => 13, 'line_pct' => '4.0000', 'internal_code' => 'RM13', 'manual_hazard_json' => null]],
        2 => [['raw_material_id' => 14, 'line_pct' => '10.0000', 'internal_code' => 'RM14', 'manual_hazard_json' => null]],
    ],
    'fg_lines' => [
        1 => [
            ['finished_good_component_id' => 102, 'line_pct' => '20.0000', 'component_product_code' => 'BASE C'],
            ['finished_good_component_id' => 103, 'line_pct' => '5.0000', 'component_product_code' => 'RED BASE'],
        ],
    ],
    'current_formula' => [102 => 2],   // FG 103 has no current formula
];
$inst->setValue(null, $makeFake($fx));
$comp = null;
try {
    $comp = Formula::getExpandedComposition(1);
} catch (\Throwable $e) {
    check(false, 'getExpandedComposition ran without error', $e->getMessage());
}
$byCas = [];
foreach ((array) $comp as $row) {
    $byCas[$row['cas_number']] = $row;
}
$c = $byCas['111-76-2'] ?? null;
check($c !== null, 'CAS 111-76-2 present');
if ($c !== null) {
    check(near((float) $c['concentration_pct'], 31.03), 'CAS pct 31.03', $c['concentration_pct']);
    check(near((float) $c['concentration_min'], 27.01), 'CAS min 27.01 (was 0.01)', $c['concentration_min']);
    check(near((float) $c['concentration_max'], 31.03), 'CAS max 31.03 (was 0.03)', $c['concentration_max']);
    check($band($c) === '15 - 40%', "CAS band 15 - 40% (was '<0.1%')", $band($c));
}
$ts = $byCas['TRADE_SECRET'] ?? null;
check($ts !== null, 'TRADE_SECRET bucket present');
if ($ts !== null) {
    check(near((float) $ts['concentration_pct'], 6.0), 'TS pct 6', $ts['concentration_pct']);
    check(near((float) $ts['concentration_min'], 6.0) && near((float) $ts['concentration_max'], 6.0), 'TS min = max = 6', [$ts['concentration_min'], $ts['concentration_max']]);
}
check(count((array) $comp) === 2, 'FG 103 (no current formula) ignored without error', $comp);
$inv = true;
foreach ((array) $comp as $row) {
    if (!isset($row['concentration_min'], $row['concentration_max'])
        || !is_float($row['concentration_min']) || !is_float($row['concentration_max'])
        || $row['concentration_min'] > $row['concentration_pct'] + 1e-9
        || !near((float) $row['concentration_pct'], (float) $row['concentration_max'])) {
        $inv = false;
    }
}
check($inv, 'every row: numeric min/max, min <= pct, max == pct');

// ---------------------------------------------------------------------
echo "4. FormulaCalcService::buildResaleComposition (#38)\n";
$calc = new \SDS\Services\FormulaCalcService();
$resaleM = new ReflectionMethod($calc, 'buildResaleComposition');
$resaleM->setAccessible(true);

$rmConstituents = [
    ['cas_number' => '222-22-2', 'chemical_name' => 'Acrylic resin', 'pct_exact' => null, 'pct_min' => '10.0000', 'pct_max' => '30.0000',
     'is_trade_secret' => 0, 'is_non_hazardous' => 0, 'trade_secret_description' => null, 'has_nitrogen' => 0, 'has_sulfur' => 0, 'has_halogen' => 0],
    ['cas_number' => '333-33-3', 'chemical_name' => 'Copolymer', 'pct_exact' => '5.0000', 'pct_min' => null, 'pct_max' => null,
     'is_trade_secret' => 0, 'is_non_hazardous' => 0, 'trade_secret_description' => null, 'has_nitrogen' => 0, 'has_sulfur' => 0, 'has_halogen' => 0],
    ['cas_number' => '333-33-3', 'chemical_name' => 'Copolymer', 'pct_exact' => '2.0000', 'pct_min' => null, 'pct_max' => null,
     'is_trade_secret' => 1, 'is_non_hazardous' => 0, 'trade_secret_description' => 'Acrylic copolymer', 'has_nitrogen' => 1, 'has_sulfur' => 0, 'has_halogen' => 0],
];
$rm = ['id' => 20, 'internal_code' => 'RM20', 'hazardous_no_cas' => 0, 'constituents' => $rmConstituents];
$resale = $resaleM->invoke($calc, $rm);
$rByCas = [];
foreach ($resale as $row) {
    $rByCas[$row['cas_number']] = $row;
}
$a = $rByCas['222-22-2'] ?? [];
check(near((float) ($a['concentration_pct'] ?? -1), 30.0), 'resale 222-22-2 pct 30 (range max; was midpoint 20)', $a['concentration_pct'] ?? null);
check(near((float) ($a['concentration_min'] ?? -1), 10.0) && near((float) ($a['concentration_max'] ?? -1), 30.0), 'resale 222-22-2 min 10 / max 30');
check($band($a) === '10 - 30%', 'resale 222-22-2 band 10 - 30% (was 15 - 40%)', $band($a));
$b = $rByCas['333-33-3'] ?? [];
check(near((float) ($b['concentration_pct'] ?? -1), 7.0) && near((float) ($b['concentration_min'] ?? -1), 7.0) && near((float) ($b['concentration_max'] ?? -1), 7.0), 'resale 333-33-3 pct = min = max = 7');
check(($b['is_trade_secret'] ?? null) === true, 'resale 333-33-3 trade secret from the second row (OR merge)');
check(array_column($b['contributing_materials'] ?? [], 'is_trade_secret') === [false, true], 'resale 333-33-3 per-material is_trade_secret flags [false, true] (#36(1) parity)', array_column($b['contributing_materials'] ?? [], 'is_trade_secret'));
check(($b['trade_secret_description'] ?? null) === 'Acrylic copolymer', 'resale 333-33-3 description from the trade-secret row');
check(($b['has_nitrogen'] ?? null) === true, 'resale 333-33-3 has_nitrogen OR-merged');
// #17 / Q5: the non-hazardous flag is no longer read or carried.
check(!array_key_exists('is_non_hazardous', $b), 'resale 333-33-3 carries no is_non_hazardous key (#17)');
$tsResale = $resaleM->invoke($calc, ['id' => 21, 'internal_code' => 'RM21', 'hazardous_no_cas' => 1, 'manual_hazard_json' => null]);
check(($tsResale[0]['concentration_min'] ?? null) === 100.0 && ($tsResale[0]['concentration_max'] ?? null) === 100.0, 'resale hazardous_no_cas RM: min = max = 100');

// Integration: a blank-CAS trade-secret constituent with declared H-codes
// reaches the resale TRADE_SECRET bucket (as Formula::getExpandedComposition
// routes it), and its real chemical name is never carried (Q4).
$tsConst = static fn(?string $desc, string $name, string $codes, $min, $max): array => [
    'cas_number' => '', 'chemical_name' => $name, 'pct_exact' => null, 'pct_min' => $min, 'pct_max' => $max,
    'is_trade_secret' => 1, 'trade_secret_description' => $desc, 'trade_secret_h_codes' => $codes,
    'has_nitrogen' => 0, 'has_sulfur' => 0, 'has_halogen' => 0,
];
$tsRows = $resaleM->invoke($calc, ['id' => 22, 'internal_code' => 'RM22', 'hazardous_no_cas' => 0, 'constituents' => [
    ['cas_number' => '222-22-2', 'chemical_name' => 'Acrylic resin', 'pct_exact' => '60.0000', 'pct_min' => null, 'pct_max' => null,
     'is_trade_secret' => 0, 'trade_secret_description' => null, 'has_nitrogen' => 0, 'has_sulfur' => 0, 'has_halogen' => 0],
    $tsConst('Sensitizing monomer', 'Secret acrylate XYZ', 'H317, H302', '1.0000', '3.0000'),
    ['cas_number' => '', 'chemical_name' => 'Undeclared', 'pct_exact' => '1.0000', 'pct_min' => null, 'pct_max' => null,
     'is_trade_secret' => 1, 'trade_secret_description' => null, 'trade_secret_h_codes' => '', 'has_nitrogen' => 0, 'has_sulfur' => 0, 'has_halogen' => 0],
]]);
$tsByCas = [];
foreach ($tsRows as $row) {
    $tsByCas[$row['cas_number']] = $row;
}
$tsB = $tsByCas['TRADE_SECRET'] ?? [];
check($tsB !== [], 'resale blank-CAS trade secret with H-codes -> TRADE_SECRET bucket', array_keys($tsByCas));
check(near((float) ($tsB['concentration_pct'] ?? -1), 3.0) && near((float) ($tsB['concentration_min'] ?? -1), 1.0) && near((float) ($tsB['concentration_max'] ?? -1), 3.0),
    'resale TRADE_SECRET pct 3 (range max), min 1, max 3', [$tsB['concentration_pct'] ?? null, $tsB['concentration_min'] ?? null, $tsB['concentration_max'] ?? null]);
$tsJson = $tsB['manual_hazard_json'][0] ?? [];
check(in_array('H317', (array) ($tsJson['h_statements'] ?? $tsJson['h_codes'] ?? []), true) || str_contains(json_encode($tsJson), 'H317'),
    'resale TRADE_SECRET carries the declared H317 as hazard JSON', $tsJson);
check(near((float) ($tsJson['_contribution_pct'] ?? -1), 3.0), 'resale TRADE_SECRET hazard JSON stamped with its real share (#15)', $tsJson['_contribution_pct'] ?? null);
check(($tsB['trade_secret_description'] ?? null) === 'Sensitizing monomer', 'resale TRADE_SECRET description from the trade-secret row');
check(!str_contains(json_encode($tsRows), 'Secret acrylate XYZ'), 'resale rows never carry the trade-secret chemical name (Q4)');
check(count($tsRows) === 2, 'blank-CAS trade secret without H-codes still skipped (no CAS, no declaration)', array_keys($tsByCas));

// ---------------------------------------------------------------------
echo "5. #38 parity: resale RM vs finished good = that RM at 100 %\n";
$parityRows = [];
foreach ($rmConstituents as $rc) {
    $parityRows[] = $constRow(20, '100.0000', $rc['cas_number'], $rc['chemical_name'], $rc['pct_exact'], $rc['pct_min'], $rc['pct_max'], [
        'is_trade_secret' => $rc['is_trade_secret'],
        'trade_secret_description' => $rc['trade_secret_description'],
        'has_nitrogen' => $rc['has_nitrogen'],
    ]);
}
$inst->setValue(null, $makeFake([
    'formula_fg' => [5 => 500],
    'constituents' => [5 => $parityRows],
    'ts_rms' => [], 'fg_lines' => [], 'current_formula' => [],
]));
$fgComp = Formula::getExpandedComposition(5);
$fByCas = [];
foreach ($fgComp as $row) {
    $fByCas[$row['cas_number']] = $row;
}
check(array_keys($fByCas) === array_keys($rByCas), 'same CAS list in the same order', [array_keys($fByCas), array_keys($rByCas)]);
foreach ($rByCas as $cas => $rRow) {
    $fRow = $fByCas[$cas] ?? [];
    foreach (['concentration_pct', 'concentration_min', 'concentration_max'] as $k) {
        check(near((float) ($fRow[$k] ?? -1), (float) $rRow[$k]), "{$cas} {$k} identical", [$fRow[$k] ?? null, $rRow[$k]]);
    }
    check(($fRow['is_trade_secret'] ?? null) === $rRow['is_trade_secret'], "{$cas} is_trade_secret identical");
    check(($fRow['trade_secret_description'] ?? null) === $rRow['trade_secret_description'], "{$cas} trade_secret_description identical");
    check($band($fRow) === $band($rRow), "{$cas} band identical ({$band($rRow)})");
}

$inst->setValue(null, $savedDb);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
