<?php
/**
 * DB-free checks for A11 printing (fixed-text review §3b, owner 2026-10-12):
 * the product-family SDS profile on the sheet.
 *
 *   1. familyFields() carries the typed profile (family_profile);
 *   2. physical-state precedence: product > family default > largest raw > Liquid;
 *   3. WaterBasedRule (explicit threshold, no DB);
 *   4. Section 7 storage temperature sentence + "Protect from freezing.";
 *   5. Section 10 conditions: "temperatures above X °C (Y °F)" + "freezing";
 *   6. Section 9 pH "8.0 – 9.5 (typical)" (display only; override wins);
 *   7. the resolved physical state reaches Sections 6, 8, 9 and flammabilityInputs;
 *   8. temperature formatting;
 *   9. the eight new translation keys exist and are translated.
 *
 * Same Reflection bootstrap as SDSGeneratorSection9Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorFamilyProfileTest.php
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

use SDS\Models\ProductFamily as PF;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;
use SDS\Services\WaterBasedRule as WB;

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

/** A generator for one language plus a reflection-method getter. */
$make = static function (string $lang): array {
    $t   = new TranslationService($lang);
    $gen = new SDSGenerator($t);
    $m   = static function (string $name) use ($gen): ReflectionMethod {
        $r = new ReflectionMethod($gen, $name);
        $r->setAccessible(true);
        return $r;
    };
    return [$gen, $m, $t];
};
$static = static function (string $name): ReflectionMethod {
    $r = new ReflectionMethod(SDSGenerator::class, $name);
    $r->setAccessible(true);
    return $r;
};

// Real engine shape.
$hz = static fn(array $codes, array $p = []): array => [
    'h_statements' => array_map(static fn($c) => ['code' => $c, 'text' => ''], $codes),
    'p_statements' => array_map(static fn($c) => ['code' => $c, 'text' => ''], $p),
];

/** $fg after attachFamily() + applyFamilyProfile() for a given profile. */
$fgWith = static fn(array $profile, bool $water = false): array => [
    'family_profile' => array_merge(PF::PROFILE_EMPTY, $profile),
    'is_water_based' => $water,
];

$calc = static fn(string $domState = ''): array => [
    'formula'       => ['lines' => []],
    'formula_props' => [
        'flash_point_c'            => null,
        'flash_point_greater_than' => false,
        'boiling_point_c'          => null,
        'solubility_key'           => null,
        'soluble_fraction_pct'     => null,
        'physical_state'           => $domState,
        'odor'                     => '',
        'appearance'               => '',
        'enriched_lines'           => [],
    ],
    'voc'           => ['total_voc_wt_pct' => 3.2, 'mixture_sg' => 1.08, 'voc_lb_per_gal' => 0.29, 'solids_wt_pct' => 40.1],
    'composition'   => [],
    'warnings'      => [],
];

$inOrder = static function (string $haystack, array $needles): bool {
    $last = -1;
    foreach ($needles as $n) {
        $p = strpos($haystack, $n);
        if ($p === false || $p <= $last) {
            return false;
        }
        $last = $p;
    }
    return true;
};

// ---------------------------------------------------------------------
echo "1. familyFields() carries the typed SDS profile\n";
$row = [
    'name' => 'Water-Based Flexo', 'is_active' => 1, 'is_uv' => 0,
    'storage_temp_min_c' => '5.0', 'storage_temp_max_c' => '30.0', 'water_based' => 'auto',
    'default_physical_state' => 'Paste', 'ph_min' => '8.0', 'ph_max' => '9.5',
];
$p = SDSGenerator::familyFields($row, null)['family_profile'];
check($p['storage_temp_min_c'] === 5.0 && $p['storage_temp_max_c'] === 30.0, 'storage 5.0 / 30.0 as floats', $p);
check($p['ph_min'] === 8.0 && $p['ph_max'] === 9.5, 'pH 8.0 / 9.5 as floats', $p);
check($p['default_physical_state'] === 'Paste' && $p['water_based'] === 'auto', "state 'Paste', water 'auto'", $p);
check(SDSGenerator::familyFields(['is_active' => 0] + $row, null)['family_profile'] === PF::PROFILE_EMPTY, 'inactive family -> empty profile');
check(SDSGenerator::familyFields(null, null)['family_profile'] === PF::PROFILE_EMPTY, 'no family -> empty profile');
check(SDSGenerator::familyFields(['water_based' => 'YES'] + $row, null)['family_profile']['water_based'] === 'yes', "water_based 'YES' -> 'yes'");
check(SDSGenerator::familyFields(['water_based' => 'maybe'] + $row, null)['family_profile']['water_based'] === 'auto', "water_based 'maybe' -> 'auto'");
check(SDSGenerator::familyFields(['default_physical_state' => ''] + $row, null)['family_profile']['default_physical_state'] === null, 'blank state -> null');
check(SDSGenerator::familyFields(['storage_temp_min_c' => 'abc'] + $row, null)['family_profile']['storage_temp_min_c'] === null, "non-numeric min 'abc' -> null");

// ---------------------------------------------------------------------
echo "2. resolvePhysicalState(): product > family > largest raw > Liquid\n";
$fam = static fn(?string $s): array => ['family_profile' => ['default_physical_state' => $s] + PF::PROFILE_EMPTY];
check(SDSGenerator::resolvePhysicalState(['physical_state' => 'Gel'] + $fam('Paste'), $calc('Liquid')) === 'Gel', 'product Gel wins');
check(SDSGenerator::resolvePhysicalState(['physical_state' => ''] + $fam('Paste'), $calc('Liquid')) === 'Paste', 'blank product -> family Paste');
check(SDSGenerator::resolvePhysicalState(['physical_state' => ''] + $fam('  '), $calc('Powder')) === 'Powder', 'whitespace family -> raw Powder');
check(SDSGenerator::resolvePhysicalState([], []) === 'Liquid', 'nothing at all -> Liquid');
check(SDSGenerator::physicalStateIsDefault($fam('Paste'), []) === false, 'family default set -> not the Liquid default');

// ---------------------------------------------------------------------
echo "3. WaterBasedRule (explicit threshold, no DB)\n";
$water = static fn(float ...$pcts): array => array_map(static fn($p) => ['cas_number' => '7732-18-5', 'concentration_pct' => $p], $pcts);
check(WB::isWaterBased('auto', $water(30.0, 15.0), 20.0) === true, 'auto: 30 + 15 = 45 % >= 20 -> true');
check(WB::isWaterBased('auto', $water(19.9), 20.0) === false, 'auto: 19.9 % -> false');
check(WB::isWaterBased('auto', $water(20.0), 20.0) === true, 'auto: 20.0 % -> true (at the threshold)');
check(WB::isWaterBased('no', $water(45.0), 20.0) === false, "'no' + 45 % -> false");
check(WB::isWaterBased('yes', [], 20.0) === true, "'yes' + 0 % -> true");
check(WB::isWaterBased('auto', [['cas_number' => '64-17-5', 'concentration_pct' => 60.0]], 20.0) === false, 'no water -> false');
check(WB::waterPct([['cas_number' => 'TRADE_SECRET', 'concentration_pct' => 50.0]]) === 0.0, 'TRADE_SECRET bucket is not water');

// ---------------------------------------------------------------------
echo "4. Section 7 storage (en)\n";
[$gen, $m, $t] = $make('en');
$s7  = $m('section7');
$set = $m('setProfileState');
$incompat = 'Store away from incompatible materials (see Section 10): strong oxidizing agents, strong acids, strong bases.';
$base     = $t->get('section7.storage_base');
$s = $s7->invoke($gen, $hz([]), [])['storage'];
check($s === $base . ' ' . $incompat, 'no profile: today\'s text unchanged', $s);

$set->invoke($gen, $fgWith(['storage_temp_min_c' => 5.0, 'storage_temp_max_c' => 30.0], true));
$s = $s7->invoke($gen, $hz([]), [])['storage'];
check($s === 'Store in a cool, dry, well-ventilated area. Keep container tightly closed when not in use. Store in the original container. Store between 5 and 30 °C (41–86 °F). Protect from freezing. ' . $incompat, 'range + freezing, exact (V1)', $s);
$s = $s7->invoke($gen, $hz(['H225']), [])['storage'];
check($inOrder($s, [$base, 'Store between 5 and 30 °C (41–86 °F).', 'Protect from freezing.', $t->get('section7.storage_ignition')]), 'H225: base < temperature < freezing < ignition', $s);
check($s7->invoke($gen, $hz([]), [7 => ['storage' => 'Custom']])['storage'] === 'Custom', 'storage override replaces the whole field');

$set->invoke($gen, $fgWith(['storage_temp_max_c' => 30.0]));
$s = $s7->invoke($gen, $hz([]), [])['storage'];
check(str_contains($s, 'Store at temperatures not exceeding 30 °C (86 °F).'), 'max only', $s);
check(!str_contains($s, 'Protect from freezing.'), 'not water-based: no freezing sentence', $s);
$set->invoke($gen, $fgWith(['storage_temp_min_c' => 5.0]));
$s = $s7->invoke($gen, $hz([]), [])['storage'];
check(str_contains($s, 'Store at temperatures not below 5 °C (41 °F).'), 'min only', $s);
$set->invoke($gen, $fgWith(['storage_temp_min_c' => 30.0, 'storage_temp_max_c' => 5.0]));
$s = $s7->invoke($gen, $hz([]), [])['storage'];
check($s === $base . ' ' . $incompat, 'inverted range: no temperature sentence', $s);
$set->invoke($gen, []);
$s = $s7->invoke($gen, $hz([]), [])['storage'];
check($s === $base . ' ' . $incompat, 'pre-A11 base (no profile keys): unchanged', $s);

$enRange = 'Store between 5 and 30 °C (41–86 °F).';
foreach (['es' => 'Almacenar entre 5 y 30 °C (41–86 °F). Proteger de las heladas.',
          'fr' => 'Stocker entre 5 et 30 °C (41–86 °F). Protéger du gel.',
          'de' => 'Zwischen 5 und 30 °C (41–86 °F) lagern. Vor Frost schützen.'] as $lang => $want) {
    [$g, $mm] = $make($lang);
    $mm('setProfileState')->invoke($g, $fgWith(['storage_temp_min_c' => 5.0, 'storage_temp_max_c' => 30.0], true));
    $s = $mm('section7')->invoke($g, $hz([]), [])['storage'];
    check(str_contains($s, $want) && !str_contains($s, $enRange) && !str_contains($s, 'section7.'), "{$lang}: translated range + freezing (V2)", $s);
}

// ---------------------------------------------------------------------
echo "5. Section 10 conditions to avoid (en)\n";
$s10 = $m('section10');
$set->invoke($gen, []);
$c = $s10->invoke($gen, $hz([]), [])['conditions_avoid'];
check($c === 'Excessive heat, contact with strong oxidizing agents.', 'no profile: unchanged', $c);
$set->invoke($gen, $fgWith(['storage_temp_min_c' => 5.0, 'storage_temp_max_c' => 30.0], true));
$c = $s10->invoke($gen, $hz([]), [])['conditions_avoid'];
check($c === 'Excessive heat, temperatures above 30 °C (86 °F), freezing, contact with strong oxidizing agents.', 'max + water-based (V3)', $c);
$c = $s10->invoke($gen, $hz(['H242']), [])['conditions_avoid'];
check(!str_contains($c, $t->get('section10.cond_storage_temp')) && substr_count($c, 'temperatures above 30 °C (86 °F)') === 1, 'H242 + max: named limit replaces the generic one', $c);
check($s10->invoke($gen, $hz([]), [10 => ['conditions_avoid' => 'Custom.']])['conditions_avoid'] === 'Custom.', 'override replaces the whole field');
$set->invoke($gen, $fgWith([], true));
$c = $s10->invoke($gen, $hz([]), [])['conditions_avoid'];
check($c === 'Excessive heat, freezing, contact with strong oxidizing agents.', 'water-based only', $c);
$set->invoke($gen, $fgWith([], false));
$c = $s10->invoke($gen, $hz(['H242']), [])['conditions_avoid'];
check(str_contains($c, $t->get('section10.cond_storage_temp')), 'no max: H242 keeps the generic storage-limit item', $c);
[$g, $mm] = $make('de');
$mm('setProfileState')->invoke($g, $fgWith(['storage_temp_min_c' => 5.0, 'storage_temp_max_c' => 30.0], true));
$c = $mm('section10')->invoke($g, $hz([]), [])['conditions_avoid'];
check(str_contains($c, 'Temperaturen über 30 °C (86 °F)') && str_contains($c, ', Frost,'), 'de: Temperaturen über 30 °C (86 °F), Frost', $c);

// ---------------------------------------------------------------------
echo "6. Section 9 typical pH\n";
$s9 = $m('section9');
$nd = $t->get('labels.not_determined');
$base9 = ['physical_state' => 'Liquid', 'color' => ''];
$ph = static fn(array $profile, array $ov = []) => $s9->invoke($gen, $base9 + $fgWith($profile), $calc(), $ov)['ph'];
check($ph(['ph_min' => 8.0, 'ph_max' => 9.5]) === '8.0 – 9.5 (typical)', 'range 8.0 – 9.5 (typical)', $ph(['ph_min' => 8.0, 'ph_max' => 9.5]));
check($ph(['ph_min' => 8.0]) === '8.0 (typical)', 'min only -> 8.0 (typical)', $ph(['ph_min' => 8.0]));
check($ph(['ph_min' => 9.0, 'ph_max' => 9.0]) === '9.0 (typical)', 'equal -> 9.0 (typical)', $ph(['ph_min' => 9.0, 'ph_max' => 9.0]));
check($ph(['ph_min' => 8.0, 'ph_max' => 9.5], [9 => ['ph' => '8.7']]) === '8.7', 'per-product override wins');
check($ph([]) === $nd, 'no family pH -> Not determined');
check($s9->invoke($gen, $base9, $calc(), [])['ph'] === $nd, 'no family_profile key -> Not determined');
check($ph(['ph_min' => 8.0, 'ph_max' => 15.0]) === $nd, 'pH 15 -> Not determined');
check($ph(['ph_min' => 9.5, 'ph_max' => 8.0]) === $nd, 'inverted -> Not determined');
[$g, $mm] = $make('fr');
$r = $mm('section9')->invoke($g, $base9 + $fgWith(['ph_min' => 8.0, 'ph_max' => 9.5]), $calc(), []);
check($r['ph'] === '8.0 – 9.5 (typique)', 'fr: 8.0 – 9.5 (typique)', $r['ph']);

// ---------------------------------------------------------------------
echo "7. The resolved physical state reaches every consumer\n";
$s6   = $m('section6');
$eng  = $m('engineeringControls');
$flam = $static('flammabilityInputs');
$fg   = ['physical_state' => '', 'color' => ''] + $fgWith(['default_physical_state' => 'Paste']);
$cl   = $calc('Liquid');
check($flam->invoke(null, $cl, $fg)['physical_state'] === 'Paste', 'flammabilityInputs on an unresolved row -> Paste');
$fg['physical_state'] = SDSGenerator::resolvePhysicalState($fg, $cl);
check($fg['physical_state'] === 'Paste', 'resolved -> Paste');
$r = $s9->invoke($gen, $fg, $cl, []);
check($r['physical_state'] === 'Paste', 'Section 9 physical_state Paste', $r['physical_state']);
$r = $s6->invoke($gen, $hz([]), $fg, []);
check($r['containment'] === $t->get('section6.containment_paste'), 'Section 6 containment uses the paste wording', $r['containment']);
check($flam->invoke(null, $cl, $fg)['physical_state'] === 'Paste', 'flammabilityInputs -> Paste');
$fgP = ['physical_state' => '', 'color' => ''] + $fgWith(['default_physical_state' => 'Powder']);
$fgP['physical_state'] = SDSGenerator::resolvePhysicalState($fgP, $cl);
$e = $eng->invoke($gen, $hz([]), $fgP);
check(str_contains($e, $t->get('section8.engineering_dust')), 'family Powder -> Section 8 dust engineering fragment', $e);
$fgL = ['physical_state' => 'Liquid', 'color' => ''] + $fgWith(['default_physical_state' => 'Paste']);
check(SDSGenerator::resolvePhysicalState($fgL, $cl) === 'Liquid', 'product Liquid keeps Liquid in a Paste family (V6)');

// ---------------------------------------------------------------------
echo "8. Temperature formatting\n";
$fc = $static('formatProfileTempC');
$ff = $static('formatProfileTempF');
foreach ([[2.5, '2.5'], [-5.0, '-5'], [30.0, '30'], [-0.04, '0']] as [$in, $want]) {
    $got = $fc->invoke(null, $in);
    check($got === $want, "formatProfileTempC({$in}) = '{$want}'", $got);
}
foreach ([[2.5, '37'], [5.0, '41'], [-5.0, '23']] as [$in, $want]) {
    $got = $ff->invoke(null, $in);
    check($got === $want, "formatProfileTempF({$in}) = '{$want}'", $got);
}

// ---------------------------------------------------------------------
echo "9. New translation keys\n";
$keys = ['section7.storage_temp_range', 'section7.storage_temp_max', 'section7.storage_temp_min', 'section7.storage_protect_freezing',
         'section9.ph_typical_range', 'section9.ph_typical_value', 'section10.cond_storage_max', 'section10.cond_freezing'];
$mustDiffer = ['section7.storage_temp_range', 'section7.storage_protect_freezing', 'section9.ph_typical_range', 'section10.cond_storage_max'];
$en = new TranslationService('en');
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $all = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($keys as $k) {
        [$sec, $leaf] = explode('.', $k, 2);
        $v = $all[$sec][$leaf] ?? null;
        check(is_string($v) && trim($v) !== '', "{$lang}: {$k} present", $v);
        if ($lang !== 'en' && in_array($k, $mustDiffer, true)) {
            check($v !== $en->get($k), "{$lang}: {$k} translated", $v);
        }
    }
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
