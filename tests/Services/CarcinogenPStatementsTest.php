#!/usr/bin/env php
<?php
/**
 * Carcinogen P-statement Test (audit item #5)
 *
 * Locks in that SDSGenerator::applyCarcinogenFindings() takes its
 * precautionary statements from the GHSHazardData class defaults
 * (GHS Rev. 7 Annex 3 / OSHA 2024 HazCom Appendix C) rather than a
 * hardcoded list, and that:
 *
 *   - the withdrawn P281 is never emitted;
 *   - the emitted set is exactly P201, P202, P280, P308+P313, P405, P501;
 *   - codes already present in p_statements are not duplicated;
 *   - Cat 1A (H350 / Danger) and Cat 2 (H351 / Warning) both resolve
 *     to the matching GHSHazardData key.
 *
 * DB-free: SDSGenerator's constructor only builds a TranslationService,
 * and applyCarcinogenFindings() touches no database.
 *
 * Run:
 *   php tests/Services/CarcinogenPStatementsTest.php
 *
 * Exit code:
 *   0 = all passed
 *   1 = any failure
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

// Bootstrap App static properties without a DB connection (same pattern
// as tests/smoke_pdf.php).
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);

use SDS\Services\GHSStatements;
use SDS\Services\SDSGenerator;

$passed = 0;
$failed = 0;

function pass(string $msg): void {
    global $passed;
    $passed++;
    echo "  PASS  {$msg}\n";
}

function fail(string $msg, string $detail = ''): void {
    global $failed;
    $failed++;
    echo "  FAIL  {$msg}\n";
    if ($detail !== '') {
        echo "        {$detail}\n";
    }
}

function freshHazard(): array {
    return [
        'hazard_classes' => [],
        'h_statements'   => [],
        'p_statements'   => [],
        'pictograms'     => [],
        'signal_word'    => null,
        'hazardous_cas'  => [],
        'exposure_limits' => [],
    ];
}

function carcResult(string $classification): array {
    return [
        'findings' => [[
            'cas_number'        => '71-43-2',
            'chemical_name'     => 'Benzene',
            'concentration_pct' => 1.0,
            'agencies'          => [['agency' => 'IARC', 'classification' => $classification]],
        ]],
        'has_carcinogens' => true,
    ];
}

function invokeCarcinogenFindings(array &$hazard, array $carc): void {
    $gen  = new SDSGenerator();
    $refl = new \ReflectionClass($gen);
    $m    = $refl->getMethod('applyCarcinogenFindings');
    $m->setAccessible(true);
    $m->invokeArgs($gen, [&$hazard, $carc]);
}

$expectedCodes = ['P201', 'P202', 'P280', 'P308+P313', 'P405', 'P501'];

echo "=== Carcinogen P-statement Test (audit #5) ===\n\n";

// ── Case 1: IARC Group 1 → Cat 1A, H350, Danger ─────────────────────
echo "Case 1: IARC Group 1 (Cat 1A)\n";
$h1 = freshHazard();
invokeCarcinogenFindings($h1, carcResult('Group 1'));
$codes1 = array_column($h1['p_statements'], 'code');

$codes1 === $expectedCodes
    ? pass('p_statements codes are exactly P201, P202, P280, P308+P313, P405, P501')
    : fail('unexpected p_statements codes', 'got: ' . json_encode($codes1));

!in_array('P281', $codes1, true)
    ? pass('withdrawn P281 is not emitted')
    : fail('P281 emitted');

$p280 = null;
foreach ($h1['p_statements'] as $s) {
    if (($s['code'] ?? '') === 'P280') { $p280 = $s; break; }
}
($p280 !== null && $p280['text'] === GHSStatements::pText('P280') && $p280['text'] !== '')
    ? pass('P280 text resolved from GHSStatements::pText')
    : fail('P280 text mismatch', 'got: ' . json_encode($p280));

(($h1['h_statements'][0]['code'] ?? null) === 'H350')
    ? pass('H350 added')
    : fail('H350 missing', 'got: ' . json_encode($h1['h_statements']));

($h1['signal_word'] === 'Danger')
    ? pass('signal word Danger')
    : fail('signal word not Danger', 'got: ' . json_encode($h1['signal_word']));

(($h1['hazard_classes'][0]['category'] ?? null) === 'Cat 1A')
    ? pass('hazard class category Cat 1A')
    : fail('hazard class category wrong', 'got: ' . json_encode($h1['hazard_classes']));

// ── Case 2: second pass on mutated result → no duplicates ───────────
echo "\nCase 2: re-apply same findings on already-mutated result\n";
invokeCarcinogenFindings($h1, carcResult('Group 1'));
$codes2 = array_column($h1['p_statements'], 'code');
$codes2 === $expectedCodes
    ? pass('second pass adds nothing (CAS already classified)')
    : fail('second pass changed p_statements', 'got: ' . json_encode($codes2));

// ── Case 3: pre-seeded P280 → de-duplicated ─────────────────────────
echo "\nCase 3: P280 pre-seeded by engine\n";
$h3 = freshHazard();
$h3['p_statements'][] = ['code' => 'P280', 'text' => 'x'];
invokeCarcinogenFindings($h3, carcResult('Group 1'));
$codes3 = array_column($h3['p_statements'], 'code');
$p280Count = count(array_keys($codes3, 'P280', true));
$p280Count === 1
    ? pass('P280 appears exactly once')
    : fail('P280 duplicated', 'got: ' . json_encode($codes3));

(count($codes3) === 6 && empty(array_diff($expectedCodes, $codes3)))
    ? pass('all six class-default codes present, no extras')
    : fail('code set wrong with pre-seeded P280', 'got: ' . json_encode($codes3));

($codes3[0] === 'P280' && $h3['p_statements'][0]['text'] === 'x')
    ? pass('pre-existing P280 entry left untouched')
    : fail('pre-existing P280 entry altered', 'got: ' . json_encode($h3['p_statements'][0]));

// ── Case 4: IARC Group 2B → Cat 2, H351, Warning ────────────────────
echo "\nCase 4: IARC Group 2B (Cat 2)\n";
$h4 = freshHazard();
invokeCarcinogenFindings($h4, carcResult('Group 2B'));
$codes4 = array_column($h4['p_statements'], 'code');

$codes4 === $expectedCodes
    ? pass('p_statements codes are exactly P201, P202, P280, P308+P313, P405, P501')
    : fail('unexpected p_statements codes', 'got: ' . json_encode($codes4));

!in_array('P281', $codes4, true)
    ? pass('withdrawn P281 is not emitted')
    : fail('P281 emitted');

(($h4['h_statements'][0]['code'] ?? null) === 'H351')
    ? pass('H351 added')
    : fail('H351 missing', 'got: ' . json_encode($h4['h_statements']));

($h4['signal_word'] === 'Warning')
    ? pass('signal word Warning')
    : fail('signal word not Warning', 'got: ' . json_encode($h4['signal_word']));

(($h4['hazard_classes'][0]['category'] ?? null) === 'Cat 2')
    ? pass('hazard class category Cat 2')
    : fail('hazard class category wrong', 'got: ' . json_encode($h4['hazard_classes']));

// ── Case 5: IARC Group 2A → Cat 1B, H350, Danger ────────────────────
echo "\nCase 5: IARC Group 2A (Cat 1B)\n";
$h5 = freshHazard();
invokeCarcinogenFindings($h5, carcResult('Group 2A'));
$codes5 = array_column($h5['p_statements'], 'code');
$codes5 === $expectedCodes
    ? pass('p_statements codes are exactly P201, P202, P280, P308+P313, P405, P501')
    : fail('unexpected p_statements codes', 'got: ' . json_encode($codes5));
(($h5['hazard_classes'][0]['category'] ?? null) === 'Cat 1B')
    ? pass('hazard class category Cat 1B')
    : fail('hazard class category wrong', 'got: ' . json_encode($h5['hazard_classes']));

// ── Summary ─────────────────────────────────────────────────────────
echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
