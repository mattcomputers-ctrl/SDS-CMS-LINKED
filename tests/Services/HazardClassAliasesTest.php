#!/usr/bin/env php
<?php
/**
 * HazardClassAliases Test Suite
 *
 * Validates the Phase 1 normalisation layer: English alias coverage,
 * category canonicalisation, signal word translation, and multi-language
 * round-trips driven by the authoritative ghs_*.php translation files.
 *
 * Run:
 *   php tests/Services/HazardClassAliasesTest.php
 *
 * Exit code:
 *   0 = all cases passed
 *   1 = one or more cases failed
 *
 * No DB or network access required. The translation files are loaded from
 * the project's templates/translations directory.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

new \SDS\Core\App();

use SDS\Services\GHSHazardClass;
use SDS\Services\HazardClassAliases;

$passed = 0;
$failed = 0;
$failures = [];

function assertEquals(string $desc, $expected, $actual): bool
{
    global $passed, $failed, $failures;
    if ($expected === $actual) {
        $passed++;
        return true;
    }
    $failed++;
    $failures[] = $desc;
    echo "  FAIL  {$desc}\n";
    echo "        Expected: " . var_export($expected, true) . "\n";
    echo "        Got:      " . var_export($actual, true) . "\n";
    return false;
}

function assertNull(string $desc, $actual): bool
{
    return assertEquals($desc, null, $actual);
}

echo "=== HazardClassAliases Test Suite ===\n\n";

// ──────────────────────────────────────────────────────────────────────
echo "[1] normalize() — canonical English display names round-trip.\n";
foreach (GHSHazardClass::all() as $canonical) {
    $display = GHSHazardClass::displayName($canonical);
    assertEquals("display-name({$canonical}): '{$display}' → {$canonical}",
        $canonical, HazardClassAliases::normalize($display));
}

// ──────────────────────────────────────────────────────────────────────
echo "[2] normalize() — common abbreviations.\n";
$abbrev = [
    'Carc.'            => GHSHazardClass::CARCINOGENICITY,
    'carc.'            => GHSHazardClass::CARCINOGENICITY,
    'Muta.'            => GHSHazardClass::GERM_CELL_MUTAGENICITY,
    'Repr.'            => GHSHazardClass::REPRODUCTIVE_TOXICITY,
    'Skin Sens.'       => GHSHazardClass::SKIN_SENSITIZATION,
    'Resp. Sens.'      => GHSHazardClass::RESPIRATORY_SENSITIZATION,
    'STOT SE'          => GHSHazardClass::STOT_SINGLE,
    'STOT RE'          => GHSHazardClass::STOT_REPEATED,
    'Asp. Tox.'        => GHSHazardClass::ASPIRATION_HAZARD,
    'Flam. Liq.'       => GHSHazardClass::FLAMMABLE_LIQUIDS,
    'Flam. Sol.'       => GHSHazardClass::FLAMMABLE_SOLIDS,
    'Flam. Gas'        => GHSHazardClass::FLAMMABLE_GASES,
    'Eye Irrit.'       => GHSHazardClass::EYE_DAMAGE_IRRITATION,
    'Eye Dam.'         => GHSHazardClass::EYE_DAMAGE_IRRITATION,
    'Skin Irrit.'      => GHSHazardClass::SKIN_CORROSION_IRRITATION,
    'Skin Corr.'       => GHSHazardClass::SKIN_CORROSION_IRRITATION,
    'Ox. Liq.'         => GHSHazardClass::OXIDIZING_LIQUIDS,
    'Ox. Sol.'         => GHSHazardClass::OXIDIZING_SOLIDS,
    'Ox. Gas'          => GHSHazardClass::OXIDIZING_GASES,
    'Press. Gas'       => GHSHazardClass::GASES_UNDER_PRESSURE,
    'Met. Corr.'       => GHSHazardClass::CORROSIVE_TO_METALS,
];
foreach ($abbrev as $raw => $expected) {
    assertEquals("abbrev: '{$raw}' → {$expected}",
        $expected, HazardClassAliases::normalize($raw));
}

// ──────────────────────────────────────────────────────────────────────
echo "[3] normalize() — case / whitespace / punctuation variants.\n";
$variants = [
    'CARCINOGENICITY'             => GHSHazardClass::CARCINOGENICITY,
    '  Carcinogenicity  '         => GHSHazardClass::CARCINOGENICITY,
    'Skin   Corrosion/Irritation' => GHSHazardClass::SKIN_CORROSION_IRRITATION,
    'STOT — Single Exposure'      => GHSHazardClass::STOT_SINGLE,     // em-dash
    'STOT – Single Exposure'      => GHSHazardClass::STOT_SINGLE,     // en-dash
    'STOT - Single Exposure'      => GHSHazardClass::STOT_SINGLE,     // hyphen
    'Serious Eye Damage/Irritation' => GHSHazardClass::EYE_DAMAGE_IRRITATION,  // legacy form
    'skin corrosion'              => GHSHazardClass::SKIN_CORROSION_IRRITATION,
    'oxidising liquids'           => GHSHazardClass::OXIDIZING_LIQUIDS,  // British
];
foreach ($variants as $raw => $expected) {
    assertEquals("variant: '{$raw}' → {$expected}",
        $expected, HazardClassAliases::normalize($raw));
}

// ──────────────────────────────────────────────────────────────────────
echo "[4] normalize() — multilingual round-trip across all ghs_*.php files.\n";
$langs = ['de', 'es', 'fr'];
$translationFiles = [];
foreach ($langs as $lang) {
    $file = $basePath . "/templates/translations/ghs_{$lang}.php";
    if (is_file($file)) {
        $translationFiles[$lang] = require $file;
    }
}

// Reverse-lookup: English display name → canonical code
$englishToCanonical = [];
foreach (GHSHazardClass::all() as $canonical) {
    $englishToCanonical[GHSHazardClass::displayName($canonical)] = $canonical;
}

foreach ($translationFiles as $lang => $t) {
    foreach (($t['hazard_class_names'] ?? []) as $english => $translated) {
        if (!isset($englishToCanonical[$english])) {
            // Translation file has a key we don't recognise — skip (maintenance
            // drift, not an aliases bug)
            continue;
        }
        $expected = $englishToCanonical[$english];
        assertEquals("translate {$lang}: '{$translated}' → {$expected}",
            $expected, HazardClassAliases::normalize($translated));
    }
}

// ──────────────────────────────────────────────────────────────────────
echo "[5] normalize() — unmappable / empty / null inputs.\n";
assertNull("empty string → null", HazardClassAliases::normalize(''));
assertNull("whitespace → null", HazardClassAliases::normalize('   '));
assertNull("null → null", HazardClassAliases::normalize(null));
assertNull("gibberish → null", HazardClassAliases::normalize('Zzzzzz Not A Hazard'));
assertNull("partial word → null", HazardClassAliases::normalize('Toxi'));

// ──────────────────────────────────────────────────────────────────────
echo "[6] normalizeCategory() — English variants.\n";
$catTests = [
    'Category 1'            => 'Cat 1',
    'Category 2'            => 'Cat 2',
    'Category 2A'           => 'Cat 2A',
    'Category 1A'           => 'Cat 1A',
    'Category 1B'           => 'Cat 1B',
    'Category 1 (1A/1B)'    => 'Cat 1',
    'Category 1A (1A/1B)'   => 'Cat 1A',
    'Cat 2'                 => 'Cat 2',
    'Cat. 3'                => 'Cat 3',
    'cat 4'                 => 'Cat 4',
    '1A'                    => 'Cat 1A',
    '2'                     => 'Cat 2',
    '2B'                    => 'Cat 2B',
    'Division 1.1'          => 'Division 1.1',
    'Division 1.4'          => 'Division 1.4',
    'Type A'                => 'Type A',
    'Type C & D'            => 'Type C & D',
    'Lactation'             => 'Lactation',
    'Compressed gas'        => 'Compressed gas',
    'Liquefied gas'         => 'Liquefied gas',
    ''                      => '',
];
foreach ($catTests as $raw => $expected) {
    assertEquals("category: '{$raw}' → '{$expected}'",
        $expected, HazardClassAliases::normalizeCategory($raw));
}

// ──────────────────────────────────────────────────────────────────────
echo "[7] normalizeCategory() — translated inputs reverse-map to English.\n";
$translatedCatTests = [
    'Catégorie 1A'     => 'Cat 1A',     // French
    'Catégorie 2'      => 'Cat 2',
    'Kategorie 1A'     => 'Cat 1A',     // German
    'Kategorie 2'      => 'Cat 2',
    'Categoría 1A'     => 'Cat 1A',     // Spanish
    'Categoría 2'      => 'Cat 2',
    'Gaz comprimé'     => 'Compressed gas',  // FR passthrough
];
foreach ($translatedCatTests as $raw => $expected) {
    assertEquals("translated-category: '{$raw}' → '{$expected}'",
        $expected, HazardClassAliases::normalizeCategory($raw));
}

// ──────────────────────────────────────────────────────────────────────
echo "[8] normalizeSignalWord() — English + translated.\n";
$sigTests = [
    'Warning'       => 'Warning',
    'warning'       => 'Warning',
    'WARNING'       => 'Warning',
    'Danger'        => 'Danger',
    'danger'        => 'Danger',
    'Attention'     => 'Warning',       // French
    'Achtung'       => 'Warning',       // German
    'Advertencia'   => 'Warning',       // Spanish
    'Gefahr'        => 'Danger',        // German
    'Peligro'       => 'Danger',        // Spanish
];
foreach ($sigTests as $raw => $expected) {
    assertEquals("signal-word: '{$raw}' → '{$expected}'",
        $expected, HazardClassAliases::normalizeSignalWord($raw));
}
assertNull("signal-word: '' → null", HazardClassAliases::normalizeSignalWord(''));
assertNull("signal-word: null → null", HazardClassAliases::normalizeSignalWord(null));
assertNull("signal-word: 'Mild' → null", HazardClassAliases::normalizeSignalWord('Mild'));

// ──────────────────────────────────────────────────────────────────────
echo "[9] GHSHazardClass helpers.\n";
assertEquals('group(CARCINOGENICITY)=health', 'health',
    GHSHazardClass::group(GHSHazardClass::CARCINOGENICITY));
assertEquals('group(FLAMMABLE_LIQUIDS)=physical', 'physical',
    GHSHazardClass::group(GHSHazardClass::FLAMMABLE_LIQUIDS));
assertEquals('group(AQUATIC_ACUTE)=environmental', 'environmental',
    GHSHazardClass::group(GHSHazardClass::AQUATIC_ACUTE));
assertEquals('group(unknown)=health-default', 'health',
    GHSHazardClass::group('not_a_real_code'));
assertEquals('isValid(CARCINOGENICITY)', true,
    GHSHazardClass::isValid(GHSHazardClass::CARCINOGENICITY));
assertEquals('isValid(unknown)', false,
    GHSHazardClass::isValid('not_a_real_code'));
assertEquals('displayName(CARCINOGENICITY)', 'Carcinogenicity',
    GHSHazardClass::displayName(GHSHazardClass::CARCINOGENICITY));

// ──────────────────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed > 0) {
    echo "\nFailures (" . count($failures) . "):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "All HazardClassAliases cases passed.\n";
exit(0);
