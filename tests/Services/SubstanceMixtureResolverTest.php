<?php
/**
 * DB-free checks for SDS content audit item #6 (Substance vs Mixture):
 *   - SubstanceMixtureResolver rules (default Mixture; FG column wins; auto =
 *     single raw-material line marked Substance; RM auto = Mixture).
 *   - SDSGenerator::section3() prints labels.substance / labels.mixture from
 *     the resolved key and keeps the machine key beside it.
 *   - Aliases inherit (createAliasVariant leaves Section 3 untouched).
 *   - labels.substance exists in all four languages and differs from labels.mixture.
 *   - Source guards: both models allow-list the column, Formula::getLines
 *     selects it, migration 054 adds it to both tables.
 * Run: docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SubstanceMixtureResolverTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath'); $bp->setAccessible(true); $bp->setValue(null, $basePath);
$cfg = $ref->getProperty('config');  $cfg->setAccessible(true); $cfg->setValue(null, ['paths' => []]);

$failures = 0; $checks = 0;
function check(bool $ok, string $label, $actual = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) { echo "  ok   {$label}\n"; return; }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) { echo '       actual: ' . var_export($actual, true) . "\n"; }
}

use SDS\Services\SubstanceMixtureResolver as R;

$rmLine = static fn (?string $setting, int $rmId = 7): array => [
    'id' => 1, 'formula_id' => 1, 'raw_material_id' => $rmId, 'finished_good_component_id' => null,
    'pct' => 100.0, 'sort_order' => 1, 'line_type' => 'raw_material', 'substance_mixture' => $setting,
];
$fgLine = ['id' => 2, 'formula_id' => 1, 'raw_material_id' => null, 'finished_good_component_id' => 9,
    'pct' => 100.0, 'sort_order' => 1, 'line_type' => 'finished_good', 'substance_mixture' => null];

echo "a. normalize / isValid\n";
check(R::normalize(null) === 'auto', 'null -> auto');
check(R::normalize('') === 'auto', 'blank -> auto');
check(R::normalize(' Substance ') === 'substance', 'trim + lowercase');
check(R::normalize('bogus') === 'auto', 'unknown -> auto');
check(R::normalize(['x']) === 'auto', 'array -> auto');
check(R::isValid(null) && R::isValid('') && R::isValid('mixture') && R::isValid('SUBSTANCE'), 'valid values');
check(!R::isValid('bogus') && !R::isValid(['x']), 'invalid values rejected');
check(R::VALUES === ['auto', 'substance', 'mixture'], 'VALUES matches the ENUM');

echo "b. Raw material (resale path)\n";
check(R::resolveForRawMaterial(null) === 'mixture', 'null -> Mixture');
check(R::resolveForRawMaterial('auto') === 'mixture', 'auto -> Mixture');
check(R::resolveForRawMaterial('mixture') === 'mixture', 'mixture -> Mixture');
check(R::resolveForRawMaterial('substance') === 'substance', 'substance -> Substance');

echo "c. Finished good\n";
check(R::resolveForFinishedGood('substance', [$rmLine('auto'), $rmLine('auto', 8)]) === 'substance', 'FG column substance wins over a 2-line mixture');
check(R::resolveForFinishedGood('mixture', [$rmLine('substance')]) === 'mixture', 'FG column mixture wins over a single Substance line');
check(R::resolveForFinishedGood('auto', [$rmLine('substance')]) === 'substance', 'auto + single RM line marked substance -> Substance');
check(R::resolveForFinishedGood(null, [$rmLine('substance')]) === 'substance', 'null column behaves as auto');
check(R::resolveForFinishedGood('auto', [$rmLine('auto')]) === 'mixture', 'auto + single RM line auto -> Mixture');
check(R::resolveForFinishedGood('auto', [$rmLine('mixture')]) === 'mixture', 'auto + single RM line mixture -> Mixture');
check(R::resolveForFinishedGood('auto', [$rmLine('substance'), $rmLine('substance', 8)]) === 'mixture', 'two lines -> Mixture even if both Substance');
check(R::resolveForFinishedGood('auto', []) === 'mixture', 'no lines -> Mixture');
check(R::resolveForFinishedGood('auto', [$fgLine]) === 'mixture', 'single finished-good component line -> Mixture');
check(R::resolveForFinishedGood('bogus', [$rmLine('substance')]) === 'substance', 'unknown FG value treated as auto');
check(R::resolveForFinishedGood('auto', [5 => $rmLine('substance')]) === 'substance', 'non-zero-indexed single line still resolves');

echo "d. section3() output\n";
$hz = ['hazardous_cas' => [], 'exposure_limits' => [], 'hazard_classes' => []];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $t   = new \SDS\Services\TranslationService($lang);
    $gen = new \SDS\Services\SDSGenerator($t);
    $s3  = new ReflectionMethod($gen, 'section3'); $s3->setAccessible(true);
    $sub = $s3->invoke($gen, [], $hz, [], 'substance');
    $mix = $s3->invoke($gen, [], $hz, [], 'mixture');
    $def = $s3->invoke($gen, [], $hz, []);
    check($sub['substance_or_mixture'] === $t->get('labels.substance') && $sub['substance_mixture'] === 'substance', "{$lang}: substance prints labels.substance", $sub);
    check($mix['substance_or_mixture'] === $t->get('labels.mixture') && $mix['substance_mixture'] === 'mixture', "{$lang}: mixture prints labels.mixture", $mix);
    check($def['substance_or_mixture'] === $t->get('labels.mixture') && $def['substance_mixture'] === 'mixture', "{$lang}: default is Mixture", $def);
    check($sub['substance_or_mixture'] !== $mix['substance_or_mixture'], "{$lang}: the two labels differ");
    if ($lang === 'en') {
        check($sub['substance_or_mixture'] === 'Substance' && $mix['substance_or_mixture'] === 'Mixture', 'en literal text', [$sub['substance_or_mixture'], $mix['substance_or_mixture']]);
        $labels = new ReflectionMethod($gen, 'getLabels'); $labels->setAccessible(true);
        $l = $labels->invoke($gen);
        check(($l['substance'] ?? '') === 'Substance' && ($l['mixture'] ?? '') === 'Mixture', 'meta.labels carries substance + mixture', array_intersect_key($l, ['substance' => 1, 'mixture' => 1]));
    }
}

echo "e. Aliases inherit\n";
$sds = ['meta' => ['product_code' => 'X', 'description' => 'd'], 'sections' => [1 => ['product_identifier' => 'X'], 3 => $sub]];
$alias = \SDS\Services\SDSGenerator::createAliasVariant($sds, 'ALIAS-1', 'Alias desc');
check($alias['sections'][3] === $sub, 'createAliasVariant leaves Section 3 untouched');

echo "f. Translation keys\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tr = require $basePath . '/templates/translations/' . $lang . '.php';
    $s = $tr['labels']['substance'] ?? null; $m = $tr['labels']['mixture'] ?? null;
    check(is_string($s) && $s !== '' && is_string($m) && $m !== '' && $s !== $m, "{$lang}: labels.substance and labels.mixture present and distinct", [$s, $m]);
}

echo "g. Source guards (column wired through models, formula lines and migration)\n";
$src = static fn (string $rel): string => (string) file_get_contents($basePath . '/' . $rel);
check(str_contains($src('src/Models/FinishedGood.php'), "'substance_mixture'"), 'FinishedGood allow-lists substance_mixture');
check(str_contains($src('src/Models/RawMaterial.php'), "'substance_mixture'"), 'RawMaterial allow-lists substance_mixture');
check(str_contains($src('src/Models/Formula.php'), 'rm.substance_mixture'), 'Formula::getLines selects rm.substance_mixture');
$mig = $src('migrations/054_physical_props_transport.sql');
check(substr_count($mig, "COLUMN_NAME = 'substance_mixture'") === 2 && str_contains($mig, "TABLE_NAME = 'finished_goods'") && str_contains($mig, "TABLE_NAME = 'raw_materials'"), 'migration 054 guards substance_mixture on both tables');
check(substr_count($mig, "VALUES ('054_physical_props_transport')") === 1, 'migration 054 records itself exactly once');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
