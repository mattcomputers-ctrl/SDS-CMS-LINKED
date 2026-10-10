#!/usr/bin/env php
<?php
/**
 * SDSGenerator::section8() engineering-controls test (audit item #14, DB-free)
 *
 *   - base sentence alone for an unclassified liquid (and when $fg is omitted);
 *   - dust fragment for physical_state Powder only (#64) (case/whitespace tolerant);
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
check($eng([], ['physical_state' => 'Solid']) === $base, 'Solid -> base only (#64)');
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
check($eng(['H314', 'H226'], ['physical_state' => 'Powder']) === $base . ' ' . $dust . ' ' . $flam . ' ' . $corr, 'base + dust + flammable + corrosive in fixed order', $eng(['H314', 'H226'], ['physical_state' => 'Powder']));
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

echo "h. UV acrylate PPE fold (audit #35)\n";
$ppeFields = \SDS\Services\HazardEngine::PPE_FIELDS;
$uvOn  = $m->invoke($gen, $hz(['H315', 'H317', 'H319']), [], [], ['physical_state' => 'Liquid'], true);
$uvOff = $m->invoke($gen, $hz(['H315', 'H317', 'H319']), [], [], ['physical_state' => 'Liquid'], false);
$uvDef = $m->invoke($gen, $hz(['H315', 'H317', 'H319']), [], [], ['physical_state' => 'Liquid']);
foreach ($ppeFields as $f) {
    $uvSentence = $t->get('section8.uv_' . $f);
    check(str_ends_with((string) $uvOn[$f], ' ' . $uvSentence), "uvPack true: {$f} ends with the UV sentence", $uvOn[$f]);
    check(strlen((string) $uvOn[$f]) > strlen($uvSentence) + 1, "uvPack true: {$f} keeps the tier sentence in front");
    check(!str_contains((string) $uvOff[$f], $uvSentence), "uvPack false: {$f} has no UV sentence");
    check(!str_contains((string) $uvDef[$f], $uvSentence), "uvPack omitted: {$f} has no UV sentence");
    check($uvOff[$f] === $uvDef[$f], "uvPack false === omitted for {$f}");
}
check(!isset($uvOn['uv_acrylate_note']), 'Section 8 carries no uv_acrylate_note key');
$uvOv = $m->invoke($gen, $hz(['H315', 'H317', 'H319']), [], [8 => ['hand_protection' => 'Custom gloves']], ['physical_state' => 'Liquid'], true);
check($uvOv['hand_protection'] === 'Custom gloves', 'override replaces the whole hand_protection field (no UV append)', $uvOv['hand_protection']);
check(str_ends_with((string) $uvOv['eye_protection'], ' ' . $t->get('section8.uv_eye_protection')), 'eye_protection still ends with the UV sentence beside an override');
$uvBlank = $m->invoke($gen, $hz(['H315']), [], [8 => ['skin_protection' => '   ']], ['physical_state' => 'Liquid'], true);
check(str_ends_with((string) $uvBlank['skin_protection'], ' ' . $t->get('section8.uv_skin_protection')), 'blank override does not block the UV append');
foreach (['es', 'fr', 'de'] as $lang) {
    $tl = new \SDS\Services\TranslationService($lang);
    $gl = new \SDS\Services\SDSGenerator($tl);
    $ml = new ReflectionMethod($gl, 'section8');
    $ml->setAccessible(true);
    $sl = $ml->invoke($gl, $hz(['H315', 'H317']), [], [], ['physical_state' => 'Liquid'], true);
    check(str_ends_with((string) $sl['hand_protection'], ' ' . $tl->get('section8.uv_hand_protection')) && $tl->get('section8.uv_hand_protection') !== $t->get('section8.uv_hand_protection'), "{$lang} hand_protection ends with the translated UV sentence");
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
