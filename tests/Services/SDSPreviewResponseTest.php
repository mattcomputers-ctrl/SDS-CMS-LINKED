#!/usr/bin/env php
<?php
/**
 * Audit #38 — SDSPreviewResponse (no DB).
 *
 * Verifies the preview-mode switch (pdf=1 wins over html=1; "" and "0" are
 * absent), the pdf/html URL rewriting that keeps every other query parameter
 * (lang, alias_id, the ad-hoc private-label form fields) and the inline
 * filename shape SDS_{code}_{draft|vN}_{lang}.pdf. send() streams headers and
 * exits, so it is not unit-tested here.
 *
 * Run:
 *   php tests/Services/SDSPreviewResponseTest.php
 *
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

// Bootstrap App static properties without a DB connection (same as
// ManufacturerVariantDisclaimerTest.php).
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

use SDS\Services\SDSPreviewResponse as R;

$failures = 0;
$assert = function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

// --- mode() -----------------------------------------------------------------
$assert(R::mode([]) === 'page', 'mode: no switch -> page');
$assert(R::mode(['lang' => 'es']) === 'page', 'mode: unrelated params -> page');
$assert(R::mode(['html' => '1']) === 'html', 'mode: html=1 -> html');
$assert(R::mode(['pdf' => '1']) === 'pdf', 'mode: pdf=1 -> pdf');
$assert(R::mode(['pdf' => '1', 'html' => '1']) === 'pdf', 'mode: pdf wins over html');
$assert(R::mode(['pdf' => '0']) === 'page', 'mode: pdf=0 counts as absent');
$assert(R::mode(['pdf' => '']) === 'page', 'mode: pdf= (empty) counts as absent');
$assert(R::mode(['pdf' => ['1']]) === 'page', 'mode: non-scalar pdf counts as absent');

// --- pdfUrl() / htmlUrl() -----------------------------------------------------
$assert(R::pdfUrl('/sds/12/preview') === '/sds/12/preview?pdf=1', 'pdfUrl: bare path gets ?pdf=1');
$assert(
    R::pdfUrl('/sds/12/preview?lang=es&html=1') === '/sds/12/preview?lang=es&pdf=1',
    'pdfUrl: keeps lang, drops html, adds pdf'
);
$assert(
    R::htmlUrl('/private-label/live-preview?item_id=4&lang=fr&pdf=1')
        === '/private-label/live-preview?item_id=4&lang=fr&html=1',
    'htmlUrl: keeps item_id+lang, drops pdf, adds html'
);
$assert(R::htmlUrl('/sds/resale/5/preview?alias_id=9') === '/sds/resale/5/preview?alias_id=9&html=1', 'htmlUrl: resale alias_id preserved');

// Ad-hoc private-label form params survive (compare arrays, not the literal
// string, so '+' vs '%20' encoding cannot break the check).
$u = R::pdfUrl('/private-label/live-preview?finished_good_id=7&manufacturer_id=2&identity_mode=custom&custom_code=ABC%20X&custom_description=&lang=de');
$assert(parse_url($u, PHP_URL_PATH) === '/private-label/live-preview', 'pdfUrl: live-preview path kept');
$q = [];
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
$assert(
    $q === [
        'finished_good_id'   => '7',
        'manufacturer_id'    => '2',
        'identity_mode'      => 'custom',
        'custom_code'        => 'ABC X',
        'custom_description' => '',
        'lang'               => 'de',
        'pdf'                => '1',
    ],
    'pdfUrl: every ad-hoc form parameter survives, pdf=1 appended'
);

// --- filename() ---------------------------------------------------------------
$assert(
    R::filename(['meta' => ['product_code' => 'BK1080-2G', 'language' => 'en']]) === 'SDS_BK1080_draft_en.pdf',
    'filename: pack extension stripped, draft, en'
);
$assert(
    R::filename(['meta' => ['product_code' => 'BK1080', 'language' => 'es', 'sds_version' => 3]]) === 'SDS_BK1080_v3_es.pdf',
    'filename: stamped version -> _v3_'
);
$assert(
    R::filename(['meta' => ['product_code' => 'A/B C', 'language' => 'EN']]) === 'SDS_A_B_C_draft_en.pdf',
    'filename: unsafe characters replaced, language lower-cased'
);
$assert(R::filename(['meta' => []]) === 'SDS_draft_en.pdf', 'filename: empty meta -> SDS_draft_en.pdf');
$assert(R::filename([]) === 'SDS_draft_en.pdf', 'filename: missing meta -> SDS_draft_en.pdf');

echo PHP_EOL . ($failures === 0 ? 'ALL PASSED' : "{$failures} FAILED") . PHP_EOL;
exit($failures > 0 ? 1 : 0);
