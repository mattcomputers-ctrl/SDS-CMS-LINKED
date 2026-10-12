<?php
/**
 * DB-free checks for the A12(a) acrylate flag in UVAcrylateRulePack
 * (fixed-text review 2026-10-12):
 *
 *   1. flagValue(): NULL / '' -> null; '1' / 1 / true -> true; 0 / '0' / false -> false.
 *   2. The hard-coded labels are gone (LEGACY_ACRYLATE_CAS is a plain CAS list).
 *   3. detectAcrylates(): the composition row's is_acrylate wins (1 = yes,
 *      0 = manual no); null / absent falls back to the legacy list or the
 *      name rule; a nameless disclosed row shows its CAS; cut-off and
 *      trade-secret masking unchanged; Section 4 sentence end to end.
 *   4. nameLooksLikeAcrylate / seedFlagFor / effectiveFlag.
 *   5. FormulaCalcService::buildResaleComposition() propagates is_acrylate.
 *
 * Run: docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/UVAcrylateFlagDetectionTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

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
use SDS\Services\TranslationService;
use SDS\Services\UVAcrylateRulePack as P;

echo "1. flagValue\n";
check(P::flagValue(null) === null && P::flagValue('') === null, 'null / empty -> null');
check(P::flagValue('1') === true && P::flagValue(1) === true && P::flagValue(true) === true, "'1' / 1 / true -> true");
check(P::flagValue(0) === false && P::flagValue('0') === false && P::flagValue(false) === false, "0 / '0' / false -> false");

echo "2. labels gone\n";
check(!array_key_exists('ACRYLATE_CAS_LIST', (new ReflectionClass(P::class))->getConstants()), 'ACRYLATE_CAS_LIST removed');
check(count(P::LEGACY_ACRYLATE_CAS) === 14 && array_is_list(P::LEGACY_ACRYLATE_CAS), 'legacy list: 14 plain CAS');
foreach (['2223-82-7', '28961-43-5', '52408-84-1', '55818-57-0'] as $cas) {
    check(P::isLegacyAcrylateCas($cas), "{$cas} still on the legacy list");
}
$src = (string) file_get_contents($basePath . '/src/Services/UVAcrylateRulePack.php');
foreach (['EO-TMPTA', 'PO-TMPTA', 'DPHA', 'IBOA'] as $label) {
    check(!str_contains($src, $label), "source no longer contains {$label}");
}

echo "3. detectAcrylates with the flag\n";
$comp = static fn (array $r): array => $r + ['concentration_pct' => 20.0];

$found = P::detectAcrylates([$comp(['cas_number' => '99999-03-3', 'chemical_name' => 'Aliphatic urethane oligomer', 'is_acrylate' => true])]);
check($found === ['99999-03-3' => 'Aliphatic urethane oligomer'], 'flag 1 names a CAS the rule misses', $found);

$r = P::detectAcrylates([$comp(['cas_number' => '13048-33-4', 'chemical_name' => '1,6-Hexanediol Diacrylate', 'is_acrylate' => false])]);
check($r === [], 'flag 0 (manual no) beats the legacy list and the name rule', $r);

$r = P::detectAcrylates([$comp(['cas_number' => '2223-82-7', 'chemical_name' => 'Neopentyl glycol diacrylate', 'is_acrylate' => null])]);
check($r === ['2223-82-7' => 'Neopentyl glycol diacrylate'], 'flag null -> legacy list, composition name', $r);

$r = P::detectAcrylates([$comp(['cas_number' => '2223-82-7', 'chemical_name' => ''])]);
check($r === ['2223-82-7' => '2223-82-7'], 'key absent, empty name -> the CAS, never an old label', $r);

$r = P::detectAcrylates([['cas_number' => '99999-03-3', 'chemical_name' => 'Aliphatic urethane oligomer', 'is_acrylate' => true, 'concentration_pct' => 0.05]]);
check($r === [], 'flag 1 below the 0.1 % cut-off -> not named', $r);

$r = P::detectAcrylates([$comp(['cas_number' => '99999-03-3', 'chemical_name' => 'Aliphatic urethane oligomer', 'is_acrylate' => 1,
    'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary oligomer'])]);
check($r === ['99999-03-3' => 'Proprietary oligomer'], 'trade secret: description only', $r);

$sentence = P::section4SkinFragment($found, new TranslationService('en'));
check($sentence === 'UV/EB-curable product containing acrylate monomers/oligomers (Aliphatic urethane oligomer): remove uncured product from the skin immediately with soap and water. Do not use solvents or thinners to clean the skin.',
    'Section 4 sentence end to end', $sentence);

echo "4. helpers\n";
check(P::nameLooksLikeAcrylate('Isobornyl Acrylate') && P::nameLooksLikeAcrylate('Methacrylic acid'), 'name rule: acrylate / methacrylic acid');
check(!P::nameLooksLikeAcrylate('Acrylic resin') && !P::nameLooksLikeAcrylate('Styrene acrylate copolymer')
    && !P::nameLooksLikeAcrylate('Sodium polyacrylate') && !P::nameLooksLikeAcrylate(''), 'name rule: polymers / resins / empty never');
check(P::seedFlagFor('55818-57-0', ['Calcium Zinc stabilizer']) === 1, 'seedFlagFor: legacy CAS -> 1');
check(P::seedFlagFor('64-17-5', ['Ethanol']) === null, 'seedFlagFor: ethanol -> null');
check(P::effectiveFlag(null, '64-17-5', 'Ethyl acrylate') === true, 'effectiveFlag: null -> name rule');
check(P::effectiveFlag('0', '13048-33-4', 'HDDA diacrylate') === false, "effectiveFlag: '0' wins");

echo "5. resale composition propagation\n";
$rm = ['id' => 7, 'internal_code' => 'RM-UV1', 'hazardous_no_cas' => 0, 'constituents' => [
    ['cas_number' => '99999-03-3', 'chemical_name' => 'Aliphatic urethane oligomer', 'pct_exact' => 40, 'pct_min' => null, 'pct_max' => null,
     'is_trade_secret' => 0, 'has_nitrogen' => 0, 'has_sulfur' => 0, 'has_halogen' => 0, 'is_acrylate' => '1'],
    ['cas_number' => '64-17-5', 'chemical_name' => 'Ethanol', 'pct_exact' => 10, 'pct_min' => null, 'pct_max' => null,
     'is_trade_secret' => 0, 'has_nitrogen' => 0, 'has_sulfur' => 0, 'has_halogen' => 0, 'is_acrylate' => null],
]];
$m = new ReflectionMethod(FormulaCalcService::class, 'buildResaleComposition');
$m->setAccessible(true);
$compR = $m->invoke(new FormulaCalcService(), $rm);
$byCas = [];
foreach ($compR as $b) {
    $byCas[$b['cas_number']] = $b;
}
check(array_key_exists('is_acrylate', $byCas['99999-03-3'] ?? []) && $byCas['99999-03-3']['is_acrylate'] === true, 'oligomer is_acrylate === true', $byCas['99999-03-3'] ?? null);
check(array_key_exists('is_acrylate', $byCas['64-17-5'] ?? []) && $byCas['64-17-5']['is_acrylate'] === null, 'ethanol is_acrylate === null', $byCas['64-17-5'] ?? null);
$d = P::detectAcrylates($compR);
check($d === ['99999-03-3' => 'Aliphatic urethane oligomer'], 'detectAcrylates on the resale composition', $d);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
