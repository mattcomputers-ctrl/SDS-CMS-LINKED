#!/usr/bin/env php
<?php
/**
 * STOT target organs — live-DB test (needs config/config.php and migration 062)
 *
 * Runs inside ONE transaction that is always rolled back, so it is safe on
 * the production database: its rows (CAS 7777760..7777763, raw material
 * ZZSTOT1) disappear at the end, also on failure. Checks the 2026-10-21
 * STOT tab rework through StotTargetOrgans::writeCasStot(), the writer the
 * single-CAS save and the batch save share:
 *   - organs and a route are saved; saving the same again is "unchanged";
 *   - unticking every organ clears the exposure type and its route (it used
 *     to be refused while a route was set);
 *   - a CAS typed with a typographic dash is saved under the canonical CAS,
 *     and a cas_master row is created when there is none;
 *   - each change is audited, and the raw materials carrying the CAS are
 *     found in any spelling (so they are bumped and queued).
 *
 * Run:
 *   sudo -u www-data php tests/Services/StotTargetOrgansLiveTest.php
 *
 * Exit code: 0 = passed (or skipped), 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\StotTargetOrgans as Stot;

if (!is_file($basePath . '/config/config.php')) {
    echo "SKIP: no config/config.php (live-DB test)\n";
    exit(0);
}
new \SDS\Core\App();
$db  = \SDS\Core\Database::getInstance();
$pdo = $db->getPdo();
if ((int) ($db->fetch("SELECT COUNT(*) AS n FROM cas_master WHERE cas_number IN ('7777760-77-1', '7777761-77-2', '7777762-77-3', '7777763-77-4')")['n'] ?? 0) > 0) {
    echo "SKIP: test CAS 7777760..7777763 already exist\n";
    exit(0);
}

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
        echo '       actual: ' . json_encode($actual) . "\n";
    }
}

$pdo->beginTransaction();
try {
    $row = static fn (string $cas): ?array => $db->fetch(
        "SELECT stot_se_organs, stot_se_route, stot_re_organs, stot_re_route FROM cas_master WHERE cas_number = ?",
        [$cas]
    );
    $db->insert('cas_master', ['cas_number' => '7777760-77-1', 'preferred_name' => 'ZZ STOT test']);

    echo "Writer\n";
    $w = Stot::writeCasStot($db, 'not a cas', ['stot_se_organs' => ['lungs']]);
    check(($w['error'] ?? '') === 'A valid CAS number is required.', 'an invalid CAS is refused', $w);

    $w = Stot::writeCasStot($db, '7777760-77-1', ['stot_re_organs' => ['lungs', 'liver'], 'stot_re_route' => 'inhalation']);
    $r = $row('7777760-77-1');
    check(($w['changed'] ?? null) === true && ($r['stot_re_organs'] ?? '') === 'lungs,liver' && ($r['stot_re_route'] ?? '') === 'inhalation'
        && $r['stot_se_organs'] === null, 'repeated-exposure organs and route are saved (single exposure untouched)', [$w, $r]);
    $w = Stot::writeCasStot($db, '7777760-77-1', ['stot_re_organs' => ['liver', 'lungs'], 'stot_re_route' => 'inhalation']);
    check(($w['changed'] ?? null) === false, 'the same organs again (any order): unchanged', $w);

    $w = Stot::writeCasStot($db, '7777760-77-1', ['stot_re_organs' => ['lungs'], 'stot_re_route' => 'inhalation']);
    check(($w['changed'] ?? null) === true && ($row('7777760-77-1')['stot_re_organs'] ?? '') === 'lungs', 'unticking one organ keeps the others', $row('7777760-77-1'));
    $w = Stot::writeCasStot($db, '7777760-77-1', ['stot_re_organs' => [], 'stot_re_route' => 'inhalation']);
    $r = $row('7777760-77-1');
    check(($w['changed'] ?? null) === true && $r['stot_re_organs'] === null && $r['stot_re_route'] === null,
        'unticking every organ clears the exposure type and its route (no longer refused)', [$w, $r]);
    $w = Stot::writeCasStot($db, '7777760-77-1', ['stot_se_organs' => ['bogus_organ'], 'stot_se_route' => 'teleport']);
    check(($w['changed'] ?? null) === false, 'unknown organ keys and routes are ignored', $w);

    $w = Stot::writeCasStot($db, "7777761\u{2013}77\u{2013}2", ['stot_se_organs' => 'central_nervous_system,eyes', 'stot_se_route' => 'all']);
    $r = $row('7777761-77-2');
    check(($w['cas'] ?? '') === '7777761-77-2' && ($r['stot_se_organs'] ?? '') === 'central_nervous_system,eyes' && ($r['stot_se_route'] ?? '') === 'all',
        'a CAS typed with en dashes is saved under the canonical CAS, creating its cas_master row', [$w, $r]);

    $audits = (int) ($db->fetch("SELECT COUNT(*) AS n FROM audit_log WHERE entity_type = 'cas_stot' AND entity_id IN ('7777760-77-1', '7777761-77-2')")['n'] ?? 0);
    check($audits === 4, 'each change is audited, unchanged saves are not', $audits);

    echo "\nRaw materials to bump\n";
    $rm = (int) $db->insert('raw_materials', ['internal_code' => 'ZZSTOT1']);
    $db->insert('raw_material_constituents', ['raw_material_id' => $rm, 'cas_number' => "7777762-77-3\u{00A0}", 'chemical_name' => 'ZZ', 'pct_exact' => 100]);
    $w = Stot::writeCasStot($db, '7777762-77-3', ['stot_re_organs' => ['kidneys']]);
    check(($w['changed'] ?? null) === true && in_array($rm, \SDS\Services\TSCAService::rawMaterialIdsForCasMany(['7777762-77-3']), true),
        'the raw material carrying the CAS (typed with a no-break space) is found for the bump', $w);

    echo "\nWhat the sheet reads, however the CAS was typed\n";
    $f = Stot::loadByCas($db, ["7777762-77-3\u{00A0}"]);
    check(($f['7777762-77-3']['re']['organs'] ?? null) === ['kidneys'], 'a constituent CAS typed with a no-break space finds the organs saved under the canonical CAS', $f);
    $db->insert('cas_master', ['cas_number' => "7777763\u{2013}77\u{2013}4", 'preferred_name' => 'ZZ legacy dash row', 'stot_re_organs' => 'liver']);
    $f = Stot::loadByCas($db, ["7777763\u{2013}77\u{2013}4"]);
    check(($f['7777763-77-4']['re']['organs'] ?? null) === ['liver'], 'organs entered earlier on a row stored with en dashes still print', $f);
    $db->insert('cas_master', ['cas_number' => '7777763-77-4', 'preferred_name' => 'ZZ canonical row', 'stot_re_organs' => 'kidneys']);
    $f = Stot::loadByCas($db, ["7777763\u{2013}77\u{2013}4"]);
    check(($f['7777763-77-4']['re']['organs'] ?? null) === ['kidneys'], '... but once the canonical row has organs (what the tab saves), it wins', $f);
    $admin = array_values(array_filter(Stot::adminRows($db), static fn (array $r): bool => in_array(\SDS\Services\TSCAService::normaliseCas((string) $r['cas_number']), ['7777763-77-4'], true)));
    check(count($admin) === 1 && $admin[0]['cas_number'] === '7777763-77-4' && $admin[0]['stot_re_organs'] === 'kidneys',
        'the STOT tab shows the substance once, under the canonical CAS, with the organs the sheet prints', $admin);
} finally {
    $pdo->rollBack();
}

check((int) ($db->fetch("SELECT COUNT(*) AS n FROM cas_master WHERE cas_number IN ('7777760-77-1', '7777761-77-2', '7777762-77-3', '7777763-77-4')")['n'] ?? 0) === 0, 'rolled back: no test rows left');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
