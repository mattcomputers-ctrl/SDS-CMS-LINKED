#!/usr/bin/env php
<?php
/**
 * Engine v1.11 (2026-10-28): the signal word of a legacy free-text CAS
 * determination (or trade-secret hazards) with an ignored Flammable Liquids
 * class — DB-free.
 *
 * Q3 re-derives such a row's word from its triggered classes, because the
 * stored word may be the ignored Flammable Liquids entry's (Danger for Cat 2).
 * A free-text class the H-codes cannot work out ("Acute Tox. 3") lost the
 * stored word as soon as another class could be worked out: next to "Eye
 * Irritation" (H319) the row printed Warning. Now such a class keeps the
 * stored word up to the word its name surely needs at its own cut-off
 * (HazardEngine::readFreeTextClass()), and a route-less "Acute Toxicity" up
 * to the word its declared codes of any route need. The word only ever rises,
 * never above the stored word; everything else is as in v1.10.
 *
 * Run: php tests/Services/CpdFreeTextSignalFloorTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\GHSHazardClass as G;
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
$run = static function (string $classes, string $hCodes, string $stored, float $conc = 30.0, string $source = 'CAS determination') use ($engine, $parse, $trace): array {
    $trace->setValue($engine, []);
    return $parse->invoke($engine, [
        'selected_hazards' => '[]', 'hazard_classes' => $classes, 'h_statements' => $hCodes,
        'p_statements' => '', 'pictograms' => '', 'signal_word' => $stored,
    ], '50-00-0', 'Test substance', $conc, $source);
};
$sw = static fn (string $classes, string $hCodes, string $stored = 'Danger', float $conc = 30.0): ?string => $run($classes, $hCodes, $stored, $conc)['signal_word'];

echo "a. A class that cannot be worked out keeps the stored Danger it needs\n";
check($sw('Flammable Liquids, Eye Irritation, Acute Tox. 3', 'H225, H301, H319') === 'Danger', 'Flammable Liquids + Eye Irritation (H319) + "Acute Tox. 3", stored Danger: Danger (was Warning)');
$steps = array_column($trace->getValue($engine), 'step');
check(!in_array('cpd_signal_word_derived', $steps, true), '... the stored word stands, so no derived-word trace step', $steps);
check($sw('Flammable Liquids Cat 2, Eye Irritation Cat 2A, Acute Tox. 3 (Inhalation)', 'H225, H319, H331') === 'Danger', '... written as the old form\'s placeholder ("Eye Irritation Cat 2A") with "Acute Tox. 3 (Inhalation)"');
check($sw('Flammable Liquids, Eye Irritation, Carc. 1B', 'H225, H319, H350') === 'Danger', '"Carc. 1B": Danger');
check($sw('Flammable Liquids, Skin Irritation, Skin Corr. 1B', 'H225, H315') === 'Danger', '"Skin Corr. 1B" next to Skin Irritation (H315): Danger');
check($sw('Flammable Liquids, Eye Irritation, Carcinogenicity (Category 1B)', 'H225, H319') === 'Danger', '"Carcinogenicity (Category 1B)" (bracketed category): Danger');
check($sw('Flammable Liquids, Skin Irritation, Serious Eye Damage', 'H225, H315') === 'Danger', '"Serious Eye Damage" without an eye code (only Category 1) next to Skin Irritation (H315): Danger');
check($run('Flammable Liquids, Eye Irritation, Acute Tox. 3', 'H225, H301, H319', 'Danger', 100.0, 'manual (trade secret)')['signal_word'] === 'Danger', 'trade-secret hazards: the same');
check($sw('Flammable Liquids, Acute Toxicity, Eye Irritation, Carcinogenicity', 'H225, H302, H312, H331, H319, H351') === 'Danger', 'route-less "Acute Toxicity" with H331 next to H302: Danger (was Warning)');
check($sw('Flammable Liquids, Acute Tox.', 'H225, H302, H311') === 'Danger', '"Acute Tox." with H311 next to H302: Danger (was Warning)');
check($sw('Flammable Liquids, Carc. 1B, Skin Sensitization', 'H225, H350, H317', 'Danger', 0.5) === 'Danger', '"Carc. 1B" at 0.5 % (under the 1 % default, over its own 0.1 %) next to Skin Sensitization (H317): Danger (was Warning)');

echo "\nb. ... and nothing more: no Danger a class does not surely need\n";
foreach ([
    'Skin Sensitization'                  => 'no H317',
    'Eye Irrit. 2'                        => 'CLP short form, Category 2',
    'STOT SE 3'                           => 'CLP short form, Category 3',
    'Aquatic Chronic 2'                   => 'no signal word',
    'Gases Under Pressure Liquefied gas'  => 'class and category',
    'Press. Gas (Liq.)'                   => 'CLP short form',
    'Skin Irrit.'                         => 'no H315',
    'Skin Irritation - Category 2'        => 'a GHS key, no H315',
    'Carcinogenicity'                     => 'it may be Category 2',
    'Carcinogenicity (Category 2)'        => 'bracketed category',
    'Acute Tox. 4 Inhalation'             => 'category and route',
    'Acute Toxicity (Inhalation - Vapour)' => 'a route in brackets',
    'Aerosol 2'                           => 'Category 2',
    'Lactation'                           => 'no signal word',
    'Toxic'                               => 'no class can be read from it',
    'Category 2'                          => 'a category split off by the comma',
    'Liquides inflammables (Catégorie 2)' => 'a translated name with a bracketed category: not read',
    'Serious Eye Damage (H319)'           => 'a code in brackets: not read',
    'Serious Eye Damage (not classified)' => 'a "not" in brackets: not read',
] as $class => $why) {
    check($sw("Flammable Liquids, Eye Irritation, {$class}", 'H225, H319') === 'Warning', "... + \"{$class}\" ({$why}), stored Danger: Warning, as before");
}
check($sw('Flammable Liquids, Acute Toxicity, Eye Irritation', 'H225, H312, H332, H319') === 'Warning', 'route-less "Acute Toxicity" with H312 / H332 (Category 4): Warning, as before');
check($sw('Flammable Liquids, Skin Irritation, Asp. Tox. 1', 'H225, H304, H315', 'Danger', 5.0) === 'Warning', '"Asp. Tox. 1" at 5 % (under its own 10 %): Warning, as before');
check($sw('Flammable Liquids, Skin Irritation, Asp. Tox. 1', 'H225, H304, H315', 'Danger', 15.0) === 'Danger', '... at 15 %: Danger');
check($sw('Flammable Liquids, Skin Corr. 1B, Skin Sensitization', 'H225, H314, H317', 'Danger', 0.5) === 'Warning', '"Skin Corr. 1B" at 0.5 % (under its own 1 %): Warning, as before');
check($sw('Flammable Liquids, Reproductive Toxicity, Acute Tox. 5', 'H225, H362', 'Danger', 0.5) === null, '"Acute Tox. 5" at 0.5 % (a category the cut-off table lacks: the highest, 1 %): none, as before');
check($sw('Flammable Liquids, Muta. 1B, Skin Sensitization', 'H225, H340, H317', 'Danger', 0.5) === 'Danger', '"Muta. 1B" at 0.5 % (Category 1 cut-off, 0.1 %): Danger (was Warning)');
check($sw('Flammable Liquids, Reproductive Toxicity, Lact., Serious Eye Damage', 'H225, H362, H318', 'Danger', 0.5) === null, '"Lact." under the default, Serious Eye Damage under its cut-off, at 0.5 %: none, as before');

echo "\nc. The stored word is the ceiling, and nothing is lowered\n";
check($sw('Flammable Liquids, Eye Irritation, Acute Tox. 3', 'H225, H301, H319', 'Warning') === 'Warning', 'stored Warning next to "Acute Tox. 3": Warning, as before');
check($sw('Flammable Liquids, Acute Tox.', 'H225, H302, H311', 'Warning') === 'Warning', 'stored Warning, route-less acute with H311: Warning, as before');
check($sw('Flammable Liquids, Serious Eye Damage, Acute Tox. 4', 'H225, H318', 'Warning') === 'Danger', 'a class worked out above the stored word (H318): Danger, as before');
check($sw('Flammable Liquids, Acute Toxicity, Carcinogenic', 'H225, H312, H350') === 'Danger', 'route-less acute with only H312 next to an unreadable class: the stored Danger stands, as before');
check($sw('Flammable Liquids, Skin Sensitization', 'H225') === 'Danger', 'nothing worked out ("Skin Sensitization" without H317): the stored Danger stands, as before');
check($sw('Flammable Liquids, Acute Tox. 3', 'H225, H301', '') === null, 'nothing stored: none, as before');

echo "\nd. Other rows are unchanged\n";
check($sw('Eye Irritation, Acute Tox. 3', 'H301, H319', 'Warning') === 'Warning', 'no Flammable Liquids class: "Eye Irritation, Acute Tox. 3", stored Warning: Warning');
check($sw('Flam. Liq. 2, Eye Irritation', 'H225, H319') === 'Danger', 'unmapped spelling "Flam. Liq. 2" + Eye Irritation, stored Danger: kept, as in v1.10');
check($sw('Flam. Liq. 2, Eye Irritation, Acute Tox. 3', 'H225, H301, H319', 'Warning') === 'Warning', '... stored Warning: Warning, as in v1.10');

echo "\ne. readFreeTextClass()\n";
$read = new ReflectionMethod(HazardEngine::class, 'readFreeTextClass');
$read->setAccessible(true);
foreach ([
    ['Acute Tox. 3', null, 'Danger'],
    ['Acute Tox. 4', null, 'Warning'],
    ['Acute Tox. 4 *', null, 'Warning'],
    ['Acute Tox. 3 (Inhalation)', null, 'Danger'],
    ['Acute Toxicity (Oral) Category 2', G::ACUTE_TOXICITY_ORAL, 'Danger'],
    ['Acute Toxicity', G::ACUTE_TOXICITY_ORAL, 'Warning'],
    ['Eye Irrit. 2', null, 'Warning'],
    ['Eye Dam. 1', null, 'Danger'],
    ['Eye Irritation - Category 2A', null, 'Warning'],
    ['Eye Irritation', G::EYE_DAMAGE_IRRITATION, 'Warning'],
    ['Serious Eye Damage', G::EYE_DAMAGE_IRRITATION, 'Danger'],
    ['Serious Eye Damage/Eye Irritation', G::EYE_DAMAGE_IRRITATION, 'Warning'],
    ['Skin Irrit.', G::SKIN_CORROSION_IRRITATION, 'Warning'],
    ['Skin Corrosion', G::SKIN_CORROSION_IRRITATION, 'Danger'],
    ['Skin Corr. 1B', null, 'Danger'],
    ['Skin Corrosion/Irritation', G::SKIN_CORROSION_IRRITATION, 'Warning'],
    ['Skin Sensitization', G::SKIN_SENSITIZATION, 'Warning'],
    ['Respiratory Sensitization', G::RESPIRATORY_SENSITIZATION, 'Danger'],
    ['STOT SE 3', null, 'Warning'],
    ['STOT SE 1', null, 'Danger'],
    ['Carcinogenicity', G::CARCINOGENICITY, 'Warning'],
    ['Carcinogenicity (Category 2)', null, 'Warning'],
    ['Carcinogenicity (Category 1B)', null, 'Danger'],
    ['Carc. 2', null, 'Warning'],
    ['Repr. 1B', null, 'Danger'],
    ['Reproductive Toxicity', G::REPRODUCTIVE_TOXICITY, null],
    ['Aquatic Chronic 2', null, null],
    ['Aquatic Acute 1', G::AQUATIC_ACUTE, 'Warning'],
    ['Flam. Sol. 2', null, 'Warning'],
    ['Aerosol 2', null, 'Warning'],
    ['Explosives - Division 1.4', null, 'Warning'],
    ['Explosives Division 1.4', null, 'Warning'],
    ['Self-Reactive Substances Type E & F', null, 'Warning'],
    ['Self-Reactive - Type E/F', null, 'Warning'],
    ['Gases Under Pressure Liquefied gas', null, 'Warning'],
    ['Press. Gas (Liq.)', null, 'Warning'],
    ['Serious Eye Damage (rabbit)', null, 'Warning'],
    ['Reproductive Toxicity - Lactation', null, null],
    ['Lactation', null, null],
    ['Lact.', null, null],
    ['Effects on or via lactation', null, null],
] as [$class, $canonical, $want]) {
    $got = $read->invoke(null, $class, $canonical);
    check($got !== null && $got['floor'] === $want, sprintf('"%s" → %s', $class, $want ?? 'none'), $got);
}
foreach (['Toxic', 'Category 2', 'Cat. 2A', '2', 'Oral (Category 4)', 'Specific Target Organ Toxicity - Single Exposure', 'Skin Sensitizer Cat 1', '',
          'Aerosol 3', 'Flam. Liq. 2', 'Flammable Liquids - Category 2', 'Liquides inflammables (Catégorie 2)',
          'Serious Eye Damage (H319)', 'Serious Eye Damage (not classified)', 'Skin Corrosion (Category 1, H314)'] as $class) {
    check($read->invoke(null, $class, null) === null, sprintf('"%s" → no class read', $class));
}
$got = $read->invoke(null, 'Carc. 1B', null);
check(($got['canonical'] ?? null) === G::CARCINOGENICITY && ($got['category'] ?? null) === 'Cat 1B', '"Carc. 1B" reads as Carcinogenicity Cat 1B (its cut-off)', $got);
$cut = new ReflectionMethod($engine, 'freeTextCutoff');
$cut->setAccessible(true);
foreach ([[G::CARCINOGENICITY, 'Cat 1B', 0.1], [G::GERM_CELL_MUTAGENICITY, 'Cat 1B', 0.1], [G::ACUTE_TOXICITY_ORAL, 'Cat 5', 1.0],
          [G::EYE_DAMAGE_IRRITATION, 'Cat 2B', 10.0], [G::SKIN_SENSITIZATION, '', 0.1], [G::GASES_UNDER_PRESSURE, '', 1.0]] as [$c, $k, $want]) {
    $got = $cut->invoke($engine, $c, $k);
    check(abs($got - $want) < 1e-9, sprintf('freeTextCutoff(%s, "%s") = %s %%', $c, $k, $want), $got);
}
$routeless = new ReflectionMethod(HazardEngine::class, 'isRoutelessAcute');
$routeless->setAccessible(true);
check($routeless->invoke(null, 'Acute Toxicity') && $routeless->invoke(null, 'Acute Tox. 3') && $routeless->invoke(null, 'acute toxicity category 4'),
    'isRoutelessAcute(): "Acute Toxicity" / "Acute Tox." without a route');
check(!$routeless->invoke(null, 'Acute Toxicity (Oral)') && !$routeless->invoke(null, 'Acute Tox. Oral') && !$routeless->invoke(null, 'Acute Toxicity Category 3 (Oral)')
    && !$routeless->invoke(null, 'Acute Toxicity (Inhalation - Vapour)'),
    '... not with a route, in brackets or after the category');

echo "\nf. Version\n";
check(HazardEngine::ENGINE_VERSION === 'v1.11-free-text-signal-floor' && strlen(HazardEngine::ENGINE_VERSION) <= 30, 'engine version v1.11-free-text-signal-floor', HazardEngine::ENGINE_VERSION);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
