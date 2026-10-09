<?php
/**
 * DB-free checks for SDS content audit item #40 (+#39) housekeeping:
 *
 *   - SDSDocumentStrings is the single source of the document-level strings
 *     (title / SECTION prefix / Page x of y / Rev.) and their English
 *     fallbacks; SDSGenerator::getDocumentStrings() translates exactly that
 *     key list in every language.
 *   - SDSDocumentStrings::resolve() falls back for missing AND empty values.
 *   - The dead labels (hazard_statements, health_hazard, revision_note) are
 *     gone from SDSGenerator::getLabels() and from all four language files,
 *     while the live keys they were confused with remain.
 *   - Every key getLabels() lists resolves in all four languages.
 *   - Source-text assertions: sds.voc_calc_mode is gone from the settings UI,
 *     config example and seed; the seed writes only real keys; auto-send
 *     delegates the missing-data gate to SDSReadinessService; the renderers
 *     carry no inline document-string fallbacks.
 *
 * Same Reflection bootstrap as SDSGeneratorSection5Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/Audit40HousekeepingTest.php
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

use SDS\Services\SDSDocumentStrings;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

$languages    = ['en', 'es', 'fr', 'de'];
$expectedKeys = ['title', 'section_prefix', 'page', 'page_of', 'revision_prefix'];
$deadLabels   = ['hazard_statements', 'health_hazard', 'revision_note'];

// ---------------------------------------------------------------------------
echo "\n[1] SDSDocumentStrings::DEFAULTS matches the EN document.* translations\n";
// ---------------------------------------------------------------------------
check(array_keys(SDSDocumentStrings::DEFAULTS) === $expectedKeys, 'DEFAULTS key list', array_keys(SDSDocumentStrings::DEFAULTS));
$en = new TranslationService('en');
foreach (SDSDocumentStrings::DEFAULTS as $key => $fallback) {
    $translated = $en->get('document.' . $key);
    check($translated === $fallback, "DEFAULTS[{$key}] === en document.{$key}", [$fallback, $translated]);
}

// ---------------------------------------------------------------------------
echo "\n[2] SDSDocumentStrings::resolve() fallback semantics\n";
// ---------------------------------------------------------------------------
check(SDSDocumentStrings::resolve([], 'title') === 'SAFETY DATA SHEET', 'missing key -> English default');
check(SDSDocumentStrings::resolve(['title' => ''], 'title') === 'SAFETY DATA SHEET', 'empty string -> English default');
check(SDSDocumentStrings::resolve(['title' => 'HOJA'], 'title') === 'HOJA', 'present value wins');
check(SDSDocumentStrings::resolve([], 'nope') === 'nope', 'unknown key -> key itself');
check(SDSDocumentStrings::resolve(['page' => null], 'page') === 'Page', 'null -> English default');

// ---------------------------------------------------------------------------
echo "\n[3] SDSGenerator::getDocumentStrings() per language\n";
// ---------------------------------------------------------------------------
foreach ($languages as $lang) {
    $gen = new SDSGenerator(new TranslationService($lang));
    $m   = new ReflectionMethod(SDSGenerator::class, 'getDocumentStrings');
    $m->setAccessible(true);
    $doc = $m->invoke($gen);
    check(array_keys($doc) === $expectedKeys, "[{$lang}] key list equals DEFAULTS keys", array_keys($doc));
    foreach ($expectedKeys as $key) {
        $v = $doc[$key] ?? null;
        check(is_string($v) && $v !== '' && $v !== 'document.' . $key, "[{$lang}] document.{$key} translated", $v);
    }
}

// ---------------------------------------------------------------------------
echo "\n[4] SDSGenerator::getLabels() has no dead keys and every key translates\n";
// ---------------------------------------------------------------------------
$translators = [];
foreach ($languages as $lang) {
    $translators[$lang] = new TranslationService($lang);
}
$gen = new SDSGenerator($translators['en']);
$m   = new ReflectionMethod(SDSGenerator::class, 'getLabels');
$m->setAccessible(true);
$labels = $m->invoke($gen);
check(is_array($labels) && count($labels) > 50, 'getLabels() returns a populated map', is_array($labels) ? count($labels) : $labels);
foreach ($deadLabels as $dead) {
    check(!array_key_exists($dead, $labels), "getLabels() no longer lists '{$dead}'");
}
$untranslated = [];
foreach (array_keys($labels) as $key) {
    foreach ($languages as $lang) {
        if ($translators[$lang]->get('labels.' . $key) === 'labels.' . $key) {
            $untranslated[] = "{$lang}:{$key}";
        }
    }
}
check($untranslated === [], 'every getLabels() key resolves in en/es/fr/de', $untranslated);

// ---------------------------------------------------------------------------
echo "\n[5] Translation files: dead labels removed, live keys kept\n";
// ---------------------------------------------------------------------------
foreach ($languages as $lang) {
    $arr = require $basePath . "/templates/translations/{$lang}.php";
    check(is_array($arr['labels'] ?? null), "[{$lang}] labels array present");
    foreach ($deadLabels as $dead) {
        check(!array_key_exists($dead, $arr['labels'] ?? []), "[{$lang}] labels.{$dead} removed");
    }
    check(isset($arr['labels']['health_hazards']), "[{$lang}] labels.health_hazards (plural) kept");
    check(isset($arr['section16']['disclaimer']), "[{$lang}] section16.disclaimer kept");
    check(isset($arr['section2']['other_hazards']), "[{$lang}] section2.other_hazards kept");
}

// ---------------------------------------------------------------------------
echo "\n[6] Source-text assertions\n";
// ---------------------------------------------------------------------------
$src = static function (string $rel) use ($basePath): string {
    $s = @file_get_contents($basePath . '/' . $rel);
    return $s === false ? '' : $s;
};

$settings = $src('src/Views/admin/settings.php');
check($settings !== '', 'settings.php readable');
check(strpos($settings, 'voc_calc_mode') === false, 'settings.php: no voc_calc_mode');
check(strpos($settings, 'name="sds__block_publish_missing"') !== false, 'settings.php: sds__block_publish_missing field');
check(strpos($settings, 'name="uv_acrylate_rule_pack"') !== false, 'settings.php: uv_acrylate_rule_pack field');

$seed = $src('seeds/seed.php');
check($seed !== '', 'seed.php readable');
foreach (["'voc_calc_mode'", "'source_priority'", "'company_name'", "'sara_deminimis_default'", "'sds_block_publish_missing'"] as $needle) {
    check(strpos($seed, $needle) === false, "seed.php: no {$needle}");
}
foreach (["'company.name'", "'sds.block_publish_missing'", "'sds.missing_threshold_pct'", "'uv_acrylate_rule_pack'"] as $needle) {
    check(strpos($seed, $needle) !== false, "seed.php: seeds {$needle}");
}

$configExample = $src('config/config.example.php');
check($configExample !== '', 'config.example.php readable');
check(strpos($configExample, 'voc_calc_mode') === false, 'config.example.php: no voc_calc_mode');

$autoSend = $src('src/Services/SDSAutoSendService.php');
check($autoSend !== '', 'SDSAutoSendService.php readable');
check(strpos($autoSend, 'missing_threshold_pct') === false, 'SDSAutoSendService.php: no missing_threshold_pct');
check(strpos($autoSend, 'SDSReadinessService::missingHazardDataError') !== false, 'SDSAutoSendService.php: delegates to SDSReadinessService::missingHazardDataError');

$fallbacks = ["?? 'SAFETY DATA SHEET'", "?? 'SECTION'", "?? 'Page'", "?? 'of'", "?? 'Rev.'"];
foreach (['src/Services/SDSTcpdf.php', 'src/Services/PDFService.php', 'src/Views/sds/preview.php'] as $rel) {
    $s = $src($rel);
    check($s !== '', "{$rel} readable");
    foreach ($fallbacks as $fb) {
        check(strpos($s, $fb) === false, "{$rel}: no inline fallback {$fb}");
    }
    check(strpos($s, 'SDSDocumentStrings::resolve(') !== false, "{$rel}: uses SDSDocumentStrings::resolve()");
}

$migration = $src('migrations/055_regulatory_lists_overrides.sql');
check(strpos($migration, "'sds.voc_calc_mode', 'voc_calc_mode'") !== false, '055 migration: deletes voc_calc_mode rows');
check(strpos($migration, "'company_name', 'sds_block_publish_missing', 'source_priority', 'sara_deminimis_default'") !== false, '055 migration: deletes legacy seed rows');

// ---------------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
