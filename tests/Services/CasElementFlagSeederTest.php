<?php
/**
 * DB-free checks for CasElementFlagSeeder — the seeder shared by
 * scripts/seed-cas-element-flags.php and the "Seed element flags (preview)"
 * page (/determinations/element-flags):
 *
 *   namesFor():  preferred name + synonyms_json (bare list and {"synonyms":[]})
 *                + extra list names.
 *   classify():  formula-based (formula wins over names, basis "formula …"),
 *                keyword-based (basis "names: {…}" with the stems that
 *                fired), unchanged, manual-skipped, manual + force.
 *   plan():      counts (scanned / changed / unchanged / skippedManual /
 *                inUseRmCount), rows only for the CAS that change, in input
 *                order, rmCount per row, changedCas; force rewrites manual
 *                rows; a manual row whose flags already match is unchanged.
 *   summary():   audit / flash counts.
 *
 * Same Reflection bootstrap as TSCAInventoryImporterTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/CasElementFlagSeederTest.php
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

use SDS\Services\CasElementFlagSeeder as S;

$flags = static fn(int $n, int $s, int $x): array => ['has_nitrogen' => $n, 'has_sulfur' => $s, 'has_halogen' => $x];
$row   = static function (string $cas, string $name, ?string $formula, array $cur, ?string $source = null, ?string $syn = null): array {
    return [
        'cas_number'           => $cas,
        'preferred_name'       => $name,
        'synonyms_json'        => $syn,
        'molecular_formula'    => $formula,
        'has_nitrogen'         => $cur['has_nitrogen'],
        'has_sulfur'           => $cur['has_sulfur'],
        'has_halogen'          => $cur['has_halogen'],
        'element_flags_source' => $source,
    ];
};

$seeder = new S(null);

// -----------------------------------------------------------------
echo "a. namesFor\n";
$r = $row('1-1-1', 'Aniline', null, $flags(0, 0, 0), null, '["Phenylamine","Aminobenzene"]');
check(S::namesFor($r, ['Benzenamine']) === ['Aniline', 'Phenylamine', 'Aminobenzene', 'Benzenamine'], 'bare synonym list + extras', S::namesFor($r, ['Benzenamine']));
$r = $row('1-1-1', 'Aniline', null, $flags(0, 0, 0), null, '{"synonyms":["Phenylamine"],"other":1}');
check(S::namesFor($r) === ['Aniline', 'Phenylamine'], '{"synonyms": [...]} shape', S::namesFor($r));
$r = $row('1-1-1', 'Aniline', null, $flags(0, 0, 0), null, 'not json');
check(S::namesFor($r) === ['Aniline'], 'invalid synonyms_json ignored');
$r = $row('1-1-1', 'Aniline', null, $flags(0, 0, 0), null, '[1, "Phenylamine", null]');
check(S::namesFor($r) === ['Aniline', 'Phenylamine'], 'non-string synonyms dropped');

// -----------------------------------------------------------------
echo "b. classify\n";
// formula-based: nitrobenzene C6H5NO2, stored flags empty → N
$c = S::classify($row('98-95-3', 'Nitrobenzene', 'C6H5NO2', $flags(0, 0, 0)), [], false);
check($c['status'] === 'changed', 'formula row: changed');
check($c['proposed'] === $flags(1, 0, 0), 'formula row: proposed N only', $c['proposed']);
check($c['current'] === $flags(0, 0, 0), 'formula row: current preserved');
check($c['basis'] === 'formula C6H5NO2', 'formula row: basis', $c['basis']);
check($c['cas'] === '98-95-3' && $c['name'] === 'Nitrobenzene' && $c['source'] === null, 'formula row: cas / name / source');

// formula wins over a misleading name
$c = S::classify($row('100-41-4', 'Ethylbenzene amine blend', 'C8H10', $flags(0, 0, 0)), ['Aniline'], false);
check($c['status'] === 'unchanged' && $c['proposed'] === $flags(0, 0, 0), 'parseable formula wins over names (no N)', $c);

// keyword-based: no formula, name fires
$c = S::classify($row('75-09-2', 'Dichloromethane', null, $flags(0, 0, 0)), [], false);
check($c['status'] === 'changed' && $c['proposed'] === $flags(0, 0, 1), 'keyword row: halogen', $c['proposed']);
check(str_starts_with($c['basis'], 'names: ') && str_contains($c['basis'], 'has_halogen'), 'keyword row: basis lists the stems', $c['basis']);

// keyword from an extra list name only (preferred name says nothing)
$c = S::classify($row('62-56-6', 'CAS 62-56-6', 'Unspecified', $flags(0, 0, 0)), ['Thiourea'], false);
check($c['proposed'] === $flags(1, 1, 0), 'unparseable formula → extra names (Thiourea: N + S)', $c['proposed']);
$decoded = json_decode(substr($c['basis'], strlen('names: ')), true);
check(($decoded['has_nitrogen'] ?? null) === ['urea'] && ($decoded['has_sulfur'] ?? null) === ['thio'], 'basis JSON = matchedKeywords()', $c['basis']);

// unchanged
$c = S::classify($row('75-09-2', 'Dichloromethane', 'CH2Cl2', $flags(0, 0, 1), 'seed'), [], false);
check($c['status'] === 'unchanged' && $c['source'] === 'seed', 'already correct → unchanged');

// manual, differs → skipped unless force
$c = S::classify($row('64-17-5', 'Ethanol', 'C2H6O', $flags(1, 0, 0), 'manual'), [], false);
check($c['status'] === 'manual' && $c['proposed'] === $flags(0, 0, 0), 'manual row differs → manual (skipped)', $c);
$c = S::classify($row('64-17-5', 'Ethanol', 'C2H6O', $flags(1, 0, 0), 'manual'), [], true);
check($c['status'] === 'changed' && $c['source'] === 'manual', 'manual row + force → changed, source still reports manual', $c);
// manual, already matches → unchanged (not counted as skipped)
$c = S::classify($row('64-17-5', 'Ethanol', 'C2H6O', $flags(0, 0, 0), 'manual'), [], false);
check($c['status'] === 'unchanged', 'manual row that already matches → unchanged');

// current flags tolerate string / null values from PDO
$c = S::classify(['cas_number' => '1-1-1', 'preferred_name' => 'Aniline', 'molecular_formula' => null,
    'has_nitrogen' => '1', 'has_sulfur' => null, 'has_halogen' => '0', 'element_flags_source' => ''], [], false);
check($c['current'] === $flags(1, 0, 0) && $c['status'] === 'unchanged' && $c['source'] === null, 'string / null current flags normalised; empty source → null', $c);

// -----------------------------------------------------------------
echo "c. plan — injected rows\n";
$rows = [
    $row('98-95-3',  'Nitrobenzene',     'C6H5NO2', $flags(0, 0, 0)),            // formula → N          (changed)
    $row('75-09-2',  'Dichloromethane',  null,      $flags(0, 0, 0)),            // keyword → Hal        (changed)
    $row('64-17-5',  'Ethanol',          'C2H6O',   $flags(1, 0, 0), 'manual'),  // manual, differs      (skipped)
    $row('141-78-6', 'Ethyl acetate',    'C4H8O2',  $flags(0, 0, 0), 'seed'),    // unchanged
    $row('62-56-6',  'CAS 62-56-6',      null,      $flags(0, 0, 0)),            // keyword via list name (changed)
    $row('7732-18-5','Water',            'H2O',     $flags(0, 0, 0), 'manual'),  // manual, matches      (unchanged)
];
$namesByCas = ['62-56-6' => ['Thiourea']];
$rmsByCas   = [
    '98-95-3' => [10, 11],
    '75-09-2' => [11, 12, 12],   // duplicate id tolerated
    '64-17-5' => [20],           // manual → not bumped unless force
    '141-78-6' => [30],          // unchanged → not bumped
];

$p = $seeder->plan(false, $rows, $namesByCas, $rmsByCas);
check($p['scanned'] === 6, 'scanned = 6', $p['scanned']);
check($p['changed'] === 3, 'changed = 3', $p['changed']);
check($p['unchanged'] === 2, 'unchanged = 2 (incl. matching manual row)', $p['unchanged']);
check($p['skippedManual'] === 1, 'skippedManual = 1', $p['skippedManual']);
check($p['force'] === false, 'force echoed');
check($p['changedCas'] === ['98-95-3', '75-09-2', '62-56-6'], 'changedCas in input order', $p['changedCas']);
check(count($p['rows']) === 3, 'rows = only the changed CAS', count($p['rows']));
check($p['inUseRmCount'] === 3, 'inUseRmCount = distinct RMs across changed CAS (10, 11, 12)', $p['inUseRmCount']);

$byCas = [];
foreach ($p['rows'] as $r) {
    $byCas[$r['cas']] = $r;
}
check(isset($byCas['98-95-3']) && $byCas['98-95-3']['proposed'] === $flags(1, 0, 0) && $byCas['98-95-3']['basis'] === 'formula C6H5NO2', 'row 98-95-3: formula basis');
check(isset($byCas['98-95-3']) && $byCas['98-95-3']['rmCount'] === 2, 'row 98-95-3: rmCount 2', $byCas['98-95-3']['rmCount'] ?? null);
check(isset($byCas['75-09-2']) && $byCas['75-09-2']['proposed'] === $flags(0, 0, 1) && str_starts_with($byCas['75-09-2']['basis'], 'names: '), 'row 75-09-2: keyword basis');
check(isset($byCas['75-09-2']) && $byCas['75-09-2']['rmCount'] === 2, 'row 75-09-2: rmCount deduped to 2', $byCas['75-09-2']['rmCount'] ?? null);
check(isset($byCas['62-56-6']) && $byCas['62-56-6']['proposed'] === $flags(1, 1, 0) && $byCas['62-56-6']['rmCount'] === 0, 'row 62-56-6: list-name keywords, no RMs');
check(!isset($byCas['64-17-5']) && !isset($byCas['141-78-6']) && !isset($byCas['7732-18-5']), 'manual / unchanged rows absent');
foreach ($p['rows'] as $r) {
    check(!array_key_exists('status', $r) && array_keys($r) === ['cas', 'name', 'current', 'proposed', 'basis', 'source', 'rmCount'], 'row shape: ' . $r['cas'], array_keys($r));
}

echo "d. plan — force\n";
$pf = $seeder->plan(true, $rows, $namesByCas, $rmsByCas);
check($pf['changed'] === 4 && $pf['skippedManual'] === 0 && $pf['unchanged'] === 2, 'force: manual row counted as changed', [$pf['changed'], $pf['skippedManual'], $pf['unchanged']]);
check($pf['changedCas'] === ['98-95-3', '75-09-2', '64-17-5', '62-56-6'], 'force: changedCas includes the manual row', $pf['changedCas']);
check($pf['inUseRmCount'] === 4, 'force: inUseRmCount includes the manual row\'s RM', $pf['inUseRmCount']);
$fm = null;
foreach ($pf['rows'] as $r) {
    if ($r['cas'] === '64-17-5') {
        $fm = $r;
    }
}
check($fm !== null && $fm['source'] === 'manual' && $fm['proposed'] === $flags(0, 0, 0) && $fm['current'] === $flags(1, 0, 0), 'force: manual row shows source=manual, current → proposed', $fm);

echo "e. plan — edge cases\n";
$p0 = $seeder->plan(false, [], [], []);
check($p0['scanned'] === 0 && $p0['changed'] === 0 && $p0['rows'] === [] && $p0['inUseRmCount'] === 0, 'empty registry');
$p1 = $seeder->plan(false, [$row('141-78-6', 'Ethyl acetate', 'C4H8O2', $flags(0, 0, 0), 'seed')], [], ['141-78-6' => [1, 2]]);
check($p1['changed'] === 0 && $p1['inUseRmCount'] === 0, 'idempotent: nothing changes, nothing to bump');
$p2 = $seeder->plan(false, [$row('75-09-2', 'Dichloromethane', null, $flags(0, 0, 0))], [], []);
check($p2['changed'] === 1 && $p2['rows'][0]['rmCount'] === 0 && $p2['inUseRmCount'] === 0, 'changed CAS not in use → rmCount 0');

echo "f. summary\n";
$s = S::summary($p + ['rmsBumped' => 3, 'sdsQueued' => 7, 'queue' => false]);
check($s === [
    'force' => false, 'queue' => false, 'scanned' => 6, 'changed' => 3, 'unchanged' => 2,
    'skipped_manual' => 1, 'rms_bumped' => 3, 'sds_queued' => 7,
], 'summary keys / values', $s);
check(S::summary([])['queue'] === true && S::summary([])['changed'] === 0, 'summary defaults');
check(S::SOURCE_SEED === 'seed' && S::SOURCE_MANUAL === 'manual', 'source constants');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
