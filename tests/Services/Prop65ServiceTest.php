<?php
/**
 * DB-free checks for finding #47 — Prop 65 / manual list data:
 *
 *   - Prop65Service::normaliseTypes(): case-insensitive, trimmed, 'toxicity'
 *     suffix stripped, OEHHA short 'female' / 'male' mapped, de-duplicated.
 *   - Prop65Service::isLegacyNameOnly(): a name with no CAS and no Override
 *     tick is used as typed.
 *   - SDSGenerator::pctByRawMaterial(): formula % summed per raw material.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/Prop65ServiceTest.php
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

use SDS\Services\Prop65Service;

echo "a. normaliseTypes\n";
check(Prop65Service::normaliseTypes('Cancer, Developmental') === ['cancer', 'developmental'], 'comma string, capitals', Prop65Service::normaliseTypes('Cancer, Developmental'));
$n = Prop65Service::normaliseTypes(['Female', ' male ', 'developmental toxicity', '', 'cancer', 'CANCER']);
check($n === ['female reproductive', 'male reproductive', 'developmental', 'cancer'], 'array: short forms mapped, suffix stripped, blanks / duplicates dropped', $n);

echo "b. isLegacyNameOnly\n";
check(Prop65Service::isLegacyNameOnly(['chemical_name' => 'Styrene', 'cas_number' => '', 'toxicity_types' => 'cancer']) === true, 'name, no CAS, no Override');
check(Prop65Service::isLegacyNameOnly(['chemical_name' => 'Styrene', 'cas_number' => '', 'is_override' => 1]) === false, 'Override ticked is not legacy');
check(Prop65Service::isLegacyNameOnly(['chemical_name' => 'Styrene', 'cas_number' => '100-42-5']) === false, 'CAS present is not legacy');
check(Prop65Service::isLegacyNameOnly(['chemical_name' => '  ', 'cas_number' => '']) === false, 'blank name is not legacy');

echo "c. SDSGenerator::pctByRawMaterial\n";
$m = new ReflectionMethod(\SDS\Services\SDSGenerator::class, 'pctByRawMaterial');
$m->setAccessible(true);
$p = $m->invoke(null, [
    ['raw_material_id' => 7, 'pct' => 10],
    ['raw_material_id' => 7, 'pct' => 5],
    ['raw_material_id' => 9, 'pct' => 2.5],
    ['raw_material_id' => null, 'pct' => 50],
]);
check($p === [7 => 15.0, 9 => 2.5], 'summed per raw material, lines without one ignored', $p);

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
