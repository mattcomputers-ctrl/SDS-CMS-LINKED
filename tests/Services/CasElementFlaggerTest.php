#!/usr/bin/env php
<?php
/**
 * CasElementFlagger unit test (audit item #19, DB-free, pure)
 *
 *   - fromFormula(): Hill formulas parsed by element symbol ("Na" is not
 *     N, "Cl" is a halogen), repeat-unit suffixes accepted, non-formulas
 *     ("Unspecified", "Mixture", blank) return null;
 *   - fromNames(): conservative keywords fire on amines/amides/sulfates/
 *     halides, not on fluorene/fluorescein/chlorophyll/"cyan";
 *   - matchedKeywords(): reports which stems fired;
 *   - infer(): a parseable formula wins over the names.
 *
 * Run: php tests/Services/CasElementFlaggerTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SDS\Services\CasElementFlagger as F;

$failures = 0;
$checks   = 0;
function check(bool $ok, string $label, $actual = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) { echo "  ok   {$label}\n"; return; }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) { echo '       actual: ' . var_export($actual, true) . "\n"; }
}

$flags = fn(bool $n, bool $s, bool $x): array => ['has_nitrogen' => $n, 'has_sulfur' => $s, 'has_halogen' => $x];

echo "a. fromFormula\n";
check(F::fromFormula('C6H5NO2') === $flags(true, false, false), 'C6H5NO2 -> N only', F::fromFormula('C6H5NO2'));
check(F::fromFormula('NaCl') === $flags(false, false, true), 'NaCl -> halogen only (Na is not N)', F::fromFormula('NaCl'));
check(F::fromFormula('C2H6OS') === $flags(false, true, false), 'C2H6OS -> S only', F::fromFormula('C2H6OS'));
check(F::fromFormula('C8H10') === $flags(false, false, false), 'C8H10 -> none');
check(F::fromFormula('CCl4') === $flags(false, false, true), 'CCl4 -> halogen');
check(F::fromFormula('C3H3N') === $flags(true, false, false), 'C3H3N -> N');
check(F::fromFormula('(C2H4O)n') === $flags(false, false, false), '(C2H4O)n -> parsed, none', F::fromFormula('(C2H4O)n'));
check(F::fromFormula('(C3H6O)x') === $flags(false, false, false), '(C3H6O)x -> parsed, none');
check(F::fromFormula('C2H5Br') === $flags(false, false, true), 'C2H5Br -> halogen (Br)');
check(F::fromFormula('CH3I') === $flags(false, false, true), 'CH3I -> halogen (I)');
check(F::fromFormula('C4H2F6') === $flags(false, false, true), 'C4H2F6 -> halogen (F)');
check(F::fromFormula('TiO2') === $flags(false, false, false), 'TiO2 -> none');
check(F::fromFormula('Unspecified') === null, 'Unspecified -> null');
check(F::fromFormula('') === null, 'empty -> null');
check(F::fromFormula(null) === null, 'null -> null');
check(F::fromFormula('Mixture') === null, 'Mixture -> null');
check(F::fromFormula('C2H5On') === null, 'C2H5On (bare n) -> null');
check(F::fromFormula('  C2H6OS  ') === $flags(false, true, false), 'whitespace trimmed');

echo "b. fromNames\n";
$n = fn(array $names): array => F::fromNames($names);
check($n(['Triethanolamine']) === $flags(true, false, false), 'Triethanolamine -> N');
check($n(['Aniline']) === $flags(true, false, false), 'Aniline -> N');
check($n(['Copper phthalocyanine']) === $flags(true, false, false), 'Copper phthalocyanine -> N');
check($n(['Sodium lauryl sulfate']) === $flags(false, true, false), 'Sodium lauryl sulfate -> S');
check($n(['Methionine']) === $flags(false, true, false), 'Methionine -> S (thio)', $n(['Methionine']));
check($n(['Dichloromethane']) === $flags(false, false, true), 'Dichloromethane -> halogen');
check($n(['1-Bromopropane']) === $flags(false, false, true), '1-Bromopropane -> halogen');
check($n(['Ethyl acetate']) === $flags(false, false, false), 'Ethyl acetate -> none');
check($n(['Fluorene']) === $flags(false, false, false), 'Fluorene -> none (excluded)');
check($n(['Fluorescein']) === $flags(false, false, false), 'Fluorescein -> none (excluded)');
check($n(['Chlorophyll']) === $flags(false, false, false), 'Chlorophyll -> none (excluded)');
check($n(['Titanium dioxide']) === $flags(false, false, false), 'Titanium dioxide -> none');
check($n(['Carbon black']) === $flags(false, false, false), 'Carbon black -> none');
check($n(['Cyan']) === $flags(false, false, false), 'Cyan -> none (ambiguous stem excluded)');
check($n(['xyz', 'Acrylamide']) === $flags(true, false, false), 'any name counts');
check($n(['THIOUREA']) === $flags(true, true, false), 'case-insensitive: THIOUREA -> N + S');
check($n(['', '  ']) === $flags(false, false, false), 'blank names ignored');
check($n([]) === $flags(false, false, false), 'no names -> none');

echo "c. matchedKeywords\n";
check(F::matchedKeywords(['Dimethylformamide']) === ['has_nitrogen' => ['amide']], 'Dimethylformamide -> amide', F::matchedKeywords(['Dimethylformamide']));
$mk = F::matchedKeywords(['Thiourea']);
check(($mk['has_nitrogen'] ?? []) === ['urea'] && ($mk['has_sulfur'] ?? []) === ['thio'], 'Thiourea -> urea + thio', $mk);
check(F::matchedKeywords(['Fluorene']) === [], 'Fluorene -> nothing fired');

echo "d. infer\n";
check(F::infer('C8H10', ['Aniline'])['has_nitrogen'] === false, 'formula wins over names');
check(F::infer(null, ['Aniline'])['has_nitrogen'] === true, 'no formula -> names');
$i = F::infer('Unspecified', ['Thiourea']);
check($i['has_nitrogen'] === true && $i['has_sulfur'] === true, 'unparseable formula -> names');
check(F::infer('', [])['has_halogen'] === false, 'nothing -> none');
check(F::FLAGS === ['has_nitrogen', 'has_sulfur', 'has_halogen'], 'FLAGS order');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
