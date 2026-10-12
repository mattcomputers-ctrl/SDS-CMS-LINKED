#!/usr/bin/env php
<?php
/**
 * A12(c) Combustible dust (owner decisions 2026-10-12), DB-free.
 *
 * raw_materials.combustible_dust (not_assessed | yes | no, migration 062):
 *   - SDSGenerator::combustibleDustStatus(): applies only to a Powder/Solid
 *     product; lists raws marked Yes and Powder/Solid raws not assessed;
 *   - SDSGenerator::applyCombustibleDust(): OSHA Combustible Dust class
 *     (no H-code, no pictogram), signal word Warning, skipped in replace mode,
 *     idempotent; grouped under Physical hazards;
 *   - Section 2 localises class + statement (EN/ES/FR/DE); Section 5 unsuitable
 *     media + specific hazards and Section 7 handling get the dust fragments;
 *     Section 15 OSHA status follows;
 *   - the PDF renderer prints "class: statement" for the H-code-less entry;
 *   - SDSReadinessService::combustibleDustNotAssessedWarning() (warning only);
 *   - RawMaterial::normalizeCombustibleDust() / combustibleDustChangeIsMetadataOnly()
 *     (bump rule: only a change to or from 'yes' is sheet content);
 *   - source parity (PDF / preview, three assembly paths, enriched lines);
 *   - the 5 translation keys in all four languages.
 *
 * Run:
 *   php tests/Services/CombustibleDustTest.php
 *
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

// TCPDF: throw instead of die() (same defines as PDFServiceGenerateToFileTest.php).
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
$cfg->setValue(null, ['paths' => []]);

use SDS\Models\RawMaterial;
use SDS\Services\GHSStatements;
use SDS\Services\HazardEngine;
use SDS\Services\SDSGenerator;
use SDS\Services\SDSReadinessService;
use SDS\Services\TranslationService;

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

$line = static fn (int $id, string $code, float $pct, string $state, ?string $dust): array => array_filter([
    'raw_material_id'  => $id,
    'internal_code'    => $code,
    'pct'              => $pct,
    'physical_state'   => $state,
    'combustible_dust' => $dust,
], static fn ($v) => $v !== null);
$calcWith = static fn (array $lines): array => ['formula_props' => ['enriched_lines' => $lines], 'composition' => []];

$hz0 = [
    'signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [],
    'p_statements' => [], 'exposure_limits' => [], 'trace' => [],
];

// ---------------------------------------------------------------------
echo "a. combustibleDustStatus\n";
$pig = $line(1, 'RM-PIG-01', 40.0, 'Powder', 'yes');
$st = SDSGenerator::combustibleDustStatus(['physical_state' => 'Powder'], $calcWith([$pig]));
check($st['applies'] === true && $st['yes'] === ['RM-PIG-01'] && $st['not_assessed'] === [], 'Powder product + Yes raw -> applies, yes [RM-PIG-01]', $st);
$st = SDSGenerator::combustibleDustStatus(['physical_state' => 'Liquid'], $calcWith([$pig]));
check($st['applies'] === false && $st['yes'] === [] && $st['not_assessed'] === [], 'Liquid product -> applies false, both lists empty', $st);
$st = SDSGenerator::combustibleDustStatus(['physical_state' => 'Solid'], $calcWith([
    $line(2, 'RM-RES-07', 30.0, 'Solid', 'not_assessed'),
    $line(3, 'RM-SOLV', 50.0, 'Liquid', 'not_assessed'),
    $line(4, 'RM-FIL', 20.0, 'Powder', 'no'),
]));
check($st['not_assessed'] === ['RM-RES-07'] && $st['yes'] === [], 'Solid product: only the Solid not-assessed raw is listed', $st);
$st = SDSGenerator::combustibleDustStatus(['physical_state' => 'Powder'], $calcWith([$line(5, 'RM-OLD', 10.0, 'Powder', null)]));
check($st['not_assessed'] === ['RM-OLD'], 'line without the key (pre-062) counts as not assessed', $st);
$st = SDSGenerator::combustibleDustStatus(['physical_state' => 'Powder'], $calcWith([$line(6, 'RM-ZERO', 0.0, 'Powder', 'yes')]));
check($st['yes'] === [] && $st['not_assessed'] === [], 'pct 0 line ignored', $st);
$st = SDSGenerator::combustibleDustStatus(['physical_state' => 'powder'], $calcWith([$pig]));
check($st['applies'] === true && $st['yes'] === ['RM-PIG-01'], "lower-case 'powder' accepted", $st);
$st = SDSGenerator::combustibleDustStatus(['physical_state' => 'Powder'], $calcWith([
    $line(7, 'RM-B', 5.0, 'Powder', 'not_assessed'),
    $line(8, 'RM-A', 5.0, 'Solid', 'not_assessed'),
    $line(7, 'RM-B', 3.0, 'Powder', 'not_assessed'), // same raw via a sub-FG
    $line(9, '1001', 5.0, 'Powder', 'not_assessed'),
]));
check($st['not_assessed'] === ['1001', 'RM-A', 'RM-B'], 'codes sorted and unique; numeric-string code stays a string', $st);
check(is_string($st['not_assessed'][0]), 'numeric code is a string');

// ---------------------------------------------------------------------
echo "b. applyCombustibleDust\n";
$yesStatus = SDSGenerator::combustibleDustStatus(['physical_state' => 'Powder'], $calcWith([$pig]));
$applied = SDSGenerator::applyCombustibleDust($hz0, $yesStatus, null);
check(count($applied['hazard_classes']) === 1, 'exactly one class entry', $applied['hazard_classes']);
$hc = $applied['hazard_classes'][0] ?? [];
check(($hc['canonical'] ?? '') === 'osha_combustible_dust' && ($hc['class'] ?? '') === 'Combustible Dust', 'canonical + class', $hc);
check(($hc['h_codes'] ?? null) === [] && ($hc['cas'] ?? '') === 'MIXTURE', 'no H-codes, cas MIXTURE', $hc);
check($applied['signal_word'] === 'Warning', 'signal word Warning', $applied['signal_word']);
check($applied['pictograms'] === [] && $applied['h_statements'] === [] && $applied['p_statements'] === [], 'pictograms / H / P untouched');
check(in_array('combustible_dust', array_column($applied['trace'], 'step'), true), 'trace step combustible_dust');
$danger = SDSGenerator::applyCombustibleDust(array_merge($hz0, ['signal_word' => 'Danger']), $yesStatus, null);
check($danger['signal_word'] === 'Danger', 'Danger kept', $danger['signal_word']);
check(SDSGenerator::applyCombustibleDust($hz0, $yesStatus, ['mode' => 'replace']) === $hz0, 'replace override -> unchanged');
check(count(SDSGenerator::applyCombustibleDust($hz0, $yesStatus, ['mode' => 'additive'])['hazard_classes']) === 1, 'additive override -> applied');
check(count(SDSGenerator::applyCombustibleDust($applied, $yesStatus, null)['hazard_classes']) === 1, 'idempotent');
check(SDSGenerator::applyCombustibleDust($hz0, array_merge($yesStatus, ['yes' => []]), null) === $hz0, 'no Yes raw -> unchanged');
check(SDSGenerator::applyCombustibleDust($hz0, array_merge($yesStatus, ['applies' => false]), null) === $hz0, 'not applicable -> unchanged');

// ---------------------------------------------------------------------
echo "c. grouping\n";
$groups = HazardEngine::groupByHazardType($applied['hazard_classes']);
check(count($groups['physical']) === 1 && $groups['health'] === [], 'Combustible Dust under Physical hazards', $groups);

// ---------------------------------------------------------------------
echo "d. Section 2 (EN/ES/FR/DE)\n";
$s2payload = [];
$enStatement = (new TranslationService('en'))->get('section2.combustible_dust_statement');
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $t   = new TranslationService($lang);
    $gen = new SDSGenerator($t);
    $m   = new ReflectionMethod($gen, 'section2');
    $m->setAccessible(true);
    $hzL = $lang === 'en' ? $applied : GHSStatements::translateHazardResult($applied, $lang);
    $s2  = $m->invoke($gen, $hzL, []);
    $s2payload[$lang] = $s2;
    $c   = $s2['hazard_classes'][0] ?? [];
    check($s2['is_classified'] === true, "{$lang}: is_classified");
    check(($c['class_translated'] ?? '') === $t->get('section2.combustible_dust_class'), "{$lang}: class_translated", $c);
    check(($c['statement'] ?? '') === $t->get('section2.combustible_dust_statement'), "{$lang}: statement", $c);
    check(($c['category_translated'] ?? null) === '', "{$lang}: category_translated empty", $c);
    if ($lang !== 'en') {
        check(($c['statement'] ?? '') !== $enStatement, "{$lang}: statement differs from EN");
    }
}
check($s2payload['en']['signal_word'] === 'Warning', 'en signal word Warning', $s2payload['en']['signal_word']);
check($s2payload['fr']['signal_word'] === 'Attention', 'fr signal word Attention', $s2payload['fr']['signal_word']);

// ---------------------------------------------------------------------
echo "e. Section 5\n";
$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$s5  = new ReflectionMethod($gen, 'section5');
$s5->setAccessible(true);
$calc5 = ['formula_props' => [], 'composition' => []];
$r = $s5->invoke($gen, $calc5, $applied, []);
check($r['unsuitable_media'] === $t->get('section5.unsuitable_media') . ' ' . $t->get('section5.unsuitable_combustible_dust'), 'unsuitable media + dust sentence', $r['unsuitable_media']);
check(str_ends_with($r['specific_hazards'], $t->get('section5.specific_hazards_combustible_dust')), 'specific hazards ends with dust explosion sentence', $r['specific_hazards']);
$r0 = $s5->invoke($gen, $calc5, $hz0, []);
check(!str_contains($r0['unsuitable_media'], $t->get('section5.unsuitable_combustible_dust'))
    && !str_contains($r0['specific_hazards'], $t->get('section5.specific_hazards_combustible_dust')), 'no class -> no dust fragments', $r0);
$ro = $s5->invoke($gen, $calc5, $applied, [5 => ['specific_hazards' => 'X']]);
check($ro['specific_hazards'] === 'X', 'Section 5 override wins', $ro['specific_hazards']);

// ---------------------------------------------------------------------
echo "f. Section 7\n";
$s7 = new ReflectionMethod($gen, 'section7');
$s7->setAccessible(true);
$r7 = $s7->invoke($gen, $applied, []);
$pIgn  = strpos($r7['handling'], $t->get('section7.handling_ignition'));
$pDust = strpos($r7['handling'], $t->get('section7.handling_combustible_dust'));
check($pIgn !== false && $pDust !== false && $pIgn < $pDust, 'handling: ignition then dust fragment', $r7['handling']);
check(str_contains($r7['storage'], $t->get('section7.storage_ignition')), 'storage: ignition sentence', $r7['storage']);
$r70 = $s7->invoke($gen, $hz0, []);
check(!str_contains($r70['handling'], $t->get('section7.handling_ignition'))
    && !str_contains($r70['handling'], $t->get('section7.handling_combustible_dust'))
    && !str_contains($r70['storage'], $t->get('section7.storage_ignition')), 'no class -> no fire / dust wording', $r70);
check(substr_count($r7['handling'], 'Ground and bond') === 1, 'dust only: grounding sentence printed once', $r7['handling']);
// Flammable solid (H228) + Combustible Dust: one grounding sentence, from the flammable fragment.
$appliedFlam = $applied;
$appliedFlam['h_statements'][] = ['code' => 'H228', 'text' => 'Flammable solid'];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tL = new TranslationService($lang);
    $gL = new SDSGenerator($tL);
    $hL = $s7->invoke($gL, $appliedFlam, [])['handling'];
    check(str_contains($hL, $tL->get('section7.handling_flammable_static'))
        && str_contains($hL, $tL->get('section7.handling_combustible_dust_no_ground'))
        && !str_contains($hL, $tL->get('section7.handling_combustible_dust')), "{$lang}: H228 + dust -> flammable static + dust fragment without grounding", $hL);
}
$hEn = $s7->invoke($gen, $appliedFlam, [])['handling'];
check(substr_count($hEn, 'Ground and bond') === 1, 'en H228 + dust: "Ground and bond" exactly once', $hEn);

// ---------------------------------------------------------------------
echo "g. Section 15 OSHA status\n";
$osha = new ReflectionMethod($gen, 'resolveOshaStatus');
$osha->setAccessible(true);
check($osha->invoke($gen, $applied, []) === $t->get('section15.osha_status'), 'classified status');
check($osha->invoke($gen, $hz0, []) === $t->get('section15.osha_status_not_classified'), 'unclassified status without the class');

// ---------------------------------------------------------------------
echo "h. Readiness / preview warning\n";
$w = SDSReadinessService::combustibleDustNotAssessedWarning(['state' => 'Solid', 'applies' => true, 'yes' => [], 'not_assessed' => ['RM-B', 'RM-A']]);
check(is_string($w) && str_contains($w, 'RM-A, RM-B') && str_contains($w, 'Solid') && str_contains($w, 'Publishing is not blocked.')
    && str_contains($w, 'powder/solid raw materials') && str_contains($w, 'on each'), 'plural warning', $w);
$w1 = SDSReadinessService::combustibleDustNotAssessedWarning(['state' => 'Powder', 'applies' => true, 'yes' => [], 'not_assessed' => ['RM-RES-07']]);
check(is_string($w1) && str_contains($w1, 'a powder/solid raw material whose') && str_contains($w1, 'on it ('), 'singular warning', $w1);
check(SDSReadinessService::combustibleDustNotAssessedWarning(['state' => 'Solid', 'applies' => true, 'yes' => ['X'], 'not_assessed' => ['RM-A']]) === null, 'null when a raw is Yes');
check(SDSReadinessService::combustibleDustNotAssessedWarning(['state' => 'Solid', 'applies' => true, 'yes' => [], 'not_assessed' => []]) === null, 'null when nothing not assessed');
check(SDSReadinessService::combustibleDustNotAssessedWarning(['state' => 'Liquid', 'applies' => false, 'yes' => [], 'not_assessed' => ['RM-A']]) === null, 'null when not applicable');

// ---------------------------------------------------------------------
echo "i. RawMaterial normalise / bump rule\n";
check(RawMaterial::normalizeCombustibleDust(null) === 'not_assessed' && RawMaterial::normalizeCombustibleDust('') === 'not_assessed', 'blank -> not_assessed');
check(RawMaterial::normalizeCombustibleDust(' YES ') === 'yes', "' YES ' -> yes");
$threw = false;
try {
    RawMaterial::normalizeCombustibleDust('maybe');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check($threw, "'maybe' rejected");
check(RawMaterial::combustibleDustChangeIsMetadataOnly('not_assessed', 'no') === true, 'not_assessed -> no: metadata only');
check(RawMaterial::combustibleDustChangeIsMetadataOnly('no', 'not_assessed') === true, 'no -> not_assessed: metadata only');
check(RawMaterial::combustibleDustChangeIsMetadataOnly('not_assessed', 'yes') === false, 'not_assessed -> yes: content (bump)');
check(RawMaterial::combustibleDustChangeIsMetadataOnly('yes', 'no') === false, 'yes -> no: content (bump)');
check(RawMaterial::combustibleDustChangeIsMetadataOnly('no', 'no') === false, 'no change -> false');
check(RawMaterial::combustibleDustChangeIsMetadataOnly('yes', null) === false, 'not posted -> false');
check(RawMaterial::combustibleDustChangeIsMetadataOnly(null, 'no') === true, 'pre-062 row (null) -> no: metadata only');

// ---------------------------------------------------------------------
echo "j. PDF Section 2 render\n";
try {
    $svc = new \SDS\Services\PDFService();
    $pdf = new \TCPDF();
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->setCompression(false);
    $pdf->AddPage();
    $rs2 = new ReflectionMethod($svc, 'renderSection2');
    $rs2->setAccessible(true);
    $rs2->invoke($svc, $pdf, $s2payload['en']);
    $raw = $pdf->Output('', 'S');
    check(str_contains($raw, 'Combustible Dust: May form combustible dust concentrations in air.'), 'PDF prints "Combustible Dust: May form combustible dust concentrations in air."');
    check(!str_contains($raw, 'Not a hazardous substance or mixture.'), 'PDF: no "Not a hazardous substance or mixture."');
} catch (\Throwable $e) {
    check(false, 'PDF render threw: ' . get_class($e) . ': ' . $e->getMessage());
}

// ---------------------------------------------------------------------
echo "k. Source parity\n";
$src = static fn (string $rel): string => (string) file_get_contents($basePath . '/' . $rel);
check(str_contains($src('src/Services/PDFService.php'), "\$hc['statement'] ?? ''"), 'PDFService prints the class statement fallback');
check(str_contains($src('src/Views/sds/preview.php'), "\$hc['statement'] ?? ''"), 'preview.php prints the class statement fallback');
check(str_contains($src('src/Services/FormulaCalcService.php'), "'combustible_dust'"), 'enriched lines carry combustible_dust');
$gsrc = $src('src/Services/SDSGenerator.php');
check(substr_count($gsrc, 'self::applyCombustibleDust(') === 3, 'applyCombustibleDust called in generate, computeBase, resale', substr_count($gsrc, 'self::applyCombustibleDust('));
check(substr_count($gsrc, 'combustibleDustNotAssessedWarning(') === 2, 'operator warning in generate and generateFromBase', substr_count($gsrc, 'combustibleDustNotAssessedWarning('));
check(str_contains($src('src/Services/HazardEngine.php'), "'Combustible Dust'"), 'HazardEngine group order has Combustible Dust');
check(str_contains($src('src/Views/raw-materials/form.php'), 'name="combustible_dust"'), 'raw-material form field');
check(str_contains($src('src/Views/sds-review/index.php'), "combustible_dust_warning"), 'readiness view shows the warning');
check(preg_match('/ADD COLUMN `combustible_dust` ENUM\(\'\'not_assessed\'\',\'\'yes\'\',\'\'no\'\'\) NOT NULL DEFAULT \'\'not_assessed\'\'/', $src('migrations/062_sds_fixed_text_t4.sql')) === 1, 'migration 062 adds the column');

// ---------------------------------------------------------------------
echo "l. Translation keys\n";
$keys = [
    'section2.combustible_dust_class', 'section2.combustible_dust_statement',
    'section5.unsuitable_combustible_dust', 'section5.specific_hazards_combustible_dust',
    'section7.handling_combustible_dust', 'section7.handling_combustible_dust_no_ground',
];
$files = [];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $files[$lang] = require $basePath . "/templates/translations/{$lang}.php";
}
$get = static function (array $arr, string $key) {
    [$sec, $k] = explode('.', $key, 2);
    return $arr[$sec][$k] ?? null;
};
foreach ($keys as $key) {
    foreach (['en', 'es', 'fr', 'de'] as $lang) {
        $v = $get($files[$lang], $key);
        check(is_string($v) && trim($v) !== '', "{$lang}: {$key} present");
        if ($lang !== 'en') {
            check($v !== $get($files['en'], $key), "{$lang}: {$key} translated");
        }
    }
}
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $v = (string) $get($files[$lang], 'section7.handling_combustible_dust');
    $n = substr_count($v, '. ') + (str_ends_with($v, '.') ? 1 : 0);
    check($n === 4, "{$lang}: handling_combustible_dust has 4 sentences", $n);
    $ng = (string) $get($files[$lang], 'section7.handling_combustible_dust_no_ground');
    check($ng !== '' && str_starts_with($v, rtrim($ng, '.')) && substr_count($ng, '. ') + 1 === 3, "{$lang}: _no_ground = the first 3 sentences", $ng);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
