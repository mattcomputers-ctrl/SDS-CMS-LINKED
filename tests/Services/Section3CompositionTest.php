<?php
/**
 * DB-free checks for SDSGenerator::section3() (SDS data-source audit #36(3)
 * and #37):
 *
 *   a. Two CAS in the same hazard class both keep their H-code (per-CAS map
 *      recorded by HazardEngine before consolidation).
 *   b. Inhalation-only CAS (carbon black) keep only the final attribution:
 *      wet mixture -> not listed; powder carcinogen entry -> listed with H351.
 *   c. Nothing listed but the product is classified -> the translated
 *      section3.classified_no_ingredients_note, never "No hazardous ingredients".
 *   d. Nothing listed and unclassified -> labels.no_hazardous_note.
 *   e. Substance sheet: the identity row is always listed; no mixture notes.
 *   f. Substance whose identity is the TRADE_SECRET bucket stays masked.
 *   g. Substance with no CAS constituent -> identity_missing flag.
 *   h. Source guards (generator unsets the flag on both paths; PDF and HTML
 *      preview read mixture_notes / empty_note).
 *   i. Translation present in all four languages.
 *
 * Hazard results are built from the real engine pieces (buildCasHCodeMap,
 * consolidateHazardClasses, HazardRowNormalizer stamping) via reflection.
 * Same Reflection bootstrap as tests/smoke_pdf.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/Section3CompositionTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\GHSHazardClass;
use SDS\Services\HazardRowNormalizer;
use SDS\Services\TransportClassifier;

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

$inh = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'inhalationOnlyCas');
$inh->setAccessible(true);
$inhSaved = $inh->getValue();
$inh->setValue(null, ['1333-86-4' => 'Carbon Black']);   // keeps section3() DB-free

$engine = new \SDS\Services\HazardEngine();
$em = static function (string $name) use ($engine): ReflectionMethod {
    $m = new ReflectionMethod($engine, $name);
    $m->setAccessible(true);
    return $m;
};
$buildMap    = $em('buildCasHCodeMap');
$consolidate = $em('consolidateHazardClasses');

/** classify()'s tail: per-CAS map, consolidation, h_codes stamping. */
$engineResult = static function (array $entries, array $hazardousCas, array $extra = []) use ($engine, $buildMap, $consolidate): array {
    $casMap = $buildMap->invoke($engine, $entries);
    $classes = $consolidate->invoke($engine, $entries);
    foreach ($classes as &$hc) {
        $entry = HazardRowNormalizer::entryFor((string) ($hc['canonical'] ?? ''), (string) ($hc['category_canonical'] ?? ''), (string) ($hc['category'] ?? ''));
        $hc['h_codes'] = $entry['h_codes'] ?? [];
    }
    unset($hc);
    $hStatements = [];
    foreach ($classes as $hc) {
        foreach ($hc['h_codes'] as $code) {
            $hStatements[$code] = ['code' => $code, 'text' => ''];
        }
    }
    return array_merge([
        'hazard_classes'  => $classes,
        'h_statements'    => array_values($hStatements),
        'p_statements'    => [],
        'pictograms'      => [],
        'signal_word'     => null,
        'exposure_limits' => [],
        'hazardous_cas'   => $hazardousCas,
        'cas_h_codes'     => $casMap,
    ], $extra);
};

$entry = static fn(string $cas, string $chem, float $pct, string $canonical, string $cat, string $catDisplay, float $cutoff): array => [
    'class'              => GHSHazardClass::displayName($canonical),
    'category'           => $catDisplay,
    'canonical'          => $canonical,
    'category_canonical' => $cat,
    'cas'                => $cas,
    'chemical'           => $chem,
    'concentration_pct'  => $pct,
    'cutoff_pct'         => $cutoff,
];
$row = static fn(string $cas, string $name, float $pct, ?float $min = null, ?float $max = null, array $extra = []): array => array_merge([
    'cas_number'        => $cas,
    'chemical_name'     => $name,
    'concentration_pct' => $pct,
    'concentration_min' => $min ?? $pct,
    'concentration_max' => $max ?? $pct,
    'is_trade_secret'   => false,
], $extra);

$hzEmpty = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'exposure_limits' => [], 'hazardous_cas' => []];

$langs = ['en', 'es', 'fr', 'de'];
$gens = [];
foreach ($langs as $lang) {
    $tr = new \SDS\Services\TranslationService($lang);
    $g  = new \SDS\Services\SDSGenerator($tr);
    $m  = new ReflectionMethod($g, 'section3');
    $m->setAccessible(true);
    $gens[$lang] = [$tr, $g, $m];
}
[$t, $gen, $s3m] = $gens['en'];
$s3 = static fn(array $comp, array $hz, string $sm = 'mixture'): array => $s3m->invoke($gen, $comp, $hz, [], $sm);
$byCas = static function (array $section): array {
    $out = [];
    foreach ($section['components'] as $c) {
        $out[$c['cas_number']] = $c;
    }
    return $out;
};

// ---------------------------------------------------------------------
echo "a. Two CAS in the same class keep their H-code (#37)\n";
$skin = GHSHazardClass::SKIN_CORROSION_IRRITATION;
$entriesA = [
    $entry('13048-33-4', 'HDDA', 20.0, $skin, 'Cat 2', 'Category 2', 10.0),
    $entry('42978-66-5', 'TPGDA', 15.0, $skin, 'Cat 2', 'Category 2', 10.0),
];
$hzA = $engineResult($entriesA, ['13048-33-4', '42978-66-5']);
$secA = $byCas($s3([$row('13048-33-4', 'HDDA', 20.0), $row('42978-66-5', 'TPGDA', 15.0)], $hzA));
check(($secA['13048-33-4']['h_codes'] ?? null) === ['H315'], 'HDDA row H315', $secA['13048-33-4']['h_codes'] ?? null);
check(($secA['42978-66-5']['h_codes'] ?? null) === ['H315'], 'TPGDA row H315 (was blank after consolidation)', $secA['42978-66-5']['h_codes'] ?? null);
$consolidatedOnly = TransportClassifier::casHCodeMap(['hazard_classes' => $hzA['hazard_classes']]);
check(count($consolidatedOnly) === 1, 'contrast: consolidated hazard_classes alone credit only one CAS', $consolidatedOnly);

// ---------------------------------------------------------------------
echo "b. Inhalation-only CAS keep only the final attribution\n";
$carc = GHSHazardClass::CARCINOGENICITY;
$hzB = $engineResult([$entry('1333-86-4', 'Carbon black', 5.0, $carc, 'Cat 2', 'Category 2', 1.0)], ['1333-86-4']);
check(($hzB['cas_h_codes']['1333-86-4'] ?? null) === ['H351'], 'engine map has carbon black H351', $hzB['cas_h_codes']);
$hzB['hazard_classes'] = [];   // wet mixture: the carcinogen entry was stripped after the engine
$hzB['h_statements'] = [];
$secB = $s3([$row('1333-86-4', 'Carbon black', 5.0)], $hzB);
check($secB['components'] === [], 'wet mixture: carbon black not listed (no OEL, no final attribution)', $secB['components']);
$hzB['hazard_classes'] = [['class' => 'Carcinogenicity', 'category' => 'Category 2', 'h_codes' => ['H351'], 'cas' => '1333-86-4']];
$secB2 = $byCas($s3([$row('1333-86-4', 'Carbon black', 5.0)], $hzB));
check(($secB2['1333-86-4']['h_codes'] ?? null) === ['H351'], 'powder: carbon black listed with H351', $secB2['1333-86-4']['h_codes'] ?? null);

// ---------------------------------------------------------------------
echo "c. Nothing listed but classified -> classified note (all languages)\n";
$hzC = [
    'hazard_classes' => [[
        'class' => GHSHazardClass::displayName(GHSHazardClass::AQUATIC_CHRONIC), 'category' => 'Category 3',
        'canonical' => GHSHazardClass::AQUATIC_CHRONIC, 'category_canonical' => 'Cat 3',
        'cas' => 'MIXTURE', 'h_codes' => ['H412'], 'contributors' => ['55965-84-9'],
    ]],
    'h_statements' => [['code' => 'H412', 'text' => '']],
    'p_statements' => [], 'pictograms' => [], 'signal_word' => null, 'exposure_limits' => [], 'hazardous_cas' => [],
];
$compC = [$row('55965-84-9', 'Isothiazolinone biocide', 0.05)];
foreach ($langs as $lang) {
    [$tl, $gl, $ml] = $gens[$lang];
    $sec = $ml->invoke($gl, $compC, $hzC, [], 'mixture');
    check($sec['components'] === [], "{$lang}: components empty");
    check(($sec['mixture_notes'] ?? null) === true, "{$lang}: mixture_notes true");
    check(($sec['empty_note'] ?? null) === $tl->get('section3.classified_no_ingredients_note'), "{$lang}: empty_note = classified note", $sec['empty_note'] ?? null);
    check(($sec['empty_note'] ?? null) !== $tl->get('labels.no_hazardous_note'), "{$lang}: not the 'no hazardous ingredients' note");
}

// ---------------------------------------------------------------------
echo "d. Nothing listed and unclassified -> no_hazardous_note\n";
$secD = $s3([$row('7732-18-5', 'Water', 60.0)], $hzEmpty);
check($secD['components'] === [] && ($secD['empty_note'] ?? null) === $t->get('labels.no_hazardous_note'), 'unclassified: labels.no_hazardous_note', $secD['empty_note'] ?? null);

// ---------------------------------------------------------------------
echo "e. Substance identity row (#36(3))\n";
$compE = [$row('57-55-6', 'Propylene glycol', 99.5, 99.0, 100.0), $row('7732-18-5', 'Water', 0.5)];
$secE = $s3($compE, $hzEmpty, 'substance');
check(count($secE['components']) === 1, 'exactly one row', $secE['components']);
$idRow = $secE['components'][0] ?? [];
check(($idRow['cas_number'] ?? null) === '57-55-6', 'identity row 57-55-6');
check(($idRow['concentration_range'] ?? null) === '80 - 100%', 'identity band 80 - 100%', $idRow['concentration_range'] ?? null);
check(($idRow['h_codes'] ?? null) === [], 'identity h_codes empty');
check(($secE['mixture_notes'] ?? null) === false, 'mixture_notes false');
check(array_key_exists('empty_note', $secE) && $secE['empty_note'] === null, 'empty_note null');
check(($secE['identity_missing'] ?? null) === false, 'identity_missing false');
$secEm = $s3($compE, $hzEmpty, 'mixture');
check($secEm['components'] === [] && ($secEm['empty_note'] ?? null) === $t->get('labels.no_hazardous_note'), 'same input as Mixture: no rows, no_hazardous_note');

// ---------------------------------------------------------------------
echo "f. Substance identity = TRADE_SECRET bucket stays masked\n";
$compF = [$row('TRADE_SECRET', 'Trade Secret', 100.0, 100.0, 100.0, ['is_trade_secret' => true, 'trade_secret_description' => 'Trade Secret'])];
foreach ($langs as $lang) {
    [$tl, $gl, $ml] = $gens[$lang];
    $sec = $ml->invoke($gl, $compF, $hzEmpty, [], 'substance');
    check(count($sec['components']) === 1 && ($sec['components'][0]['cas_number'] ?? null) === $tl->get('labels.trade_secret_cas'), "{$lang}: one masked row", $sec['components']);
}

// ---------------------------------------------------------------------
echo "g. Substance with no CAS constituent\n";
$secG = $s3([], $hzEmpty, 'substance');
check(($secG['identity_missing'] ?? null) === true && $secG['components'] === [], 'identity_missing true, no rows');

// ---------------------------------------------------------------------
echo "h. Source guards\n";
$genSrc = (string) file_get_contents($basePath . '/src/Services/SDSGenerator.php');
check(substr_count($genSrc, "unset(\$sds['sections'][3]['identity_missing']);") === 2, 'generator unsets identity_missing on both assembly paths');
$pdfSrc = (string) file_get_contents($basePath . '/src/Services/PDFService.php');
check(str_contains($pdfSrc, "\$s['mixture_notes'] ?? true") && str_contains($pdfSrc, "\$s['empty_note'] ?? \$this->label('no_hazardous_note')"), 'PDF reads mixture_notes / empty_note');
$prevSrc = (string) file_get_contents($basePath . '/src/Views/sds/preview.php');
check(str_contains($prevSrc, "\$section['mixture_notes'] ?? true") && str_contains($prevSrc, "\$section['empty_note'] ?? \$l('no_hazardous_note')"), 'HTML preview reads mixture_notes / empty_note');

// ---------------------------------------------------------------------
echo "i. Translations\n";
$en = $gens['en'][0]->get('section3.classified_no_ingredients_note');
check($en !== '' && $en !== 'section3.classified_no_ingredients_note', 'EN note present');
foreach (['es', 'fr', 'de'] as $lang) {
    $v = $gens[$lang][0]->get('section3.classified_no_ingredients_note');
    check($v !== '' && $v !== 'section3.classified_no_ingredients_note' && $v !== $en, "{$lang}: translated", $v);
}

$inh->setValue(null, $inhSaved);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
