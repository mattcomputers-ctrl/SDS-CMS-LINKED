#!/usr/bin/env php
<?php
/**
 * Engine v1.10 (2026-10-28): the Q3 P-code filter of a CAS determination
 * (or a raw material's trade-secret hazards) — DB-free.
 *
 * Q3 drops the P-codes an ignored Flammable Liquids entry brings unless
 * another triggered class carries them. Up to v1.9 the filter ran on every
 * determination over the whole Flammable Liquids P-code set, so a P280 ticked
 * by hand next to Acute Toxicity Oral 3 (no Flammable Liquids entry) never
 * printed. Now the cross-cutting codes (P280, P501, P303+P361+P353) are
 * filtered only when a Flammable Liquids entry was ignored (a Flammable
 * Liquids class, an unmapped free-text spelling such as "Flam. Liq. 2", or an
 * H224-H227 stored without one); the flammability-only codes (P210, P233,
 * P240-P243, P370+P378, P403+P235) on every determination, as before. Signal
 * words are as in v1.9.
 *
 * Run: php tests/Services/CpdFlammablePCodeFilterTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\HazardEngine;

// App statics without a DB connection (as CpdAutoSignalWordTest)
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp  = $ref->getProperty('basePath');
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
        echo '       actual: ' . json_encode($actual) . "\n";
    }
}

$engine = new HazardEngine();
$parse  = new ReflectionMethod($engine, 'parseDeterminationStructure');
$parse->setAccessible(true);
$trace  = new ReflectionProperty($engine, 'trace');
$trace->setAccessible(true);
$p = static function (array $det, float $conc = 50.0, string $source = 'CAS determination') use ($engine, $parse, $trace): array {
    $trace->setValue($engine, []);
    return array_keys($parse->invoke($engine, $det, '50-00-0', 'Test substance', $conc, $source)['p_statements']);
};
$det = static fn (array $hazards, string $pCodes, array $extra = []): array => $extra + [
    'selected_hazards' => json_encode($hazards), 'h_statements' => '', 'p_statements' => $pCodes, 'pictograms' => '', 'signal_word' => '',
];
$has  = static fn (array $codes, string ...$want): bool => array_diff($want, $codes) === [];
$none = static fn (array $codes, string ...$gone): bool => array_intersect($gone, $codes) === [];

$oral3  = 'Acute Toxicity Oral - Category 3';   // P264 P270 P301+P310 P321 P330 P405 P501
$inhal4 = 'Acute Toxicity Inhalation - Category 4';
$fl2    = 'Flammable Liquids - Category 2';
$flOnly = ['P210', 'P233', 'P240', 'P241', 'P242', 'P243', 'P370+P378', 'P403+P235'];

echo "a. No Flammable Liquids entry: a hand-ticked cross-cutting code prints\n";
$out = $p($det([$oral3], 'P264, P270, P280, P301+P310, P321, P330, P405, P501'));
check($has($out, 'P280', 'P264', 'P501'), 'P280 ticked by hand next to Acute Toxicity Oral 3 prints', $out);
$out = $p($det([$inhal4], 'P261, P271, P280, P304+P340, P312'));
check($has($out, 'P280'), 'P280 ticked by hand next to Acute Toxicity Inhalation 4 prints', $out);
$out = $p($det([$oral3], 'P264, P281'));
check($has($out, 'P280') && $none($out, 'P281'), 'a legacy P281 prints as P280', $out);
$out = $p($det(['Eye Irritation - Category 2A'], 'P264, P280, P305+P351+P338, P501'), 20.0);
check($has($out, 'P501'), 'P501 ticked by hand next to Eye Irritation 2A prints', $out);
$out = $p($det([$oral3], 'P264, P303+P361+P353'));
check($has($out, 'P303+P361+P353'), 'P303+P361+P353 ticked by hand next to Acute Toxicity Oral 3 prints', $out);
$out = $p($det([$oral3], 'P264, P280', ['signal_word_override' => '']), 100.0, 'manual (trade secret)');
check($has($out, 'P280'), 'trade-secret hazards: a hand-added P280 prints', $out);
$out = $p($det([], 'P264, P280, P210', ['hazard_classes' => 'Acute Toxicity (Oral)']));
check($has($out, 'P280') && $none($out, 'P210'), 'legacy free-text classes: P280 prints, P210 still not', $out);

echo "\nb. No Flammable Liquids entry: flammability-only codes still never ride along (Q3)\n";
$out = $p($det([$oral3], 'P210, P233, P240, P241, P242, P243, P264, P370+P378, P403+P235'));
check($none($out, ...$flOnly) && $has($out, 'P264'), 'P210, P233, P240-P243, P370+P378, P403+P235 dropped next to Acute Toxicity Oral 3', $out);
check(in_array('cpd_code_dropped_untriggered_class', array_column($trace->getValue($engine), 'step'), true), '... and the trace says why');
$out = $p($det(['Flammable Solids - Category 1'], 'P210, P240, P241, P280, P370+P378'));
check($has($out, 'P210', 'P240', 'P241', 'P280', 'P370+P378'), 'kept when a triggered class carries them (Flammable Solids 1)', $out);

echo "\nc. An ignored Flammable Liquids entry: none of its P-codes ride along\n";
$all = 'P210, P233, P240, P241, P242, P243, P264, P270, P280, P301+P310, P303+P361+P353, P321, P330, P370+P378, P403+P235, P405, P501';
$out = $p($det([$fl2, $oral3], $all));
check($none($out, ...$flOnly), 'Flammable Liquids 2 + Acute Toxicity Oral 3: the flammability-only codes are dropped', $out);
check($none($out, 'P280', 'P303+P361+P353'), '... and P280 / P303+P361+P353, which only the ignored entry carries', $out);
check($has($out, 'P264', 'P301+P310', 'P501'), '... Acute Toxicity Oral 3\'s own codes, P501 included, print', $out);
$out = $p($det([$fl2, $oral3], 'P210, P264, P281'));
check($none($out, 'P280', 'P281', 'P210'), 'a legacy P281 is dropped like P280 (as after a resave)', $out);
$out = $p($det([$fl2, 'Skin Irritation - Category 2'], 'P210, P264, P280'));
check($has($out, 'P264', 'P280') && $none($out, 'P210'), 'Flammable Liquids 2 + Skin Irritation 2: P280 kept (Skin Irritation carries it)', $out);
$out = $p($det([$fl2, 'Skin Corrosion - Category 1'], 'P210, P260, P280, P303+P361+P353'));
check($has($out, 'P280', 'P303+P361+P353') && $none($out, 'P210'), 'Flammable Liquids 2 + Skin Corrosion 1: P280 / P303+P361+P353 kept', $out);
$out = $p($det([], 'P210, P264, P280', ['hazard_classes' => 'Flammable Liquids, Acute Toxicity (Oral)']));
check($none($out, 'P210', 'P280') && $has($out, 'P264'), 'legacy free-text Flammable Liquids + Acute Toxicity: P210 / P280 dropped', $out);
$out = $p($det([$fl2], 'P210, P280, P501'));
check($out === [], 'Flammable Liquids only: nothing (as before)', $out);

echo "\nd. Other signs of an ignored Flammable Liquids entry count too\n";
$flAll  = 'P210, P233, P240, P241, P242, P243, P280, P303+P361+P353, P370+P378, P403+P235, P501';
$out = $p($det(['Acute Toxicity Oral - Category 4'], $flAll . ', P264, P270, P301+P312, P330', ['h_statements' => 'H225, H302']), 30.0);
check($none($out, 'P280', 'P303+P361+P353', ...$flOnly) && $has($out, 'P264', 'P501'), 'H225 left without a Flammable Liquids key (old form, federal prefill): its P280 / P303+P361+P353 dropped', $out);
$out = $p($det(['Acute Toxicity Inhalation - Category 4'], $flAll . ', P261, P271, P304+P340, P312', ['h_statements' => 'h226, H332']), 30.0);
check($none($out, 'P280', 'P303+P361+P353'), '... the same with H226 (any case)', $out);
$out = $p($det([$oral3], 'P264, P280', ['h_statements' => 'H301']));
check($has($out, 'P280'), 'no H224-H227: a hand-ticked P280 still prints', $out);
$ingP = new ReflectionProperty($engine, 'flammableIngredients');
$ingP->setAccessible(true);
foreach (['Flam. Liq. 3', 'Flammable Liquids - Category 2', 'Flammable Liquid 2'] as $spelling) {
    $ingP->setValue($engine, []);
    // No H224-H227 stored, so only recognising the class can drop P280.
    $out = $p($det([], 'P210, P261, P280, P303+P361+P353, P304+P340, P403+P235, P501', ['hazard_classes' => "{$spelling}, Acute Toxicity (Inhalation)", 'h_statements' => 'H332']), 30.0);
    check($none($out, 'P210', 'P280', 'P303+P361+P353', 'P403+P235') && $has($out, 'P261', 'P304+P340', 'P501'),
        "legacy free-text \"{$spelling}\" (the alias table does not map it) is ignored like Flammable Liquids", $out);
    check(isset($ingP->getValue($engine)['50-00-0']), '... and recorded as a flammable ingredient', $ingP->getValue($engine));
}
$trace->setValue($engine, []);
$whole = $parse->invoke($engine, $det([], $flAll, ['hazard_classes' => 'Flam. Liq. 2', 'h_statements' => 'H225', 'pictograms' => 'GHS02', 'signal_word' => 'Danger']), '50-00-0', 'Test substance', 50.0, 'CAS determination');
check($whole['hazard_classes'] === [] && $whole['p_statements'] === [] && $whole['signal_word'] === null, 'legacy free-text "Flam. Liq. 2" alone: contributes nothing, not even its Danger', $whole);
$sw = static fn (string $classes, string $codes): ?string => $parse->invoke($engine, $det([], '', ['hazard_classes' => $classes, 'h_statements' => $codes, 'signal_word' => 'Danger']), '50-00-0', 'Test substance', 30.0, 'CAS determination')['signal_word'];
// The signal word of these rows is as in v1.9 (only the P-codes changed).
check($sw('Flam. Liq. 2, Eye Irritation', 'H225, H319') === 'Danger', 'legacy "Flam. Liq. 2, Eye Irritation", stored Danger: kept, as in v1.9');
check($sw('Flam. Liq. 2, Eye Irritation, Acute Tox. 3', 'H225, H301, H319') === 'Danger', '... with "Acute Tox. 3": kept, as in v1.9');
check($sw('Flammable Liquids, Eye Irritation', 'H225, H319') === 'Warning', 'usual spelling "Flammable Liquids, Eye Irritation": worked out, Warning, as in v1.9');
check($sw('Flammable Liquids, Eye Irritation, Skin Sensitization', 'H225, H319') === 'Warning', '... next to a class that cannot be worked out (Skin Sensitization, no H317): Warning, as in v1.9');
$isFl = new ReflectionMethod(HazardEngine::class, 'isFlammableLiquidsClass');
$isFl->setAccessible(true);
check($isFl->invoke(null, null, 'Flam. Liq. 2') && $isFl->invoke(null, 'flammable_liquids', 'x') && !$isFl->invoke(null, 'flammable_solids', 'Flammable Solids') && !$isFl->invoke(null, null, 'Acute Tox. 4'),
    'isFlammableLiquidsClass(): the canonical class or an unmapped "Flam. Liq." name');

echo "\ne. The code sets\n";
$only = new ReflectionMethod(HazardEngine::class, 'flammabilityOnlyPCodes');
$only->setAccessible(true);
$set = $only->invoke(null);
sort($set);
$want = $flOnly;
sort($want);
check($set === $want, 'flammability-only P-codes: P210, P233, P240-P243, P370+P378, P403+P235', $set);
$fl = new ReflectionMethod(HazardEngine::class, 'flammableLiquidPCodes');
$fl->setAccessible(true);
$cross = array_values(array_diff($fl->invoke(null), $set));
sort($cross);
check($cross === ['P280', 'P303+P361+P353', 'P501'], 'cross-cutting: P280, P303+P361+P353, P501', $cross);
check(preg_match('/^v(\d+\.\d+)/', HazardEngine::ENGINE_VERSION, $vm) === 1 && version_compare($vm[1], '1.10', '>=') && strlen(HazardEngine::ENGINE_VERSION) <= 30,
    'engine version v1.10 (cross-cutting P-codes) or later', HazardEngine::ENGINE_VERSION);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
