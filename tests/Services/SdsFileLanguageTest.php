#!/usr/bin/env php
<?php
/**
 * Language in SDS PDF file names (2026-10-22) — DB-free.
 *
 * The owner's rule: the language follows the version, nothing for English,
 * "_ESP" for Spanish (and _FRA French, _DEU German), e.g. UVNG009_v3.pdf /
 * UVNG009_v3_ESP.pdf. Checks the helper, the stored name, the archive entry
 * of a file stored before the change (_es), and that every place an SDS PDF
 * is named for a user uses the helper: the three downloads, previews, both
 * export ZIPs, the shipped-SDS ZIP and auto-send attachments.
 *
 * Run: php tests/Services/SdsFileLanguageTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Controllers\SdsArchiveController;
use SDS\Services\PDFService;
use SDS\Services\SDSPreviewResponse;

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
        echo '       actual: ' . json_encode($actual) . "\n";
    }
}

echo "a. The tag\n";
$tags = array_map('sds_language_suffix', ['en' => 'en', 'es' => 'es', 'fr' => 'fr', 'de' => 'de']);
check($tags === ['en' => '', 'es' => '_ESP', 'fr' => '_FRA', 'de' => '_DEU'], 'nothing for English; _ESP, _FRA, _DEU', $tags);
check(sds_language_suffix('EN') === '' && sds_language_suffix(' Es ') === '_ESP' && sds_language_suffix('') === '', 'any case or spacing; blank = English');
check(sds_language_suffix('it') === '_IT' && sds_language_tag('es') === 'ESP' && sds_language_tag('en') === '', 'another language: its code upper-cased; the bare tag');

echo "\nb. Stored files (PDFService)\n";
check(PDFService::versionedBaseName('UVNG009', 3, 'en') === 'UVNG009_v3', 'English: UVNG009_v3');
check(PDFService::versionedBaseName('UVNG009', 3, 'es') === 'UVNG009_v3_ESP', 'Spanish: UVNG009_v3_ESP', PDFService::versionedBaseName('UVNG009', 3, 'es'));
check(PDFService::versionedBaseName('ACME01_PL_Acme', 2, 'de') === 'ACME01_PL_Acme_v2_DEU', 'private label: ..._v2_DEU');

echo "\nc. Previews\n";
check(SDSPreviewResponse::filename(['meta' => ['product_code' => 'UVNG009', 'language' => 'es', 'sds_version' => 3]]) === 'SDS_UVNG009_v3_ESP.pdf', 'SDS_UVNG009_v3_ESP.pdf');
check(SDSPreviewResponse::filename(['meta' => ['product_code' => 'UVNG009', 'language' => 'en']]) === 'SDS_UVNG009_draft.pdf', 'SDS_UVNG009_draft.pdf');

echo "\nd. SDS Archive entries\n";
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_v2_es.pdf', 2, 'es') === 'UVNG009_v2_ESP.pdf', 'a file stored before the change: _es becomes _ESP');
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_v2_fr_2.pdf', 2, 'fr') === 'UVNG009_v2_FRA_2.pdf', '... the clash counter stays after it');
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_v2_ESP.pdf', 2, 'es') === 'UVNG009_v2_ESP.pdf', 'a new file keeps its name');
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_v2.pdf', 2, 'en') === 'UVNG009_v2.pdf', 'English unchanged');
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_SDS_es_20261001_120000.pdf', 2, 'es') === 'UVNG009_v2_ESP.pdf', 'a file stored before 2026-10-08 ({code}_SDS_{lang}_{stamp}) is named from its row');
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_SDS_en_20261001_120000_2.pdf', 1, 'en') === 'UVNG009_v1.pdf', '... English loses its _en (clash counter dropped)');
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_SDS_de_20260501_090000_a1b2c3d4.pdf', 4, 'de') === 'UVNG009_v4_DEU.pdf', '... also the older form with a random tag');
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_SDS_es_20261001_120000.pdf', 2, 'fr') === 'UVNG009_SDS_es_20261001_120000.pdf', '... only when the stored language is the row\'s');
check(SdsArchiveController::archiveEntryName('public/generated-pdfs/UVNG009_v12_es.pdf', 2, 'es') === 'UVNG009_v12_es.pdf', 'only the row\'s own version is matched');

echo "\ne. Every naming site uses the helper\n";
$src = static fn (string $rel): string => (string) file_get_contents($basePath . '/' . $rel);
$sites = [
    'src/Controllers/SDSController.php'        => "sds_language_suffix((string) \$version['language']) . '.pdf';",
    'src/Controllers/LookupController.php'     => "'_v' . \$version['version'] . sds_language_suffix((string) \$version['language']) . '.pdf';",
    'src/Controllers/PrivateLabelController.php' => "sds_language_suffix((string) \$version['language']) . '.pdf';",
    'src/Controllers/ExportController.php'     => "'-SDS' . \$v['version'] . sds_language_suffix((string) \$v['language']) . '.pdf';",
    'scripts/export-worker.php'                => "'-SDS' . \$v['version'] . sds_language_suffix((string) \$v['language']) . '.pdf';",
    'src/Services/SDSAutoSendService.php'      => "'_SDS' . sds_language_suffix((string) \$lang) . '.pdf';",
];
foreach ($sites as $rel => $needle) {
    check(str_contains($src($rel), $needle), basename($rel) . ' names the PDF with the language tag');
}
$report = $src('src/Controllers/ReportController.php');
check(substr_count($report, "\$safeCode . '_SDS' . sds_language_suffix(") === 2, 'ReportController: both shipped-SDS ZIP entries');
check(str_contains($report, "'_' . (sds_language_tag(\$exportLang) ?: 'EN')") && str_contains($src('scripts/export-worker.php'), "(sds_language_tag(\$language) ?: 'EN')"),
    'ZIP names: _ESP etc., EN kept so an English-only ZIP is told apart');
check(str_contains($src('src/Controllers/SdsArchiveController.php'), 'self::archiveEntryName((string) $v[\'pdf_path\'], (int) $v[\'version\'], (string) $v[\'language\'])'), 'the archive names its entries');
$old = [];
foreach (['src/Controllers/SDSController.php', 'src/Controllers/LookupController.php', 'src/Controllers/PrivateLabelController.php', 'src/Controllers/ExportController.php',
          'scripts/export-worker.php', 'src/Controllers/ReportController.php', 'src/Services/SDSAutoSendService.php', 'src/Services/SDSPreviewResponse.php', 'src/Services/PDFService.php'] as $rel) {
    if (preg_match('/[\'"]_[\'"] \. strtoupper\(\$(v\[\'language\'\]|lang|exportLang|language)\)|[\'"]_[\'"] \. \$version\[\'language\'\]|sanitize_filename\(\$lang\)/', $src($rel))) {
        $old[] = $rel;
    }
}
check($old === [], 'no site builds the old _es / _ES suffix any more', $old);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
