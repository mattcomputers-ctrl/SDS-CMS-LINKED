#!/usr/bin/env php
<?php
/**
 * Engine v1.9 (2026-10-28): a CAS determination on Auto contributes the
 * signal word of its triggered hazard classes, worked out on every classify —
 * never a stored word that could be stale — DB-free.
 *
 * The owner's worry: "if a hazard for a CAS changes, the signal word will be
 * stuck if it is supposed to change". parseDeterminationStructure() now
 * derives the word from the classes that trigger at the component's
 * concentration unless the determination records a manual override
 * (signal_word_override). Determinations saved before that key existed are
 * Auto (none was a manual override).
 *
 * Run: php tests/Services/CpdAutoSignalWordTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\GHSHazardClass as G;
use SDS\Services\HazardEngine;

// App statics without a DB connection (as HazardEngineAcuteToxicityTest)
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
$cutoff = new ReflectionMethod($engine, 'getCutoff');
$cutoff->setAccessible(true);
$trace  = new ReflectionProperty($engine, 'trace');
$trace->setAccessible(true);
$sw = static function (array $det, float $conc = 50.0) use ($engine, $parse, $trace): ?string {
    $trace->setValue($engine, []);
    return $parse->invoke($engine, $det, '50-00-0', 'Test substance', $conc, 'CAS determination')['signal_word'];
};
$det = static fn (array $hazards, string $stored, array $extra = []): array => $extra + [
    'selected_hazards' => json_encode($hazards), 'h_statements' => '', 'p_statements' => '', 'pictograms' => '', 'signal_word' => $stored,
];
$corr = 'Skin Corrosion - Category 1';        // Danger
$irr  = 'Skin Irritation - Category 2';       // Warning
$sens = 'Skin Sensitization - Category 1A';   // Warning, 0.1 % cut-off

echo "a. Auto: the word follows the hazards\n";
check($sw($det([$corr], 'Warning')) === 'Danger', 'a stale stored Warning on a Danger hazard (saved before 2026-10-28): Danger');
check(in_array('cpd_signal_word_derived', array_column($trace->getValue($engine), 'step'), true), '... and the trace says the word was worked out');
check($sw($det([$irr], 'Danger', ['signal_word_override' => ''])) === 'Warning', 'a hazard changed from Danger to Warning level: Warning (saved on Auto)');
check($sw($det([$irr], 'Warning', ['signal_word_override' => ''])) === 'Warning', 'matching stored word: unchanged');
check($sw($det([$irr, 'Obsolete Hazard - Category 1'], 'Danger')) === 'Danger', 'a selected key no longer known: the stored word is the floor');

echo "\nThe word covers every H-code that still prints\n";
$hc = static fn (string $key, string $codes, string $stored): array => $det([$key], $stored, ['h_statements' => $codes]);
check($sw($hc('Carcinogenicity - Category 2', 'H350, H351', 'Warning'), 1.0) === 'Danger', 'H350 left from Category 1B next to Category 2: Danger (H350 needs it)');
check($sw($hc('Eye Irritation - Category 2A', 'H318, H319', 'Warning'), 20.0) === 'Danger', 'H318 left next to Eye Irritation 2A: Danger');
check($sw($hc($irr, 'H300+H310, H315', 'Warning'), 20.0) === 'Danger', 'a combined code (H300+H310): Danger');
check($sw($hc('Carcinogenicity - Category 2', 'H350i, H351', 'Warning'), 1.0) === 'Danger', 'a sub-code (H350i) reads as its base code');
check($sw($hc($irr, 'H315, H999', 'Danger'), 20.0) === 'Warning' && $sw($hc($irr, 'H315, EUH066', 'Danger'), 20.0) === 'Warning', 'a code GHS data does not know (H999, EUH066): no floor, so a stored word cannot stick');
check($sw($hc($irr, 'H200, H315', 'Warning'), 20.0) === 'Danger' && $sw($hc($irr, 'H231, H315', 'Danger'), 20.0) === 'Warning', 'H200 needs Danger; H231 none');
$fl = $det(['Flammable Liquids - Category 2', 'Eye Irritation - Category 2A', 'STOT Single Exposure - Category 3 (Narcotic)'], 'Danger', ['h_statements' => 'H225, H231, H319, H336']);
check($sw($fl, 20.0) === 'Warning', 'Q3 kept: an ignored Flammable Liquids Cat 2 entry does not bring Danger back through an extra code (H231)');
check($sw($hc('Flammable Solids - Category 2', 'H228', 'Danger'), 50.0) === 'Warning', 'a code shared by a Danger and a Warning category (H228): Warning, its lowest');
check($sw($hc('Reproductive Toxicity - Lactation', 'H362', 'Danger'), 5.0) === null, 'Lactation only (H362): still no signal word');
check($sw($hc($irr, 'H315', 'Danger'), 20.0) === 'Warning', 'Skin Corrosion changed to Skin Irritation on today\'s form (H314 dropped): Warning');

echo "\nLegacy free-text classes (no selected hazards) keep their stored word\n";
$ft = static fn (string $classes, string $codes, string $stored): array => $det([], $stored, ['hazard_classes' => $classes, 'h_statements' => $codes]);
check($sw($ft('Carcinogenicity, Skin Sensitization', 'H350i, H317', 'Danger')) === 'Danger', 'H350i (not workable out) + a Warning class: Danger kept');
check($sw($ft('Acute Toxicity, Skin Irritation', 'H331, H315', 'Danger')) === 'Danger', 'an acute-toxicity class filtered out + a Warning class: Danger kept');
check($sw($ft('Flammable Solids', 'H228', 'Warning')) === 'Warning', 'a shared H-code (H228, Cat 1 Danger / Cat 2 Warning): Warning kept');
check($sw($ft('Skin Corrosion', 'H314', 'Danger')) === 'Danger', 'a plain one: as stored');

echo "\nb. Only the classes that trigger count\n";
$cSens = (float) $cutoff->invoke($engine, G::SKIN_SENSITIZATION, 'Cat 1A');
$cCorr = (float) $cutoff->invoke($engine, G::SKIN_CORROSION_IRRITATION, 'Cat 1');
if ($cSens < $cCorr) {
    $mid = ($cSens + $cCorr) / 2;
    check($sw($det([$corr, $sens], 'Danger'), $mid) === 'Warning', "at {$mid} % only the sensitizer triggers: Warning, not the stored Danger");
    check($sw($det([$corr, $sens], 'Danger'), $cCorr + 1) === 'Danger', '... above both cut-offs: Danger');
} else {
    check(false, 'test precondition: sensitizer cut-off below corrosion cut-off', [$cSens, $cCorr]);
}
check($sw($det([$corr], 'Danger'), 0.0001) === null, 'nothing triggers: no signal word (as before)');

echo "\nc. A manual override still applies\n";
check($sw($det([$corr], 'Warning', ['signal_word_override' => 'Warning'])) === 'Warning', 'override Warning on a Danger hazard: Warning');
check($sw($det([$irr], 'Danger', ['signal_word_override' => 'Danger'])) === 'Danger', 'override Danger on a Warning hazard: Danger');

echo "\nd. The form and the save agree\n";
check(\SDS\Services\GHSHelper::signalWordOverride(['signal_word' => 'Warning', 'selected_hazards' => json_encode([$corr])]) === '',
    'a determination saved before 2026-10-28 opens on Auto (none was a manual override)');
check(HazardEngine::ENGINE_VERSION === 'v1.9-cpd-auto-signal-word' && strlen(HazardEngine::ENGINE_VERSION) <= 30, 'engine version v1.9-cpd-auto-signal-word');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
