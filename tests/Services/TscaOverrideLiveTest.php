#!/usr/bin/env php
<?php
/**
 * TSCA overrides — live-DB test (needs config/config.php and migration 066)
 *
 * Runs inside ONE transaction that is always rolled back, so it is safe on
 * the production database: its rows (codes ZZTSC*, CAS 7777771..7777777)
 * disappear at the end, also on failure. Checks the 2026-10-21 fixes for
 * "TSCA inventory status has not been verified" despite overrides:
 *   - a raw-material override covers components without a CAS (a whole
 *     trade-secret raw material, a raw material with no constituents);
 *   - a CAS override matches a constituent CAS typed with an invisible
 *     character or zero padding, and an override stored on such a spelling
 *     still covers the clean CAS;
 *   - the TSCA Review lists agree with Section 15 (nothing covered is
 *     listed, nothing uncovered is missing), and a CAS override finds every
 *     raw material whatever way its CAS was typed.
 *
 * Run:
 *   sudo -u www-data php tests/Services/TscaOverrideLiveTest.php
 *
 * Exit code: 0 = passed (or skipped), 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\TSCAService as T;

if (!is_file($basePath . '/config/config.php')) {
    echo "SKIP: no config/config.php (live-DB test)\n";
    exit(0);
}
new \SDS\Core\App();
$db  = \SDS\Core\Database::getInstance();
$pdo = $db->getPdo();
if (!T::hasRawMaterialOverrideColumns()) {
    echo "SKIP: migration 066 has not run yet\n";
    exit(0);
}
if ((int) ($db->fetch("SELECT COUNT(*) AS n FROM raw_materials WHERE internal_code LIKE 'ZZTSC%'")['n'] ?? 0) > 0) {
    echo "SKIP: raw materials ZZTSC* already exist\n";
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

$nbsp = "\u{00A0}";
$pdo->beginTransaction();
try {
    $rm = function (string $code, int $noCas = 0) use ($db): int {
        return (int) $db->insert('raw_materials', ['internal_code' => $code, 'hazardous_no_cas' => $noCas]);
    };
    $con = function (int $rmId, string $cas, string $name) use ($db): void {
        $db->insert('raw_material_constituents', ['raw_material_id' => $rmId, 'cas_number' => $cas, 'chemical_name' => $name, 'pct_exact' => 100]);
    };
    $whole = $rm('ZZTSCWHOLE', 1);                 // whole trade secret (no CAS at all)
    $empty = $rm('ZZTSCEMPTY');                    // no constituents entered
    $nb    = $rm('ZZTSCNBSP');  $con($nb, '7777777-77-1' . $nbsp, 'Pasted with a no-break space');
    $pad   = $rm('ZZTSCPAD');   $con($pad, '07777776-77-2', 'Zero padded');
    $dirty = $rm('ZZTSCDIRTY'); $con($dirty, '7777775-77-3', 'Clean constituent, dirty registry row');
    $auto  = $rm('ZZTSCAUTO');  $con($auto, '7777774-77-4', 'Not on the inventory, no override');
    $dash  = $rm('ZZTSCDASH');  $con($dash, "7777777\u{2013}77\u{2013}1", 'Typed with en dashes');
    $zw    = $rm('ZZTSCZWSP');  $con($zw, "7777776-\u{200B}77-2", 'Interior zero-width space');
    $sp    = $rm('ZZTSCSPACE'); $con($sp, '7777775 - 77 - 3', 'Interior spaces');
    $prop  = $rm('ZZTSCPROP');  $con($prop, 'Proprietary', 'Not a CAS number');
    $tab   = $rm('ZZTSCTAB');   $con($tab, "\t", 'CAS is only a tab');

    // A finished good using all of them, so they count as "in a formula".
    $fg = (int) $db->insert('finished_goods', ['product_code' => 'ZZTSCFG']);
    $f  = (int) $db->insert('formulas', ['finished_good_id' => $fg, 'is_current' => 1]);
    foreach ([$whole, $empty, $nb, $pad, $dirty, $auto, $dash, $zw, $sp, $prop, $tab] as $id) {
        $db->insert('formula_lines', ['formula_id' => $f, 'raw_material_id' => $id, 'pct' => 10]);
    }

    // CAS overrides: clean spellings for the NBSP / padded constituents; a
    // registry row stored WITH a no-break space for the clean constituent.
    $ov = function (string $cas) use ($db): void {
        $db->query("INSERT INTO cas_master (cas_number, preferred_name, tsca_status, tsca_note) VALUES (?, 'ZZ test', 'listed', 'test')
                    ON DUPLICATE KEY UPDATE tsca_status = 'listed', tsca_note = 'test'", [$cas]);
    };
    $ov('7777777-77-1');
    $ov('7777776-77-2');
    $ov('7777775-77-3' . $nbsp);

    $key = static fn (array $ids): string => T::statusKey(T::rollUpForRawMaterialIds($ids));

    echo "CAS spelling\n";
    check($key([$nb]) === 'section15.tsca_status', 'constituent CAS with a no-break space matches its override', T::rollUpForRawMaterialIds([$nb]));
    check($key([$pad]) === 'section15.tsca_status', 'zero-padded constituent CAS matches its override');
    check($key([$dirty]) === 'section15.tsca_status', 'override stored with a no-break space still covers the clean CAS');
    check($key([$auto]) === 'section15.tsca_status_not_verified', 'a CAS with no override and not on the inventory: not verified');
    $forPad = T::rawMaterialIdsForCas('7777776-77-2');
    check(in_array($pad, $forPad, true), 'a CAS override finds the raw material whose CAS is zero-padded (so it is bumped)', $forPad);
    check(in_array($nb, T::rawMaterialIdsForCas('7777777-77-1'), true), '... and the one typed with a no-break space');
    check($key([$dash]) === 'section15.tsca_status' && $key([$zw]) === 'section15.tsca_status' && $key([$sp]) === 'section15.tsca_status',
        'constituent CAS with en dashes, an interior zero-width space or interior spaces matches its override');
    $many = T::rawMaterialIdsForCasMany(['7777777-77-1', '7777776-77-2', '7777775-77-3']);
    check(array_diff([$nb, $pad, $dirty, $dash, $zw, $sp], $many) === [], '... and a CAS override finds all of them (so they are bumped and queued)', $many);

    $review = array_column(T::reviewCandidates(), null, 'cas_number');
    check(!isset($review['7777777-77-1']) && !isset($review['7777776-77-2']) && !isset($review['7777775-77-3']), 'TSCA Review does not list a CAS that Section 15 counts as covered', array_keys($review));
    check(isset($review['7777774-77-4']) && str_contains($review['7777774-77-4']['raw_material_codes'], 'ZZTSCAUTO'), 'TSCA Review lists the uncovered CAS with its raw material');

    echo "\nRaw-material override\n";
    check($key([$whole]) === 'section15.tsca_status_not_verified', 'whole trade-secret raw material: not verified (no CAS override possible)');
    check($key([$empty]) === 'section15.tsca_status_not_verified', 'raw material with no constituents: not verified');
    $noCas = array_column(T::noCasReviewCandidates(), null, 'internal_code');
    check(isset($noCas['ZZTSCWHOLE']) && in_array('whole_trade_secret', $noCas['ZZTSCWHOLE']['reasons'], true), 'second list shows the whole trade-secret raw material', $noCas['ZZTSCWHOLE'] ?? null);
    check(isset($noCas['ZZTSCEMPTY']) && in_array('no_constituents', $noCas['ZZTSCEMPTY']['reasons'], true), '... and the raw material with no constituents');
    check(!isset($noCas['ZZTSCNBSP']) && !isset($noCas['ZZTSCAUTO']), '... but not raw materials whose components all have a CAS');
    check(in_array('invalid_cas', $noCas['ZZTSCPROP']['reasons'] ?? [], true) && !isset($review['PROPRIETARY']) && !isset($review['Proprietary']),
        'a constituent "CAS" that is not a CAS number is on the second list, not the CAS list', $noCas['ZZTSCPROP'] ?? null);
    check(in_array('blank_cas', $noCas['ZZTSCTAB']['reasons'] ?? [], true) && $key([$tab]) === 'section15.tsca_status_not_verified',
        'a CAS that is only a tab counts as blank', $noCas['ZZTSCTAB'] ?? null);

    $db->query("UPDATE raw_materials SET tsca_status = 'listed', tsca_note = 'Supplier SDS Section 15' WHERE id IN (?, ?)", [$whole, $empty]);
    check($key([$whole]) === 'section15.tsca_status', 'raw-material override (listed): the whole trade-secret raw material is covered');
    check($key([$empty]) === 'section15.tsca_status', '... and the one with no constituents');
    $emptyRoll = T::rollUpForRawMaterialIds([$empty]);
    check(in_array('ZZTSCEMPTY', array_column($emptyRoll['incomplete_raw_materials'] ?? [], 'internal_code'), true) && T::warningText($emptyRoll) === null,
        '... its composition warning stays (no constituents), its TSCA warning goes', $emptyRoll);
    $noCas = array_column(T::noCasReviewCandidates(), null, 'internal_code');
    check(!isset($noCas['ZZTSCWHOLE']) && !isset($noCas['ZZTSCEMPTY']), 'overridden raw materials leave the second list');
    $inEffect = array_column(T::rawMaterialOverridesInEffect(), null, 'internal_code');
    check(($inEffect['ZZTSCWHOLE']['tsca_status'] ?? '') === 'listed', '... and appear under raw-material overrides in effect');

    $db->query("UPDATE raw_materials SET tsca_status = 'exempt', tsca_note = 'Supplier certifies' WHERE id = ?", [$auto]);
    check($key([$auto]) === 'section15.tsca_status', 'raw-material override covers its CAS that is not on the inventory');
    check(!isset(array_column(T::reviewCandidates(), null, 'cas_number')['7777774-77-4']), '... which then leaves the TSCA Review list');

    $db->query("UPDATE raw_materials SET tsca_status = 'listed', tsca_note = 'Supplier SDS' WHERE id IN (?, ?)", [$prop, $tab]);
    check($key([$prop]) === 'section15.tsca_status' && $key([$tab]) === 'section15.tsca_status', 'raw-material override covers a non-CAS value and a blank CAS');
    check($key([$whole, $empty, $nb, $pad, $dirty, $auto, $dash, $zw, $sp, $prop, $tab]) === 'section15.tsca_status', 'the whole product: "All components ... listed on or exempt from the TSCA inventory"');
} finally {
    $pdo->rollBack();
}

check((int) ($db->fetch("SELECT COUNT(*) AS n FROM raw_materials WHERE internal_code LIKE 'ZZTSC%'")['n'] ?? 0) === 0, 'rolled back: no test rows left');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
