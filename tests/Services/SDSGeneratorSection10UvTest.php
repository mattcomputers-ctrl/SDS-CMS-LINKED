#!/usr/bin/env php
<?php
/**
 * SDSGenerator::section10() UV "conditions to avoid" test (audit item #35 /
 * decision #19, DB-free)
 *
 *   - default ($isUv omitted / false): the plain conditions items only;
 *   - $isUv = true: base items + section10.cond_uv as the last item
 *     (audit #19 made the field an additive item list);
 *   - water-reactive (H260) + UV: water item + UV item;
 *   - an operator override replaces the whole field (no UV append);
 *   - the UV sentence is translated in es/fr/de.
 *
 * Run: php tests/Services/SDSGeneratorSection10UvTest.php
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

$hz = fn(array $codes): array => [
    'h_statements'   => array_map(fn($c) => ['code' => $c, 'text' => ''], $codes),
    'p_statements'   => [],
    'hazard_classes' => [],
];

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);
$m   = new ReflectionMethod($gen, 'section10');
$m->setAccessible(true);

// Audit #19 rewrote "Conditions to avoid" as an additive item list
// (section10.cond_*); the UV condition is the trailing cond_uv item.
$base  = 'Excessive heat, contact with strong oxidizing agents.';
$uv    = $t->get('section10.cond_uv');
$water = 'Excessive heat, ' . $t->get('section10.cond_ignition') . ', ' . $t->get('section10.cond_water') . ', contact with strong oxidizing agents'; // #34: water-reactive avoids ignition

echo "a. Default (not a UV product)\n";
$s = $m->invoke($gen, $hz(['H315', 'H319']), []);
check($s['conditions_avoid'] === $base, '$isUv omitted -> base sentence only', $s['conditions_avoid']);
$s = $m->invoke($gen, $hz(['H315', 'H319']), [], false);
check($s['conditions_avoid'] === $base, '$isUv false -> base sentence only');
check(!str_contains($s['conditions_avoid'], $uv), 'no UV sentence when not UV');

echo "b. UV product\n";
$s = $m->invoke($gen, $hz(['H315', 'H317', 'H319']), [], true);
check($s['conditions_avoid'] === rtrim($base, '.') . ', ' . $uv . '.', '$isUv true -> base items + UV item', $s['conditions_avoid']);
check(str_contains($uv, 'UV'), 'UV item mentions UV light');
check($s['reactivity'] === $t->get('section10.reactivity'), 'other fields untouched');

echo "c. Water-reactive + UV\n";
$s = $m->invoke($gen, $hz(['H260']), [], true);
check($s['conditions_avoid'] === $water . ', ' . $uv . '.', 'H260 + UV -> water item + UV item', $s['conditions_avoid']);

echo "d. Override replaces the whole field\n";
$s = $m->invoke($gen, $hz(['H315']), [10 => ['conditions_avoid' => 'Custom']], true);
check($s['conditions_avoid'] === 'Custom', 'override + UV -> override only (no append)', $s['conditions_avoid']);

echo "e. Translated UV sentence (es/fr/de)\n";
foreach (['es', 'fr', 'de'] as $lang) {
    $tl  = new \SDS\Services\TranslationService($lang);
    $gl  = new \SDS\Services\SDSGenerator($tl);
    $ml  = new ReflectionMethod($gl, 'section10');
    $ml->setAccessible(true);
    $uvL = $tl->get('section10.cond_uv');
    $sl  = $ml->invoke($gl, $hz(['H315']), [], true);
    check($uvL !== $uv && $uvL !== 'section10.cond_uv', "{$lang} UV item translated", $uvL);
    check(str_ends_with($sl['conditions_avoid'], ', ' . $uvL . '.'), "{$lang} items + UV item last", $sl['conditions_avoid']);
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    check(is_string($trFile['section10']['cond_uv'] ?? null) && $trFile['section10']['cond_uv'] !== '', "{$lang} section10.cond_uv present");
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
