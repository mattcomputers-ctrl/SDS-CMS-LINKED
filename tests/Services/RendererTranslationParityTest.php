<?php
/**
 * DB-free checks for SDS content audit item #37, track T3 (renderer strings
 * and translation-file parity):
 *
 *   - SDSGenerator::getLabels() now carries uv_acrylate_note, h_codes, none
 *     and the preview alt texts, translated in every language.
 *   - SDSDocumentStrings::translate() / PDFService::label() fall back to the
 *     sheet language's translation file for snapshots whose meta.labels lack
 *     a key, instead of an English PHP literal or the ucwords'd key.
 *   - The renderers no longer hard-code 'None', 'H-Codes', 'UV Acrylate
 *     Information', the PDF Title/Subject, or byte-wise strtoupper().
 *   - Translation-file fixes: no English "Chapter"/"summation method" in
 *     ES/FR/DE Section 12, ES uses SGA, localized n.o.s. abbreviations exist,
 *     FR pagination reads "Page x sur y".
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/RendererTranslationParityTest.php
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

use SDS\Services\AbbreviationService;
use SDS\Services\PDFService;
use SDS\Services\SDSDocumentStrings;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

$languages = ['en', 'es', 'fr', 'de'];

// ---------------------------------------------------------------------------
echo "\n[1] getLabels() carries the #37 renderer labels in every language\n";
// ---------------------------------------------------------------------------
$expected = [
    'en' => ['uv_acrylate_note' => 'UV Acrylate Information', 'h_codes' => 'H-Codes', 'none' => 'None'],
    'es' => ['h_codes' => 'Indicaciones H', 'none' => 'Ninguno', 'ghs_classification' => 'Clasificación SGA'],
    'fr' => ['h_codes' => 'Mentions H', 'none' => 'Aucun', 'uv_acrylate_note' => 'Informations sur les acrylates UV'],
    'de' => ['h_codes' => 'H-Sätze', 'none' => 'Keine', 'uv_acrylate_note' => 'UV-Acrylat-Information'],
];
$m = new ReflectionMethod(SDSGenerator::class, 'getLabels');
$m->setAccessible(true);
foreach ($languages as $lang) {
    $labels = $m->invoke(new SDSGenerator(new TranslationService($lang)));
    foreach (['uv_acrylate_note', 'h_codes', 'none', 'company_logo_alt', 'prop65_pictogram_alt'] as $k) {
        $v = $labels[$k] ?? null;
        check(is_string($v) && $v !== '' && $v !== 'labels.' . $k, "[{$lang}] getLabels()['{$k}'] translated", $v);
    }
    foreach ($expected[$lang] as $k => $want) {
        check(($labels[$k] ?? null) === $want, "[{$lang}] labels.{$k} === '{$want}'", $labels[$k] ?? null);
    }
}

// ---------------------------------------------------------------------------
echo "\n[2] meta.document carries the PDF Title/Subject per language\n";
// ---------------------------------------------------------------------------
$docWant = [
    'en' => ['SDS - :code', 'Safety Data Sheet'],
    'es' => ['HDS - :code', 'Hoja de datos de seguridad'],
    'fr' => ['FDS - :code', 'Fiche de données de sécurité'],
    'de' => ['SDB - :code', 'Sicherheitsdatenblatt'],
];
$dm = new ReflectionMethod(SDSGenerator::class, 'getDocumentStrings');
$dm->setAccessible(true);
foreach ($languages as $lang) {
    $doc = $dm->invoke(new SDSGenerator(new TranslationService($lang)));
    check(($doc['pdf_title'] ?? null) === $docWant[$lang][0], "[{$lang}] document.pdf_title", $doc['pdf_title'] ?? null);
    check(($doc['pdf_subject'] ?? null) === $docWant[$lang][1], "[{$lang}] document.pdf_subject", $doc['pdf_subject'] ?? null);
}
check(SDSDocumentStrings::resolve([], 'pdf_title') === 'SDS - :code', 'legacy snapshot: pdf_title English default');
check((new TranslationService('fr'))->get('document.page_of') === 'sur', 'fr document.page_of === "sur"');

// ---------------------------------------------------------------------------
echo "\n[3] Renderer translation fallback for snapshots missing a label\n";
// ---------------------------------------------------------------------------
check(SDSDocumentStrings::translate('es', 'labels.uv_acrylate_note') === 'Información de Acrilato UV', 'translate(es, labels.uv_acrylate_note)');
check(SDSDocumentStrings::translate('de', 'section2.not_classified') === (new TranslationService('de'))->get('section2.not_classified'), 'translate(de, section2.not_classified)');
check(SDSDocumentStrings::translate('fr', 'labels.does_not_exist') === null, 'translate(): unknown key -> null');
check(SDSDocumentStrings::translate('xx', 'labels.none') === 'None', 'translate(): unsupported language -> EN');

$pdf = new PDFService();
$rp  = new ReflectionClass(PDFService::class);
$setProp = static function (string $name, $value) use ($rp, $pdf): void {
    $p = $rp->getProperty($name);
    $p->setAccessible(true);
    $p->setValue($pdf, $value);
};
$label = $rp->getMethod('label');
$label->setAccessible(true);
$text = $rp->getMethod('text');
$text->setAccessible(true);

// Legacy ES snapshot: meta.labels without uv_acrylate_note / none / h_codes.
$setProp('labels', ['email' => 'Correo electrónico']);
$setProp('language', 'es');
check($label->invoke($pdf, 'email') === 'Correo electrónico', 'meta.labels value wins');
check($label->invoke($pdf, 'uv_acrylate_note') === 'Información de Acrilato UV', 'legacy ES: uv_acrylate_note from es.php');
check($label->invoke($pdf, 'none') === 'Ninguno', 'legacy ES: none from es.php');
check($label->invoke($pdf, 'mobility') === (new TranslationService('es'))->get('labels.mobility'), 'legacy ES: mobility from es.php (no English literal)');
check($text->invoke($pdf, 'section16.draft') === (new TranslationService('es'))->get('section16.draft'), 'legacy ES: footer draft text from es.php');
check($label->invoke($pdf, 'no_such_label', 'Fallback') === 'Fallback', 'unknown label -> $default');
$setProp('language', 'en');
check($label->invoke($pdf, 'uv_acrylate_note') === 'UV Acrylate Information', 'legacy EN: identical to the old literal');
check($label->invoke($pdf, 'sara_313_threshold') === 'de minimis threshold', 'legacy EN: sara_313_threshold identical');

// ---------------------------------------------------------------------------
echo "\n[4] Renderer source: no English literals / byte-wise strtoupper\n";
// ---------------------------------------------------------------------------
$src = static function (string $rel) use ($basePath): string {
    $s = @file_get_contents($basePath . '/' . $rel);
    return $s === false ? '' : $s;
};
$pdfSrc = $src('src/Services/PDFService.php');
$preSrc = $src('src/Views/sds/preview.php');
check($pdfSrc !== '' && $preSrc !== '', 'renderer sources readable');
foreach (["'H-Codes'", "'None'", "'UV Acrylate Information'", "'Safety Data Sheet'", "'SDS - '", "'Draft (not yet published)'", "'Not a hazardous substance or mixture.'", "'Mobility in Soil'", "'Listed chemicals'", "'de minimis threshold'"] as $needle) {
    check(strpos($pdfSrc, $needle) === false, "PDFService.php: no {$needle}");
}
check(preg_match('/(?<!mb_)strtoupper\(\$/', $pdfSrc) === 0, 'PDFService.php: no byte-wise strtoupper($...)');
check(preg_match('/(?<!mb_)substr\(\$hapName/', $pdfSrc) === 0, 'PDFService.php: HAP name truncated with mb_substr');
foreach (['>H-Codes<', '>None<', "'UV Acrylate Information'", 'alt="Company Logo"', 'alt="Warning"', "'Not a hazardous substance or mixture.'", "'Listed chemicals'"] as $needle) {
    check(strpos($preSrc, $needle) === false, "preview.php: no {$needle}");
}
// strtoupper($language) on the ASCII language code in the preview banner is fine.
check(preg_match('/(?<!mb_)strtoupper\(\$(section|sectionPrefix)|(?<!mb_)strtoupper\(\\\\SDS/', $preSrc) === 0, 'preview.php: section title / signal word / prefix use mb_strtoupper');

// ---------------------------------------------------------------------------
echo "\n[5] Translation-file fixes (Section 12, SGA/SGH, localized n.o.s.)\n";
// ---------------------------------------------------------------------------
foreach (['es', 'fr', 'de'] as $lang) {
    $t = new TranslationService($lang);
    foreach (['ecotoxicity_classified', 'ecotoxicity_classified_no_table', 'ecotoxicity_not_classified'] as $k) {
        $v = $t->get('section12.' . $k);
        check(strpos($v, 'Chapter') === false && strpos($v, 'summation method') === false, "[{$lang}] section12.{$k} has no English", $v);
    }
}
foreach (['es' => 'SGA', 'fr' => 'SGH'] as $lang => $acr) {
    $t = new TranslationService($lang);
    foreach (['section12.ecotoxicity_classified', 'section12.ecotoxicity_classified_no_table', 'section12.ecotoxicity_not_classified', 'labels.ghs_classification', 'document.ghs_section_note'] as $k) {
        $v = $t->get($k);
        check(strpos($v, 'GHS') === false && strpos($v, $acr) !== false, "[{$lang}] {$k} prints {$acr}, not GHS", $v);
    }
}
$tables = [];
foreach (['es' => ['SGA', 'n.e.p.'], 'fr' => ['SGH', 'n.s.a.'], 'de' => ['GHS', 'n.a.g.']] as $lang => $terms) {
    $tables[$lang] = AbbreviationService::table(new TranslationService($lang));
    foreach ($terms as $term) {
        check(isset($tables[$lang][$term]) && $tables[$lang][$term] !== '', "[{$lang}] abbreviation_table has '{$term}'");
    }
}
$esPsn = 'Líquidos inflamables, n.e.p.';
check(AbbreviationService::filter($tables['es'], $esPsn) === ['n.e.p.' => $tables['es']['n.e.p.']], 'ES PSN "…, n.e.p." matches only the n.e.p. entry', AbbreviationService::filter($tables['es'], $esPsn));
check(isset(AbbreviationService::filter($tables['fr'], 'Liquide inflammable, n.s.a.')['n.s.a.']), 'FR PSN "…, n.s.a." matches the n.s.a. entry');
check(isset(AbbreviationService::filter($tables['de'], 'Entzündbarer flüssiger Stoff, n.a.g.')['n.a.g.']), 'DE PSN "…, n.a.g." matches the n.a.g. entry');

// ---------------------------------------------------------------------------
echo "\n[6] mb_strtoupper on accented banners\n";
// ---------------------------------------------------------------------------
foreach (['es' => 'section2.title', 'fr' => 'section10.title', 'de' => 'section4.title'] as $lang => $key) {
    $banner = mb_strtoupper((new TranslationService($lang))->get($key), 'UTF-8');
    check(preg_match('/\p{Ll}/u', $banner) === 0, "[{$lang}] {$key} banner has no lower-case letters", $banner);
}
check(mb_strtoupper('Atención', 'UTF-8') === 'ATENCIÓN', 'ES signal word -> ATENCIÓN');

// ---------------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
