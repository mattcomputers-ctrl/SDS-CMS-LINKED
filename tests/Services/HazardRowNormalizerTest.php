<?php
/**
 * DB-free checks for batch E track T2a, findings #19 and #20:
 * HazardRowNormalizer (per-class attribution of ingredient hazard rows),
 * the PubChem parser fixes, and HazardEngine consolidation by canonical
 * class with bare category tokens ranked correctly.
 *
 * Fixtures use the stored row shape (hazard_classifications columns:
 * class_name, category, *_json, signal_word, optional *_canonical) and the
 * engine's hazard_classes entry shape (canonical = GHSHazardClass constant,
 * category_canonical = 'Cat N').
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/HazardRowNormalizerTest.php
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

use SDS\Services\GHSHazardClass as C;
use SDS\Services\HazardRowNormalizer as N;

/** Stored-row fixture: the whole substance label on one class row (pre-batch-E PubChem shape). */
$substance = [
    'signal_word'       => 'Danger',
    'h_statements_json' => json_encode([
        ['code' => 'H301', 'text' => '(100%): Toxic if swallowed'],
        ['code' => 'H350', 'text' => ''],
        ['code' => 'H315', 'text' => ''],
    ]),
    'p_statements_json' => json_encode(['P264', 'P270', 'P301+P310', 'P201', 'P202', 'P280', 'P308+P313', 'P337+P317']),
    'pictograms_json'   => json_encode(['GHS06', 'GHS08', 'GHS07']),
];
$contrib = static function (array $row): array {
    $rows = N::expandRows([$row]);
    $r    = $rows[0];
    return N::contributionForRow($r, N::rowCanonical($r), N::rowCategoryCanonical($r));
};

// ---------------------------------------------------------------------
echo "a. #19 a triggered row contributes only its own class\n";
$c = $contrib($substance + ['class_name' => 'Carcinogenicity', 'category' => 'Category 1B']);
check(array_keys($c['h']) === ['H350'], 'Carc. 1B row keeps H350 only (no H301 / H315)', array_keys($c['h']));
check($c['pictograms'] === ['GHS08'], 'Carc. 1B row pictogram GHS08 only (no GHS06 / GHS07)', $c['pictograms']);
check($c['signal_word'] === 'Danger', 'Carc. 1B signal word Danger (class default)', $c['signal_word']);
$pc = array_keys($c['p']);
sort($pc);
check($pc === ['P201', 'P202', 'P280', 'P308+P313', 'P405', 'P501'], 'Carc. 1B P-codes = class defaults; P264 / P270 / P301+P310 / P337+P317 dropped', $pc);
check(in_array('H301', $c['dropped'], true) && in_array('GHS06', $c['dropped'], true), 'dropped codes reported for the trace', $c['dropped']);

$c = $contrib(['signal_word' => 'Danger', 'h_statements_json' => json_encode(['H314', 'H315']), 'p_statements_json' => '[]',
               'pictograms_json' => json_encode(['GHS05', 'GHS07']), 'class_name' => 'Skin Irrit.', 'category' => '2']);
check(array_keys($c['h']) === ['H315'] && $c['pictograms'] === ['GHS07'] && $c['signal_word'] === 'Warning',
    'Skin Irrit. 2 row (bare token): H315, GHS07, Warning — not the substance Danger / H314 / GHS05', $c);

$c = $contrib(['signal_word' => 'Warning', 'h_statements_json' => json_encode(['H335', 'H336', 'H319']), 'p_statements_json' => '[]',
               'pictograms_json' => '[]', 'class_name' => 'STOT SE', 'category' => '3']);
check(array_keys($c['h']) === ['H335', 'H336'], 'STOT SE 3 keeps both Cat 3 codes (RI + narcotic), drops H319', array_keys($c['h']));

$c = $contrib(['signal_word' => 'Warning', 'h_statements_json' => json_encode(['H999']), 'p_statements_json' => '[]',
               'pictograms_json' => json_encode(['GHS08']), 'class_name' => 'Some Totally New Hazard Class 2099', 'category' => 'Category 1']);
check(array_keys($c['h']) === ['H999'] && $c['signal_word'] === 'Warning', 'unreadable class implying nothing: legacy row unchanged (golden [14])', $c);

// ---------------------------------------------------------------------
echo "b. #19 rows whose class cannot be read are split per implied class\n";
$rows = N::expandRows([[
    'class_name' => 'Unclassified', 'category' => '', 'signal_word' => 'Danger',
    'h_statements_json' => json_encode(['H225', 'H319', 'H336', 'H304', 'H411']),
    'p_statements_json' => '[]', 'pictograms_json' => '[]',
]]);
$got = array_map(fn($r) => $r['class_name_canonical'] . '|' . $r['category_canonical'], $rows);
check($got === [
    C::FLAMMABLE_LIQUIDS . '|Cat 2',
    C::EYE_DAMAGE_IRRITATION . '|Cat 2A',
    C::STOT_SINGLE . '|Cat 3',
    C::ASPIRATION_HAZARD . '|Cat 1',
    C::AQUATIC_CHRONIC . '|Cat 2',
], "'Unclassified' row -> one row per class its H-codes imply", $got);
check($rows[2]['category'] === 'Category 3 (Narcotic Effects)' && $rows[2]['class_name'] === 'STOT — Single Exposure',
    'implied rows carry the GHSHazardData display class / category', $rows[2]);

$rows = N::expandRows([
    ['class_name' => 'Flammable Liquids', 'category' => 'Category 2', 'h_statements_json' => json_encode(['H225', 'H319'])],
    ['class_name' => 'Unclassified', 'category' => '', 'h_statements_json' => json_encode(['H225', 'H319'])],
]);
check(count($rows) === 2 && $rows[1]['class_name_canonical'] === C::EYE_DAMAGE_IRRITATION,
    'implied class already on a readable row is not duplicated', array_column($rows, 'class_name'));

$rows = N::expandRows([['class_name' => 'Acute Toxicity', 'category' => 'Category 4', 'h_statements_json' => json_encode(['H302', 'H332', 'H319'])]]);
check(array_column($rows, 'class_name_canonical') === [C::ACUTE_TOXICITY_ORAL, C::ACUTE_TOXICITY_INHALATION],
    'generic "Acute Toxicity" row split by route from its H-codes (H319 ignored)', array_column($rows, 'class_name_canonical'));

$rows = N::expandRows([['class_name' => 'Serious Eye Damage/Eye Irritation', 'category' => 'Category 2', 'h_statements_json' => json_encode(['H318', 'H319'])]]);
check($rows[0]['category_canonical'] === 'Cat 2A', "eye 'Category 2' (no table entry) -> Cat 2A from H319, never Cat 1 from H318", $rows[0]['category_canonical'] ?? null);

check(N::impliedClasses(['H300'])[C::ACUTE_TOXICITY_ORAL]['category_canonical'] === 'Cat 1', 'H300 implies the most severe of Cat 1 / Cat 2');
check(N::impliedClasses(['H317'])[C::SKIN_SENSITIZATION]['category_canonical'] === 'Cat 1', 'H317 implies the plain Cat 1 over 1A / 1B');

// ---------------------------------------------------------------------
echo "c. #20 category tokens and display\n";
check(N::categoryDisplayFromToken('2') === 'Category 2' && N::categoryDisplayFromToken('1b') === 'Category 1B', "bare '2' / '1b' -> 'Category 2' / 'Category 1B'");
check(N::categoryDisplayFromToken('Category 3') === 'Category 3', 'a full category is unchanged');
check(N::categoryDisplay(C::SKIN_SENSITIZATION, 'Cat 1', '1') === 'Category 1 (1A/1B)', 'display from the GHSHazardData entry');
check(N::categoryDisplay(C::EYE_DAMAGE_IRRITATION, 'Cat 2', '2') === 'Category 2', "no entry: 'Cat 2' -> 'Category 2'");

// ---------------------------------------------------------------------
echo "d. #19/#20 PubChem storage rows\n";
$ghs = [
    'signal_word'              => 'Danger',
    'hazard_classes'           => [],
    'hazard_statements'        => [['code' => 'H225', 'text' => '(100%): Highly Flammable liquid and vapor'], ['code' => 'H319', 'text' => ''], ['code' => 'H336', 'text' => '']],
    'precautionary_statements' => array_map(fn($p) => ['code' => $p, 'text' => ''], ['P210', 'P233', 'P261', 'P264+P265', 'P280', 'P305+P351+P338', 'P337+P317', 'P501']),
    'pictogram_codes'          => ['GHS02', 'GHS07'],
];
$stored = N::rowsForStorage($ghs);
check(array_column($stored, 'class_name_canonical') === [C::FLAMMABLE_LIQUIDS, C::EYE_DAMAGE_IRRITATION, C::STOT_SINGLE],
    'no parsed classes: one stored row per implied class (no Unclassified row)', array_column($stored, 'class_name'));
check($stored[1]['signal_word'] === 'Warning' && array_column($stored[1]['h_statements'], 'code') === ['H319'] && $stored[1]['pictograms'] === ['GHS07'],
    'eye row stores Warning / H319 / GHS07 only', $stored[1]);
check(array_column($stored[2]['h_statements'], 'code') === ['H336'], 'STOT SE 3 narcotic row stores H336 (not RI H335)', $stored[2]['h_statements']);

$stored = N::rowsForStorage(['signal_word' => 'Danger', 'hazard_classes' => [['class' => 'Flam. Liq.', 'category' => '3']],
    'hazard_statements' => [['code' => 'H226', 'text' => '']], 'precautionary_statements' => [], 'pictogram_codes' => ['GHS02']]);
check($stored[0]['category'] === 'Category 3' && $stored[0]['category_canonical'] === 'Cat 3' && $stored[0]['signal_word'] === 'Warning',
    "bare PubChem '3' stored as 'Category 3' / 'Cat 3' with the Cat 3 signal word", $stored[0]);

$conn  = (new ReflectionClass(\SDS\Services\FederalData\Connectors\PubChemConnector::class))->newInstanceWithoutConstructor();
$parse = new ReflectionMethod($conn, 'parseHazardClassText');
$parse->setAccessible(true);
check($parse->invoke($conn, 'Flammable Liquids, Category 2') === ['class' => 'Flammable Liquids', 'category' => 'Category 2'], "parseHazardClassText: 'Category 2', not '2'");
check($parse->invoke($conn, 'Flam. Liq. 2 (100%)') === ['class' => 'Flam. Liq.', 'category' => 'Category 2'], 'parseHazardClassText: CLP short form with notification share');
$view = ['Record' => ['Section' => [['TOCHeading' => 'GHS Classification', 'Information' => [
    ['Name' => 'Precautionary Statement Codes', 'Value' => ['StringWithMarkup' => [['String' => 'P210, P233, P264+P265, P280, and P501']]]],
    ['Name' => 'GHS Hazard Statements', 'Value' => ['StringWithMarkup' => [['String' => 'H225 (100%): Highly Flammable liquid and vapor'], ['String' => 'EUH066: Repeated exposure may cause skin dryness or cracking']]]],
]]]]];
$parsed = $conn->parseGHSData($view);
check(array_column($parsed['precautionary_statements'], 'code') === ['P210', 'P233', 'P264+P265', 'P280', 'P501'], 'a P-code list string yields every code, not only the first', array_column($parsed['precautionary_statements'], 'code'));
check(array_column($parsed['hazard_statements'], 'code') === ['H225'], 'EUH066 is not read as H066', array_column($parsed['hazard_statements'], 'code'));

// ---------------------------------------------------------------------
echo "e. #20 HazardEngine consolidation by canonical class, bare tokens ranked\n";
$engine = new \SDS\Services\HazardEngine();
$sev = new ReflectionMethod($engine, 'categoryToSeverity');
$sev->setAccessible(true);
check($sev->invoke($engine, '2') === 20 && $sev->invoke($engine, '1B') === 11, "categoryToSeverity: bare '2' = 20, '1B' = 11");
$con = new ReflectionMethod($engine, 'consolidateHazardClasses');
$con->setAccessible(true);
$out = $con->invoke($engine, [
    ['class' => 'Serious Eye Damage/Eye Irritation', 'category' => 'Category 2A', 'canonical' => C::EYE_DAMAGE_IRRITATION, 'category_canonical' => 'Cat 2A', 'cas' => '111-11-1'],
    ['class' => 'Serious eye damage/eye irritation', 'category' => '1', 'canonical' => C::EYE_DAMAGE_IRRITATION, 'category_canonical' => 'Cat 1', 'cas' => '222-22-2'],
]);
check(count($out) === 1 && $out[0]['category_canonical'] === 'Cat 1', 'two spellings of one class consolidate; bare Cat 1 beats Category 2A', $out);

echo "\n";
echo $failures === 0 ? "PASSED: {$checks} checks\n" : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
