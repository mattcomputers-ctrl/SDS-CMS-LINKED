<?php
/**
 * DB-free checks for CasTcMetalSeeder (content audit #12) — the seeder shared
 * by scripts/seed-cas-tc-metals.php and the "Seed RCRA metal flags (preview)"
 * page (/determinations/tc-metals):
 *
 *   classify():  formula-based (formula wins over names, basis "formula …"),
 *                name / Colour Index based (basis "names: {…}"), NULL and
 *                "none" equal (no mass bump), stored list normalised,
 *                manual-skipped, manual + force.
 *   plan():      counts (scanned / changed / unchanged / skippedManual /
 *                inUseRmCount), rows only for the CAS that change, in input
 *                order, row shape, rmCount deduped, changedCas; force
 *                rewrites manual rows.
 *   summary():   audit / flash counts (same keys as #19).
 *
 * Same Reflection bootstrap as CasElementFlagSeederTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/CasTcMetalSeederTest.php
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

use SDS\Services\CasTcMetalSeeder as S;

$row = static fn (string $cas, string $name, ?string $formula, ?string $metals, ?string $source = null, ?string $syn = null): array => [
    'cas_number' => $cas, 'preferred_name' => $name, 'synonyms_json' => $syn, 'molecular_formula' => $formula,
    'tc_metals' => $metals, 'tc_metals_source' => $source,
];
$seeder = new S(null);

echo "a. classify\n";
$c = S::classify($row('7758-97-6', 'Lead chromate', 'CrO4Pb', null), [], false);
check($c['status'] === 'changed' && $c['proposed'] === ['Cr', 'Pb'] && $c['current'] === [] && $c['basis'] === 'formula CrO4Pb', 'formula row: NULL -> Cr,Pb', $c);
$c = S::classify($row('1344-37-2', 'CAS 1344-37-2', 'Unspecified', null), ['C.I. Pigment Yellow 34'], false);
check($c['proposed'] === ['Cr', 'Pb'] && str_starts_with($c['basis'], 'names: '), 'CI name via extra list name', $c);
$c = S::classify($row('108-88-3', 'Toluene', 'C7H8', null), [], false);
check($c['status'] === 'unchanged' && $c['proposed'] === [], 'NULL and none are equal -> unchanged (no mass bump)', $c);
$c = S::classify($row('7727-43-7', 'Barium sulfate', null, 'Ba', 'seed'), [], false);
check($c['status'] === 'unchanged' && $c['source'] === 'seed', 'already correct -> unchanged', $c);
$c = S::classify($row('64-17-5', 'Ethanol', 'C2H6O', 'Pb', 'manual'), [], false);
check($c['status'] === 'manual' && $c['proposed'] === [] && $c['current'] === ['Pb'], 'manual differs -> skipped', $c);
$c = S::classify($row('64-17-5', 'Ethanol', 'C2H6O', 'Pb', 'manual'), [], true);
check($c['status'] === 'changed', 'manual + force -> changed', $c);
$c = S::classify($row('7440-22-4', 'Silver', 'Ag', ' ag ', ''), [], false);
check($c['status'] === 'unchanged' && $c['source'] === null, 'stored value normalised; empty source -> null', $c);

echo "b. plan\n";
$rows = [
    $row('7758-97-6', 'Lead chromate', 'CrO4Pb', null),
    $row('108-88-3',  'Toluene',       'C7H8',   null),
    $row('64-17-5',   'Ethanol',       'C2H6O',  'Pb', 'manual'),
    $row('7727-43-7', 'Barium sulfate', null,    null),
];
$p = $seeder->plan(false, $rows, [], ['7758-97-6' => [1, 2], '7727-43-7' => [2, 3, 3], '108-88-3' => [9]]);
check($p['scanned'] === 4 && $p['changed'] === 2 && $p['unchanged'] === 1 && $p['skippedManual'] === 1, 'counts', $p);
check($p['changedCas'] === ['7758-97-6', '7727-43-7'] && $p['inUseRmCount'] === 3, 'changedCas order; distinct RMs (1,2,3)', $p);
foreach ($p['rows'] as $r) {
    check(array_keys($r) === ['cas', 'name', 'current', 'proposed', 'basis', 'source', 'rmCount'], 'row shape ' . $r['cas'], array_keys($r));
}
check($p['rows'][1]['proposed'] === ['Ba'] && $p['rows'][1]['rmCount'] === 2, 'barium sulfate by name; rmCount deduped', $p['rows'][1]);
$pf = $seeder->plan(true, $rows, [], []);
check($pf['changed'] === 3 && $pf['skippedManual'] === 0, 'force includes the manual row', $pf);
$p0 = $seeder->plan(false, [], [], []);
check($p0['scanned'] === 0 && $p0['rows'] === [], 'empty registry');

echo "c. summary\n";
$s = S::summary($p + ['rmsBumped' => 3, 'sdsQueued' => 4, 'queue' => false]);
check($s === ['force' => false, 'queue' => false, 'scanned' => 4, 'changed' => 2, 'unchanged' => 1, 'skipped_manual' => 1, 'rms_bumped' => 3, 'sds_queued' => 4], 'summary', $s);
check(S::SOURCE_SEED === 'seed' && S::SOURCE_MANUAL === 'manual' && str_contains(S::QUEUE_REASON, '#12'), 'constants');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
