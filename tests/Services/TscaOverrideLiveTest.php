#!/usr/bin/env php
<?php
/**
 * TSCA overrides — live-DB test (needs config/config.php and migration 066)
 *
 * Runs inside ONE transaction that is always rolled back, so it is safe on
 * the production database: its rows (codes ZZTSC*, CAS 7777770..7777777)
 * disappear at the end, also on failure. Checks the 2026-10-21 fixes for
 * "TSCA inventory status has not been verified" despite overrides:
 *   - a raw-material override covers components without a CAS (a whole
 *     trade-secret raw material, a raw material with no constituents);
 *   - a CAS override matches a constituent CAS typed with an invisible
 *     character or zero padding, and an override stored on such a spelling
 *     still covers the clean CAS;
 *   - the TSCA Review lists agree with Section 15 (nothing covered is
 *     listed, nothing uncovered is missing), and a CAS override finds every
 *     raw material whatever way its CAS was typed;
 *   - the TSCA Review lists only raw materials on a sheet Bulk SDS Publish
 *     is cleared to publish; the rest are reported as "not listed yet";
 *   - a CAS on the inventory but INACTIVE is listed (Notice of Activity);
 *   - the third list names raw materials whose constituents add up to less
 *     than 100% (undisclosed, possibly trade-secret components).
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

    echo "\nScope: sheets Bulk SDS Publish is cleared to publish\n";
    $uid = (int) ($db->fetch("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if ($uid === 0) {
        $uid = (int) $db->insert('users', ['username' => 'zztsc_reviewer', 'password_hash' => 'x']);
    }
    $review = function (int $rmId) use ($db, $uid): void {
        $db->insert('audit_log', ['user_id' => $uid, 'entity_type' => 'raw_material', 'entity_id' => (string) $rmId, 'action' => 'update']);
    };
    $sheet = function (int $rmId, string $summary) use ($db): void {
        $db->insert('sds_versions', ['raw_material_id' => $rmId, 'language' => 'en', 'version' => 1, 'status' => 'published',
            'change_summary' => $summary, 'published_at' => gmdate('Y-m-d H:i:s'), 'is_deleted' => 0]);
    };
    $part = function (string $code, array $cons) use ($db, $rm, $review): int {
        $id = $rm($code);
        $review($id);
        foreach ($cons as $c) {
            $db->insert('raw_material_constituents', ['raw_material_id' => $id, 'chemical_name' => 'ZZ part'] + $c);
        }
        return $id;
    };
    $product = function (string $code, array $rmIds) use ($db): int {
        $fgId = (int) $db->insert('finished_goods', ['product_code' => $code]);
        $fId  = (int) $db->insert('formulas', ['finished_good_id' => $fgId, 'is_current' => 1]);
        foreach ($rmIds as $id) {
            $db->insert('formula_lines', ['formula_id' => $fId, 'raw_material_id' => $id, 'pct' => 10]);
        }
        return $fId;
    };
    $scope = static fn (): array => \SDS\Controllers\BulkPublishController::rawMaterialsOnClearedSheets($db);
    $lists = static function (array $scope): array {
        $waiting = [];
        $cas = array_column(T::reviewCandidates($scope, $waiting), null, 'cas_number');
        $rms = array_column(T::noCasReviewCandidates($scope, $waiting), null, 'internal_code');
        $und = array_column(T::undisclosedCompositionCandidates($scope, array_column($rms, 'id')), null, 'internal_code');
        return [$cas, $rms, $waiting, $und];
    };
    $hU = $rm('ZZTSCHOLDU');                                      // not reviewed, no data, in a formula
    $hC = $rm('ZZTSCHOLDC'); $con($hC, '7777773-77-5', 'Not reviewed, CAS not on the inventory');
    $hR = $rm('ZZTSCHOLDR'); $review($hR); $sheet($hR, \SDS\Services\SDSReadinessService::BULK_RESALE_CHANGE_SUMMARY);
    $hF = $rm('ZZTSCHOLDF'); $review($hF);                        // reviewed, no data, only in ZZTSCFG2
    $fg2 = (int) $db->insert('finished_goods', ['product_code' => 'ZZTSCFG2']);
    $f2  = (int) $db->insert('formulas', ['finished_good_id' => $fg2, 'is_current' => 1]);
    foreach ([$hU, $hF] as $id) {
        $db->insert('formula_lines', ['formula_id' => $f2, 'raw_material_id' => $id, 'pct' => 50]);
    }

    $sc = $scope();
    check(!isset($sc[$hU]) && !isset($sc[$hF]), 'a product bulk publish skips (one raw material not reviewed): none of its raw materials are in scope');
    check(!isset($sc[$hC]), 'not reviewed, in no formula and with no sheet: not in scope');
    check(!isset($sc[$hR]), 'no constituents and only a sheet bulk publish wrote: not in scope (the resale gate refuses it)');
    [$casList, $rmList, $waiting] = $lists($sc);
    check(!isset($casList['7777773-77-5']), 'CAS list leaves out a CAS carried only by raw materials not in scope', array_keys($casList));
    check(!isset($rmList['ZZTSCHOLDU']) && !isset($rmList['ZZTSCHOLDR']) && !isset($rmList['ZZTSCHOLDF']), '... and so does the second list', array_keys($rmList));
    check(($waiting[$hU] ?? '') === 'ZZTSCHOLDU' && ($waiting[$hC] ?? '') === 'ZZTSCHOLDC' && ($waiting[$hR] ?? '') === 'ZZTSCHOLDR' && ($waiting[$hF] ?? '') === 'ZZTSCHOLDF',
        '... and reports them as "not listed yet"', $waiting);
    check(isset(array_column(T::noCasReviewCandidates(), null, 'internal_code')['ZZTSCHOLDU']), 'without a scope the raw material would be listed (default unchanged)');

    $review($hU);   // every raw material of ZZTSCFG2 reviewed: bulk publish is cleared to publish it
    [, $rmList] = $lists($scope());
    check(isset($rmList['ZZTSCHOLDF']) && isset($rmList['ZZTSCHOLDU']), 'once the product is cleared, its raw materials with no constituents are listed (Section 15 prints "not verified")', array_keys($rmList));
    $pail = $rm('ZZTSCPAIL');
    $db->query("UPDATE raw_materials SET sds_not_required = 1 WHERE id = ?", [$pail]);
    [, , $waiting] = $lists($scope());
    check(!isset($waiting[$pail]), 'a No SDS required item no formula uses is not even "not listed yet" (no sheet can carry it)');

    $sheet($hR, 'Published from SDS Creation Readiness Check');   // published by hand
    $sc = $scope();
    check(isset($sc[$hR]), 'a resale raw material with no constituents whose sheet was published by hand is in scope (bulk publish republishes it)');

    $review($hC);
    $sc = $scope();
    check(!isset($sc[$hC]), 'reviewed with constituents but in no formula and with no sheet: still not in scope (no sheet carries it)');
    $sheet($hC, 'Published from SDS Creation Readiness Check');
    [$casList] = $lists($scope());
    check(isset($casList['7777773-77-5']), '... with a resale sheet of its own: its CAS is listed');

    $hB = $part('ZZTSCBLKC', [['cas_number' => '7777771-77-8', 'pct_exact' => 100]]);   // reviewed, CAS not on the inventory
    $f4 = $product('ZZTSCFG4', [$hB, $rm('ZZTSCHOLDB')]);                               // skipped: ZZTSCHOLDB not reviewed
    [$casList, , $waiting] = $lists($scope());
    check(!isset($casList['7777771-77-8']) && ($waiting[$hB] ?? '') === 'ZZTSCBLKC', 'reviewed with data but only in a product bulk publish skips: not listed yet', $waiting[$hB] ?? array_keys($casList));

    // A raw-material line whose code matches an active product: the planner
    // re-routes it (Rule 1 never checks it) but Section 15 prints its data.
    $sub = (int) $db->insert('finished_goods', ['product_code' => 'ZZTSCSUB']);
    $db->insert('formulas', ['finished_good_id' => $sub, 'is_current' => 1]);
    $hX  = $rm('ZZTSCSUB-1G');                                   // not reviewed, no data
    $product('ZZTSCTOP', [$hX]);
    [, $rmList] = $lists($scope());
    check(isset($rmList['ZZTSCSUB-1G']), 'not reviewed but on a re-routed line of a product bulk publish is cleared to publish: listed (its gap prints on that sheet)', array_keys($rmList));
    $db->query("UPDATE finished_goods SET is_active = 0 WHERE product_code = 'ZZTSCTOP'");
    $sc = $scope();
    check(!isset($sc[$hX]), '... not in scope once that product is inactive (bulk publish leaves it out)');

    echo "\nINACTIVE inventory entries\n";
    $db->insert('tsca_inventory', ['cas_number' => '7777770-77-0', 'chemical_name' => 'ZZ inactive', 'is_active_inventory' => 0]);
    $hI = $part('ZZTSCINACT', [['cas_number' => '7777770-77-0', 'pct_exact' => 100]]);
    $product('ZZTSCFG5', [$hI]);
    [$casList] = $lists($scope());
    check(($casList['7777770-77-0']['status'] ?? '') === T::STATUS_INACTIVE, 'a CAS on the inventory but INACTIVE is listed (Notice of Activity needed)', $casList['7777770-77-0'] ?? array_keys($casList));
    check($key([$hI]) === 'section15.tsca_status' && str_contains((string) T::warningText(T::rollUpForRawMaterialIds([$hI])), 'INACTIVE'),
        '... although Section 15 prints it as listed (the preview only warns)');
    $db->query("UPDATE raw_materials SET tsca_status = 'listed', tsca_note = 'Supplier SDS' WHERE id = ?", [$hI]);
    [$casList] = $lists($scope());
    check(($casList['7777770-77-0']['status'] ?? '') === T::STATUS_INACTIVE && str_contains((string) T::warningText(T::rollUpForRawMaterialIds([$hI])), 'INACTIVE'),
        '... still listed when its raw material has a raw-material override (that does not confirm a Notice of Activity; the sheet still warns)', $casList['7777770-77-0'] ?? array_keys($casList));
    $ov('7777770-77-0');   // Listed, with the Notice of Activity reference as the note
    [$casList] = $lists($scope());
    check(!isset($casList['7777770-77-0']), '... and it leaves the list once its CAS override is set');

    echo "\nConstituents adding up to less than 100% (third list)\n";
    $ov('7777772-77-6');                                          // covered: Section 15 reads "all listed"
    $pIn    = $part('ZZTSCPART',  [['cas_number' => '7777772-77-6', 'pct_exact' => 60]]);
    $pBlk   = $part('ZZTSCPARTB', [['cas_number' => '7777772-77-6', 'pct_exact' => 50]]);
    $pOpen  = $part('ZZTSCOPEN',  [['cas_number' => '7777772-77-6', 'pct_exact' => 40], ['cas_number' => '7777772-77-6', 'pct_min' => 10]]);
    $pFull  = $part('ZZTSCFULL',  [['cas_number' => '7777772-77-6', 'pct_min' => 40, 'pct_max' => 60], ['cas_number' => '7777772-77-6', 'pct_max' => 45]]);
    $pTs    = $part('ZZTSCPARTT', [['cas_number' => '7777772-77-6', 'pct_exact' => 30, 'is_trade_secret' => 1]]);
    $pSheet = $part('ZZTSCSHEET', [['cas_number' => '7777772-77-6', 'pct_exact' => 70]]);
    $sheet($pSheet, 'Published from SDS Creation Readiness Check');
    $product('ZZTSCFG3', [$pIn, $pOpen, $pFull, $pTs]);
    $db->insert('formula_lines', ['formula_id' => $f4, 'raw_material_id' => $pBlk, 'pct' => 50]);   // only in the skipped ZZTSCFG4

    $u = $lists($scope())[3];
    check(($u['ZZTSCPART']['undisclosed_pct'] ?? null) == 40 && ($u['ZZTSCPART']['entered_pct'] ?? null) == 60 && ($u['ZZTSCPART']['in_formula'] ?? 0) === 1,
        '60% entered, in a product bulk publish is cleared to publish: listed, 40% undisclosed', $u['ZZTSCPART'] ?? array_keys($u));
    check($key([$pIn]) === 'section15.tsca_status', '... while Section 15 still reads "all components listed" (why it is worth a look)');
    check(($u['ZZTSCPARTT']['trade_secret_count'] ?? 0) === 1, 'a trade-secret constituent is counted on its row', $u['ZZTSCPARTT'] ?? null);
    check(isset($u['ZZTSCSHEET']) && ($u['ZZTSCSHEET']['in_formula'] ?? 1) === 0, 'not in a formula but with a resale sheet bulk publish republishes: listed');
    check(!isset($u['ZZTSCPARTB']), 'only in a product bulk publish skips: not listed');
    check(!isset($u['ZZTSCOPEN']), 'a constituent with only a minimum: cannot tell, not listed');
    check(!isset($u['ZZTSCFULL']), 'ranges whose maximums reach 100%: not listed');

    $db->query("UPDATE raw_materials SET tsca_status = 'listed', tsca_note = 'Supplier SDS Section 15' WHERE id = ?", [$pIn]);
    $u = $lists($scope())[3];
    check(!isset($u['ZZTSCPART']), 'a raw-material override takes it off the list');
} finally {
    $pdo->rollBack();
}

check((int) ($db->fetch("SELECT COUNT(*) AS n FROM raw_materials WHERE internal_code LIKE 'ZZTSC%'")['n'] ?? 0) === 0, 'rolled back: no test rows left');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
