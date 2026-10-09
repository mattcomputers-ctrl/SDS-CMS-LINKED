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
 *     sentence; an operator Section 15 TSCA Status override wins and marks
 *     the roll-up overridden (no warning); a blank override falls back.
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

$r = $s15->invoke($gen, $hz0, $sara, $p65, $hap, ['composition' => []], [15 => ['tsca_status' => 'Vendor confirms TSCA.']]);
check($r['tsca_status'] === 'Vendor confirms TSCA.', 'operator Section 15 override wins', $r['tsca_status']);
check(($r['tsca']['overridden'] ?? null) === true, 'override marks the roll-up overridden', $r['tsca'] ?? null);
check(SDSReadinessService::tscaWarning(['sections' => [15 => $r]]) === null, 'no warning when the operator owns the sentence');

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
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
