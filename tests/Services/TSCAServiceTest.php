<?php
/**
 * DB-free checks for audit item #29 — TSCA inventory status:
 *
 *   - TSCAService::normaliseCas(): EPA zero padding stripped, trim, passthrough.
 *   - TSCAService::rollUp(): all_covered only when every CAS is listed/exempt
 *     and nothing is unverifiable; unverified / not_listed maps keyed by CAS.
 *   - TSCAService::analyse(): DB-free for an empty / CAS-less composition.
 *   - TSCAService::warningText(): null when covered or overridden; otherwise
 *     names the CAS, truncates after 8, mentions NOT LISTED / no-CAS buckets,
 *     never blocks.
 *   - SDSGenerator::section15(): empty composition prints the "not verified"
 *     sentence; a stored Section 15 TSCA Status override is ignored (Q12).
 *   - SDSReadinessService::tscaWarning(): reads sections[15]['tsca'].
 *   - Translation keys present in all four languages.
 *
 * Same Reflection bootstrap as SDSGeneratorSection15Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/TSCAServiceTest.php
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

use SDS\Services\SDSReadinessService;
use SDS\Services\TSCAService;

// ---------------------------------------------------------------------
echo "a. normaliseCas\n";
check(TSCAService::normaliseCas('0000050-00-0') === '50-00-0', 'EPA zero padding stripped', TSCAService::normaliseCas('0000050-00-0'));
check(TSCAService::normaliseCas(' 108-88-3 ') === '108-88-3', 'trimmed', TSCAService::normaliseCas(' 108-88-3 '));
check(TSCAService::normaliseCas('') === '', 'empty stays empty');
check(TSCAService::normaliseCas('TRADE_SECRET') === 'TRADE_SECRET', 'TRADE_SECRET passthrough');
check(TSCAService::normaliseCas('0-00-0') === '0-00-0', 'all-zero first segment keeps a single 0', TSCAService::normaliseCas('0-00-0'));

// ---------------------------------------------------------------------
echo "b. rollUp\n";
$r = TSCAService::rollUp([]);
check($r['all_covered'] === false && $r['total'] === 0, 'empty → not covered, total 0', $r);
check(TSCAService::rollUp([], 1)['no_cas_count'] === 1, 'noCasCount carried');

$r = TSCAService::rollUp([
    '108-88-3' => ['status' => 'listed', 'name' => 'Toluene'],
    '64-17-5'  => ['status' => 'exempt', 'name' => 'Ethanol'],
]);
check($r['all_covered'] === true && $r['listed_count'] === 1 && $r['exempt_count'] === 1 && $r['total'] === 2, 'all listed/exempt → covered', $r);

$r = TSCAService::rollUp([
    '108-88-3' => ['status' => 'listed', 'name' => 'Toluene'],
    '999-99-9' => ['status' => 'not_verified', 'name' => ''],
], 0, ['999-99-9' => 'Mystery']);
check($r['all_covered'] === false && $r['unverified'] === ['999-99-9' => 'Mystery'], 'one not_verified → not covered, keyed by CAS with names fallback', $r);

$r = TSCAService::rollUp(['123-45-6' => ['status' => 'not_listed', 'name' => 'Absent']]);
check($r['all_covered'] === false && $r['not_listed'] === ['123-45-6' => 'Absent'], 'not_listed lands in not_listed', $r);

$r = TSCAService::rollUp(['108-88-3' => ['status' => 'listed', 'name' => 'Toluene']], 1);
check($r['all_covered'] === false && $r['no_cas_count'] === 1, 'listed + one no-CAS bucket → not covered', $r);
check($r['overridden'] === false, 'rollUp never marks overridden');

// ---------------------------------------------------------------------
echo "c. analyse (DB-free paths)\n";
$r = TSCAService::analyse([]);
check($r['all_covered'] === false && $r['no_cas_count'] === 0 && $r['total'] === 0, 'empty composition → not covered, no DB', $r);
$r = TSCAService::analyse([['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade secret', 'is_trade_secret' => true]]);
check($r['all_covered'] === false && $r['no_cas_count'] === 1 && $r['total'] === 0, 'TRADE_SECRET bucket → one no-CAS, no DB', $r);
$r = TSCAService::analyse([['cas_number' => '', 'chemical_name' => 'No CAS']]);
check($r['no_cas_count'] === 1, 'blank CAS counted as no-CAS', $r);

// ---------------------------------------------------------------------
echo "d. warningText\n";
check(TSCAService::warningText([]) === null, 'empty → null');
check(TSCAService::warningText(['all_covered' => true]) === null, 'covered → null');
check(TSCAService::warningText(['all_covered' => false, 'overridden' => true, 'unverified' => ['1-11-1' => 'X']]) === null, 'overridden → null');

$w = TSCAService::warningText(['all_covered' => false, 'unverified' => ['999-99-9' => 'Mystery'], 'not_listed' => [], 'no_cas_count' => 0]);
check(is_string($w) && str_contains($w, 'TSCA warning') && str_contains($w, '999-99-9') && str_contains($w, 'Mystery') && str_contains($w, 'Publishing is not blocked'), 'unverified → warning names CAS, not blocking', $w);

$many = [];
for ($i = 1; $i <= 11; $i++) {
    $many["{$i}-11-1"] = 'C' . $i;
}
$w = TSCAService::warningText(['all_covered' => false, 'unverified' => $many]);
check(is_string($w) && str_contains($w, '(+3 more)'), '>8 CAS truncated with (+N more)', $w);

$w = TSCAService::warningText(['all_covered' => false, 'unverified' => [], 'not_listed' => ['123-45-6' => 'Absent']]);
check(is_string($w) && str_contains($w, 'NOT LISTED') && str_contains($w, '123-45-6'), 'not_listed → NOT LISTED', $w);

$w = TSCAService::warningText(['all_covered' => false, 'unverified' => [], 'not_listed' => [], 'no_cas_count' => 2]);
check(is_string($w) && str_contains($w, '2 trade-secret components with no CAS cannot be checked'), 'no-CAS buckets → cannot be checked', $w);

$w = TSCAService::warningText(['all_covered' => false, 'unverified' => [], 'not_listed' => [], 'no_cas_count' => 0, 'total' => 0]);
check(is_string($w) && str_contains($w, 'no CAS-level composition'), 'nothing to check → still a warning', $w);

// ---------------------------------------------------------------------
echo "e. section15 (Reflection, empty composition)\n";
$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);
$s15 = new ReflectionMethod($gen, 'section15');
$s15->setAccessible(true);

// section15() calls ghsSectionNote(), which reads settings via Database::getInstance() unless its static cache is pre-set.
$ghsNoteProp = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'showGhsSectionNote');
$ghsNoteProp->setAccessible(true);
$ghsNoteProp->setValue(null, false);

$hz0  = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'exposure_limits' => []];
$sara = ['reportable' => [], 'below_threshold' => []];
$p65  = ['requires_warning' => false, 'warning_text' => '', 'cancer_chemicals' => [], 'repro_chemicals' => [], 'listed_chemicals' => []];
$hap  = ['has_haps' => false, 'total_hap_pct' => 0.0, 'hap_chemicals' => []];

$r = $s15->invoke($gen, $hz0, $sara, $p65, $hap, ['composition' => []], []);
check($r['tsca_status'] === $t->get('section15.tsca_status_not_verified'), 'empty composition prints the not-verified sentence', $r['tsca_status']);
check($r['tsca_status'] === 'TSCA inventory status has not been verified for all components.', 'en not-verified wording', $r['tsca_status']);
check(($r['tsca']['all_covered'] ?? null) === false && ($r['tsca']['overridden'] ?? null) === false, 'tsca roll-up stored: not covered, not overridden', $r['tsca'] ?? null);
check(SDSReadinessService::tscaWarning(['sections' => [15 => $r]]) !== null, 'readiness warning raised from the snapshot');

// Q12: the TSCA sentence is no longer editable per product; a stored row is ignored.
$r = $s15->invoke($gen, $hz0, $sara, $p65, $hap, ['composition' => []], [15 => ['tsca_status' => 'Vendor confirms TSCA.']]);
check($r['tsca_status'] === $t->get('section15.tsca_status_not_verified'), 'stored Section 15 TSCA override ignored (Q12)', $r['tsca_status']);
check(($r['tsca']['overridden'] ?? null) === false, 'roll-up never marked overridden', $r['tsca'] ?? null);
check(SDSReadinessService::tscaWarning(['sections' => [15 => $r]]) !== null, 'the TSCA warning still fires');

$r = $s15->invoke($gen, $hz0, $sara, $p65, $hap, ['composition' => []], [15 => ['tsca_status' => '   ']]);
check($r['tsca_status'] === $t->get('section15.tsca_status_not_verified') && ($r['tsca']['overridden'] ?? null) === false, 'blank override falls back to the translated sentence', $r['tsca_status']);

$ghsNoteProp->setValue(null, null);

// ---------------------------------------------------------------------
echo "f. SDSReadinessService::tscaWarning\n";
$w = SDSReadinessService::tscaWarning(['sections' => [15 => ['tsca' => ['all_covered' => false, 'unverified' => ['999-99-9' => 'X'], 'not_listed' => [], 'no_cas_count' => 0, 'total' => 1]]]]);
check(is_string($w) && str_contains($w, '999-99-9'), 'warning from sds data', $w);
check(SDSReadinessService::tscaWarning([]) === null, 'no sections → null');
check(SDSReadinessService::tscaWarning(['sections' => [15 => ['tsca' => ['all_covered' => true]]]]) === null, 'covered → null');

// ---------------------------------------------------------------------
echo "g. translations\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    $a = $trFile['section15']['tsca_status'] ?? null;
    $b = $trFile['section15']['tsca_status_not_verified'] ?? null;
    check(is_string($a) && $a !== '', "{$lang} section15.tsca_status", $a);
    check(is_string($b) && $b !== '', "{$lang} section15.tsca_status_not_verified", $b);
    check($a !== $b, "{$lang} the two sentences differ");
}
$en = require $basePath . '/templates/translations/en.php';
check($en['section15']['tsca_status'] === 'All components of this product are listed on or exempt from the TSCA inventory.', 'en tsca_status wording (decision #29)', $en['section15']['tsca_status']);

// ---------------------------------------------------------------------
echo "h. #27 / #46\n";
$inact = TSCAService::rollUp([
    '108-88-3' => ['status' => TSCAService::STATUS_LISTED, 'name' => 'Toluene'],
    '64-17-5'  => ['status' => TSCAService::STATUS_INACTIVE, 'name' => 'Ethanol'],
]);
check($inact['all_covered'] === true && $inact['inactive'] === ['64-17-5' => 'Ethanol'] && $inact['listed_count'] === 2, '#46 inactive counts as listed, kept for the warning', $inact);
$w = TSCAService::warningText($inact);
check(is_string($w) && str_contains($w, 'INACTIVE') && str_contains($w, '64-17-5') && str_contains($w, 'All components'), '#46 inactive entry raises a warning; the sentence stays "all listed"', $w);

check(TSCAService::statusKey(['all_covered' => true, 'not_listed' => []]) === 'section15.tsca_status', 'statusKey covered');
check(TSCAService::statusKey(['all_covered' => false, 'not_listed' => ['1-1-1' => 'X']]) === 'section15.tsca_status_not_listed', 'statusKey not_listed (#46)');
check(TSCAService::statusKey(['all_covered' => false, 'not_listed' => [], 'unverified' => ['1-1-1' => 'X']]) === 'section15.tsca_status_not_verified', 'statusKey not verified');

check(TSCAService::incompleteReasons(['n_constituents' => 0]) === ['no_constituents'], '#27 reasons: no constituents');
check(TSCAService::incompleteReasons(['n_constituents' => 3, 'n_blank_cas' => 1, 'n_ts_no_cas' => 0, 'n_no_pct' => 2]) === ['blank_cas', 'no_percentage'], '#27 reasons: blank CAS + no percentage');
check(TSCAService::incompleteReasons(['n_constituents' => 2]) === [], '#27 reasons: complete');

$covered = TSCAService::rollUp(['108-88-3' => ['status' => 'listed', 'name' => 'Toluene']]);
$inc = TSCAService::applyIncomplete($covered, [['id' => 5, 'internal_code' => 'W-1', 'reasons' => ['no_constituents']]]);
check($inc['all_covered'] === false && ($inc['incomplete_raw_materials'][0]['internal_code'] ?? null) === 'W-1', '#27 a raw material without constituents makes TSCA not verified', $inc);
$w = TSCAService::warningText($inc);
check(is_string($w) && str_contains($w, 'W-1') && str_contains($w, 'no constituents'), '#27 TSCA warning names the raw material', $w);
check(TSCAService::statusKey($inc) === 'section15.tsca_status_not_verified', '#27 sentence is "not verified"');

$inc2 = TSCAService::applyIncomplete($covered, [['id' => 6, 'internal_code' => 'P-2', 'reasons' => ['no_percentage']]]);
check($inc2['all_covered'] === true, '#27 a missing percentage alone does not affect TSCA', $inc2);
check(TSCAService::warningText($inc2) === null, '#27 no TSCA warning for a missing percentage alone');

$cw = SDSReadinessService::incompleteCompositionWarning([['id' => 6, 'internal_code' => 'P-2', 'reasons' => ['no_percentage']]]);
check(is_string($cw) && str_contains($cw, 'P-2') && str_contains($cw, 'Publishing is not blocked'), '#27 composition warning (warning only)', $cw);
check(SDSReadinessService::incompleteCompositionWarning([]) === null, '#27 no composition warning when complete');

foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    $nl = $trFile['section15']['tsca_status_not_listed'] ?? null;
    check(is_string($nl) && $nl !== ''
        && $nl !== ($trFile['section15']['tsca_status'] ?? null)
        && $nl !== ($trFile['section15']['tsca_status_not_verified'] ?? null), "{$lang} section15.tsca_status_not_listed present and distinct", $nl);
}
check($en['section15']['tsca_status_not_listed'] === 'One or more components of this product are not listed on the TSCA inventory.', 'en tsca_status_not_listed wording (#46)');

// ---------------------------------------------------------------------
echo "\ng. CAS typed with invisible characters, odd dashes or full-width digits (2026-10-21)\n";
foreach ([
    "64-17-5\u{00A0}"      => 'trailing no-break space',
    "\u{00A0}64-17-5"      => 'leading no-break space',
    "64-17-5\u{200B}"      => 'zero-width space',
    "\u{FEFF}64-17-5"      => 'byte-order mark',
    "64\u{2013}17\u{2013}5" => 'en dashes',
    "64\u{2011}17\u{2011}5" => 'non-breaking hyphens',
    "\u{FF16}\u{FF14}-17-5" => 'full-width digits',
    '0064-17-5'            => 'zero padding',
    " 64-17-5\t"           => 'ASCII whitespace',
    "\u{200E}64-17-5\u{200F}" => 'left-to-right / right-to-left marks',
    "\u{202A}64-17-5\u{202C}" => 'directional embedding',
    "64-17\u{2061}-5"      => 'invisible function application',
    "64\u{034F}-17-5"      => 'combining grapheme joiner',
    "64-17-5\u{FE0F}"      => 'variation selector',
    "64-\u{00AD}17-5"      => 'soft hyphen',
    '64 - 17 - 5'          => 'spaces around the dashes',
] as $raw => $label) {
    $n = TSCAService::normaliseCas($raw);
    check($n === '64-17-5' && preg_match(TSCAService::CAS_PATTERN, $n) === 1, "normaliseCas: {$label} -> 64-17-5", $n);
}
check(TSCAService::normaliseCas("\xC3\x28") === "\xC3\x28", 'invalid UTF-8: returned as is, no error');

echo "\nh. Raw-material TSCA override (migration 066)\n";
// Composition rows as Formula::getExpandedComposition builds them.
$ts  = ['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade Secret', 'contributing_materials' => [['raw_material_id' => 7, 'internal_code' => 'TS-RESIN']]];
$tol = ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'contributing_materials' => [['raw_material_id' => 3, 'internal_code' => 'SOLV']]];
$unk = ['cas_number' => '999-99-9', 'chemical_name' => 'Unknown', 'contributing_materials' => [['raw_material_id' => 7, 'internal_code' => 'TS-RESIN']]];
$listed = ['108-88-3' => ['status' => 'listed', 'source' => 'inventory', 'name' => 'Toluene']];
$ovr7   = [7 => ['status' => 'listed', 'note' => 'Supplier SDS', 'internal_code' => 'TS-RESIN']];

$r = TSCAService::analyseResolved([$tol, $ts], $listed, [], null);
check(empty($r['all_covered']) && $r['no_cas_count'] === 1 && $r['no_cas_raw_materials'] === ['TS-RESIN'], 'trade-secret raw material without override: not verified, named', $r);
check(str_contains((string) TSCAService::warningText($r), 'TS-RESIN') && str_contains((string) TSCAService::warningText($r), 'raw-material TSCA override'), 'warning names the raw material and the fix');
$r = TSCAService::analyseResolved([$tol, $ts], $listed, $ovr7, null);
check(!empty($r['all_covered']) && $r['no_cas_count'] === 0 && $r['covered_without_cas'] === 1, 'raw-material override (listed) covers its trade-secret component', $r);
check(TSCAService::statusKey($r) === 'section15.tsca_status', '... Section 15: all components listed on or exempt from the TSCA inventory');
$r = TSCAService::analyseResolved([$ts], [], $ovr7, null);
check(!empty($r['all_covered']), 'product made only of an overridden trade-secret raw material: covered', $r);
$r = TSCAService::analyseResolved([$tol, $unk], $listed + ['999-99-9' => ['status' => 'not_verified', 'source' => 'none', 'name' => '']], $ovr7, null);
check(!empty($r['all_covered']) && $r['unverified'] === [], 'a CAS not on the inventory but only from an overridden raw material: covered', $r);
$both = ['cas_number' => '999-99-9', 'chemical_name' => 'Unknown', 'contributing_materials' => [['raw_material_id' => 7, 'internal_code' => 'TS-RESIN'], ['raw_material_id' => 3, 'internal_code' => 'SOLV']]];
$r = TSCAService::analyseResolved([$both], ['999-99-9' => ['status' => 'not_verified', 'source' => 'none', 'name' => '']], $ovr7, null);
check(empty($r['all_covered']) && isset($r['unverified']['999-99-9']), '... but not when it also comes from a raw material without an override', $r);
$r = TSCAService::analyseResolved([$unk], ['999-99-9' => ['status' => 'not_listed', 'source' => 'override', 'name' => 'Absent']], $ovr7, null);
check(TSCAService::statusKey($r) === 'section15.tsca_status_not_listed', 'a CAS-level "not listed" still wins over a raw-material override', $r);
$exempt7 = [7 => ['status' => 'exempt', 'note' => 'Polymer exemption', 'internal_code' => 'TS-RESIN']];
$res = TSCAService::applyRawMaterialOverrides(['999-99-9' => ['status' => 'not_verified']], ['999-99-9' => [7]], $exempt7);
check(($res['999-99-9']['status'] ?? '') === 'exempt' && ($res['999-99-9']['source'] ?? '') === 'raw_material_override', 'an exempt raw material makes its CAS exempt');
$incomplete = [['id' => 7, 'internal_code' => 'TS-RESIN', 'reasons' => [TSCAService::REASON_NO_CONSTITUENTS]], ['id' => 3, 'internal_code' => 'SOLV', 'reasons' => [TSCAService::REASON_NO_PERCENTAGE]]];
$r = TSCAService::analyseResolved([$tol], $listed, [], $incomplete);
check(empty($r['all_covered']), 'raw material with no constituents and no override: not verified');
$r = TSCAService::analyseResolved([$tol], $listed, $ovr7, $incomplete);
check(!empty($r['all_covered']), 'raw material with no constituents but a raw-material override: covered', $r);
check(array_column($r['incomplete_raw_materials'] ?? [], 'internal_code') === ['TS-RESIN', 'SOLV']
    && str_contains((string) \SDS\Services\SDSReadinessService::incompleteCompositionWarning($r['incomplete_raw_materials']), 'TS-RESIN'),
    '... but the composition warning still names it (its hazards are still left out)', $r);
check(TSCAService::warningText($r) === null, '... while the TSCA warning does not', TSCAService::warningText($r));
check(array_column($r['tsca_open_incomplete'] ?? [], 'internal_code') === ['SOLV'], '... TSCA keeps only the raw materials without an override');
$r = TSCAService::analyseResolved([$tol], $listed, [3 => ['status' => 'listed', 'note' => 'x', 'internal_code' => 'SOLV']], [['id' => 3, 'internal_code' => 'SOLV', 'reasons' => [TSCAService::REASON_NO_PERCENTAGE]]]);
check(($r['covered_without_cas'] ?? null) === 0, 'an overridden raw material missing only a percentage is not counted as a component covered without a CAS', $r);
$r = TSCAService::analyseResolved([], [], [7 => $ovr7[7]], [['id' => 7, 'internal_code' => 'TS-RESIN', 'reasons' => [TSCAService::REASON_NO_PERCENTAGE]]]);
check(empty($r['all_covered']), '... so it alone cannot make a product with no checked component "all covered"', $r);
check(str_contains(TSCAService::sqlBlankCas('rmc.cas_number'), 'CHAR(9)') && str_contains(TSCAService::sqlBlankCas('x'), 'CHAR(10)'), 'SQL blank-CAS test ignores tabs and line breaks');
check(str_contains(TSCAService::noCasReasonLabel('invalid_cas'), 'not a CAS number'), 'second list reason: constituent CAS is not a CAS number');
check(TSCAService::rollUp([], 0, [], 1)['all_covered'] === true, 'rollUp: a component covered without a CAS counts towards "all covered"');
check(array_keys(TSCAService::RM_OVERRIDE_OPTIONS) === ['auto', 'listed', 'exempt'], 'raw-material override values: auto, listed, exempt');

$mig = (string) file_get_contents($basePath . '/migrations/066_rm_tsca_override.sql');
check(str_contains($mig, "ADD COLUMN `tsca_status` ENUM(''auto'',''listed'',''exempt'') NOT NULL DEFAULT ''auto''")
    && str_contains($mig, "INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('066_rm_tsca_override');"), 'migration 066: guarded raw_materials.tsca_status');
$adm = (string) file_get_contents($basePath . '/src/Controllers/AdminController.php');
check(str_contains($adm, 'public function saveTscaRawMaterialOverride(): void') && str_contains($adm, "AuditService::log('tsca_rm_override'"), 'raw-material override save, audited apart from raw-material reviews');
check(str_contains($adm, '$rmIds  = TSCAService::rawMaterialIdsForCas($cas);'), 'a CAS override bumps raw materials whatever way the CAS was typed');
$app = (string) file_get_contents($basePath . '/src/Core/App.php');
check(str_contains($app, "\$router->post('/determinations/tsca-rm',      'AdminController@saveTscaRawMaterialOverride');"), 'route POST /determinations/tsca-rm');
$svc = (string) file_get_contents($basePath . '/src/Services/TSCAService.php');
check(str_contains($svc, '$overrides[self::normaliseCas((string) $r[\'cas_number\'])] = $r;') && str_contains($svc, '$inventory[self::normaliseCas((string) $r[\'cas_number\'])] = $r;'), 'resolve() keys overrides and inventory by the canonical CAS');
check(str_contains($svc, 'foreach (self::resolve(array_keys($byCas)) as $cas => $res) {'), 'TSCA Review list built on resolve() (cannot disagree with Section 15)');
check(str_contains($svc, "!preg_match(self::CAS_PATTERN, \$cas) || isset(\$rmOverrides"), 'TSCA Review CAS list skips values that are not a CAS (they go to the raw-material list)');
check(!str_contains($svc, "cas_number LIKE"), 'raw materials for a CAS are found in canonical form, not with LIKE');
$imp = (string) file_get_contents($basePath . '/src/Services/TSCAInventoryImporter.php');
check(str_contains($imp, 'TSCAService::rawMaterialIdsForCasMany($affected)') && !str_contains($imp, 'bumpByCasMany('), 'TSCA inventory import bumps raw materials whatever way the CAS was typed');
check(substr_count($adm, 'TSCAService::rawMaterialIdsForCas(') >= 4, 'TSCA inventory add / edit / remove and CAS override all use the canonical match');
check(str_contains($adm, "'/raw-materials/' . \$rmId . '/edit#tsca-override'"), 'raw-material override from the edit page returns to the raw material');
$rmForm = (string) file_get_contents($basePath . '/src/Views/raw-materials/form.php');
check(str_contains($rmForm, 'action="/determinations/tsca-rm"') && str_contains($rmForm, 'name="back_to" value="raw_material"'), 'raw-material edit page has the TSCA override card');

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
