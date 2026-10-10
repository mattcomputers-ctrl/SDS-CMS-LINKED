<?php
/**
 * DB-free checks for finding #26 — CAA 112(b) HAP analysis:
 *
 *   - Direct hap_list rows by CAS; glycol-ether category members reported
 *     under the category name with hap_category; delisted EGBE and rows
 *     below 0.01 % absent; manual raw-material entries included.
 *   - Rows sorted by concentration; total summed (Section 15 bands it).
 *   - analyse() needs no DB for an empty composition.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/HAPServiceTest.php
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

use SDS\Services\HAPService;

$comp = [
    ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene',      'concentration_pct' => 4.5],
    ['cas_number' => '112-34-5', 'chemical_name' => 'DGBE',         'concentration_pct' => 3.0],
    ['cas_number' => '111-76-2', 'chemical_name' => 'EGBE',         'concentration_pct' => 2.0],
    ['cas_number' => '50-00-0',  'chemical_name' => 'Formaldehyde', 'concentration_pct' => 0.005],
];
$direct = [
    '108-88-3' => ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene'],
    '50-00-0'  => ['cas_number' => '50-00-0',  'chemical_name' => 'Formaldehyde'],
];
$cats = [
    '112-34-5' => [['category_code' => 'HAP_GE', 'category_name' => 'Glycol ethers', 'deminimis_pct' => null, 'is_pbt' => 0]],
];
$manual = [['chemical_name' => 'Methanol', 'cas_number' => '67-56-1', 'concentration_pct' => 0.3]];

$r = HAPService::evaluate($comp, $manual, $direct, $cats);
$rows = $r['hap_chemicals'];

echo "a. rows\n";
check(count($rows) === 3, 'three HAP rows', $rows);
check(($rows[0]['hap_name'] ?? null) === 'Toluene' && !isset($rows[0]['hap_category']), 'row 1 Toluene (direct)', $rows[0] ?? null);
check(($rows[1]['hap_name'] ?? null) === 'Glycol ethers' && ($rows[1]['hap_category'] ?? null) === 'HAP_GE'
    && ($rows[1]['chemical_name'] ?? null) === 'DGBE' && ($rows[1]['cas_number'] ?? null) === '112-34-5', 'row 2 DGBE under Glycol ethers', $rows[1] ?? null);
check(($rows[2]['hap_name'] ?? null) === 'Methanol', 'row 3 manual Methanol', $rows[2] ?? null);
$names = array_column($rows, 'chemical_name');
check(!in_array('EGBE', $names, true) && !in_array('Formaldehyde', $names, true), 'EGBE (delisted) and 0.005 % formaldehyde absent', $names);

echo "b. total\n";
check($r['total_hap_pct'] === 7.8 && $r['has_haps'] === true, 'total 7.8, has_haps', $r['total_hap_pct']);

echo "c. no DB\n";
$e = HAPService::analyse([], []);
check($e['has_haps'] === false && $e['total_hap_pct'] === 0.0 && $e['hap_chemicals'] === [], 'empty composition needs no DB', $e);

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
