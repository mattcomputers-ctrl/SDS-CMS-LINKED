#!/usr/bin/env php
<?php
/**
 * HazardEngine::applyATECalculation() ate_results unit test (audit #20, DB-free)
 *
 * Drives the ATE summation directly through Reflection (the engine has no
 * constructor and applyATECalculation() touches no DB — only GHSHazardData /
 * HazardClassAliases constants) and checks that the per-route summation
 * result is carried onto $ateResults for every outcome:
 *   - classified          : class entry stamped, ate_results carries ate_mix
 *   - not_classified      : ATEmix above every upper bound, no class entry
 *   - dominated           : a more-severe per-component entry already exists
 *   - already_classified  : per-component entry at the same category (the
 *                           gap decision #20 closes: no class entry, but the
 *                           ATEmix value must still reach Section 11)
 *   - inhalation_dust route + unit
 *   - empty buffer -> []
 *
 * Run:
 *   php tests/Services/HazardEngineAteResultsTest.php
 *
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

$near = static fn($a, $b, float $eps = 1e-6): bool => is_numeric($a) && abs((float) $a - $b) < $eps;

$engine = new \SDS\Services\HazardEngine();
$buf = new ReflectionProperty($engine, 'ateBuffer');  $buf->setAccessible(true);
$res = new ReflectionProperty($engine, 'ateResults'); $res->setAccessible(true);
$run = new ReflectionMethod($engine, 'applyATECalculation'); $run->setAccessible(true);

$call = function (array $buffer, array $classes = []) use ($engine, $buf, $res, $run): array {
    $buf->setValue($engine, $buffer);
    $res->setValue($engine, []);
    $h = []; $p = []; $pic = []; $sw = null; $haz = [];
    $args = [&$classes, &$h, &$p, &$pic, &$sw, &$haz];
    $run->invokeArgs($engine, $args);
    return ['classes' => $classes, 'ate_results' => $res->getValue($engine)];
};
$c = fn(string $cas, float $conc, string $cat, ?float $ate = null) => [
    'cas' => $cas, 'name' => $cas, 'conc' => $conc, 'category' => $cat,
    'ate' => $ate, 'ate_source' => $ate === null ? 'unknown' : 'vendor', 'source' => 'hazard_classification',
];

$ORAL = \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL;
$INH  = \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_INHALATION;

// ---------------------------------------------------------------------
echo "1. classified: 0.05 % Cat 1 oral (default ATE 0.5) -> ATEmix 1000 -> Cat 4\n";
$r = $call(['oral' => [$c('A', 0.05, 'Cat 1')]]);
$o = $r['ate_results']['oral'] ?? null;
check($o !== null, 'ate_results[oral] present', $r['ate_results']);
check($near($o['ate_mix'] ?? null, 1000.0), 'ate_mix 1000', $o['ate_mix'] ?? null);
check(($o['category'] ?? null) === 'Cat 4', 'category Cat 4', $o['category'] ?? null);
check(($o['outcome'] ?? null) === 'classified', 'outcome classified', $o['outcome'] ?? null);
check(($o['unit'] ?? null) === 'mg/kg', 'unit mg/kg', $o['unit'] ?? null);
check(($o['canonical'] ?? null) === $ORAL && ($o['route'] ?? null) === 'oral' && ($o['contributor_count'] ?? null) === 1, 'canonical/route/contributor_count', $o);
check(count($r['classes']) === 1 && $near($r['classes'][0]['ate_mix'] ?? null, 1000.0) && ($r['classes'][0]['route'] ?? null) === 'oral'
    && ($r['classes'][0]['source'] ?? null) === 'ate_mixture' && ($r['classes'][0]['category_canonical'] ?? null) === 'Cat 4', 'one ate_mixture class entry carrying ate_mix/route', $r['classes']);

// ---------------------------------------------------------------------
echo "2. not_classified: two 0.05 % Cat 1 contributors with vendor ATE 10 -> ATEmix 10000\n";
$r = $call(['oral' => [$c('C', 0.05, 'Cat 1', 10.0), $c('D', 0.05, 'Cat 1', 10.0)]]);
$o = $r['ate_results']['oral'] ?? null;
check($o !== null && $near($o['ate_mix'] ?? null, 10000.0), 'ate_mix 10000', $o['ate_mix'] ?? null);
check(is_array($o) && array_key_exists('category', $o) && $o['category'] === null, 'category null', $o);
check(($o['outcome'] ?? null) === 'not_classified', 'outcome not_classified', $o['outcome'] ?? null);
check(($o['contributor_count'] ?? null) === 2, 'contributor_count 2', $o['contributor_count'] ?? null);
check($r['classes'] === [], 'no class entry', $r['classes']);

// ---------------------------------------------------------------------
echo "2b. not_classified: the former GHS Cat 5 band (2000-5000 mg/kg) is not adopted by 29 CFR 1910.1200 App. A.1\n";
$r = $call(['oral' => [$c('E', 0.02, 'Cat 1')]]);   // default ATE 0.5 -> ATEmix 2500
$o = $r['ate_results']['oral'] ?? null;
check($o !== null && $near($o['ate_mix'] ?? null, 2500.0), 'ate_mix 2500', $o['ate_mix'] ?? null);
check(is_array($o) && array_key_exists('category', $o) && $o['category'] === null, 'category null (no Cat 5)', $o);
check(($o['outcome'] ?? null) === 'not_classified', 'outcome not_classified', $o['outcome'] ?? null);
check($r['classes'] === [], 'no class entry, no H303', $r['classes']);
$r = $call(['dermal' => [$c('F', 0.08, 'Cat 1', 2.0)]]);   // ATEmix 2500 dermal
$o = $r['ate_results']['dermal'] ?? null;
check($o !== null && $near($o['ate_mix'] ?? null, 2500.0) && $o['category'] === null && ($o['outcome'] ?? null) === 'not_classified' && $r['classes'] === [], 'dermal ATEmix 2500 not classified, no H313', $o);
$r = $call(['oral' => [$c('G', 25.0, 'Cat 4')]]);   // default ATE 500 -> ATEmix exactly 2000 -> Cat 4
check((($r['ate_results']['oral']['category'] ?? null) === 'Cat 4') && count($r['classes']) === 1, 'ATEmix 2000 boundary still Cat 4', $r['ate_results']['oral'] ?? null);
$r = $call(['oral' => [$c('H', 24.9, 'Cat 4')]]);   // ATEmix 2008 -> not classified
check((($r['ate_results']['oral']['outcome'] ?? null) === 'not_classified') && $r['classes'] === [], 'ATEmix 2008 not classified', $r['ate_results']['oral'] ?? null);

// ---------------------------------------------------------------------
echo "3. dominated: pre-seeded Cat 1 oral + 0.2 % Cat 1 -> ATEmix 250 -> Cat 3\n";
$seedCat1 = [['canonical' => $ORAL, 'category_canonical' => 'Cat 1', 'class' => 'Acute Toxicity (Oral)', 'category' => 'Category 1']];
$r = $call(['oral' => [$c('A', 0.2, 'Cat 1')]], $seedCat1);
$o = $r['ate_results']['oral'] ?? null;
check($o !== null && $near($o['ate_mix'] ?? null, 250.0), 'ate_mix 250', $o['ate_mix'] ?? null);
check(($o['category'] ?? null) === 'Cat 3', 'category Cat 3', $o['category'] ?? null);
check(($o['outcome'] ?? null) === 'dominated', 'outcome dominated', $o['outcome'] ?? null);
check(count($r['classes']) === 1, 'classes count stays 1', $r['classes']);

// ---------------------------------------------------------------------
echo "4. already_classified: pre-seeded Cat 4 oral + 30 % Cat 4 (default 500) -> ATEmix 1666.67 -> Cat 4\n";
$seedCat4 = [['canonical' => $ORAL, 'category_canonical' => 'Cat 4', 'class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4']];
$r = $call(['oral' => [$c('B', 30.0, 'Cat 4')]], $seedCat4);
$o = $r['ate_results']['oral'] ?? null;
check($o !== null && $near($o['ate_mix'] ?? null, 1666.6666667, 1e-3), 'ate_mix 1666.67', $o['ate_mix'] ?? null);
check(($o['category'] ?? null) === 'Cat 4', 'category Cat 4', $o['category'] ?? null);
check(($o['outcome'] ?? null) === 'already_classified', 'outcome already_classified', $o['outcome'] ?? null);
check(count($r['classes']) === 1 && !isset($r['classes'][0]['ate_mix']), 'classes count stays 1, no ate_mix on the per-component entry', $r['classes']);

// ---------------------------------------------------------------------
echo "5. inhalation_dust: 10 % Cat 1 (default 0.005) -> ATEmix 0.05 -> Cat 1\n";
$r = $call(['inhalation_dust' => [$c('E', 10.0, 'Cat 1')]]);
$o = $r['ate_results']['inhalation_dust'] ?? null;
check($o !== null && $near($o['ate_mix'] ?? null, 0.05), 'ate_mix 0.05', $o['ate_mix'] ?? null);
check(($o['category'] ?? null) === 'Cat 1', 'category Cat 1', $o['category'] ?? null);
check(($o['route'] ?? null) === 'inhalation_dust' && ($o['canonical'] ?? null) === $INH, 'route inhalation_dust / canonical inhalation', $o);
check(($o['unit'] ?? null) === 'mg/L/4h', 'unit mg/L/4h', $o['unit'] ?? null);
check(($o['outcome'] ?? null) === 'classified' && count($r['classes']) === 1 && ($r['classes'][0]['route'] ?? null) === 'inhalation_dust', 'classified with dust route on the class entry', $r['classes']);

// ---------------------------------------------------------------------
echo "6. empty buffer\n";
$r = $call([]);
check($r['ate_results'] === [], 'ate_results === []', $r['ate_results']);
check($r['classes'] === [], 'no classes', $r['classes']);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
