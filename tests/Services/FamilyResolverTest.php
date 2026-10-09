#!/usr/bin/env php
<?php
/**
 * FamilyResolver unit test (SDS content audit #3, DB-free)
 *
 * Exercises the pure core of FamilyResolver with in-memory arrays:
 *   - matchRules(): rule precedence (exact > prefix > contains, longer
 *     pattern wins, own identifier beats alias, lowest rule id), applies_to
 *     filtering, case-insensitivity, pack-stripped base-code matching,
 *     alias code / alias description matching, blank patterns;
 *   - resolveFromData(): manual > rule > content > null across a fixture of
 *     raw materials, intermediates (FG lines and RM-as-FG re-routed lines),
 *     inactive families, ties and a formula cycle;
 *   - pickDominant() and baseCode().
 *
 * Run:
 *   php tests/Services/FamilyResolverTest.php
 *
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

use SDS\Services\FamilyResolver as FR;

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

$rule = static fn(int $id, int $fam, string $type, string $pattern, string $applies = 'both'): array => [
    'id' => $id, 'family_id' => $fam, 'rule_type' => $type, 'pattern' => $pattern, 'applies_to' => $applies,
];

// ---------------------------------------------------------------------
echo "1. matchRules()\n";

$item = ['code' => 'VEC47-55G', 'description' => 'UV FLEXO Cyan', 'alias_codes' => [], 'alias_descriptions' => []];

$rules = [$rule(1, 1, 'code_prefix', 'VEC47'), $rule(2, 2, 'exact_code', 'VEC47-55G'), $rule(3, 3, 'description_contains', 'uv flexo')];
check(FR::matchRules($item, $rules, 'product') === 2, 'exact beats prefix and contains', FR::matchRules($item, $rules, 'product'));
$rules = [$rule(1, 1, 'code_prefix', 'VEC47'), $rule(3, 3, 'description_contains', 'uv flexo')];
check(FR::matchRules($item, $rules, 'product') === 1, 'prefix beats contains', FR::matchRules($item, $rules, 'product'));
$rules = [$rule(3, 3, 'description_contains', 'uv flexo')];
check(FR::matchRules($item, $rules, 'product') === 3, 'contains alone matches (case-insensitive description)', FR::matchRules($item, $rules, 'product'));

$rules = [$rule(1, 1, 'code_prefix', 'VEC'), $rule(2, 2, 'code_prefix', 'VEC47')];
check(FR::matchRules($item, $rules, 'raw_material') === 2, 'longer prefix beats shorter (VEC vs VEC47)', FR::matchRules($item, $rules, 'raw_material'));

$aliasItem = ['code' => 'ABC1', 'description' => '', 'alias_codes' => ['XYZ1'], 'alias_descriptions' => []];
$rules = [$rule(1, 1, 'code_prefix', 'XYZ1'), $rule(2, 2, 'code_prefix', 'ABC1')];
check(FR::matchRules($aliasItem, $rules, 'product') === 2, 'own code beats alias at equal pattern length', FR::matchRules($aliasItem, $rules, 'product'));

$rules = [$rule(5, 2, 'code_prefix', 'VEC'), $rule(3, 1, 'code_prefix', 'VEC')];
check(FR::matchRules($item, $rules, 'product') === 1, 'lowest rule id wins a full tie', FR::matchRules($item, $rules, 'product'));

$rules = [$rule(1, 1, 'code_prefix', 'VEC', 'product')];
check(FR::matchRules($item, $rules, 'raw_material') === null, "applies_to='product' ignored for raw_material", FR::matchRules($item, $rules, 'raw_material'));
check(FR::matchRules($item, $rules, 'product') === 1, "applies_to='product' honoured for product", FR::matchRules($item, $rules, 'product'));
$rules = [$rule(1, 1, 'code_prefix', 'VEC', 'raw_material')];
check(FR::matchRules($item, $rules, 'product') === null, "applies_to='raw_material' ignored for product", FR::matchRules($item, $rules, 'product'));
check(FR::matchRules($item, $rules, 'raw_material') === 1, "applies_to='raw_material' honoured for raw_material", FR::matchRules($item, $rules, 'raw_material'));

$lower = ['code' => 'vec47-55g', 'description' => 'UV FLEXO Cyan'];
$rules = [$rule(1, 1, 'code_prefix', 'VEC47')];
check(FR::matchRules($lower, $rules, 'product') === 1, 'code match is case-insensitive (vec47-55g vs VEC47)', FR::matchRules($lower, $rules, 'product'));
$rules = [$rule(1, 1, 'description_contains', 'uv flexo')];
check(FR::matchRules($lower, $rules, 'product') === 1, 'description match is case-insensitive', FR::matchRules($lower, $rules, 'product'));

$rules = [$rule(1, 1, 'exact_code', 'VEC47')];
check(FR::matchRules($item, $rules, 'product') === 1, 'exact_code VEC47 matches VEC47-55G via pack-stripped base', FR::matchRules($item, $rules, 'product'));
$rules = [$rule(1, 1, 'exact_code', 'VEC47-5')];
check(FR::matchRules($item, $rules, 'product') === null, 'exact_code does not match a partial code', FR::matchRules($item, $rules, 'product'));

$aliasItem = ['code' => 'ABC1', 'description' => 'Plain', 'alias_codes' => ['CUST-1'], 'alias_descriptions' => ['Customer Solvent Base']];
$rules = [$rule(1, 1, 'code_prefix', 'CUST')];
check(FR::matchRules($aliasItem, $rules, 'product') === 1, 'alias code CUST-1 matched by prefix CUST', FR::matchRules($aliasItem, $rules, 'product'));
$rules = [$rule(1, 1, 'exact_code', 'CUST')];
check(FR::matchRules($aliasItem, $rules, 'product') === 1, 'alias code CUST-1 matched by exact CUST (base)', FR::matchRules($aliasItem, $rules, 'product'));
$rules = [$rule(1, 1, 'description_contains', 'solvent base')];
check(FR::matchRules($aliasItem, $rules, 'product') === 1, 'alias description matched by contains', FR::matchRules($aliasItem, $rules, 'product'));

$rules = [$rule(1, 1, 'code_prefix', 'ZZZ'), $rule(2, 2, 'description_contains', 'aqueous')];
check(FR::matchRules($item, $rules, 'product') === null, 'no match -> null', FR::matchRules($item, $rules, 'product'));
$rules = [$rule(1, 1, 'code_prefix', '   '), $rule(2, 2, 'description_contains', '')];
check(FR::matchRules($item, $rules, 'product') === null, 'blank patterns ignored', FR::matchRules($item, $rules, 'product'));
check(FR::matchRules(['code' => '', 'description' => ''], [$rule(1, 1, 'code_prefix', 'V')], 'product') === null, 'empty item never matches', null);

// ---------------------------------------------------------------------
echo "2. resolveFromData() fixture\n";

$d = [
    'families' => [
        1 => ['id' => 1, 'name' => 'UV Offset', 'is_uv' => 1, 'is_active' => 1, 'sort_order' => 1],
        2 => ['id' => 2, 'name' => 'Solvent',   'is_uv' => 0, 'is_active' => 1, 'sort_order' => 2],
        3 => ['id' => 3, 'name' => 'Inactive',  'is_uv' => 0, 'is_active' => 0, 'sort_order' => 3],
    ],
    'rules' => [
        $rule(1, 1, 'code_prefix', 'VEC47'),
        $rule(2, 2, 'description_contains', 'solvent', 'raw_material'),
        $rule(3, 3, 'code_prefix', 'X'),
    ],
    'raw_materials' => [
        10 => ['id' => 10, 'internal_code' => 'VEC47-55G', 'description' => 'UV varnish',    'family_id' => null, 'family_source' => null],
        11 => ['id' => 11, 'internal_code' => 'SOLV1',     'description' => 'Solvent blend', 'family_id' => null, 'family_source' => null],
        12 => ['id' => 12, 'internal_code' => 'WATER',     'description' => 'Water',         'family_id' => null, 'family_source' => null],
        13 => ['id' => 13, 'internal_code' => 'XZZ',       'description' => 'Something',     'family_id' => null, 'family_source' => null],
        14 => ['id' => 14, 'internal_code' => 'MAN1',      'description' => 'Manual pick',   'family_id' => 2,    'family_source' => 'manual'],
        15 => ['id' => 15, 'internal_code' => 'INT-1',     'description' => 'Intermediate as RM', 'family_id' => null, 'family_source' => null],
    ],
    'finished_goods' => [
        100 => ['id' => 100, 'product_code' => 'P100',      'description' => 'Mixed',          'family_id' => null, 'family_source' => null],
        101 => ['id' => 101, 'product_code' => 'INT-1',     'description' => 'Intermediate',   'family_id' => null, 'family_source' => null],
        102 => ['id' => 102, 'product_code' => 'P102',      'description' => 'Uses INT-1 as RM','family_id' => null, 'family_source' => null],
        103 => ['id' => 103, 'product_code' => 'P103',      'description' => 'Uses INT-1 as FG','family_id' => null, 'family_source' => null],
        104 => ['id' => 104, 'product_code' => 'P104',      'description' => 'Manual UV',      'family_id' => 1,    'family_source' => 'manual'],
        105 => ['id' => 105, 'product_code' => 'VEC47-ABC', 'description' => 'Rule beats content','family_id' => null, 'family_source' => null],
        106 => ['id' => 106, 'product_code' => 'P106',      'description' => 'No formula',     'family_id' => null, 'family_source' => null],
        107 => ['id' => 107, 'product_code' => 'P107',      'description' => 'Only water',     'family_id' => null, 'family_source' => null],
        108 => ['id' => 108, 'product_code' => 'P108',      'description' => 'Only inactive',  'family_id' => null, 'family_source' => null],
        109 => ['id' => 109, 'product_code' => 'P109',      'description' => 'Tie',            'family_id' => null, 'family_source' => null],
        110 => ['id' => 110, 'product_code' => 'P110',      'description' => 'Cycle A',        'family_id' => null, 'family_source' => null],
        111 => ['id' => 111, 'product_code' => 'P111',      'description' => 'Cycle B',        'family_id' => null, 'family_source' => null],
    ],
    'aliases_by_base' => [],
    'formula_by_fg' => [
        100 => 1000, 101 => 1001, 102 => 1002, 103 => 1003, 104 => 1004, 105 => 1005,
        107 => 1007, 108 => 1008, 109 => 1009, 110 => 1010, 111 => 1011,
    ],
    'lines_by_formula' => [
        1000 => [['rm' => 10, 'fg' => null, 'pct' => 30.0], ['rm' => 11, 'fg' => null, 'pct' => 25.0], ['rm' => 12, 'fg' => null, 'pct' => 45.0]],
        1001 => [['rm' => 11, 'fg' => null, 'pct' => 60.0], ['rm' => 12, 'fg' => null, 'pct' => 40.0]],
        1002 => [['rm' => 15, 'fg' => null, 'pct' => 50.0], ['rm' => 10, 'fg' => null, 'pct' => 50.0]],
        1003 => [['rm' => null, 'fg' => 101, 'pct' => 100.0]],
        1004 => [['rm' => 11, 'fg' => null, 'pct' => 100.0]],
        1005 => [['rm' => 12, 'fg' => null, 'pct' => 100.0]],
        1007 => [['rm' => 12, 'fg' => null, 'pct' => 100.0]],
        1008 => [['rm' => 13, 'fg' => null, 'pct' => 100.0]],
        1009 => [['rm' => 10, 'fg' => null, 'pct' => 50.0], ['rm' => 11, 'fg' => null, 'pct' => 50.0]],
        1010 => [['rm' => null, 'fg' => 111, 'pct' => 100.0]],
        1011 => [['rm' => null, 'fg' => 110, 'pct' => 100.0]],
    ],
];

$res = null;
try {
    $res = FR::resolveFromData($d);
    check(true, 'resolveFromData() runs without exception (cycle guarded)');
} catch (\Throwable $e) {
    check(false, 'resolveFromData() runs without exception', $e->getMessage());
}

if ($res !== null) {
    $rm = $res['raw_materials'];
    $fg = $res['finished_goods'];

    $expectRm = [
        10 => [1, 'rule'],
        11 => [2, 'rule'],
        12 => [null, null],
        13 => [null, null],
        14 => [2, 'manual'],
        15 => [null, null],
    ];
    $same = static fn(array $row, string $k, $want): bool => array_key_exists($k, $row) && $row[$k] === $want;
    foreach ($expectRm as $id => [$fid, $src]) {
        $row = $rm[$id] ?? [];
        check($same($row, 'family_id', $fid) && $same($row, 'source', $src), "RM {$id} -> " . var_export($fid, true) . '/' . var_export($src, true), $rm[$id] ?? null);
    }

    $expectFg = [
        100 => [1, 'content'],
        101 => [2, 'content'],
        102 => [1, 'content'],
        103 => [2, 'content'],
        104 => [1, 'manual'],
        105 => [1, 'rule'],
        106 => [null, null],
        107 => [null, null],
        108 => [null, null],
        109 => [1, 'content'],
        110 => [null, null],
        111 => [null, null],
    ];
    foreach ($expectFg as $id => [$fid, $src]) {
        $row = $fg[$id] ?? [];
        check($same($row, 'family_id', $fid) && $same($row, 'source', $src), "FG {$id} -> " . var_export($fid, true) . '/' . var_export($src, true), ['family_id' => $fg[$id]['family_id'] ?? null, 'source' => $fg[$id]['source'] ?? null]);
    }

    $shares = $fg[100]['shares'] ?? [];
    check(count($shares) === 2 && abs(($shares[1] ?? 0) - 30.0) < 1e-9 && abs(($shares[2] ?? 0) - 25.0) < 1e-9, 'FG 100 shares == [1 => 30.0, 2 => 25.0]', $shares);
    $shares = $fg[102]['shares'] ?? [];
    check(abs(($shares[1] ?? 0) - 50.0) < 1e-9 && abs(($shares[2] ?? 0) - 30.0) < 1e-9, 'FG 102 shares: re-routed INT-1 contributes 60% * 50% = 30 to Solvent', $shares);
    $shares = $fg[103]['shares'] ?? [];
    check(count($shares) === 1 && abs(($shares[2] ?? 0) - 60.0) < 1e-9, 'FG 103 shares via FG line == [2 => 60.0]', $shares);
    check(($fg[108]['shares'] ?? null) === [], 'FG 108 shares empty (inactive family contributes nothing)', $fg[108]['shares'] ?? null);
    check(($fg[106]['shares'] ?? null) === [], 'FG 106 shares empty (no formula)', $fg[106]['shares'] ?? null);
}

// ---------------------------------------------------------------------
echo "3. pickDominant() / baseCode()\n";

check(FR::pickDominant([], $d['families']) === null, 'pickDominant([]) -> null');
check(FR::pickDominant([1 => 0.0, 2 => 0.0], $d['families']) === null, 'zero shares ignored');
check(FR::pickDominant([1 => 10.0, 2 => 20.0], $d['families']) === 2, 'highest share wins');
check(FR::pickDominant([1 => 20.0, 2 => 20.0], $d['families']) === 1, 'tie -> lower sort_order');
check(FR::pickDominant([2 => 20.0, 1 => 20.0], $d['families']) === 1, 'tie -> lower sort_order regardless of input order');
check(FR::pickDominant([7 => 20.0, 5 => 20.0], []) === 5, 'tie with unknown families -> lower family id');
check(FR::baseCode('VEC47-55G') === 'VEC47', 'baseCode strips pack suffix');
check(FR::baseCode('WATER') === 'WATER', 'baseCode leaves plain code');
check(FR::baseCode('A-B-C') === 'A', 'baseCode strips at first dash');

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
