<?php
/**
 * DB-free checks for RCRAService::match() (SDS content audit item #26):
 *
 *   - rcra_waste_codes rows are grouped by CAS; codes inside a component are
 *     ordered D, F, K, P, U then by code.
 *   - Components are ordered by total weight % descending (tie → CAS), the
 *     same CAS reached through several raw materials is one component, and
 *     the % itself never leaves the service (audit #42).
 *   - Listed-waste (F/K/P/U) codes of components below MIN_CONCENTRATION_PCT
 *     (0.1 wt%) are not reported; toxicity-characteristic (D) codes have no
 *     floor (the TCLP level can be exceeded from well under 0.1 %).
 *   - Trade-secret flags are carried through unchanged; TRADE_SECRET CAS
 *     placeholders never match; rows with a blank kind default to the code's
 *     first letter; blank rows are ignored.
 *
 * Same Reflection bootstrap as SDSGeneratorSection5Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/RCRAServiceTest.php
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

$match = static fn (array $composition, array $rows): array => \SDS\Services\RCRAService::match($composition, $rows);

$row = static fn (string $cas, string $code, string $kind = '', $limit = null, string $desc = ''): array => [
    'cas_number' => $cas, 'waste_code' => $code, 'kind' => $kind, 'limit_mg_l' => $limit, 'description' => $desc,
];
$comp = static fn (string $cas, string $name, float $pct, array $extra = []): array => array_merge([
    'cas_number' => $cas, 'chemical_name' => $name, 'concentration_pct' => $pct, 'is_trade_secret' => false,
], $extra);

$codesOf = static fn (array $c): array => array_map(static fn ($x) => $x['waste_code'], $c['codes']);

// ---------------------------------------------------------------------------
echo "1. Empty rows\n";
$r = $match([$comp('108-88-3', 'Toluene', 4.5)], []);
check($r === ['components' => [], 'has_matches' => false], 'empty rows -> no components, has_matches false', $r);

$r = $match([], [$row('108-88-3', 'U220', 'U')]);
check($r === ['components' => [], 'has_matches' => false], 'empty composition -> no components', $r);

// ---------------------------------------------------------------------------
echo "2. Toluene U220 + F005\n";
$r = $match([$comp('108-88-3', 'Toluene', 4.5)], [$row('108-88-3', 'U220', 'U', null, 'Toluene'), $row('108-88-3', 'F005', 'F', null, 'Toluene — spent solvent')]);
check($r['has_matches'] === true, 'has_matches true');
check(count($r['components']) === 1, 'one component', $r['components']);
$c = $r['components'][0] ?? [];
check(($c['chemical_name'] ?? null) === 'Toluene', 'chemical_name carried');
check(($c['cas_number'] ?? null) === '108-88-3', 'cas_number carried');
check($codesOf($c) === ['F005', 'U220'], 'codes ordered F before U', $codesOf($c));
check(!array_key_exists('concentration_pct', $c), 'no concentration_pct in component');
check(!array_key_exists('_sort', $c), 'no _sort key in component');
check(($c['codes'][0]['description'] ?? null) === 'Toluene — spent solvent', 'row description carried per code');

// ---------------------------------------------------------------------------
echo "3. MEK D035 (limit string) + U159 + F005\n";
$r = $match([$comp('78-93-3', 'Methyl ethyl ketone', 12.0)], [
    $row('78-93-3', 'U159', 'U'),
    $row('78-93-3', 'D035', 'D', '200.000'),
    $row('78-93-3', 'F005', 'F'),
]);
$c = $r['components'][0] ?? [];
check($codesOf($c) === ['D035', 'F005', 'U159'], 'codes ordered D, F, U', $codesOf($c));
check(($c['codes'][0]['limit_mg_l'] ?? null) === 200.0, 'D035 limit cast to float 200.0', $c['codes'][0]['limit_mg_l'] ?? null);
check(array_key_exists('limit_mg_l', $c['codes'][1]) && $c['codes'][1]['limit_mg_l'] === null, 'F limit null');
check(array_key_exists('limit_mg_l', $c['codes'][2]) && $c['codes'][2]['limit_mg_l'] === null, 'U limit null');
check(($c['codes'][0]['kind'] ?? null) === 'D', 'kind D carried');

// ---------------------------------------------------------------------------
echo "4. Reporting threshold\n";
$r = $match([$comp('108-88-3', 'Toluene', 0.05)], [$row('108-88-3', 'U220', 'U')]);
check($r['components'] === [] && $r['has_matches'] === false, 'listed CAS at 0.05 % skipped', $r);

$r = $match([$comp('108-88-3', 'Toluene', 0.05), $comp('108-88-3', 'Toluene (via RM 2)', 0.06)], [$row('108-88-3', 'U220', 'U')]);
check(count($r['components']) === 1, 'same CAS twice (0.05 + 0.06 = 0.11 %) reported once', $r['components']);
check(($r['components'][0]['chemical_name'] ?? null) === 'Toluene', 'first-seen name kept');

$r = $match([$comp('108-88-3', 'Toluene', 0.1)], [$row('108-88-3', 'U220', 'U')]);
check(count($r['components']) === 1, 'exactly 0.1 % is reported (floor is inclusive)');

// Toxicity-characteristic (D) codes have no floor: the TCLP level can be exceeded from well under 0.1 %.
$r = $match([$comp('7440-43-9', 'Cadmium', 0.05)], [$row('7440-43-9', 'D006', 'D', '1.000')]);
check(count($r['components']) === 1 && $codesOf($r['components'][0]) === ['D006'] && $r['has_matches'] === true, 'D006 cadmium at 0.05 % reported', $r);
$r = $match([$comp('7439-97-6', 'Mercury', 0.001)], [$row('7439-97-6', 'D009', 'D', '0.2')]);
check(count($r['components']) === 1, 'D009 mercury at 0.001 % reported', $r);
$r = $match([$comp('78-93-3', 'MEK', 0.05)], [$row('78-93-3', 'D035', 'D', '200'), $row('78-93-3', 'U159', 'U'), $row('78-93-3', 'F005', 'F')]);
check(count($r['components']) === 1 && $codesOf($r['components'][0]) === ['D035'], 'below the floor only the D code survives (F / U dropped)', $r['components']);
$r = $match([$comp('7440-43-9', 'Cadmium', 0.0)], [$row('7440-43-9', 'D006', 'D', '1')]);
check($r['components'] === [], 'D code at 0 % not reported', $r);

// ---------------------------------------------------------------------------
echo "5. Ordering\n";
$rows = [$row('108-88-3', 'U220', 'U'), $row('1330-20-7', 'U239', 'U'), $row('78-93-3', 'U159', 'U')];
$r = $match([$comp('108-88-3', 'Toluene', 5.0), $comp('1330-20-7', 'Xylene', 20.0)], $rows);
$names = array_map(static fn ($c) => $c['chemical_name'], $r['components']);
check($names === ['Xylene', 'Toluene'], 'higher total first', $names);

$r = $match([$comp('78-93-3', 'MEK', 5.0), $comp('108-88-3', 'Toluene', 5.0)], $rows);
$cas = array_map(static fn ($c) => $c['cas_number'], $r['components']);
check($cas === ['108-88-3', '78-93-3'], 'tie broken by CAS (strcmp)', $cas);

// ---------------------------------------------------------------------------
echo "6. Trade secret\n";
$r = $match(
    [$comp('108-88-3', 'Toluene', 3.0, ['is_trade_secret' => true, 'trade_secret_description' => 'Proprietary solvent'])],
    [$row('108-88-3', 'U220', 'U')]
);
$c = $r['components'][0] ?? [];
check(($c['is_trade_secret'] ?? null) === true, 'is_trade_secret true carried');
check(($c['trade_secret_description'] ?? null) === 'Proprietary solvent', 'trade_secret_description carried');

$r = $match([$comp('108-88-3', 'Toluene', 3.0)], [$row('108-88-3', 'U220', 'U')]);
$c = $r['components'][0] ?? [];
check(($c['is_trade_secret'] ?? null) === false, 'is_trade_secret false by default');
check(array_key_exists('trade_secret_description', $c) && $c['trade_secret_description'] === null, 'trade_secret_description null when absent');

// ---------------------------------------------------------------------------
echo "7. Row normalisation\n";
$r = $match([$comp('78-93-3', 'MEK', 5.0)], [$row('78-93-3', 'd035', '', '200'), $row('78-93-3', 'u159', '')]);
$c = $r['components'][0] ?? [];
check($codesOf($c) === ['D035', 'U159'], 'codes upper-cased', $codesOf($c));
check(($c['codes'][0]['kind'] ?? null) === 'D' && ($c['codes'][1]['kind'] ?? null) === 'U', 'blank kind defaults to first letter of code');

$r = $match([$comp('78-93-3', 'MEK', 5.0)], [$row('', 'D035', 'D'), $row('78-93-3', '', 'D')]);
check($r['components'] === [], 'blank cas / blank code rows ignored', $r);

$r = $match(
    [$comp('TRADE_SECRET', 'Secret', 5.0, ['is_trade_secret' => true])],
    [$row('TRADE_SECRET', 'U220', 'U')]
);
check($r['components'] === [], 'TRADE_SECRET placeholder CAS never matches', $r);

$r = $match([$comp(' 108-88-3 ', 'Toluene', 5.0)], [$row('108-88-3', 'U220', 'U')]);
check(count($r['components']) === 1, 'CAS whitespace trimmed on composition side');

$r = $match([$comp('108-88-3', 'Toluene', 5.0)], [$row('108-88-3', 'U220', 'U'), $row('108-88-3', 'U220', 'U')]);
check($codesOf($r['components'][0]) === ['U220'], 'duplicate (cas, code) rows collapse to one code');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
