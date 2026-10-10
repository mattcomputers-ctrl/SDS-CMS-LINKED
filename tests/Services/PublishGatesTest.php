<?php
/**
 * DB-free checks for the batch E publish paths and gates
 * (audit #28 / Q11, #54, #55, #58, #68, #69):
 *
 *   - SDSReadinessService: inactive-FG gate, Missing Data Threshold
 *     validation / normalisation, manufacturer name gate, supplier phone and
 *     company supplier warnings.
 *   - SDSGenerator: productIdentifier() (no dangling "CODE — "), the private
 *     label variant's name / phone warnings and a bare "PL" filename tag for
 *     a blank manufacturer name.
 *   - BulkPublishController::privateLabelWorkItem(): one all-language item,
 *     custom code kept whole (never cut at a hyphen).
 *   - SheetContentSettings::changedKeys() and ProductStaleness::sdsColumnsChanged().
 *   - Source assertions: every publish path calls the one missing-data gate,
 *     the worker publishes private-label items through PrivateLabelPublisher,
 *     dead auto-send code is gone, the 059 migration carries the unique index.
 *
 * Same Reflection bootstrap as tests/smoke_pdf.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/PublishGatesTest.php
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
$cfg->setValue(null, [
    'company' => ['name' => 'Config Placeholder Co', 'phone' => '555-0000'],
    'paths'   => [],
]);

use SDS\Controllers\BulkPublishController;
use SDS\Services\ProductStaleness;
use SDS\Services\SDSGenerator;
use SDS\Services\SDSReadinessService;
use SDS\Services\SheetContentSettings;

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

function contains(?string $haystack, string $needle): bool
{
    return $haystack !== null && strpos($haystack, $needle) !== false;
}

// ---------------------------------------------------------------------------
echo "#69 inactive finished good\n";
$inactive = SDSReadinessService::inactiveFinishedGoodError(['product_code' => 'BK1080', 'is_active' => 0]);
check(contains($inactive, 'Publishing blocked') && contains($inactive, 'BK1080') && contains($inactive, 'inactive'), 'inactive FG blocks with its code', $inactive);
check(SDSReadinessService::inactiveFinishedGoodError(['product_code' => 'BK1080', 'is_active' => 1]) === null, 'active FG passes');
check(SDSReadinessService::inactiveFinishedGoodError(['product_code' => 'BK1080']) === null, 'no is_active key passes');
check(contains(SDSReadinessService::inactiveFinishedGoodError(['id' => 9, 'is_active' => '0']), '#9'), 'no code: falls back to #id');

// ---------------------------------------------------------------------------
echo "\n#69 Missing Data Threshold\n";
foreach (['', 'abc', '0', '0.009', '100.01', '-1'] as $bad) {
    check(SDSReadinessService::missingThresholdInputError($bad) !== null, "threshold '{$bad}' refused");
}
foreach (['0.01', '1', '100', ' 2.5 '] as $good) {
    check(SDSReadinessService::missingThresholdInputError($good) === null, "threshold '{$good}' accepted", SDSReadinessService::missingThresholdInputError($good));
}
check(SDSReadinessService::normaliseMissingThreshold(null, 1.0) === 1.0, 'normalise: missing row -> fallback');
check(SDSReadinessService::normaliseMissingThreshold('', 1.0) === 1.0, 'normalise: blank -> fallback');
check(SDSReadinessService::normaliseMissingThreshold('0', 1.0) === 1.0, 'normalise: 0 -> fallback (was 0 %)');
check(SDSReadinessService::normaliseMissingThreshold('2.5', 1.0) === 2.5, 'normalise: 2.5 kept');
check(SDSReadinessService::normaliseMissingThreshold(' 0.5 ', 1.0) === 0.5, 'normalise: trimmed');

// ---------------------------------------------------------------------------
echo "\n#55 manufacturer name / #68 supplier phone\n";
check(SDSReadinessService::manufacturerNameError(['name' => '']) !== null, 'blank name blocks');
check(SDSReadinessService::manufacturerNameError(['name' => '   ']) !== null, 'whitespace name blocks');
check(SDSReadinessService::manufacturerNameError([]) !== null, 'missing name blocks');
check(SDSReadinessService::manufacturerNameError(['name' => 'Acme']) === null, 'named manufacturer passes');
$nameMsg = SDSReadinessService::manufacturerNameError(['id' => 5, 'name' => '']);
check(contains($nameMsg, '#5') && contains($nameMsg, 'Appendix D') && contains($nameMsg, 'Manufacturers > Edit'), 'name message names the record, rule and fix', $nameMsg);

$phoneWarn = SDSReadinessService::manufacturerSupplierPhoneWarning(['name' => 'Acme']);
check(contains($phoneWarn, '"Acme"') && contains($phoneWarn, 'Publishing is not blocked'), 'blank manufacturer phone warns (not a block)', $phoneWarn);
check(SDSReadinessService::manufacturerSupplierPhoneWarning(['name' => 'Acme', 'phone' => '555-0100']) === null, 'manufacturer phone set: no warning');

$cfgCompany = ['name' => 'Config Placeholder Co', 'phone' => '555-0000'];
$w = SDSReadinessService::companySupplierWarnings([], $cfgCompany);
check(count($w) === 2, 'never-saved company name + phone: 2 warnings', $w);
check(contains($w[0] ?? null, 'config.php value "Config Placeholder Co"'), 'name warning names the config.php value', $w[0] ?? null);
check(contains($w[1] ?? null, '"555-0000"'), 'phone warning names the config.php value', $w[1] ?? null);
check(SDSReadinessService::companySupplierWarnings(['company.name' => 'Acme Inks', 'company.phone' => '(555) 010-0000'], $cfgCompany) === [], 'saved name + phone: no warning');
$w = SDSReadinessService::companySupplierWarnings(['company.name' => ' ', 'company.phone' => ''], $cfgCompany);
check(count($w) === 2 && contains($w[0], 'is blank') && contains($w[1], 'is blank'), 'saved blank name + phone: 2 "is blank" warnings', $w);
$w = SDSReadinessService::companySupplierWarnings(['company.name' => 'Acme'], ['phone' => '']);
check(count($w) === 1 && contains($w[0], 'Phone line'), 'no phone anywhere: 1 Phone line warning', $w);

// ---------------------------------------------------------------------------
echo "\n#69 product identifier / #55 private label variant\n";
check(SDSGenerator::productIdentifier('ABC123', 'Blue Ink') === 'ABC123 — Blue Ink', 'code — description');
check(SDSGenerator::productIdentifier('ABC123', '') === 'ABC123', 'blank description: code only');
check(SDSGenerator::productIdentifier('ABC123', '  ') === 'ABC123', 'whitespace description: code only');
check(SDSGenerator::productIdentifier('ABC123', 'abc123') === 'ABC123', 'description repeating the code: code only');

$base = [
    'meta'     => ['product_code' => 'X', 'language' => 'en'],
    'sections' => [1 => ['product_identifier' => 'X']],
    'warnings' => [],
];
$v = SDSGenerator::createManufacturerVariant($base, ['name' => '  ', 'emergency_phone' => '(800) 555-0199', 'phone' => '555-0100']);
check(($v['meta']['filename_tag'] ?? null) === 'PL', 'blank name: bare PL tag (not PL_unnamed_file)', $v['meta']['filename_tag'] ?? null);
check(count($v['warnings']) === 1 && contains($v['warnings'][0], 'has no name'), 'blank name: one "has no name" warning', $v['warnings']);
$v = SDSGenerator::createManufacturerVariant($base, ['name' => 'Acme Printing Inks', 'emergency_phone' => '(800) 555-0199', 'phone' => '555-0100']);
check(($v['meta']['filename_tag'] ?? null) === 'PL_Acme_Printing_Inks', 'named: PL_Acme_Printing_Inks tag', $v['meta']['filename_tag'] ?? null);
check($v['warnings'] === [], 'named with phones: no warnings', $v['warnings']);
$v = SDSGenerator::createManufacturerVariant($base, ['name' => 'Acme Printing Inks', 'emergency_phone' => '(800) 555-0199']);
check(count($v['warnings']) === 1 && contains($v['warnings'][0], 'Phone line'), 'no supplier phone: one Phone line warning', $v['warnings']);
$pl = SDSGenerator::createPrivateLabelVariant($base, 'ACME01', '', ['name' => 'Acme', 'emergency_phone' => 'x', 'phone' => 'y']);
check(($pl['sections'][1]['product_identifier'] ?? null) === 'ACME01', 'private label, blank description: code only', $pl['sections'][1]['product_identifier'] ?? null);

// ---------------------------------------------------------------------------
echo "\n#54 bulk private-label work item\n";
$item = BulkPublishController::privateLabelWorkItem(
    ['id' => 7, 'product_code' => 'BK1080'],
    ['id' => 11, 'manufacturer_id' => 3, 'custom_code' => 'ABC-123', 'alias_id' => null, 'fg_product_code' => 'BK1080', 'fg_description' => 'Black'],
    4,
    null
);
check(($item['type'] ?? null) === 'private_label', 'type private_label');
check(($item['language'] ?? null) === 'all', 'one item covers every language');
check(($item['version'] ?? null) === 0, 'no pre-computed version (assigned in the publisher transaction)');
check(($item['source_fg_version'] ?? null) === 4, 'source_fg_version carried');
check(($item['pl_item_id'] ?? null) === 11 && ($item['manufacturer_id'] ?? null) === 3 && ($item['id'] ?? null) === 7, 'ids carried');
check(($item['pl_code'] ?? null) === 'ABC-123', 'custom code kept whole (not cut at the hyphen)', $item['pl_code'] ?? null);
check(array_key_exists('pl_error', $item) && $item['pl_error'] === null, 'pl_error null');
$item = BulkPublishController::privateLabelWorkItem(['id' => 7, 'product_code' => 'BK1080'], ['id' => 11, 'manufacturer_id' => 3, 'fg_product_code' => 'BK1080'], 0, 'Publish the base SDS for BK1080 first');
check(($item['pl_error'] ?? null) === 'Publish the base SDS for BK1080 first' && ($item['pl_code'] ?? null) === 'BK1080', 'pl_error carried; base identity falls back to the FG code', $item);

// ---------------------------------------------------------------------------
echo "\n#58 sheet-content settings / printed FG columns\n";
check(SheetContentSettings::changedKeys(['company.phone' => '1', 'cms_sync.enabled' => '1'], ['company.phone' => '2', 'cms_sync.enabled' => '0']) === ['company.phone'], 'only printed settings are reported');
check(SheetContentSettings::changedKeys([], ['sds.show_ghs_section_note' => '1']) === [], 'a missing row equals its default');
check(SheetContentSettings::changedKeys(['uv_acrylate_rule_pack' => 'enabled'], ['uv_acrylate_rule_pack' => 'disabled']) === ['uv_acrylate_rule_pack'], 'UV rule pack toggle reported');
check(SheetContentSettings::changedKeys(['sds.legal_disclaimer.es' => ''], ['sds.legal_disclaimer.es' => 'Texto']) === ['sds.legal_disclaimer.es'], 'per-language disclaimer reported');
check(SheetContentSettings::changedKeys(['company.name' => 'A'], ['company.name' => ' A ']) === [], 'whitespace-only change ignored');
check(ProductStaleness::sdsColumnsChanged(['description' => [], 'formula_notes' => [], 'is_active' => []]) === ['description'], 'only printed FG columns count');

// ---------------------------------------------------------------------------
echo "\nSource assertions\n";
$src = static function (string $rel) use ($basePath): string {
    $s = @file_get_contents($basePath . '/' . $rel);
    return $s === false ? '' : $s;
};

$sdsController = $src('src/Controllers/SDSController.php');
check($sdsController !== '', 'SDSController.php readable');
check(strpos($sdsController, 'checkMissingHazardData') === false, 'SDSController.php: private checkMissingHazardData() removed (Q11)');
check(substr_count($sdsController, 'SDSReadinessService::missingHazardDataError') >= 2, 'SDSController.php: FG + resale publish use the one gate');
check(strpos($sdsController, 'inactiveFinishedGoodError') !== false, 'SDSController.php: inactive FG gate');
check(strpos($sdsController, 'ProductStaleness::markFinishedGood') !== false, 'SDSController.php: text edits mark the product stale');
check(strpos($sdsController, 'INSERT INTO resale_sds_text_edits') !== false, 'SDSController.php: resale text edits stamp resale_sds_text_edits (#45 / #58)');
$bulkCtl = $src('src/Controllers/BulkPublishController.php');
check(strpos($bulkCtl, 'FROM resale_sds_text_edits') !== false, 'BulkPublishController.php: resale eligibility reads resale_sds_text_edits');
check(strpos($src('migrations/059_sds_audit_batch_t4.sql'), 'CREATE TABLE IF NOT EXISTS `resale_sds_text_edits`') !== false, '059 creates resale_sds_text_edits');
$autoSendSrc = $src('src/Services/SDSAutoSendService.php');
check(strpos($autoSendSrc, "\$reason = self::clampReason(\$reason);") !== false, 'SDSAutoSendService.php: queue reason clamped to VARCHAR(500)');
check(\SDS\Services\SDSAutoSendService::clampReason(str_repeat('é', 620)) === str_repeat('é', 499) . '…' && mb_strlen(\SDS\Services\SDSAutoSendService::clampReason(str_repeat('x', 700))) === 500
    && \SDS\Services\SDSAutoSendService::clampReason('short') === 'short', 'clampReason: <= 500 characters (multibyte safe), short reasons untouched');

$updates = $src('src/Controllers/SDSUpdateController.php');
check(strpos($updates, 'SDSReadinessService::missingHazardDataError') !== false, 'SDSUpdateController.php: republish uses the missing-data gate');
check(strpos($updates, 'inactiveFinishedGoodError') !== false, 'SDSUpdateController.php: inactive FG gate');
check(strpos($updates, 'fg_updated_at') !== false, 'SDSUpdateController.php: scan reads finished_goods.updated_at');

$worker = $src('scripts/publish-worker.php');
check(strpos($worker, 'SDSReadinessService::missingHazardDataError') !== false, 'publish-worker.php: missing-data gate');
check(strpos($worker, 'publishItemFromBase') !== false, 'publish-worker.php: private labels via PrivateLabelPublisher');
check(preg_match('/\$txKey = \'tx\' \. \$gateKey;.*?foreach \(\$languages as \$txLang\).*?transportNotDeterminedError\(\$txSds\)/s', $worker) === 1,
    'publish-worker.php: FG / resale / alias items gate Section 14 across every language per source (finding #6, no partial-language version)');
check(strpos($worker, "insert('private_label_sds'") === false, 'publish-worker.php: no direct private_label_sds insert');

$publisher = $src('src/Services/PrivateLabelPublisher.php');
check(strpos($publisher, 'public function publishItemFromBase') !== false, 'PrivateLabelPublisher.php: publishItemFromBase()');
check(strpos($publisher, 'manufacturerNameError') !== false, 'PrivateLabelPublisher.php: manufacturer name gate');

$autoSend = $src('src/Services/SDSAutoSendService.php');
check($autoSend !== '', 'SDSAutoSendService.php readable');
foreach (['function autoPublishReady', 'function canAutoPublish', 'function publishSds'] as $dead) {
    check(strpos($autoSend, $dead) === false, "SDSAutoSendService.php: no {$dead}");
}

check(strpos($src('src/Models/Manufacturer.php'), 'Manufacturer name is required') !== false, 'Manufacturer.php: name required on update');

$settings = $src('src/Views/admin/settings.php');
check(strpos($settings, 'min="0.01"') !== false && strpos($settings, 'max="100"') !== false, 'settings.php: threshold limited to 0.01-100');
check(strpos($settings, 'Applies to manual publish, bulk publish and automatic sending.') === false, 'settings.php: no auto-send gate claim');

check(strpos($src('src/Controllers/BulkPublishController.php'), 'fg.updated_at AS fg_updated_at') !== false, 'BulkPublishController.php: eligibility reads finished_goods.updated_at');
check(strpos($src('src/Controllers/FinishedGoodController.php'), 'ProductStaleness::markFinishedGood') !== false, 'FinishedGoodController.php: printed column / override edits mark stale');

$migrations = glob($basePath . '/migrations/059_*.sql') ?: [];
check($migrations !== [], 'migrations/059_*.sql exists');
$mig = $migrations !== [] ? (string) file_get_contents($migrations[0]) : '';
check(strpos($mig, 'uq_plsds_item_lang_ver') !== false, '059: private_label_sds unique index');
$lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $mig) ?: []), static fn (string $l): bool => $l !== ''));
check($lines !== [] && str_starts_with((string) end($lines), 'INSERT IGNORE INTO `schema_migrations`'), '059: ends with INSERT IGNORE INTO `schema_migrations`', end($lines));

// ---------------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
