#!/usr/bin/env php
<?php
/**
 * PDFService::renderSection3() row height (DB-free): the masked trade-secret
 * CAS label ('SECRETO COMERCIAL', 'SECRET COMMERCIAL', 'GESCHÄFTSGEHEIMNIS')
 * wraps to two lines in the 25 mm CAS column. The row height must measure
 * that column too, or the second line is cut off in the PDF while the HTML
 * preview shows the whole label.
 *
 * Renders one Section 3 row per language into an uncompressed PDF and looks
 * for the second word / line in the page content. Same TCPDF bootstrap as
 * PDFServiceGenerateToFileTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/PDFSection3RowHeightTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

define('K_TCPDF_EXTERNAL_CONFIG', true);
define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
define('PDF_IMAGE_SCALE_RATIO', 1.25);

require_once $basePath . '/vendor/autoload.php';

$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);
$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, ['paths' => ['generated_pdfs' => $basePath . '/storage/temp']]);

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

$svc = new \SDS\Services\PDFService();
$render = new ReflectionMethod($svc, 'renderSection3');
$render->setAccessible(true);
$langP = new ReflectionProperty($svc, 'language');
$langP->setAccessible(true);

/** Raw (uncompressed) PDF bytes for one Section 3 trade-secret row. */
$pdfFor = static function (string $lang, string $cas, string $name) use ($svc, $render, $langP): string {
    $langP->setValue($svc, $lang);
    $pdf = new \SDS\Services\SDSTcpdf('P', 'mm', 'LETTER', true, 'UTF-8');
    $pdf->setCompression(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);
    $render->invoke($svc, $pdf, [
        'mixture_notes' => true,
        'components'    => [[
            'cas_number' => $cas, 'chemical_name' => $name,
            'concentration_range' => '1 - 5%', 'h_codes' => ['H315', 'H319'],
        ]],
    ]);
    return $pdf->Output('', 'S');
};

echo "Section 3 trade-secret CAS label is never clipped\n";
foreach ([
    'es' => ['SECRETO COMERCIAL', 'Secreto comercial', 'COMERCIAL'],
    'fr' => ['SECRET COMMERCIAL', 'Secret commercial', 'COMMERCIAL'],
    'de' => ['GESCHÄFTSGEHEIMNIS', 'Geschäftsgeheimnis', 'HEIMNIS'],
    'en' => ['TRADE SECRET', 'Trade Secret', 'SECRET'],
] as $lang => [$cas, $name, $tail]) {
    $raw = $pdfFor($lang, $cas, $name);
    check(str_contains($raw, $tail), "{$lang}: '{$cas}' printed in full (second line '{$tail}' present)");
}

$src = (string) file_get_contents($basePath . '/src/Services/PDFService.php');
preg_match('/function renderSection3.*?\$rowH = max\((.*?)\);/s', $src, $m);
check(isset($m[1]) && str_contains($m[1], '$w[0], $cas'), 'renderSection3 row height measures the CAS column', $m[1] ?? null);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
