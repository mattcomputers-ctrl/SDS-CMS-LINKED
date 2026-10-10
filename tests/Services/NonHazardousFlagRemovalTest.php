<?php
/**
 * DB-free checks for audit #17 / owner decision Q5 (follow-up 6): the
 * raw-material constituent "Non-Haz" checkbox is removed and
 * raw_material_constituents.is_non_hazardous is no longer read anywhere.
 *   a. section3(): a stale is_non_hazardous on a composition row no longer
 *      hides a hazardous or OEL-bearing constituent; output is identical
 *      with the key true, false or absent.
 *   b. FormulaCalcService::buildResaleComposition(): rows no longer carry
 *      the key (CAS path and TRADE_SECRET path).
 *   c. Source guards: no PHP reader / form field left; migration 058
 *      documents the unused column; checklist carries the count query.
 * Run: docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/NonHazardousFlagRemovalTest.php
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

use SDS\Services\GHSHazardClass as G;

// keeps section3() DB-free (getInhalationOnlyCas() reads settings otherwise)
$inh = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'inhalationOnlyCas');
$inh->setAccessible(true);
$inhSaved = $inh->getValue();
$inh->setValue(null, ['1333-86-4' => 'Carbon Black', '13463-67-7' => 'Titanium Dioxide']);

// Formula::getExpandedComposition row shape (after this change)
$row = static function (string $cas, string $name, float $pct, ?bool $nonHaz): array {
    $r = [
        'cas_number' => $cas, 'chemical_name' => $name, 'concentration_pct' => $pct,
        'concentration_min' => null, 'concentration_max' => null,
        'is_trade_secret' => false, 'trade_secret_description' => null,
        'has_nitrogen' => false, 'has_sulfur' => false, 'has_halogen' => false,
        'contributing_materials' => [],
    ];
    if ($nonHaz !== null) { $r['is_non_hazardous'] = $nonHaz; }   // stale key from an old caller
    return $r;
};
$comp = static fn (?bool $f): array => [
    $row('7732-18-5', 'Water', 60.0, $f),              // no class, no OEL -> never listed
    $row('111-76-2', '2-Butoxyethanol', 12.0, $f),     // Eye Irrit. 2A by summation + OEL
    $row('1332-58-7', 'Kaolin', 2.0, $f),              // OEL only
];

// HazardEngine::classify() output shape
$hz = [
    'hazard_classes' => [[
        'class' => G::displayName(G::EYE_DAMAGE_IRRITATION), 'category' => 'Category 2A',
        'canonical' => G::EYE_DAMAGE_IRRITATION, 'category_canonical' => 'Cat 2A',
        'cas' => 'MIXTURE', 'chemical' => 'Multiple components (summation)',
        'concentration_pct' => 12.0, 'cutoff_pct' => 10.0, 'source' => 'summation',
        'h_codes' => ['H319'], 'contributors' => ['111-76-2'],
    ]],
    'h_statements' => [], 'p_statements' => [], 'pictograms' => ['GHS07'], 'signal_word' => 'Warning',
    'hazardous_cas' => ['111-76-2'],
    'cas_h_codes' => [],
    'exposure_limits' => [
        ['cas_number' => '111-76-2', 'chemical_name' => '2-Butoxyethanol', 'concentration_pct' => 12.0,
         'limit_type' => 'TLV-TWA', 'value' => '20', 'units' => 'ppm', 'notes' => '', 'source' => 'ACGIH'],
        ['cas_number' => '1332-58-7', 'chemical_name' => 'Kaolin', 'concentration_pct' => 2.0,
         'limit_type' => 'PEL-TWA', 'value' => '15', 'units' => 'mg/m3', 'notes' => 'total dust', 'source' => 'OSHA'],
    ],
];

echo "a. section3() ignores the old flag\n";
$g  = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService('en'));
$m3 = new ReflectionMethod($g, 'section3'); $m3->setAccessible(true);
$flagged   = $m3->invoke($g, $comp(true),  $hz, [])['components'] ?? [];
$unflagged = $m3->invoke($g, $comp(false), $hz, [])['components'] ?? [];
$absent    = $m3->invoke($g, $comp(null),  $hz, [])['components'] ?? [];
$cas = array_column($flagged, 'cas_number'); sort($cas);
check($cas === ['111-76-2', '1332-58-7'], 'flagged hazardous + OEL-only constituents are listed; water is not', $cas);
$be = array_values(array_filter($flagged, fn ($r) => $r['cas_number'] === '111-76-2'))[0] ?? [];
check(in_array('H319', $be['h_codes'] ?? [], true), 'flagged 2-butoxyethanol row carries the summation H319', $be);
check($flagged === $unflagged && $flagged === $absent, 'output identical with the key true / false / absent');
$inh->setValue(null, $inhSaved);

echo "b. Resale composition no longer carries the key\n";
$svc = new \SDS\Services\FormulaCalcService();
$mr  = new ReflectionMethod($svc, 'buildResaleComposition'); $mr->setAccessible(true);
$out = $mr->invoke($svc, ['id' => 5, 'internal_code' => 'RM-ACR', 'hazardous_no_cas' => 0, 'constituents' => [[
    'cas_number' => '111-76-2', 'chemical_name' => '2-Butoxyethanol', 'pct_exact' => 12.0, 'pct_min' => null, 'pct_max' => null,
    'is_trade_secret' => 0, 'is_non_hazardous' => 1, 'trade_secret_description' => null,
    'has_nitrogen' => 0, 'has_sulfur' => 0, 'has_halogen' => 0,
]]]);
check(($out[0]['cas_number'] ?? null) === '111-76-2' && !array_key_exists('is_non_hazardous', $out[0]), 'CAS path: no is_non_hazardous key', $out[0] ?? null);
$ts = $mr->invoke($svc, ['id' => 6, 'internal_code' => 'RM-TS', 'hazardous_no_cas' => 1, 'manual_hazard_json' => null]);
check(($ts[0]['cas_number'] ?? null) === 'TRADE_SECRET' && !array_key_exists('is_non_hazardous', $ts[0]), 'TRADE_SECRET path: no is_non_hazardous key', $ts[0] ?? null);

echo "c. Source guards\n";
foreach ([
    'src/Services/SDSGenerator.php', 'src/Services/FormulaCalcService.php', 'src/Services/HazardEngine.php',
    'src/Models/Formula.php', 'src/Models/RawMaterial.php',
    'src/Controllers/RawMaterialController.php', 'src/Controllers/AdminController.php',
    'src/Views/raw-materials/form.php',
] as $rel) {
    $src = (string) file_get_contents($basePath . '/' . $rel);
    check($src !== '' && !str_contains($src, 'is_non_hazardous'), "{$rel} no longer mentions is_non_hazardous");
}
check(!str_contains((string) file_get_contents($basePath . '/src/Views/raw-materials/form.php'), 'Non-Haz'), 'form has no Non-Haz column header');
$mig = glob($basePath . '/migrations/058_*.sql') ?: [];
check(count($mig) === 1, 'exactly one 058 migration file', $mig);
$sql = $mig ? (string) file_get_contents($mig[0]) : '';
check(str_contains($sql, 'is_non_hazardous') && str_contains($sql, 'UNUSED'), 'migration 058 documents the unused column');
check(str_contains($sql, 'sds.migration.058.non_hazardous_bump_count') && str_contains($sql, 'UTC_TIMESTAMP()'), 'migration 058 one-time bump is marker-guarded and UTC');
check(substr_count($sql, 'INSERT IGNORE INTO `schema_migrations`') === 1, 'migration 058 records itself exactly once');
check(str_contains((string) file_get_contents($basePath . '/docs/post-update-checklist.md'), 'WHERE is_non_hazardous = 1'), 'post-update checklist carries the count query');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
