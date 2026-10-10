<?php
/**
 * DB-free checks for finding #26 — SARA 313 / HAP compound categories:
 *
 *   - RegulatoryCategoryService::elementsInFormula(): Hill formula parsing
 *     (CO is carbon + oxygen, not cobalt).
 *   - nameMatches(): whole-word, case-insensitive keyword match.
 *   - resolve(): element rule from the formula (authoritative), name keyword
 *     fallback only without a formula, explicit include / exclude members,
 *     TRADE_SECRET never matched.
 *   - matchForCas(): no DB for an empty CAS set.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/RegulatoryCategoryServiceTest.php
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

use SDS\Services\RegulatoryCategoryService as R;

// ---------------------------------------------------------------------
echo "a. elementsInFormula\n";
check(R::elementsInFormula('C32H16CuN8') === ['C', 'H', 'Cu', 'N'], 'copper phthalocyanine', R::elementsInFormula('C32H16CuN8'));
check(R::elementsInFormula('CO') === ['C', 'O'], 'carbon monoxide is not cobalt', R::elementsInFormula('CO'));
check(R::elementsInFormula(null) === [], 'null formula');
check(R::elementsInFormula('OZn') === ['O', 'Zn'], 'zinc oxide', R::elementsInFormula('OZn'));

// ---------------------------------------------------------------------
echo "b. nameMatches\n";
check(R::nameMatches('copper,cupric,cuprous', ['Cupric acetate']) === true, 'keyword, case-insensitive');
check(R::nameMatches('lead', ['Leadership resin']) === false, 'whole word only');
check(R::nameMatches(null, ['x']) === false, 'no keywords');

// ---------------------------------------------------------------------
echo "c. resolve\n";
$cats = [
    ['category_code' => 'N982', 'category_name' => 'Zinc compounds',        'element_symbol' => 'Zn', 'name_keywords' => 'zinc',                  'deminimis_pct' => '1.0000', 'is_pbt' => 0],
    ['category_code' => 'N100', 'category_name' => 'Copper compounds',      'element_symbol' => 'Cu', 'name_keywords' => 'copper,cupric,cuprous', 'deminimis_pct' => '1.0000', 'is_pbt' => 0],
    ['category_code' => 'N040', 'category_name' => 'Barium compounds',      'element_symbol' => 'Ba', 'name_keywords' => 'barium',                'deminimis_pct' => '1.0000', 'is_pbt' => 0],
    ['category_code' => 'N420', 'category_name' => 'Lead compounds',        'element_symbol' => 'Pb', 'name_keywords' => 'lead',                  'deminimis_pct' => '0.1000', 'is_pbt' => 1],
    ['category_code' => 'N096', 'category_name' => 'Cobalt compounds',      'element_symbol' => 'Co', 'name_keywords' => 'cobalt',                'deminimis_pct' => '0.1000', 'is_pbt' => 0],
    ['category_code' => 'N230', 'category_name' => 'Certain glycol ethers', 'element_symbol' => null, 'name_keywords' => null,                    'deminimis_pct' => '1.0000', 'is_pbt' => 0],
];
$members = [
    ['category_code' => 'N100', 'cas_number' => '147-14-8',  'member_type' => 'exclude'],
    ['category_code' => 'N040', 'cas_number' => '7727-43-7', 'member_type' => 'exclude'],
    ['category_code' => 'N230', 'cas_number' => '112-34-5',  'member_type' => 'include'],
];
$info = [
    '1314-13-2'    => ['formula' => 'OZn',               'names' => ['Zinc oxide']],
    '147-14-8'     => ['formula' => 'C32H16CuN8',        'names' => ['Pigment Blue 15:3']],
    '7727-43-7'    => ['formula' => 'BaO4S',             'names' => ['Barium sulfate']],
    '5160-02-1'    => ['formula' => 'C34H24BaCl2N4O8S2', 'names' => ['Pigment Red 53:1']],
    '630-08-0'     => ['formula' => 'CO',                'names' => ['Carbon monoxide']],
    '557-05-1'     => ['formula' => null,                'names' => ['Zinc stearate']],
    '112-34-5'     => ['formula' => 'C8H18O3',           'names' => ['2-(2-Butoxyethoxy)ethanol']],
    '108-88-3'     => ['formula' => 'C7H8',              'names' => ['Toluene']],
    '9999-99-9'    => ['formula' => 'C7H8',              'names' => ['Zinc-free toluene']],
    'TRADE_SECRET' => ['formula' => null,                'names' => ['Lead thing']],
];
$r = R::resolve($cats, $members, $info);
$codes = static fn (string $cas): array => array_map(static fn (array $c): string => (string) $c['category_code'], $r[$cas] ?? []);
check($codes('1314-13-2') === ['N982'], 'zinc oxide -> zinc compounds (formula)', $codes('1314-13-2'));
check(!isset($r['147-14-8']), 'Pigment Blue 15 excluded from copper compounds');
check(!isset($r['7727-43-7']), 'barium sulfate excluded from barium compounds');
check($codes('5160-02-1') === ['N040'], 'Pigment Red 53:1 -> barium compounds', $codes('5160-02-1'));
check(!isset($r['630-08-0']), 'carbon monoxide is not a cobalt compound');
check($codes('557-05-1') === ['N982'], 'zinc stearate without formula -> name fallback', $codes('557-05-1'));
check($codes('112-34-5') === ['N230'], 'DGBE explicit glycol ether member', $codes('112-34-5'));
check(!isset($r['108-88-3']), 'toluene in no category');
check(!isset($r['9999-99-9']), 'formula wins over a name keyword');
check(!isset($r['TRADE_SECRET']), 'TRADE_SECRET never matched');

// N100 structural exclusion (40 CFR 372.65(c), 60 FR 18350, 1995): copper
// phthalocyanines substituted only with H / Cl / Br are not copper compounds.
$cuInfo = [
    '12239-87-1' => ['formula' => 'C32H15ClCuN8',        'names' => ['Pigment Blue 15:1']],
    '68987-63-3' => ['formula' => null,                  'names' => ['Copper phthalocyanine, chlorinated']],
    '1330-38-7'  => ['formula' => 'C32H12CuN8Na4O12S4',  'names' => ['Direct Blue 86 (sulfonated copper phthalocyanine)']],
    '1-1-1'      => ['formula' => null,                  'names' => ['Copper phthalocyanine sulfonic acid']],
    '1317-38-0'  => ['formula' => 'CuO',                 'names' => ['Copper(II) oxide']],
    '7758-98-7'  => ['formula' => 'CuO4S',               'names' => ['Copper(II) sulfate']],
    '2-2-2'      => ['formula' => 'C32Br15ClCuN8',       'names' => ['Brominated copper phthalocyanine']],
];
$rc = R::resolve($cats, $members, $cuInfo);
$cc = static fn (string $cas): array => array_map(static fn (array $c): string => (string) $c['category_code'], $rc[$cas] ?? []);
check(!isset($rc['12239-87-1']), 'PB 15:1 (C32H15ClCuN8) excluded from N100 by structure, no CAS row needed', $cc('12239-87-1'));
check(!isset($rc['68987-63-3']), 'chlorinated copper phthalocyanine, name only -> excluded', $cc('68987-63-3'));
check(!isset($rc['2-2-2']), 'H / Br / Cl-only copper phthalocyanine (C32Br15ClCuN8) excluded', $cc('2-2-2'));
check($cc('1330-38-7') === ['N100'], 'sulfonated copper phthalocyanine (S / O / Na) stays in N100', $cc('1330-38-7'));
check($cc('1-1-1') === ['N100'], 'name-only sulfonic copper phthalocyanine stays in N100', $cc('1-1-1'));
check($cc('1317-38-0') === ['N100'] && $cc('7758-98-7') === ['N100'], 'copper(II) oxide / sulfate stay in N100');
check(R::isExcludedCopperPhthalocyanine('C32H16CuN8', []) && !R::isExcludedCopperPhthalocyanine('C32H16N8', []) && !R::isExcludedCopperPhthalocyanine('C320H16CuN8', []),
    'isExcludedCopperPhthalocyanine: needs Cu and a C32 / N8 core');
$rcInc = R::resolve($cats, [['category_code' => 'N100', 'cas_number' => '12239-87-1', 'member_type' => 'include']], ['12239-87-1' => $cuInfo['12239-87-1']]);
check(($rcInc['12239-87-1'][0]['category_code'] ?? null) === 'N100', 'an explicit include row still wins');

// ---------------------------------------------------------------------
echo "d. matchForCas without a DB\n";
check(R::matchForCas('sara313', []) === [], 'empty CAS set -> [] (no DB)');
check(R::matchForCas('hap', ['TRADE_SECRET' => ['x'], '' => ['y']]) === [], 'only blank / trade-secret CAS -> [] (no DB)');

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
