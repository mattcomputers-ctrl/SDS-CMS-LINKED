<?php
/**
 * DB-free checks for the SDSGenerator Section 9 "Initial boiling point and
 * boiling range" line (SDS content audit item #16):
 *
 *   - formula_props.boiling_point_c (lowest RM boiling point, recursive,
 *     weight-independent) prints as "n °C (n °F)" exactly like the flash point.
 *   - No value / legacy fixture without the key → labels.not_determined.
 *   - A non-blank Section 9 override wins; a blank/whitespace one is ignored.
 *   - The return key order of section9() is the Appendix D order (#43/Q8) (snapshot_json,
 *     preview.php and PDFService::renderSection9 all read it by key).
 *   - labels.boiling_point / labels.not_determined exist in all four languages.
 *   - The flash point line is unaffected.
 *
 * Same Reflection bootstrap as SDSGeneratorSection5Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection9BoilingPointTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

error_reporting(E_ALL);

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

// Any PHP notice/warning (e.g. undefined index on a legacy fixture) is a failure.
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    global $failures;
    $failures++;
    echo "  FAIL PHP error ({$errno}): {$errstr} at {$errfile}:{$errline}\n";
    return true;
});

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);

$method = static function (string $name) use ($gen): ReflectionMethod {
    $m = new ReflectionMethod($gen, $name);
    $m->setAccessible(true);
    return $m;
};

$s9 = $method('section9');

$calc = static fn (?float $bp = null, ?float $fp = null, bool $gt = false): array => [
    'formula'       => ['lines' => []],
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'boiling_point_c' => $bp, 'enriched_lines' => []],
    'voc'           => ['total_voc_wt_pct' => 0, 'mixture_sg' => 1.0, 'voc_lb_per_gal' => 0, 'voc_lb_per_gal_less_water_exempt' => 0, 'solids_wt_pct' => 0, 'solids_vol_pct' => null],
    'composition'   => [], 'warnings' => [],
];
$fg = ['physical_state' => 'Liquid', 'color' => ''];
$nd = $t->get('labels.not_determined');

// ---------------------------------------------------------------------
echo "1. Derived value prints as n °C (n °F)\n";
$r = $s9->invoke($gen, $fg, $calc(78.4), []);
check($r['boiling_point'] === '78.4 °C (173.1 °F)', 'bp 78.4 → 78.4 °C (173.1 °F)', $r['boiling_point']);

// ---------------------------------------------------------------------
echo "2. Whole degrees print without decimals like the flash point\n";
$r = $s9->invoke($gen, $fg, $calc(100.0), []);
check($r['boiling_point'] === '100 °C (212 °F)', 'bp 100.0 → 100 °C (212 °F)', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(-0.5), []);
check($r['boiling_point'] === '-0.5 °C (31.1 °F)', 'negative bp formatted', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(0.0), []);
check($r['boiling_point'] === '0 °C (32 °F)', 'bp 0.0 is printed (not treated as missing)', $r['boiling_point']);

// ---------------------------------------------------------------------
echo "3. No value → Not determined\n";
$r = $s9->invoke($gen, $fg, $calc(null), []);
check($r['boiling_point'] === $nd, 'null bp → labels.not_determined', $r['boiling_point']);
check($nd === 'Not determined', 'en labels.not_determined is "Not determined"', $nd);

// ---------------------------------------------------------------------
echo "4. Legacy formula_props without the key → Not determined, no notice\n";
$legacy = $calc(null);
unset($legacy['formula_props']['boiling_point_c']);
$r = $s9->invoke($gen, $fg, $legacy, []);
check($r['boiling_point'] === $nd, 'missing key → labels.not_determined', $r['boiling_point']);
$legacy2 = $calc(null);
unset($legacy2['formula_props']);
$r = $s9->invoke($gen, $fg, $legacy2, []);
check($r['boiling_point'] === $nd, 'missing formula_props entirely → labels.not_determined', $r['boiling_point']);

// ---------------------------------------------------------------------
echo "5. Override wins\n";
$r = $s9->invoke($gen, $fg, $calc(78.4), [9 => ['boiling_point' => '> 150 °C']]);
check($r['boiling_point'] === '> 150 °C', 'override replaces derived value', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(null), [9 => ['boiling_point' => '> 150 °C']]);
check($r['boiling_point'] === '> 150 °C', 'override prints even without formula data', $r['boiling_point']);

// ---------------------------------------------------------------------
echo "6. Blank / whitespace override is ignored\n";
$r = $s9->invoke($gen, $fg, $calc(78.4), [9 => ['boiling_point' => '  ']]);
check($r['boiling_point'] === '78.4 °C (173.1 °F)', 'whitespace override → derived value', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(78.4), [9 => ['boiling_point' => '']]);
check($r['boiling_point'] === '78.4 °C (173.1 °F)', 'empty override → derived value', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(null), [9 => ['boiling_point' => '']]);
check($r['boiling_point'] === $nd, 'empty override + no data → Not determined', $r['boiling_point']);

// ---------------------------------------------------------------------
echo "7. Type and key order unchanged\n";
$r = $s9->invoke($gen, $fg, $calc(78.4), []);
check(is_string($r['boiling_point']), 'boiling_point is a string', $r['boiling_point']);
// #43 / Q8: Appendix D order (same order as PDFService::renderSection9 and the
// preview $sec9LabelMap); #18(c) dropped voc_less_water_exempt / solids_vol_pct.
$expectedKeys = [
    'title', 'physical_state', 'color', 'appearance', 'odor', 'odor_threshold', 'ph', 'melting_point',
    'boiling_point', 'flash_point', 'evaporation_rate', 'flammability_solid_gas', 'flammability_limits',
    'vapor_pressure', 'vapor_density', 'specific_gravity', 'solubility', 'partition_coefficient',
    'auto_ignition_temp', 'decomposition_temp', 'viscosity', 'voc_lb_per_gal', 'voc_wt_pct', 'solids_wt_pct',
];
check(array_keys($r) === $expectedKeys, 'section9() return key order = Appendix D order (#43/Q8)', array_keys($r));

// ---------------------------------------------------------------------
echo "8. Labels present in all four languages\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tl = new \SDS\Services\TranslationService($lang);
    $v  = $tl->get('labels.boiling_point');
    check(is_string($v) && $v !== '' && $v !== 'labels.boiling_point', "{$lang}: labels.boiling_point resolves", $v);
    $v  = $tl->get('labels.not_determined');
    check(is_string($v) && $v !== '' && $v !== 'labels.not_determined', "{$lang}: labels.not_determined resolves", $v);
}

// ---------------------------------------------------------------------
echo "9. Flash point unaffected\n";
$r = $s9->invoke($gen, $fg, $calc(70.0, 38.0), []);
check($r['flash_point'] === '38 °C (100 °F) (calculated)', 'flash point still 38 °C (100 °F) (calculated)', $r['flash_point']);
check($r['boiling_point'] === '70 °C (158 °F)', 'boiling point alongside flash point', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(78.4, 55.0, true), [9 => ['boiling_point' => 'x']]);
check($r['flash_point'] === '> 55 °C (131 °F) (calculated)', 'boiling override does not touch the flash point', $r['flash_point']);

// ---------------------------------------------------------------------
echo "10. Boiling point at least double the flash point -> Not determined (owner rule 2026-10-21)\n";
$r = $s9->invoke($gen, $fg, $calc(100.0, 94.0), []);
check($r['boiling_point'] === '100 °C (212 °F)', 'water-based: bp 100 °C, fp 94 °C -> printed', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(188.0, 94.0), []);
check($r['boiling_point'] === $nd, 'bp 188 °C = 2 x fp 94 °C -> Not determined', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(187.9, 94.0), []);
check($r['boiling_point'] === '187.9 °C (370.2 °F)', 'bp 187.9 °C, just under double -> printed', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(250.0, 120.0, true), []);
check($r['boiling_point'] === $nd, 'UV ink: bp 250 °C, fp > 120 °C -> Not determined', $r['boiling_point']);
check($r['flash_point'] === '> 120 °C (248 °F) (calculated)', '... the flash point still prints', $r['flash_point']);
$r = $s9->invoke($gen, $fg, $calc(250.0, 94.0), [9 => ['boiling_point' => '250 °C (482 °F)']]);
check($r['boiling_point'] === '250 °C (482 °F)', 'a Section 9 boiling point edit (measured) prints as entered', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(230.0, 94.0), [9 => ['flash_point' => '120 °C']]);
check($r['boiling_point'] === '230 °C (446 °F)', 'compared with the flash point the sheet prints (edit 120 °C): 230 < 240 -> printed', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(240.0, 94.0), [9 => ['flash_point' => '120 °C']]);
check($r['boiling_point'] === $nd, '... 240 >= 240 -> Not determined', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(300.0, null), []);
check($r['boiling_point'] === '300 °C (572 °F)', 'no flash point -> rule does not apply', $r['boiling_point']);
$r = $s9->invoke($gen, $fg, $calc(30.0, -5.0), []);
check($r['boiling_point'] === '30 °C (86 °F)', 'flash point at or below 0 °C -> rule does not apply', $r['boiling_point']);
$r = $s9->invoke($gen, $fg + ['is_resale' => true], $calc(250.0, 94.0), []);
check($r['boiling_point'] === '250 °C (482 °F)', 'resale sheet (vendor values) -> printed', $r['boiling_point']);
check(\SDS\Services\SDSGenerator::boilingPointLooksIncomplete(200.0, 100.0) && !\SDS\Services\SDSGenerator::boilingPointLooksIncomplete(199.9, 100.0)
    && !\SDS\Services\SDSGenerator::boilingPointLooksIncomplete(null, 100.0) && !\SDS\Services\SDSGenerator::boilingPointLooksIncomplete(200.0, null), 'boilingPointLooksIncomplete: >= 2 x, nulls off');
$fw = $method('flammabilityWarnings');
$warn = $fw->invoke($gen, ['flammability' => []], null, null, [], $calc(250.0, 94.0), $fg);
check(count(array_filter($warn, static fn ($w) => str_starts_with($w, 'Boiling point warning:'))) === 1, 'operator warning explains it (preview only)', $warn);
$warn = $fw->invoke($gen, ['flammability' => []], null, null, [], $calc(100.0, 94.0), $fg);
check(count(array_filter($warn, static fn ($w) => str_starts_with($w, 'Boiling point warning:'))) === 0, 'no warning when the boiling point prints', $warn);

restore_error_handler();

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
