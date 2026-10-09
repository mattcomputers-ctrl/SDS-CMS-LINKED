#!/usr/bin/env php
<?php
/**
 * SDSGenerator::section8() engineering-controls test (audit item #14, DB-free)
 *
 *   - base sentence alone for an unclassified liquid (and when $fg is omitted);
 *   - dust fragment for physical_state Solid/Powder (case/whitespace tolerant);
 *   - flammable fragment for H224/H225/H226 only (H227, H228 do not trigger);
 *   - eyewash/shower fragment for H314 or H318, printed once when both;
 *   - fixed order base + dust + flammable + corrosive, single-space joined;
 *   - a per-FG override replaces the whole field;
 *   - every new key exists in all four language files.
 *
 * Run: php tests/Services/SDSGeneratorSection8EngineeringTest.php
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
    if ($ok) { echo "  ok   {$label}\n"; return; }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) { echo '       actual: ' . var_export($actual, true) . "\n"; }
}

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);
$m   = new ReflectionMethod($gen, 'section8');
$m->setAccessible(true);

$tr = fn(string $key): string => $t->get('section8.' . $key);
$hz = fn(array $codes): array => [
    'h_statements'    => array_map(fn($c) => ['code' => $c, 'text' => ''], $codes),
    'p_statements'    => [],
    'exposure_limits' => [],
];
$eng = fn(array $codes, array $fg = [], array $ov = []): string => $m->invoke($gen, $hz($codes), [], $ov, $fg)['engineering'];

$base = $tr('engineering');
$dust = $tr('engineering_dust');
$flam = $tr('engineering_flammable');
$corr = $tr('engineering_corrosive');

echo "a. Base sentence only\n";
check($eng([], ['physical_state' => 'Liquid']) === $base, 'unclassified liquid -> base', $eng([], ['physical_state' => 'Liquid']));
check($eng([], ['physical_state' => '']) === $base, 'blank state -> base');
check($eng([], ['physical_state' => 'Paste']) === $base, 'paste -> base');
check($eng([], ['physical_state' => 'Gel']) === $base, 'gel -> base');
check($m->invoke($gen, $hz(['H315', 'H319', 'H411']), [], [])['engineering'] === $base, '$fg omitted (smoke fixture codes) -> base');
check($eng(['H315', 'H317', 'H319', 'H411'], ['physical_state' => 'Liquid']) === $base, 'UV sensitiser ink (H315/H317/H319/H411) -> base');

echo "b. Dust fragment\n";
check($eng([], ['physical_state' => 'Powder']) === $base . ' ' . $dust, 'Powder -> base + dust', $eng([], ['physical_state' => 'Powder']));
check($eng([], ['physical_state' => 'Solid']) === $base . ' ' . $dust, 'Solid -> base + dust');
check($eng([], ['physical_state' => ' powder ']) === $base . ' ' . $dust, 'case/whitespace tolerant');

echo "c. Flammable fragment\n";
foreach (['H224', 'H225', 'H226'] as $c) {
    check($eng([$c], ['physical_state' => 'Liquid']) === $base . ' ' . $flam, "{$c} -> base + flammable");
}
check($eng(['H227'], ['physical_state' => 'Liquid']) === $base, 'H227 does not trigger');
check($eng(['H228'], ['physical_state' => 'Liquid']) === $base, 'H228 does not trigger');
check($eng(['H225', 'H226']) === $base . ' ' . $flam, 'two flammable codes -> one fragment');

echo "d. Corrosive / eye damage fragment\n";
check($eng(['H314']) === $base . ' ' . $corr, 'H314 -> base + corrosive');
check($eng(['H318']) === $base . ' ' . $corr, 'H318 -> base + corrosive');
check($eng(['H314', 'H318']) === $base . ' ' . $corr, 'H314 + H318 -> one fragment');
check($eng(['H315', 'H319']) === $base, 'H315/H319 irritants do not trigger');

echo "e. Order and combination\n";
check($eng(['H314', 'H226'], ['physical_state' => 'Solid']) === $base . ' ' . $dust . ' ' . $flam . ' ' . $corr, 'base + dust + flammable + corrosive in fixed order', $eng(['H314', 'H226'], ['physical_state' => 'Solid']));
check($eng(['H225', 'H319', 'H336'], ['physical_state' => 'Liquid']) === $base . ' ' . $flam, 'solvent ink (H225/H319/H336) -> base + flammable');

echo "f. Override wins whole-field\n";
check($eng(['H225', 'H314'], ['physical_state' => 'Powder'], [8 => ['engineering' => 'Custom controls']]) === 'Custom controls', 'override replaces everything');

echo "g. Translation completeness (en/es/fr/de)\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach (['engineering', 'engineering_dust', 'engineering_flammable', 'engineering_corrosive'] as $k) {
        $v = $trFile['section8'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section8.{$k}", $v);
    }
    check(str_contains($trFile['section8']['engineering_corrosive'], '1910.151(c)'), "{$lang} corrosive cites 29 CFR 1910.151(c)");
    check(str_contains($trFile['section8']['engineering_flammable'], 'NFPA 77'), "{$lang} flammable cites NFPA 77");
    foreach (['ANSI', 'LEL', 'NFPA'] as $abbr) {
        check(array_key_exists($abbr, $trFile['section16']['abbreviation_table'] ?? []), "{$lang} abbreviation {$abbr}");
    }
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
