<?php
/**
 * DB-free checks for finding #70 — stored SDS snapshots:
 *
 *   - SDSGenerator::snapshotPayload() drops the unprinted generation payloads
 *     (hazard_result, voc_result, carcinogen_result, sara_result, hap_result,
 *     meta.generated_at, Section 11 carcinogen_result, Section 15 tsca) and
 *     every exact percentage under 'sections'; printed content (bands,
 *     H-codes, canonical keys, Prop 65 result, warnings, disclaimer) stays.
 *   - Idempotent; snapshotJson() round-trips; the input array is unchanged.
 *
 * Fixture rows use the real engine shapes (hazard_classes canonical =
 * GHSHazardClass constants).
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SnapshotPayloadTest.php
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

use SDS\Services\SDSGenerator;

$sds = [
    'meta' => [
        'product_code' => 'X1',
        'generated_at' => '2026-10-09T00:00:00Z',
        'labels'       => ['sara_313_title' => 'SARA 313'],
    ],
    'sections' => [
        2 => [
            'hazard_classes' => [[
                'class'              => 'Flammable Liquids',
                'category'           => 'Category 3',
                'canonical'          => 'flammable_liquids',
                'category_canonical' => 'Cat 3',
                'cas'                => '64-17-5',
                'chemical'           => 'Ethanol',
                'concentration_pct'  => 12.5,
                'cutoff_pct'         => 10.0,
                'source'             => 'federal',
                'h_codes'            => ['H226'],
            ]],
        ],
        3 => [
            'components' => [[
                'cas_number'          => '64-17-5',
                'chemical_name'       => 'Ethanol',
                'concentration_pct'   => 12.5,
                'concentration_range' => '10 - 30%',
                'h_codes'             => ['H226'],
            ]],
        ],
        11 => [
            'carcinogen_result' => ['has_carcinogens' => false, 'carcinogens' => []],
            'carcinogenicity'   => 'No component is listed as a carcinogen.',
        ],
        15 => [
            'hap' => [
                'has_haps'        => true,
                'hap_chemicals'   => [['hap_name' => 'Toluene', 'concentration_range' => '1 - 5%', 'concentration_pct' => 4.5]],
                'total_hap_pct'   => 4.5,
                'total_hap_range' => '1 - 5%',
            ],
            'snur' => ['has_snur' => true, 'listed_chemicals' => [['cas_number' => '1-1-1', 'concentration_pct' => 0.3]]],
            'tsca' => ['all_covered' => true],
        ],
    ],
    'hazard_result'     => ['trace' => [['step' => 'x']]],
    'voc_result'        => ['trace' => []],
    'sara_result'       => ['reportable' => []],
    'hap_result'        => ['total_hap_pct' => 4.5],
    'carcinogen_result' => ['has_carcinogens' => false],
    'prop65_result'     => ['requires_warning' => false],
    'warnings'          => ['W'],
    'legal_disclaimer'  => 'D',
];
$before = $sds;

$p = SDSGenerator::snapshotPayload($sds);

echo "a. top level\n";
foreach (['hazard_result', 'voc_result', 'carcinogen_result', 'sara_result', 'hap_result'] as $k) {
    check(!array_key_exists($k, $p), "{$k} removed");
}
check(!array_key_exists('generated_at', $p['meta']) && $p['meta']['product_code'] === 'X1', 'meta.generated_at removed, product_code kept', $p['meta']);
check(($p['prop65_result'] ?? null) === ['requires_warning' => false] && $p['warnings'] === ['W'] && $p['legal_disclaimer'] === 'D', 'prop65_result, warnings, disclaimer kept');

echo "b. sections\n";
$c3 = $p['sections'][3]['components'][0];
check($c3['concentration_range'] === '10 - 30%' && !array_key_exists('concentration_pct', $c3), 'S3 band kept, exact % removed', $c3);
$hc = $p['sections'][2]['hazard_classes'][0];
check($hc['h_codes'] === ['H226'] && $hc['canonical'] === 'flammable_liquids' && !array_key_exists('concentration_pct', $hc), 'S2 row keeps codes / canonical, loses exact %', $hc);
check(!array_key_exists('carcinogen_result', $p['sections'][11]) && $p['sections'][11]['carcinogenicity'] !== '', 'S11 carcinogen_result removed, text kept');
check(!array_key_exists('tsca', $p['sections'][15]), 'S15 tsca roll-up removed');
check($p['sections'][15]['hap']['total_hap_range'] === '1 - 5%' && !array_key_exists('total_hap_pct', $p['sections'][15]['hap']), 'HAP total band kept, exact total removed');
check(!array_key_exists('concentration_pct', $p['sections'][15]['snur']['listed_chemicals'][0]), 'SNUR exact % removed');

echo "c. JSON\n";
$json = SDSGenerator::snapshotJson($sds);
check(!str_contains($json, 'concentration_pct') && !str_contains($json, '12.5') && !str_contains($json, '4.5'), 'no exact percentage anywhere in the JSON', $json);

echo "d. idempotent, pure\n";
check(SDSGenerator::snapshotPayload($p) === $p, 'snapshotPayload(snapshotPayload(x)) === snapshotPayload(x)');
$dec = json_decode($json, true);
check(SDSGenerator::snapshotPayload($dec) === $dec, 'decoded snapshot is a fixed point');
check($sds === $before, 'input array unchanged');

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
