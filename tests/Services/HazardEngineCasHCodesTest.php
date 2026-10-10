<?php
/**
 * DB-free checks for the per-CAS H-code map (SDS data-source audit #37):
 *
 *   1. HazardEngine::buildCasHCodeMap() (private, via reflection) on
 *      UNconsolidated entries: same-class CAS each keep the code; a
 *      sub-category with no row of its own ('Cat 1B') falls back to the base
 *      category (H314); MIXTURE entries credit their contributors; pseudo keys
 *      (FG_OVERRIDE, TRADE_SECRET, PRODUCT, '') and unmapped classes with no
 *      codes are never attributed.
 *   2. TransportClassifier::casHCodeMap(): union of the engine map and the
 *      final hazard_classes, codes sorted; pseudo keys in the map ignored.
 *   3. Section 14 technical names: two Skin Corr. 1B constituents of a Class 8
 *      n.o.s. cleaner are BOTH named, largest first (consolidation used to
 *      leave one of them without H314).
 *
 * Same Reflection bootstrap as tests/smoke_pdf.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/HazardEngineCasHCodesTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\GHSHazardClass;
use SDS\Services\HazardRowNormalizer;
use SDS\Services\TransportClassifier as TC;

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

$engine = new \SDS\Services\HazardEngine();
$buildMap = new ReflectionMethod($engine, 'buildCasHCodeMap');
$buildMap->setAccessible(true);
$consolidate = new ReflectionMethod($engine, 'consolidateHazardClasses');
$consolidate->setAccessible(true);

$e = static fn(string $cas, string $chem, float $pct, ?string $canonical, string $cat, string $catDisplay, array $extra = []): array => array_merge([
    'class'              => $canonical !== null ? GHSHazardClass::displayName($canonical) : 'Unmapped hazard',
    'category'           => $catDisplay,
    'canonical'          => $canonical,
    'category_canonical' => $cat,
    'cas'                => $cas,
    'chemical'           => $chem,
    'concentration_pct'  => $pct,
    'cutoff_pct'         => 1.0,
], $extra);

$skin = GHSHazardClass::SKIN_CORROSION_IRRITATION;

// ---------------------------------------------------------------------
echo "1. HazardEngine::buildCasHCodeMap\n";
$entries = [
    $e('13048-33-4', 'HDDA', 20.0, $skin, 'Cat 2', 'Category 2'),
    $e('42978-66-5', 'TPGDA', 15.0, $skin, 'Cat 2', 'Category 2'),
    $e('141-43-5', '2-Aminoethanol', 8.0, $skin, 'Cat 1B', 'Category 1B'),
    $e('1310-58-3', 'Potassium hydroxide', 4.0, $skin, 'Cat 1B', 'Category 1B'),
    $e('MIXTURE', 'Mixture', 0.0, GHSHazardClass::AQUATIC_CHRONIC, 'Cat 3', 'Category 3', ['contributors' => ['55965-84-9', 'TRADE_SECRET', '']]),
    $e('FG_OVERRIDE', 'Override', 0.0, GHSHazardClass::EYE_DAMAGE_IRRITATION, 'Cat 2A', 'Category 2A'),
    $e('TRADE_SECRET', 'Trade Secret', 3.0, GHSHazardClass::STOT_SINGLE, 'Cat 3', 'Category 3'),
    $e('PRODUCT', 'Product', 0.0, GHSHazardClass::FLAMMABLE_LIQUIDS, 'Cat 3', 'Category 3'),
    $e('999-99-9', 'Unknown', 5.0, null, '', 'Category 9'),
];
$map = $buildMap->invoke($engine, $entries);
check(($map['13048-33-4'] ?? null) === ['H315'], 'HDDA H315', $map['13048-33-4'] ?? null);
check(($map['42978-66-5'] ?? null) === ['H315'], 'TPGDA H315 (same class, second CAS keeps it)', $map['42978-66-5'] ?? null);
check(($map['141-43-5'] ?? null) === ['H314'], "MEA 'Cat 1B' falls back to 'Cat 1' -> H314", $map['141-43-5'] ?? null);
check(($map['1310-58-3'] ?? null) === ['H314'], "KOH 'Cat 1B' -> H314", $map['1310-58-3'] ?? null);
check(($map['55965-84-9'] ?? null) === ['H412'], 'MIXTURE aquatic chronic 3 credited to its contributor', $map['55965-84-9'] ?? null);
$bad = array_intersect(array_keys($map), ['MIXTURE', 'FG_OVERRIDE', 'TRADE_SECRET', 'PRODUCT', '', '999-99-9']);
check($bad === [], 'no pseudo keys and no unmapped class without codes', array_keys($map));

// Consolidation alone keeps one Skin Corr./Irrit. entry: the reason for the map.
$consolidated = $consolidate->invoke($engine, $entries);
$skinEntries = array_values(array_filter($consolidated, static fn(array $hc): bool => ($hc['canonical'] ?? null) === $skin));
check(count($skinEntries) === 1, 'consolidation keeps one Skin Corr./Irrit. entry', count($skinEntries));

// ---------------------------------------------------------------------
echo "2. TransportClassifier::casHCodeMap union + sort\n";
$u = TC::casHCodeMap([
    'cas_h_codes'    => ['64-17-5' => ['H319']],
    'hazard_classes' => [['cas' => '64-17-5', 'h_codes' => ['H225']]],
]);
check($u === ['64-17-5' => ['H225', 'H319']], 'union of engine map and final classes, sorted', $u);
$u2 = TC::casHCodeMap(['cas_h_codes' => ['MIXTURE' => ['H400'], 'FG_OVERRIDE' => ['H319'], 'TRADE_SECRET' => ['H301'], '' => ['H300'], '50-00-0' => ['H350', 'H301']]]);
check($u2 === ['50-00-0' => ['H301', 'H350']], 'pseudo keys in cas_h_codes ignored; codes sorted', $u2);

// ---------------------------------------------------------------------
echo "3. Section 14 technical names: both corrosive constituents named\n";
$cleaner = [
    $e('141-43-5', '2-Aminoethanol', 8.0, $skin, 'Cat 1B', 'Category 1B'),
    $e('1310-58-3', 'Potassium hydroxide', 4.0, $skin, 'Cat 1B', 'Category 1B'),
];
$engineMap = $buildMap->invoke($engine, $cleaner);
$stamped = $consolidate->invoke($engine, $cleaner);
foreach ($stamped as &$hc) {
    $entry = HazardRowNormalizer::entryFor((string) $hc['canonical'], (string) $hc['category_canonical'], (string) $hc['category']);
    $hc['h_codes'] = $entry['h_codes'] ?? [];
}
unset($hc);
$casHCodes = TC::casHCodeMap(['hazard_classes' => $stamped, 'cas_h_codes' => $engineMap]);
check(($casHCodes['1310-58-3'] ?? null) === ['H314'], 'KOH carries H314 in the combined map', $casHCodes);
$r = TC::classify([
    'flash_point_c'            => null,
    'flash_point_greater_than' => false,
    'boiling_point_c'          => null,
    'h_codes'                  => ['H314'],
    'hazard_classes'           => $stamped,
    'cas_h_codes'              => $casHCodes,
    'composition'              => [
        ['cas_number' => '141-43-5', 'chemical_name' => '2-Aminoethanol', 'concentration_pct' => 8.0],
        ['cas_number' => '1310-58-3', 'chemical_name' => 'Potassium hydroxide', 'concentration_pct' => 4.0],
    ],
    'physical_state'           => 'Liquid',
    'product_type'             => 'nos',
    'description'              => 'PLATE CLEANER',
    'family'                   => null,
]);
check(($r['status'] ?? null) === 'regulated', 'status regulated', $r['status'] ?? null);
check(($r['un_number'] ?? null) === 'UN1760', 'UN1760', $r['un_number'] ?? null);
check(($r['technical_names'] ?? null) === ['2-Aminoethanol', 'Potassium hydroxide'], 'technical names: both constituents, largest first', $r['technical_names'] ?? null);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
