<?php
/**
 * DB-free checks for SDSGenerator Section 9 HazCom Appendix D lines
 * (#43 / Q8) and the #42 physical-state default warning:
 *
 *   - section9() returns every Appendix D property in printed order;
 *   - lines with no formula data print "Not determined" (translated), or the
 *     per-product override; flammability (solid, gas) on a Liquid prints
 *     "Not applicable";
 *   - labels exist in all four languages and in getLabels();
 *   - TextOverrideService lets the editor store each line;
 *   - PDFService::renderSection9() and the HTML preview list the same keys
 *     in the same order;
 *   - SDSGenerator::physicalStateIsDefault() / PHYSICAL_STATE_DEFAULT_WARNING.
 *
 * Same Reflection bootstrap as SDSGeneratorSection9Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection9AppendixDTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

error_reporting(E_ALL);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

use SDS\Services\SDSGenerator;
use SDS\Services\TextOverrideService;
use SDS\Services\TranslationService;

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

// Any PHP notice/warning is a failure.
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    global $failures;
    $failures++;
    echo "  FAIL PHP error ({$errno}): {$errstr} at {$errfile}:{$errline}\n";
    return true;
});

$s9For = static function (string $lang): array {
    $g = new SDSGenerator(new TranslationService($lang));
    $m = new ReflectionMethod($g, 'section9');
    $m->setAccessible(true);
    return [$g, $m];
};

$calc = [
    'formula'       => ['lines' => []],
    'formula_props' => [
        'flash_point_c'            => null,
        'flash_point_greater_than' => false,
        'boiling_point_c'          => null,
        'solubility_key'           => null,
        'soluble_fraction_pct'     => null,
        'physical_state'           => 'Liquid',
        'odor'                     => '',
        'appearance'               => '',
        'enriched_lines'           => [],
    ],
    'voc'           => ['total_voc_wt_pct' => 3.2, 'mixture_sg' => 1.08, 'voc_lb_per_gal' => 0.29, 'solids_wt_pct' => 40.1],
    'composition'   => [],
    'warnings'      => [],
];

$appD = SDSGenerator::SECTION9_APPENDIX_D_FIELDS;
[$gen, $s9] = $s9For('en');

// ---------------------------------------------------------------------
echo "1. Key order (printed order)\n";
$r = $s9->invoke($gen, ['physical_state' => 'Liquid', 'color' => ''], $calc, []);
$expectedKeys = [
    'title', 'physical_state', 'color', 'appearance', 'odor', 'odor_threshold', 'ph', 'melting_point',
    'boiling_point', 'flash_point', 'evaporation_rate', 'flammability_solid_gas', 'flammability_limits',
    'vapor_pressure', 'vapor_density', 'specific_gravity', 'solubility', 'partition_coefficient',
    'auto_ignition_temp', 'decomposition_temp', 'viscosity', 'voc_lb_per_gal', 'voc_wt_pct', 'solids_wt_pct',
];
check(array_keys($r) === $expectedKeys, 'section9() returns the 24 keys in Appendix D order', array_keys($r));
check(count($appD) === 12, 'SECTION9_APPENDIX_D_FIELDS has 12 keys', $appD);

// ---------------------------------------------------------------------
echo "2. No data -> Not determined; flammability (solid, gas) on a Liquid -> Not applicable\n";
foreach ($appD as $k) {
    $want = $k === 'flammability_solid_gas' ? 'Not applicable' : 'Not determined';
    check($r[$k] === $want, "EN Liquid: {$k} = {$want}", $r[$k]);
}

// ---------------------------------------------------------------------
echo "3. Non-liquid: flammability (solid, gas) -> Not determined\n";
foreach (['Powder', 'Paste', 'Solid'] as $state) {
    $rs = $s9->invoke($gen, ['physical_state' => $state, 'color' => ''], $calc, []);
    check($rs['flammability_solid_gas'] === 'Not determined', "{$state}: flammability_solid_gas = Not determined", $rs['flammability_solid_gas']);
}
// Blank FG state resolved through formula_props ('Liquid') -> Not applicable.
$rs = $s9->invoke($gen, ['physical_state' => '', 'color' => ''], $calc, []);
check($rs['flammability_solid_gas'] === 'Not applicable', 'blank FG state resolved to Liquid -> Not applicable', $rs['flammability_solid_gas']);

// ---------------------------------------------------------------------
echo "4. Per-product overrides\n";
$rs = $s9->invoke($gen, ['physical_state' => 'Liquid', 'color' => ''], $calc, [9 => ['ph' => '8.5 - 9.5', 'viscosity' => '  ']]);
check($rs['ph'] === '8.5 - 9.5', 'ph override prints', $rs['ph']);
check($rs['viscosity'] === 'Not determined', 'whitespace viscosity override -> Not determined', $rs['viscosity']);
$rs = $s9->invoke($gen, ['physical_state' => 'Liquid', 'color' => ''], $calc, [9 => ['flammability_solid_gas' => 'Not flammable']]);
check($rs['flammability_solid_gas'] === 'Not flammable', 'flammability_solid_gas override wins over Not applicable', $rs['flammability_solid_gas']);

// ---------------------------------------------------------------------
echo "5. Translated sentinels (es / fr / de)\n";
foreach (['es', 'fr', 'de'] as $lang) {
    [$g, $m] = $s9For($lang);
    $tl = new TranslationService($lang);
    $nd = $tl->get('labels.not_determined');
    $na = $tl->get('labels.not_applicable');
    $rl = $m->invoke($g, ['physical_state' => 'Liquid', 'color' => ''], $calc, []);
    check($rl['ph'] === $nd && $nd !== 'Not determined' && $nd !== 'labels.not_determined', "{$lang}: ph = {$nd}", $rl['ph']);
    check($rl['flammability_solid_gas'] === $na && $na !== 'Not applicable' && $na !== 'labels.not_applicable', "{$lang}: flammability (Liquid) = {$na}", $rl['flammability_solid_gas']);
}
[$esGen, $esS9] = $s9For('es');
$rl = $esS9->invoke($esGen, ['physical_state' => 'Liquid', 'color' => ''], $calc, []);
check($rl['ph'] === 'No determinado', "es: ph = 'No determinado'", $rl['ph']);
check($rl['flammability_solid_gas'] === 'No aplica', "es: flammability (Liquid) = 'No aplica'", $rl['flammability_solid_gas']);

// ---------------------------------------------------------------------
echo "6. Labels in all four languages (getLabels)\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $g  = new SDSGenerator(new TranslationService($lang));
    $gl = new ReflectionMethod($g, 'getLabels');
    $gl->setAccessible(true);
    $labels = $gl->invoke($g);
    foreach ($appD as $k) {
        $v = $labels[$k] ?? null;
        check(is_string($v) && $v !== '' && $v !== 'labels.' . $k, "{$lang}: getLabels()[{$k}] = " . var_export($v, true), $v);
    }
}

// ---------------------------------------------------------------------
echo "7. TextOverrideService accepts every Appendix D key in Section 9\n";
foreach ($appD as $k) {
    check(TextOverrideService::isEditable(9, $k), "isEditable(9, {$k})");
}

// ---------------------------------------------------------------------
echo "8. Renderer parity (PDF order, preview map)\n";
$pdfSrc = (string) file_get_contents($basePath . '/src/Services/PDFService.php');
$body = preg_match('/function renderSection9\(.*?\n    \}/s', $pdfSrc, $mm) ? $mm[0] : '';
check($body !== '', 'renderSection9 body found');
$prev = -1;
foreach (array_keys($r) as $k) {
    if ($k === 'title') {
        continue;
    }
    $labelKey = $k === 'voc_lb_per_gal' ? 'voc_lb_gal' : $k;
    $pos = strpos($body, "'{$labelKey}'");
    check($pos !== false && $pos > $prev, "PDF lists '{$labelKey}' in order", $pos);
    if ($pos !== false) {
        $prev = $pos;
    }
}
$previewSrc = (string) file_get_contents($basePath . '/src/Views/sds/preview.php');
$map = preg_match('/\$sec9LabelMap = \[(.*?)\];/s', $previewSrc, $pm) ? $pm[1] : '';
check($map !== '', 'preview $sec9LabelMap found');
foreach (array_keys($r) as $k) {
    if ($k === 'title') {
        continue;
    }
    check(strpos($map, "'{$k}'") !== false, "preview map has '{$k}'");
}

// ---------------------------------------------------------------------
echo "9. #42 physical-state default warning\n";
check(SDSGenerator::physicalStateIsDefault([], []) === true, 'nothing at all -> default');
check(SDSGenerator::physicalStateIsDefault(['physical_state' => ' '], ['formula_props' => ['physical_state' => '']]) === true, 'blank FG + blank formula -> default');
check(SDSGenerator::physicalStateIsDefault(['physical_state' => 'Paste'], []) === false, 'FG Paste -> not default');
check(SDSGenerator::physicalStateIsDefault([], ['formula_props' => ['physical_state' => 'Liquid']]) === false, 'formula Liquid -> not default');
check(SDSGenerator::PHYSICAL_STATE_DEFAULT_WARNING !== ''
    && strpos(SDSGenerator::PHYSICAL_STATE_DEFAULT_WARNING, 'Not determined') === false
    && strpos(SDSGenerator::PHYSICAL_STATE_DEFAULT_WARNING, 'Not applicable') === false,
    'warning text non-empty, no sweep sentinels');

// ---------------------------------------------------------------------
echo "10. Nothing 'estimated' / 'assumed' printed\n";
$json = strtolower((string) json_encode($r));
check(strpos($json, 'estimat') === false, 'no "estimat" in the payload', $json);
check(strpos($json, 'assum') === false, 'no "assum" in the payload', $json);

restore_error_handler();

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
