#!/usr/bin/env php
<?php
/**
 * DB-free checks for the Q3 "one flammability source" follow-up fixes:
 *
 *   1. HazardEngine::applyFinishedGoodOverride(): an additive override that
 *      brings a Flammable Liquids class or H224-H227 replaces the
 *      flash-point-derived entry (no H225 + H227 on one sheet);
 *      dropLessSevereFlammableCodes() keeps one Flammable Liquids code.
 *   2. Per-CAS H-code maps: the flash-point MIXTURE code is never credited to
 *      the contributing solvents (ethanol keeps its own H225, never H227).
 *   3. parseDeterminationStructure(): a declared signal word that came only
 *      from an ignored Flammable Liquids entry does not survive.
 *   4. SDSGenerator::flammabilityWarnings(): override category vs printed
 *      formula flash point; raw materials with a flammable-liquid
 *      constituent but no flash point.
 *
 * Hazard results come from the engine's own methods (Reflection), i.e. the
 * real output shapes. Same App-reflection bootstrap as
 * HazardEngineFlammabilityTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/FlammableOverrideConsistencyTest.php
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

use SDS\Services\GHSHazardClass;
use SDS\Services\HazardEngine as HE;
use SDS\Services\TransportClassifier as TC;

$FL = GHSHazardClass::FLAMMABLE_LIQUIDS;

$m = static function (object $o, string $name): ReflectionMethod {
    $r = new ReflectionMethod($o, $name);
    $r->setAccessible(true);
    return $r;
};

$engine   = new HE();
$applyFp  = $m($engine, 'applyFlammableLiquidsFromFlashPoint');
$applyOv  = $m($engine, 'applyFinishedGoodOverride');
$dropFl   = $m($engine, 'dropLessSevereFlammableCodes');
$casMap   = $m($engine, 'buildCasHCodeMap');
$parse    = $m($engine, 'parseDeterminationStructure');
$record   = $m($engine, 'recordFlammableIngredient');
$flamP    = new ReflectionProperty($engine, 'flammability');            $flamP->setAccessible(true);
$ingP     = new ReflectionProperty($engine, 'flammableIngredients');    $ingP->setAccessible(true);
$ingCatP  = new ReflectionProperty($engine, 'flammableIngredientCats'); $ingCatP->setAccessible(true);
$traceP   = new ReflectionProperty($engine, 'trace');                   $traceP->setAccessible(true);

/**
 * Engine pipeline for one product: flash-point step, optional FG override,
 * then the same prune the classify() tail runs. Returns the real result shape.
 */
$run = static function (?float $fp, ?array $override = null, array $ingredients = [], array $ingredientCats = [], array $seedH = [])
    use ($engine, $applyFp, $applyOv, $dropFl, $casMap, $flamP, $ingP, $ingCatP, $traceP): array {
    $ingP->setValue($engine, $ingredients);
    $ingCatP->setValue($engine, $ingredientCats);
    $traceP->setValue($engine, []);
    $classes = [];
    $h = [];
    foreach ($seedH as $code) {
        $h[$code] = ['code' => $code, 'text' => ''];
    }
    $p = []; $pic = []; $sw = null; $haz = [];
    $in = ['flash_point_c' => $fp, 'flash_point_greater_than' => false, 'boiling_point_c' => 100.0, 'physical_state' => 'Liquid'];
    $applyFp->invokeArgs($engine, [$in, &$classes, &$h, &$p, &$pic, &$sw, &$haz]);
    if ($override !== null) {
        $applyOv->invokeArgs($engine, [$override, &$classes, &$h, &$p, &$pic, &$sw]);
    }
    $dropFl->invokeArgs($engine, [&$h]);
    return [
        'hazard_classes'        => $classes,
        'h_statements'          => array_values($h),
        'p_statements'          => array_values($p),
        'pictograms'            => array_keys($pic),
        'signal_word'           => $sw,
        'exposure_limits'       => [],
        'flammability'          => $flamP->getValue($engine),
        'flammable_ingredients' => $ingP->getValue($engine),
        'cas_h_codes'           => $casMap->invoke($engine, $classes),
        'steps'                 => array_column($traceP->getValue($engine), 'step'),
    ];
};
$codes = static fn (array $hr): array => array_column($hr['h_statements'], 'code');
$flCodes = static fn (array $hr): array => array_values(array_intersect(array_column($hr['h_statements'], 'code'), HE::FLAMMABLE_LIQUID_H_CODES));
$flRows = static fn (array $hr): array => array_values(array_filter($hr['hazard_classes'], static fn ($c) => ($c['canonical'] ?? null) === GHSHazardClass::FLAMMABLE_LIQUIDS));
$additive = static fn (array $hazards): array => ['mode' => 'additive', 'hazards' => $hazards];

// ---------------------------------------------------------------------
echo "1. FG override vs the flash-point-derived Flammable Liquids entry\n";
$base = $run(73.6);
check($flCodes($base) === ['H227'], 'formula fp 73.6 → derived Cat 4 (H227)', $flCodes($base));

$r = $run(73.6, $additive(['selected_hazards' => ['Flammable Liquids - Category 2'], 'h_statements' => ['H225']]));
check($flCodes($r) === ['H225'], 'additive Cat 2 / H225 over derived Cat 4 → H225 only (no H227)', $codes($r));
$rows = $flRows($r);
check(count($rows) === 1 && ($rows[0]['category_canonical'] ?? null) === 'Cat 2' && ($rows[0]['source'] ?? null) === 'fg_override', 'one Flammable Liquids row: the override Cat 2', $rows);
check($r['signal_word'] === 'Danger' && in_array('GHS02', $r['pictograms'], true), 'Cat 2 defaults: Danger + GHS02', [$r['signal_word'], $r['pictograms']]);
check(in_array('flash_point_flammability_replaced_by_override', $r['steps'], true), 'trace flash_point_flammability_replaced_by_override', $r['steps']);

$r = $run(73.6, $additive(['selected_hazards' => ['Flammable Liquids - Category 3'], 'h_statements' => ['H226']]));
check($flCodes($r) === ['H226'], 'additive Cat 3 / H226 → H226 only', $codes($r));

$r = $run(73.6, $additive(['h_statements' => ['H226']]));
check($flCodes($r) === ['H226'] && $flRows($r) === [], 'additive H226 code only → derived row and H227 dropped', [$codes($r), $flRows($r)]);

$r = $run(73.6, $additive(['selected_hazards' => ['Flammable Liquids - Category 2']]));
check($flCodes($r) === ['H225'] && $r['signal_word'] === 'Danger', 'additive class only (no H-codes) → its default H225 + Danger', [$codes($r), $r['signal_word']]);

$r = $run(10.0, $additive(['selected_hazards' => ['Flammable Liquids - Category 4'], 'h_statements' => ['H227']]));
check($flCodes($r) === ['H227'] && count($flRows($r)) === 1, 'additive Cat 4 over derived Cat 2 → the override wins (H227 only)', [$codes($r), $flRows($r)]);

$r = $run(73.6, $additive(['selected_hazards' => ['Skin Sensitization - Category 1'], 'h_statements' => ['H317']]));
check($flCodes($r) === ['H227'] && in_array('H317', $codes($r), true) && count($flRows($r)) === 1 && ($flRows($r)[0]['source'] ?? null) === 'flash_point',
    'additive override without a flammable class keeps the derived row and H227', [$codes($r), $flRows($r)]);

$r = $run(12.0, ['mode' => 'replace', 'hazards' => ['h_statements' => ['H319']]]);
check($flCodes($r) === [] && $flRows($r) === [] && ($r['flammability']['category'] ?? null) === 2, 'replace without a flammable class → no H22x; flammability block still Cat 2 (fp 12)', [$codes($r), $r['flammability']]);

$h = ['H225' => ['code' => 'H225', 'text' => ''], 'H227' => ['code' => 'H227', 'text' => ''], 'H319' => ['code' => 'H319', 'text' => '']];
$dropFl->invokeArgs($engine, [&$h]);
check(array_keys($h) === ['H225', 'H319'], 'dropLessSevereFlammableCodes: H225 + H227 → H225', array_keys($h));

// ---------------------------------------------------------------------
echo "2. Per-CAS maps: the mixture flash-point code is not credited to contributors\n";
// 10 % ethanol (own Cat 2) in a product whose flash point gives Cat 4.
$r = $run(90.8, null, ['64-17-5' => 10.0], ['64-17-5' => 2]);
check($flCodes($r) === ['H227'], 'Section 2 still H227 (product flash point)', $codes($r));
check(($r['cas_h_codes']['64-17-5'] ?? null) === ['H225'], 'engine cas_h_codes: ethanol keeps its own H225, never H227', $r['cas_h_codes']);
$tc = TC::casHCodeMap($r);
check(($tc['64-17-5'] ?? null) === ['H225'], 'TransportClassifier::casHCodeMap: ethanol H225 only', $tc);
$tcFinal = TC::casHCodeMap(['hazard_classes' => $r['hazard_classes']]);
check(!isset($tcFinal['64-17-5']), 'final hazard_classes alone credit nothing to the contributor', $tcFinal);
$r = $run(90.8, null, ['67-63-0' => 20.0], []);
check(!isset($r['cas_h_codes']['67-63-0']), 'contributor with no recorded own category → no flammability code', $r['cas_h_codes']);
// recordFlammableIngredient keeps the most severe own category, even below 1 %.
$ingP->setValue($engine, []);
$ingCatP->setValue($engine, []);
$record->invoke($engine, '108-88-3', 'Toluene', 0.5, 'Cat 2', 'test');
$record->invoke($engine, '108-88-3', 'Toluene', 0.5, 'Cat 3', 'test');
$record->invoke($engine, 'TRADE_SECRET', 'Trade Secret', 50.0, 'Cat 1', 'test');
check($ingCatP->getValue($engine) === ['108-88-3' => 2] && $ingP->getValue($engine) === [], 'own category recorded (most severe), < 1 % not a contributor, trade secret never', [$ingCatP->getValue($engine), $ingP->getValue($engine)]);

// ---------------------------------------------------------------------
echo "3. CPD signal word after an ignored Flammable Liquids entry\n";
$ingP->setValue($engine, []);
$ingCatP->setValue($engine, []);
$out = $parse->invoke($engine, [
    'selected_hazards' => json_encode(['Flammable Liquids - Category 2', 'Reproductive Toxicity - Lactation']),
    'h_statements' => 'H225,H362', 'pictograms' => 'GHS02', 'signal_word' => 'Danger',
], '111-11-1', 'Solvent X', 50.0, 'CAS determination');
check(array_keys($out['h_statements']) === ['H362'] && $out['signal_word'] === null, 'FL Cat 2 + Lactation: only H362, no signal word (Danger came from the ignored FL entry)', [$out['h_statements'], $out['signal_word']]);
$out = $parse->invoke($engine, [
    'hazard_classes' => 'Flammable Liquids, Eye Irritation', 'h_statements' => 'H225,H319',
    'pictograms' => 'GHS02,GHS07', 'signal_word' => 'Danger',
], '67-63-0', 'Isopropanol', 50.0, 'CAS determination');
check(array_keys($out['h_statements']) === ['H319'] && $out['signal_word'] === 'Warning', 'free-text FL + Eye Irritation (H319): Warning, not Danger', [$out['h_statements'], $out['signal_word']]);
$out = $parse->invoke($engine, [
    'hazard_classes' => 'Flammable Liquids, Serious Eye Damage', 'h_statements' => 'H225,H318',
    'pictograms' => 'GHS02,GHS05', 'signal_word' => 'Danger',
], '67-63-0', 'Isopropanol', 50.0, 'CAS determination');
check($out['signal_word'] === 'Danger', 'free-text FL + Serious Eye Damage (H318): Danger kept', $out['signal_word']);

// ---------------------------------------------------------------------
echo "4. SDSGenerator::flammabilityWarnings()\n";
$gen  = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService('en'));
$warn = $m($gen, 'flammabilityWarnings');
$calc = static fn (?float $fp, array $composition = [], array $lines = []): array => [
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => false, 'boiling_point_c' => 100.0, 'physical_state' => 'Liquid', 'enriched_lines' => $lines],
    'composition'   => $composition,
];
$fg = ['physical_state' => 'Liquid'];
$has = static fn (array $w, string $needle): bool => array_filter($w, static fn ($x) => str_contains($x, $needle)) !== [];

$hr = $run(73.6, $additive(['selected_hazards' => ['Flammable Liquids - Category 2'], 'h_statements' => ['H225']]));
$w = $warn->invoke($gen, $hr, null, null, [], $calc(73.6), $fg);
check($has($w, 'Flammable Liquids Category 2') && $has($w, '73.6 °C') && $has($w, 'Category 4'), 'additive Cat 2 over fp 73.6 → warning names both', $w);
$w = $warn->invoke($gen, $hr, null, null, [9 => ['flash_point' => '15 °C']], $calc(73.6), $fg);
check(!$has($w, 'finished-good hazard override'), 'kept Section 9 Flash Point edit → no override warning', $w);

$hr = $run(12.0, ['mode' => 'replace', 'hazards' => ['h_statements' => ['H319']]]);
$w = $warn->invoke($gen, $hr, null, null, [], $calc(12.0), $fg);
check($has($w, 'no Flammable Liquids class') && $has($w, '12 °C'), 'replace without a flammable class at fp 12 → warning', $w);

$hr = $run(73.6);
$w = $warn->invoke($gen, $hr, null, null, [], $calc(73.6), $fg);
check($w === [], 'no override → no warning', $w);

$hr = $run(null, ['mode' => 'additive', 'hazards' => ['h_statements' => ['H226']]]);
$w = $warn->invoke($gen, $hr, null, null, [], $calc(null), $fg);
check(!$has($w, 'finished-good hazard override'), 'override with no formula flash point (Section 9 "Not determined") → no override warning', $w);

// Raw material with a flammable-liquid constituent but no flash point.
$comp = [[
    'cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol', 'concentration_pct' => 20.0,
    'contributing_materials' => [['raw_material_id' => 5, 'internal_code' => 'IPA-99', 'pct_in_rm' => 100.0, 'pct_in_formula' => 20.0, 'is_trade_secret' => false]],
]];
$linesBlank = [['raw_material_id' => 5, 'internal_code' => 'IPA-99', 'flash_point_c' => null], ['raw_material_id' => 6, 'internal_code' => 'WATER', 'flash_point_c' => '100']];
$hr = $run(100.0, null, ['67-63-0' => 20.0], ['67-63-0' => 2]);
$w = $warn->invoke($gen, $hr, null, null, [], $calc(100.0, $comp, $linesBlank), $fg);
check($has($w, 'IPA-99') && $has($w, 'no flash point'), 'IPA raw without a flash point → warning names IPA-99', $w);
check(!$has($w, 'WATER'), 'water raw (has a flash point) not named', $w);
$hr = $run(null, null, ['67-63-0' => 20.0], ['67-63-0' => 2]);
$w = $warn->invoke($gen, $hr, null, null, [], $calc(null, $comp, $linesBlank), $fg);
check($has($w, 'IPA-99'), 'no flash point anywhere (Q2 not flammable) → warning still raised', $w);
$linesSet = [['raw_material_id' => 5, 'internal_code' => 'IPA-99', 'flash_point_c' => '12'], ['raw_material_id' => 6, 'internal_code' => 'WATER', 'flash_point_c' => '100']];
$hr = $run(82.4, null, ['67-63-0' => 20.0], ['67-63-0' => 2]);
$w = $warn->invoke($gen, $hr, null, null, [], $calc(82.4, $comp, $linesSet), $fg);
check(!$has($w, 'IPA-99'), 'flash point entered → no warning', $w);
$hrSolid = $hr;
$hrSolid['flammability'] = HE::flammabilityFromProps(['flash_point_c' => null, 'physical_state' => 'Solid']);
$w = $warn->invoke($gen, $hrSolid, null, null, [], $calc(null, $comp, $linesBlank), ['physical_state' => 'Solid']);
check(!$has($w, 'IPA-99'), 'Solid product → no warning (never a flammable liquid)', $w);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
