<?php
/**
 * DB-free checks for CasAcrylateFlagSeeder (A12a, fixed-text review
 * 2026-10-12) — the seeder shared by scripts/seed-cas-acrylate-flags.php and
 * the "Seed acrylate flags (preview)" page (/determinations/acrylate-flags):
 *
 *   classify():  legacy list / name rule (preferred name, synonyms, extra
 *                names), acrylic polymers never, never proposes 0, manual
 *                rows skipped (and rewritten with force), string flags,
 *                stale seed flag cleared, bump only when the effective
 *                detection on the sheet name changes, nameCheck.
 *   plan():      counts, changedCas / bumpCas, inUseRmCount (bumpCas only),
 *                row shape, rmCount deduped, legacyNotInRegistry, force,
 *                idempotence.
 *   summary():   audit / flash counts (#19 keys + text_changes).
 *
 * Same Reflection bootstrap as CasTcMetalSeederTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/CasAcrylateFlagSeederTest.php
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

use SDS\Services\CasAcrylateFlagSeeder as S;
use SDS\Services\UVAcrylateRulePack as P;

$row = static fn (string $cas, string $name, $flag = null, ?string $source = null, ?string $syn = null, ?string $p65 = null): array => [
    'cas_number' => $cas, 'preferred_name' => $name, 'synonyms_json' => $syn,
    'is_acrylate' => $flag, 'acrylate_source' => $source, 'prop65_name' => $p65,
];

echo "a. classify\n";
$proposals = [];

$c = S::classify($row('13048-33-4', '1,6-Hexanediol Diacrylate (HDDA)'), [], false);
$proposals[] = $c['proposed'];
check($c['status'] === 'changed' && $c['current'] === null && $c['proposed'] === 1
    && $c['basis'] === 'legacy list; names: ["1,6-Hexanediol Diacrylate (HDDA)"]'
    && $c['bump'] === false && $c['nameCheck'] === false, '1 HDDA: legacy + name, metadata only', $c);

$c = S::classify($row('55818-57-0', 'Calcium Zinc stabilizer (generic)'), [], false);
$proposals[] = $c['proposed'];
check($c['proposed'] === 1 && $c['basis'] === 'legacy list' && $c['nameCheck'] === true && $c['bump'] === false,
    '2 legacy CAS with a wrong registry name: CHECK-NAME, no bump', $c);

$c = S::classify($row('99999-01-1', 'Photomer 4028', null, null, '["Bisphenol A ethoxylate diacrylate"]'), [], false);
$proposals[] = $c['proposed'];
check($c['proposed'] === 1 && $c['bump'] === true && str_contains($c['basis'], 'Bisphenol A ethoxylate diacrylate'),
    '3 synonym fires; sheet name does not -> bump', $c);

$c = S::classify($row('99999-02-2', 'Ink base'), ['Hydroxyethyl acrylate blend'], false);
$proposals[] = $c['proposed'];
check($c['proposed'] === 1 && $c['bump'] === true, '4 extra names count', $c);

$c = S::classify($row('9003-01-4', 'Acrylic resin'), ['Polyacrylic acid'], false);
$proposals[] = $c['proposed'];
check($c['status'] === 'unchanged' && $c['proposed'] === null, '5 acrylic polymer / resin never', $c);

$c = S::classify($row('64-17-5', 'Ethanol'), [], false);
$proposals[] = $c['proposed'];
check($c['status'] === 'unchanged' && $c['proposed'] === null && $c['basis'] === 'no acrylate name', '6 ethanol: nothing', $c);

$c = S::classify($row('7085-85-0', 'Ethyl Cyanoacrylate', 0, 'manual'), [], false);
$proposals[] = $c['proposed'];
check($c['status'] === 'manual', '7 manual 0 skipped', $c);
$c = S::classify($row('7085-85-0', 'Ethyl Cyanoacrylate', 0, 'manual'), [], true);
$proposals[] = $c['proposed'];
check($c['status'] === 'changed' && $c['proposed'] === 1 && $c['bump'] === true, '7 manual 0 + force -> 1, bump', $c);

$c = S::classify($row('99999-03-3', 'Aliphatic urethane oligomer', 1, 'manual'), [], false);
$proposals[] = $c['proposed'];
check($c['status'] === 'manual' && $c['current'] === 1, '8 manual 1 the rule misses is kept', $c);

$c = S::classify($row('15625-89-5', 'Trimethylolpropane Triacrylate (TMPTA)', '1', 'manual'), [], false);
$proposals[] = $c['proposed'];
check($c['status'] === 'unchanged' && $c['current'] === 1, '9 string "1" read as 1', $c);

$c = S::classify($row('99999-04-4', 'Renamed substance', 1, 'seed'), [], false);
$proposals[] = $c['proposed'];
check($c['status'] === 'changed' && $c['proposed'] === null && $c['bump'] === true, '10 stale seed flag cleared, bump', $c);

$c = S::classify($row('140-88-5', 'Ethyl 2-propenoate', null, null, null, 'Ethyl acrylate'), ['Ethyl acrylate'], false);
$proposals[] = $c['proposed'];
check($c['proposed'] === 1 && $c['bump'] === false, '11 Prop 65 sheet name already fired: no bump', $c);

check(array_filter($proposals, static fn ($v): bool => $v !== 1 && $v !== null) === [], '12 never proposes 0', $proposals);

// 13: registry name fires but a resale constituent name does not -> the resale sheet
// starts detecting it, so the seed is a content change (bump), not metadata.
$c = S::classify($row('99999-05-5', 'Ethyl acrylate'), ['Ethyl 2-propenoate'], false);
check($c['proposed'] === 1 && $c['bump'] === true, '13 constituent name differs from the sheet name: bump', $c);

echo "a2. detectionChanges (manual save / seeder bump on every sheet-tested name)\n";
// Non-legacy CAS, flag NULL. Registry (sheet) name is a CAS index name; the resale
// constituent name is the vendor's "... triacrylate".
$names = ['2-Propenoic acid, 2-ethyl-2-(hydroxymethyl)-1,3-propanediyl ester', 'Vendor TMP triacrylate'];
check(S::detectionChanges(null, 0, '99999-06-6', $names) === true, 'untick (NULL -> 0): resale sheet stops detecting -> change');
check(S::detectionChanges(null, 1, '99999-06-6', $names) === true, 'tick (NULL -> 1): FG sheet name starts detecting -> change');
check(S::detectionChanges(null, 1, '140-88-5', ['Ethyl acrylate', 'Ethyl 2-propenoate']) === true, 'confirm tick where a constituent name does not fire -> change');
check(S::detectionChanges(null, 1, '140-88-5', ['Ethyl acrylate', 'Ethyl acrylate']) === false, 'confirm tick every name already fires -> metadata only');
check(S::detectionChanges(null, 0, '64-17-5', ['Ethanol']) === false, 'NULL -> 0 on a non-acrylate name -> metadata only');
check(S::detectionChanges(null, 0, '13048-33-4', ['HDDA']) === true, 'legacy CAS NULL -> 0 -> change');
check(S::detectionChanges(1, 1, '99999-06-6', $names) === false && S::detectionChanges(0, 1, '99999-06-6', []) === false,
    'same value, or no names -> no change');
check(S::detectionChanges(0, 1, '99999-06-6', ['Ink base']) === true, '0 -> 1 -> change');
$adminSrc = (string) file_get_contents($basePath . '/src/Controllers/AdminController.php');
check(str_contains($adminSrc, 'CasAcrylateFlagSeeder::detectionChanges($old, $new, $cas, $sheetNames)')
    && str_contains($adminSrc, 'SELECT DISTINCT chemical_name FROM raw_material_constituents WHERE cas_number = ?'),
    'saveCasAcrylateFlag decides the bump on the sheet name + every constituent name');

echo "b. plan\n";
$rows = [
    $row('13048-33-4', '1,6-Hexanediol Diacrylate (HDDA)'),
    $row('55818-57-0', 'Calcium Zinc stabilizer (generic)'),
    $row('99999-01-1', 'Photomer 4028', null, null, '["Bisphenol A ethoxylate diacrylate"]'),
    $row('64-17-5', 'Ethanol'),
    $row('7085-85-0', 'Ethyl Cyanoacrylate', 0, 'manual'),
];
$rms = ['13048-33-4' => [1, 2], '55818-57-0' => [2], '99999-01-1' => [3, 3, 4], '64-17-5' => [1]];
$p = (new S(null))->plan(false, $rows, [], $rms);
check($p['scanned'] === 5 && $p['changed'] === 3 && $p['unchanged'] === 1 && $p['skippedManual'] === 1, 'counts', $p);
check($p['changedCas'] === ['13048-33-4', '55818-57-0', '99999-01-1'], 'changedCas', $p['changedCas']);
check($p['bumpCas'] === ['99999-01-1'] && $p['textChanges'] === 1, 'bumpCas / textChanges', $p['bumpCas']);
check($p['inUseRmCount'] === 2, 'inUseRmCount counts bumpCas RMs only (3, 4)', $p['inUseRmCount']);
check($p['rows'][2]['rmCount'] === 2, 'rmCount deduped', $p['rows'][2]);
check(array_keys($p['rows'][0]) === ['cas', 'name', 'current', 'proposed', 'basis', 'source', 'bump', 'nameCheck', 'rmCount'],
    'row shape', array_keys($p['rows'][0]));
check(count($p['legacyNotInRegistry']) === 12 && in_array('2223-82-7', $p['legacyNotInRegistry'], true)
    && !in_array('13048-33-4', $p['legacyNotInRegistry'], true), 'legacyNotInRegistry', $p['legacyNotInRegistry']);

$pf = (new S(null))->plan(true, $rows, [], $rms);
check($pf['changed'] === 4 && $pf['skippedManual'] === 0 && $pf['bumpCas'] === ['99999-01-1', '7085-85-0'] && $pf['inUseRmCount'] === 2,
    'force rewrites the manual row', $pf);

$planned = [];
foreach ($p['rows'] as $r) {
    $planned[$r['cas']] = $r['proposed'];
}
$after = array_map(static function (array $r) use ($planned): array {
    if (array_key_exists($r['cas_number'], $planned)) {
        $r['is_acrylate']     = $planned[$r['cas_number']];
        $r['acrylate_source'] = $planned[$r['cas_number']] === null ? null : 'seed';
    }
    return $r;
}, $rows);
$p2 = (new S(null))->plan(false, $after, [], $rms);
check($p2['changed'] === 0 && $p2['skippedManual'] === 1, 'idempotent: second run changes nothing', $p2);

echo "c. summary\n";
$s = S::summary($p + ['queue' => false, 'rmsBumped' => 2, 'sdsQueued' => 1]);
check($s === ['force' => false, 'queue' => false, 'scanned' => 5, 'changed' => 3, 'text_changes' => 1, 'unchanged' => 1,
    'skipped_manual' => 1, 'rms_bumped' => 2, 'sds_queued' => 1], 'summary', $s);
check(S::SOURCE_SEED === 'seed' && S::SOURCE_MANUAL === 'manual' && str_contains(S::QUEUE_REASON, 'A12a'), 'constants');
check(count(P::LEGACY_ACRYLATE_CAS) === 14, 'legacy list size');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
