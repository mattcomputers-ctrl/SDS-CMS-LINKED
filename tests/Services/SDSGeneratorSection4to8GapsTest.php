#!/usr/bin/env php
<?php
/**
 * Remaining Section 4-8 wording gaps (finding #64, DB-free).
 *
 *   1. Section 4(b): sub-coded statements (H360D, H350i) use the base text.
 *   2. Section 5: explosive + water-reactive firefighter advice says "no water".
 *   3. Section 6: ignition sentence for H227 / H250 / H260 / H261; gas containment.
 *   4. Section 7: pyrophoric + water-reactive "inert gas" once; explosive, gas
 *      under pressure and STOT RE fragments; sensitizer sentence not repeating
 *      "Do not breathe"; Section 10 override lower-cased inline (not DE, not
 *      acronyms); Storage override operator warning.
 *   5. Section 8: dust sentence for Powder only; "no exposure limits" sentence.
 *   6. HazardEngine::dedupeExposureLimits(): notes are not part of the key.
 *   7. Every new key in all four languages.
 *
 * Run: php tests/Services/SDSGeneratorSection4to8GapsTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\HazardEngine;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

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
    if ($ok) { echo "  ok   {$label}\n"; return; }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) { echo '       actual: ' . var_export($actual, true) . "\n"; }
}

function m(object|string $o, string $n): ReflectionMethod
{
    $r = new ReflectionMethod($o, $n);
    $r->setAccessible(true);
    return $r;
}

/** Engine output shape (HazardEngine::classify()). */
function hz(array $h, array $p = [], array $classes = []): array
{
    return [
        'signal_word'     => $h === [] ? null : 'Warning',
        'pictograms'      => $h === [] ? [] : ['GHS07'],
        'hazard_classes'  => $classes,
        'h_statements'    => array_map(fn($c) => ['code' => $c, 'text' => \SDS\Services\GHSStatements::hText($c)], $h),
        'p_statements'    => array_map(fn($c) => ['code' => $c, 'text' => \SDS\Services\GHSStatements::pText($c)], $p),
        'exposure_limits' => [],
    ];
}

$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$s4  = m($gen, 'section4');
$s5  = m($gen, 'section5');
$s6  = m($gen, 'section6');
$s7  = m($gen, 'section7');
$s8  = m($gen, 'section8');
$liq = ['physical_state' => 'Liquid'];

// ---------------------------------------------------------------------
echo "1. Section 4(b) sub-coded statements\n";
$sym = $s4->invoke($gen, ['h_statements' => [['code' => 'H360D', 'text' => ''], ['code' => 'H350i', 'text' => '']]], [])['symptoms'];
check(str_contains($sym, 'May damage fertility or the unborn child.'), 'H360D -> H360 text', $sym);
check(str_contains($sym, 'May cause cancer.'), 'H350i -> H350 text', $sym);
check(str_starts_with($sym, $t->get('section4.symptoms_delayed_prefix')), 'both are delayed effects', $sym);

// ---------------------------------------------------------------------
echo "2. Section 5 explosive + water-reactive\n";
$ff = $s5->invoke($gen, ['formula_props' => []], hz(['H201', 'H261']), [])['firefighter_advice'];
check($ff === $t->get('section5.firefighter_advice_explosive') . ' ' . $t->get('section5.firefighter_no_water'), 'explosive + water-reactive -> explosive advice + no water', $ff);
$ff = $s5->invoke($gen, ['formula_props' => []], hz(['H201']), [])['firefighter_advice'];
check($ff === $t->get('section5.firefighter_advice_explosive'), 'explosive alone unchanged', $ff);

// ---------------------------------------------------------------------
echo "3. Section 6 ignition sentence and gas containment\n";
foreach (['H227', 'H250', 'H260', 'H261'] as $code) {
    $pp = $s6->invoke($gen, hz([$code]), $liq, [])['personal_precautions'];
    check($pp === $t->get('section6.personal_precautions') . ' ' . $t->get('section6.precautions_ignition'), "{$code} -> base + ignition", $pp);
}
$pp = $s6->invoke($gen, hz(['H225', 'H227']), $liq, [])['personal_precautions'];
check($pp === $t->get('section6.personal_precautions') . ' ' . $t->get('section6.precautions_flammable'), 'H225 + H227 -> flammable only (no double ignition wording)', $pp);
$c = $s6->invoke($gen, hz([]), ['physical_state' => 'Gas'], [])['containment'];
check($c === $t->get('section6.containment_gas'), 'Gas -> containment_gas', $c);

// ---------------------------------------------------------------------
echo "4. Section 7 fragments\n";
$h = $s7->invoke($gen, hz(['H250', 'H261']), [])['handling'];
check(str_contains($h, $t->get('section7.handling_pyrophoric_water_reactive')), 'H250 + H261 -> combined sentence', $h);
check(substr_count($h, 'inert gas') === 1, '"Handle under inert gas." printed once', $h);
$f = $s7->invoke($gen, hz(['H201']), []);
check(str_contains($f['handling'], $t->get('section7.handling_ignition')) && str_contains($f['handling'], $t->get('section7.handling_explosive')), 'H201 handling: ignition + explosive', $f['handling']);
check(str_contains($f['storage'], $t->get('section7.storage_explosive')), 'H201 storage: explosive', $f['storage']);
$f = $s7->invoke($gen, hz(['H280']), []);
check(str_contains($f['handling'], $t->get('section7.handling_gas_pressure')) && str_contains($f['storage'], $t->get('section7.storage_gas_pressure')), 'H280: gas-pressure handling + storage', $f);
$h = $s7->invoke($gen, hz(['H373']), [])['handling'];
check(str_contains($h, $t->get('section7.handling_stot_re')), 'H373 -> STOT RE sentence', $h);
$h = $s7->invoke($gen, hz(['H373', 'H317']), [])['handling'];
check(str_contains($h, $t->get('section7.handling_stot_re')) && str_contains($h, $t->get('section7.handling_sensitizer_clothing')) && !str_contains($h, 'Avoid breathing'), 'H373 + H317 -> STOT RE + clothing only', $h);
$h = $s7->invoke($gen, hz(['H314', 'H317']), [])['handling'];
check(str_contains($h, $t->get('section7.handling_sensitizer_clothing')) && !str_contains($h, 'Avoid breathing') && !str_contains($h, $t->get('section7.handling_stot_re')), 'H314 + H317 -> corrosive + clothing, no STOT RE', $h);
$st = $s7->invoke($gen, hz([]), [10 => ['incompatible' => 'Strong oxidizers, strong acids.']])['storage'];
check(str_ends_with($st, '(see Section 10): strong oxidizers, strong acids.'), 'override lower-cased inline', $st);
$st = $s7->invoke($gen, hz([]), [10 => ['incompatible' => 'PVC, strong acids.']])['storage'];
check(str_ends_with($st, ': PVC, strong acids.'), 'acronym first word kept', $st);
$genDe = new SDSGenerator(new TranslationService('de'));
$st = m($genDe, 'section7')->invoke($genDe, hz([]), [10 => ['incompatible' => 'Starke Säuren.']])['storage'];
check(str_ends_with($st, ': Starke Säuren.'), 'DE override kept as typed', $st);
$w = m(SDSGenerator::class, 'storageOverrideWarning');
check($w->invoke(null, [7 => ['storage' => 'Custom']]) !== null, 'Storage override -> operator warning');
check($w->invoke(null, []) === null, 'no override -> no warning');
check($w->invoke(null, [7 => ['storage' => '  ']]) === null, 'blank override -> no warning');

// ---------------------------------------------------------------------
echo "5. Section 8 dust sentence and no-exposure-limits sentence\n";
$s = $s8->invoke($gen, hz([]), [], [], ['physical_state' => 'Solid']);
check($s['engineering'] === $t->get('section8.engineering'), 'Solid -> no dust sentence', $s['engineering']);
check($s['exposure_limits_none'] === $t->get('section8.no_exposure_limits'), 'no limit rows -> sentence', $s['exposure_limits_none']);
$s = $s8->invoke($gen, hz([]), [], [], ['physical_state' => 'Powder']);
check($s['engineering'] === $t->get('section8.engineering') . ' ' . $t->get('section8.engineering_dust'), 'Powder -> dust sentence', $s['engineering']);
$withEl = hz([]);
$withEl['exposure_limits'] = [['cas_number' => '64-17-5', 'chemical_name' => 'Ethanol', 'limit_type' => 'OSHA PEL TWA', 'value' => '1000', 'units' => 'ppm', 'notes' => '', 'concentration_pct' => 5.0]];
$s = $s8->invoke($gen, $withEl, [['cas_number' => '64-17-5', 'concentration_pct' => 5.0]], [], $liq);
check($s['exposure_limits_none'] === '' && count($s['exposure_limits']) === 1, 'limit row present -> no sentence', $s['exposure_limits_none']);

// ---------------------------------------------------------------------
echo "6. HazardEngine::dedupeExposureLimits() ignores notes\n";
$rows = [
    ['cas_number' => '1333-86-4', 'limit_type' => 'NIOSH REL TWA', 'value' => '3.5', 'units' => 'mg/m3', 'notes' => ''],
    ['cas_number' => '1333-86-4', 'limit_type' => 'NIOSH REL TWA', 'value' => '3.50', 'units' => 'mg/m3', 'notes' => 'Carbon black in presence of PAHs: 0.1 mg PAHs/m3'],
    ['cas_number' => '1333-86-4', 'limit_type' => 'OSHA PEL TWA', 'value' => '3.5', 'units' => 'mg/m3', 'notes' => ''],
];
$out = m(HazardEngine::class, 'dedupeExposureLimits')->invoke(null, $rows);
check(count($out) === 2, 'two distinct limits remain', $out);
check(($out[0]['notes'] ?? '') === 'Carbon black in presence of PAHs: 0.1 mg PAHs/m3', 'first row adopts the PAH note', $out[0] ?? null);
check(($out[1]['limit_type'] ?? '') === 'OSHA PEL TWA', 'order stable', $out);

// ---------------------------------------------------------------------
echo "7. Translations (en/es/fr/de)\n";
$keys = [
    'section4'  => ['poison_center_immediate', 'notes_eye_damage', 'uv_skin', 'uv_skin_names'],
    'section5'  => ['firefighter_no_water', 'uv_specific_hazards'],
    'section6'  => ['precautions_ignition', 'containment_gas', 'uv_containment'],
    'section7'  => ['handling_pyrophoric_water_reactive', 'handling_explosive', 'handling_gas_pressure', 'handling_stot_re',
                    'handling_sensitizer_clothing', 'storage_explosive', 'storage_gas_pressure', 'uv_handling', 'uv_storage'],
    'section8'  => ['no_exposure_limits'],
    'section10' => ['reactivity_self_reactive', 'stability_cond_self_reactive', 'reactivity_explosive', 'reactivity_oxidizer',
                    'reactivity_self_heating', 'reactivity_unstable_gas', 'stability_cond_explosive',
                    'stability_cond_self_heating', 'stability_cond_unstable_gas'],
];
$en = require $basePath . '/templates/translations/en.php';
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $file = require $basePath . '/templates/translations/' . $lang . '.php';
    $missing = [];
    $untranslated = [];
    foreach ($keys as $sec => $list) {
        foreach ($list as $k) {
            $v = $file[$sec][$k] ?? null;
            if (!is_string($v) || trim($v) === '') {
                $missing[] = "{$sec}.{$k}";
            } elseif ($lang !== 'en' && $v === ($en[$sec][$k] ?? null)) {
                $untranslated[] = "{$sec}.{$k}";
            }
        }
    }
    check($missing === [], "{$lang}: every new key present and non-empty", $missing);
    check($untranslated === [], "{$lang}: every new key translated (differs from EN)", $untranslated);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
