#!/usr/bin/env php
<?php
/**
 * DB-free checks for SDS data-source findings #62 and #63 (unit T4d):
 *
 *   #62  - SDSDocumentStrings::forLanguage() fills a legacy snapshot's
 *          missing meta.document keys from the sheet language, so the PDF
 *          Title/Subject and banner of an old ES/FR/DE snapshot are not English;
 *        - the ES Section 10 decomposition sentences carry the release clause;
 *        - the FR/DE Section 12 component-table headers wrap inside their
 *          cells (one shared row height, never shorter than the text);
 *        - owner decision Q10: '.' decimals and m/d/Y dates in every language.
 *   #63  - SDSGenerator::section2IsClassified(): a stored is_classified wins;
 *          without it (snapshots before 178cbd0) the stored signal word,
 *          pictograms, hazard classes and H-statements decide;
 *        - a re-rendered legacy snapshot prints no "Not a hazardous substance
 *          or mixture." above its hazards and none of the removed Section 13 /
 *          Section 15 "not required by OSHA HazCom" notes.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/LanguageLegacyRenderTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

// Same TCPDF bootstrap as PDFServiceGenerateToFileTest: throw instead of die().
define('K_TCPDF_EXTERNAL_CONFIG', true);
define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
define('PDF_IMAGE_SCALE_RATIO', 1.25);

require_once $basePath . '/vendor/autoload.php';

// Bootstrap App static properties without a DB connection
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);

$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, [
    'company' => ['name' => 'Config Placeholder Co'],
    'paths'   => ['generated_pdfs' => $basePath . '/storage/temp'],
]);

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

/** Records every MultiCell() call (width, height, text) and renders it unchanged. */
final class T4dRecordingPdf extends \TCPDF
{
    /** @var list<array{0: float, 1: float, 2: string}> */
    public array $multiCells = [];

    public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = null, $y = null, $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false)
    {
        $this->multiCells[] = [(float) $w, (float) $h, (string) $txt];
        return parent::MultiCell($w, $h, $txt, $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, $ishtml, $autopadding, $maxh, $valign, $fitcell);
    }
}

use SDS\Services\GHSHazardClass;
use SDS\Services\PDFService;
use SDS\Services\SDSDocumentStrings;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

// Text operators of every content stream (same helper as tests/smoke_pdf.php).
$pdfText = static function (string $pdf): string {
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $raw) {
            $inflated = @gzuncompress($raw);
            $out .= ($inflated === false ? $raw : $inflated) . "\n";
        }
    }
    return $out;
};
// TCPDF writes Info-dictionary strings as UTF-16BE with a BOM (ASCII input only here).
$utf16 = static fn (string $ascii): string => "\xFE\xFF" . implode('', array_map(static fn (string $c): string => "\0" . $c, str_split($ascii)));

// Real engine output shape for a Section 2 hazard class entry (HazardEngine
// emits displayName(canonical) + canonical constant + 'Cat n').
$skinIrrit = [
    'class'               => GHSHazardClass::displayName(GHSHazardClass::SKIN_CORROSION_IRRITATION),
    'category'            => 'Category 2',
    'canonical'           => GHSHazardClass::SKIN_CORROSION_IRRITATION,
    'category_canonical'  => 'Cat 2',
    'h_codes'             => ['H315'],
    'class_translated'    => GHSHazardClass::displayName(GHSHazardClass::SKIN_CORROSION_IRRITATION),
    'category_translated' => 'Category 2',
];
$h315 = ['code' => 'H315', 'text' => 'Causes skin irritation.'];
$noHazards = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => []];

// ---------------------------------------------------------------------
echo "1. #63 SDSGenerator::section2IsClassified()\n";
check(SDSGenerator::section2IsClassified(['is_classified' => true] + $noHazards) === true, 'stored true wins');
check(SDSGenerator::section2IsClassified(['is_classified' => false] + $noHazards) === false, 'stored false wins');
check(SDSGenerator::section2IsClassified(['signal_word' => 'Warning'] + $noHazards) === true, 'legacy: signal word only -> classified');
check(SDSGenerator::section2IsClassified(['pictograms' => ['GHS07']] + $noHazards) === true, 'legacy: pictogram only -> classified');
check(SDSGenerator::section2IsClassified(['hazard_classes' => [$skinIrrit]] + $noHazards) === true, 'legacy: hazard class only -> classified');
check(SDSGenerator::section2IsClassified(['h_statements' => [$h315]] + $noHazards) === true, 'legacy: H-statement only -> classified');
check(SDSGenerator::section2IsClassified(['p_statements' => [['code' => 'P264', 'text' => 'x']]] + $noHazards) === false, 'legacy: P-statements alone do not classify');
check(SDSGenerator::section2IsClassified($noHazards) === false, 'legacy: nothing -> not classified');
check(SDSGenerator::section2IsClassified([]) === false, 'empty payload -> not classified');

// ---------------------------------------------------------------------
echo "2. #62 SDSDocumentStrings::forLanguage()\n";
foreach (['es', 'fr', 'de'] as $lang) {
    $t   = new TranslationService($lang);
    $doc = SDSDocumentStrings::forLanguage([], $lang);
    foreach (array_keys(SDSDocumentStrings::DEFAULTS) as $key) {
        check(($doc[$key] ?? null) === $t->get('document.' . $key), "{$lang}: legacy document.{$key} from the {$lang} translation", $doc[$key] ?? null);
    }
    check($doc['pdf_subject'] !== SDSDocumentStrings::DEFAULTS['pdf_subject'], "{$lang}: PDF Subject is not the English default", $doc['pdf_subject']);
}
check(SDSDocumentStrings::forLanguage([], 'en') === SDSDocumentStrings::DEFAULTS, 'en: identical to DEFAULTS');
check(SDSDocumentStrings::forLanguage(['title' => 'X'], 'de')['title'] === 'X', 'stored value kept');
check(SDSDocumentStrings::forLanguage(['title' => ''], 'fr')['title'] === (new TranslationService('fr'))->get('document.title'), 'blank stored value filled');
check(SDSDocumentStrings::forLanguage([], 'xx')['pdf_subject'] === 'Safety Data Sheet', 'unsupported language -> EN');
check(SDSDocumentStrings::resolve([], 'pdf_subject') === 'Safety Data Sheet', 'resolve() fallback unchanged');

// ---------------------------------------------------------------------
echo "3. #62 ES Section 10 decomposition sentences carry the release clause\n";
$esFile = require $basePath . '/templates/translations/es.php';
foreach (['decomposition', 'decomposition_nitrogen', 'decomposition_sulfur', 'decomposition_halogen', 'decomposition_multi'] as $k) {
    $v = (string) ($esFile['section10'][$k] ?? '');
    check(str_starts_with($v, 'La descomposición térmica puede liberar ') && str_ends_with($v, ' y otros gases tóxicos.'), "es section10.{$k}", $v);
}
check(str_contains((string) ($esFile['section10']['decomposition_multi'] ?? ''), ':products'), 'es decomposition_multi keeps :products');

// ---------------------------------------------------------------------
echo "4. #62 Section 12 header row wraps (FR/DE) and keeps one height\n";
$s12 = [
    'ecotoxicity'       => 'x',
    'component_aquatic' => [
        ['cas_number' => '15206-55-0', 'chemical_name' => 'Trimethylolpropane triacrylate', 'concentration_range' => '10 - 30%', 'acute' => 'Category 1 (M = 1)', 'chronic' => 'Category 2'],
    ],
    'persistence'       => 'x',
    'bioaccumulation'   => 'x',
    'mobility'          => 'x',
];
$widths = [68, 25, 17, 32, 33];
$keys   = ['chemical_name', 'cas_number', 'el_conc_pct', 'aquatic_acute', 'aquatic_chronic'];
foreach (['en', 'fr', 'de'] as $lang) {
    $svc = new PDFService();
    foreach (['labels' => [], 'language' => $lang, 'document' => []] as $prop => $val) {
        $p = new ReflectionProperty(PDFService::class, $prop);
        $p->setAccessible(true);
        $p->setValue($svc, $val);
    }
    $pdf = new T4dRecordingPdf('P', 'mm', 'LETTER', true, 'UTF-8');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);
    $m = new ReflectionMethod(PDFService::class, 'renderSection12');
    $m->setAccessible(true);
    $m->invoke($svc, $pdf, $s12);

    $headers = array_map(static fn (string $k): string => (string) SDSDocumentStrings::translate($lang, 'labels.' . $k), $keys);
    $calls   = array_values(array_filter($pdf->multiCells, static fn (array $c): bool => in_array($c[2], $headers, true)));
    check(count($calls) === 5, "{$lang}: five header MultiCells", count($calls));
    if (count($calls) !== 5) {
        continue;
    }
    $heights = array_unique(array_map(static fn (array $c): string => number_format($c[1], 4), $calls));
    check(count($heights) === 1, "{$lang}: one shared header height", $heights);
    $pdf->SetFont('helvetica', 'B', 7);
    foreach ($calls as $i => $c) {
        check($c[2] === $headers[$i] && abs($c[0] - $widths[$i]) < 0.001, "{$lang}: header {$i} in its column", $c);
        $need = $pdf->getStringHeight($widths[$i], $headers[$i]);
        check($c[1] + 0.001 >= $need, "{$lang}: header {$i} height fits its text", [$c[1], $need]);
    }
    if ($lang === 'en') {
        check(abs($calls[0][1] - 5.0) < 0.001, 'en: header row stays 5 mm', $calls[0][1]);
    } else {
        check($calls[0][1] > 5.0, "{$lang}: header row wraps (taller than 5 mm)", $calls[0][1]);
    }
}

// ---------------------------------------------------------------------
echo "5. Q10 '.' decimals and m/d/Y dates in every language\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $d = SDSGenerator::stampPublishedVersion(
        ['meta' => ['language' => $lang, 'labels' => ['version' => 'v', 'effective_date' => 'e']], 'sections' => [16 => []]],
        3,
        '2026-10-08'
    );
    check(($d['sections'][16]['effective_date'] ?? null) === '10/08/2026', "{$lang}: effective date m/d/Y", $d['sections'][16]['effective_date'] ?? null);
    $gen = new SDSGenerator(new TranslationService($lang));
    $fc  = new ReflectionMethod($gen, 'formatConcentration');
    $fc->setAccessible(true);
    $band = $fc->invoke($gen, ['concentration_min' => 0.5, 'concentration_max' => 1.2]);
    check($band === '0.5 - 1.5%', "{$lang}: band keeps '.' decimals", $band);
}

// ---------------------------------------------------------------------
echo "6. #62/#63 legacy snapshot re-render (PDF)\n";
$legacy = static function (string $lang, array $s2, array $s13, array $s15): array {
    return [
        'meta' => [
            'finished_good_id'  => 1,
            'product_code'      => 'LEG-001',
            'description'       => 'Legacy Ink',
            'language'          => $lang,
            'company_logo_path' => '',
            // no 'document', no 'labels': a snapshot from before #39/#40
        ],
        'sections' => [
            1  => ['title' => 'Identification', 'product_identifier' => 'LEG-001 — Legacy Ink', 'manufacturer_name' => 'Test Co', 'emergency_phone' => '911'],
            2  => ['title' => 'Hazards'] + $s2,
            13 => ['title' => 'Disposal', 'methods' => 'Dispose per local regulations.'] + $s13,
            15 => [
                'title'       => 'Regulatory',
                'osha_status' => 'Status line.',
                'sara_313'    => ['reportable' => []],
                'prop65'      => ['requires_warning' => false, 'warning_text' => '', 'listed_chemicals' => []],
                'state_regs'  => '',
            ] + $s15,
            16 => ['title' => 'Other', 'version' => 'Draft (not yet published)', 'effective_date' => ''],
        ],
        'warnings'         => [],
        'legal_disclaimer' => 'Disclaimer.',
    ];
};
$oldNote = 'This section is not required by OSHA HazCom but is included per GHS guidelines.';
$notClassifiedEn = (new TranslationService('en'))->get('section2.not_classified');

$classifiedS2 = ['signal_word' => 'Warning', 'signal_word_en' => 'Warning', 'pictograms' => [], 'hazard_classes' => [$skinIrrit], 'h_statements' => [$h315], 'p_statements' => []];
$txt = $pdfText((new PDFService())->generateString($legacy('en', $classifiedS2, ['note' => $oldNote], ['note' => $oldNote])));
check(!str_contains($txt, $notClassifiedEn), 'classified legacy snapshot: no "Not a hazardous substance or mixture."');
check(str_contains($txt, 'Dispose per local regulations.'), 'Section 13 methods still print');
check(!str_contains($txt, 'This section is not required'), 'no removed Section 13 / 15 note');

$txt = $pdfText((new PDFService())->generateString($legacy('en', $noHazards, [], [])));
check(str_contains($txt, $notClassifiedEn), 'unclassified legacy snapshot: sentence still prints');

$txt = $pdfText((new PDFService())->generateString($legacy('en', ['is_classified' => false] + $noHazards, [], ['ghs_note' => '', 'note' => 'Control note T4d.'])));
check(str_contains($txt, $notClassifiedEn), 'current snapshot, is_classified false: sentence prints');
check(str_contains($txt, 'Control note T4d.'), 'Section 15 note with ghs_note present still prints');

$bytes = (new PDFService())->generateString($legacy('es', $classifiedS2, [], []));
check(str_contains($bytes, '/Subject (' . $utf16('Hoja de datos de seguridad') . ')'), 'legacy ES snapshot: PDF Subject in Spanish');
check(str_contains($bytes, '/Title (' . $utf16('HDS - LEG-001') . ')'), 'legacy ES snapshot: PDF Title in Spanish');
check(str_contains($pdfText($bytes), 'HOJA DE DATOS DE SEGURIDAD'), 'legacy ES snapshot: Spanish title banner');

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
