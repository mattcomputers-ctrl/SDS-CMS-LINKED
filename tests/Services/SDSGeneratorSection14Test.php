<?php
/**
 * DB-free checks for the SDSGenerator Section 14 builder (SDS content audit
 * item #27):
 *
 *   - Transport data is DERIVED by TransportClassifier from the Section 9
 *     flash point (override first, then formula_props), the initial boiling
 *     point, the engine's H-codes and the finished good's product type.
 *   - Per-product text_overrides WIN over the derivation (inverted precedence).
 *   - "Not determined" (no flash point, no classification data) carries
 *     status 'not_determined' and SDSReadinessService::transportNotDeterminedError
 *     blocks publishing.
 *   - The new environmental_hazards line and the viscous / combustible notes.
 *   - Every new section14.* key resolves in all four language files.
 *
 * Same Reflection bootstrap as SDSGeneratorSection5Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection14Test.php
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

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);

$method = static function (string $name) use ($gen): ReflectionMethod {
    $m = new ReflectionMethod($gen, $name);
    $m->setAccessible(true);
    return $m;
};

$s14 = $method('section14');

$hz = static fn (array $codes, array $classes = []): array => [
    'signal_word' => null, 'pictograms' => [], 'hazard_classes' => $classes, 'p_statements' => [], 'exposure_limits' => [],
    'h_statements' => array_map(static fn ($c) => ['code' => $c, 'text' => ''], $codes),
];

$calc = static fn (?float $fp, bool $gt = false, array $composition = [], ?float $bp = null): array => [
    'formula'       => ['lines' => []],
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'boiling_point_c' => $bp, 'enriched_lines' => []],
    'voc'           => ['total_voc_wt_pct' => 0, 'mixture_sg' => 1.0, 'voc_lb_per_gal' => 0, 'voc_lb_per_gal_less_water_exempt' => 0, 'solids_wt_pct' => 0, 'solids_vol_pct' => null],
    'composition'   => $composition, 'warnings' => [],
];

$fg = ['description' => 'SOLVENT INK', 'family' => 'Solvent', 'physical_state' => 'Liquid', 'transport_product_type' => null];

$expectedKeys = ['title', 'un_number', 'proper_shipping_name', 'hazard_class', 'packing_group', 'environmental_hazards', 'note', 'status'];

// ---------------------------------------------------------------------
echo "1. Solvent ink fp 38 °C H226, no overrides → UN1210 / 3 / PG III, carrier note only\n";
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226', 'H319']), []);
check($r['un_number'] === 'UN1210', 'un_number', $r['un_number']);
check($r['proper_shipping_name'] === $t->get('section14.psn_printing_ink'), 'proper_shipping_name translated', $r['proper_shipping_name']);
check($r['hazard_class'] === '3', 'hazard_class', $r['hazard_class']);
check($r['packing_group'] === 'III', 'packing_group', $r['packing_group']);
check($r['environmental_hazards'] === $t->get('section14.marine_pollutant_no'), 'environmental_hazards = marine pollutant no', $r['environmental_hazards']);
check($r['note'] === $t->get('section14.note'), 'note = carrier note only (PG III gains nothing from 173.121(b)(1))', $r['note']);
check($r['status'] === 'regulated', 'status regulated', $r['status']);
check(array_keys($r) === $expectedKeys, 'exact key set', array_keys($r));
check($r['title'] === $t->get('section14.title'), 'title', $r['title']);
// PG II (fp 15 °C) Class 3 with no subsidiary: the viscous-liquid reassignment note prints.
$r = $s14->invoke($gen, $fg, $calc(15.0), $hz(['H225']), []);
check($r['packing_group'] === 'II', 'fp 15 → PG II', $r['packing_group']);
check($r['note'] === $t->get('section14.note_viscous') . ' ' . $t->get('section14.note'), 'PG II note = viscous + carrier note', $r['note']);
check(str_contains($t->get('section14.note_viscous'), '173.121(b)(1)') && str_contains($t->get('section14.note_viscous'), 'III'), 'viscous note cites 173.121(b)(1) and PG III assignment', $t->get('section14.note_viscous'));

// ---------------------------------------------------------------------
echo "2. Per-product override wins over the derivation\n";
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226', 'H319']), [14 => ['un_number' => 'UN1263', 'hazard_class' => '3']]);
check($r['un_number'] === 'UN1263', 'override un_number wins', $r['un_number']);
check($r['proper_shipping_name'] === $t->get('section14.psn_printing_ink'), 'proper_shipping_name still derived', $r['proper_shipping_name']);
check($r['status'] === 'override', 'status override', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226']), [14 => ['packing_group' => '  ', 'environmental_hazards' => 'Marine pollutant: Yes (operator)']]);
check($r['packing_group'] === 'III', 'blank override falls back to derived', $r['packing_group']);
check($r['environmental_hazards'] === 'Marine pollutant: Yes (operator)', 'environmental_hazards override wins', $r['environmental_hazards']);
check($r['status'] === 'regulated', 'status stays regulated when only minor fields overridden', $r['status']);

// ---------------------------------------------------------------------
echo "3. Not determined: no flash point, no codes → publish gate\n";
$r = $s14->invoke($gen, $fg, $calc(null), $hz([]), []);
$nd = $t->get('labels.not_determined');
check($r['un_number'] === $nd && $r['proper_shipping_name'] === $nd && $r['hazard_class'] === $nd && $r['packing_group'] === $nd, 'four lines Not determined', $r);
check($r['environmental_hazards'] === $nd, 'environmental_hazards Not determined', $r['environmental_hazards']);
check($r['status'] === 'not_determined', 'status not_determined', $r['status']);
check($r['note'] === $t->get('section14.note'), 'only the carrier note', $r['note']);
$err = \SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'X'], 'sections' => [14 => $r]]);
check(is_string($err) && str_contains($err, 'Publishing blocked') && str_contains($err, 'X'), 'transportNotDeterminedError message', $err);
$ok = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226']), []);
check(\SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'X'], 'sections' => [14 => $ok]]) === null, 'null for status regulated');
check(\SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => [], 'sections' => []]) === null, 'null for a snapshot without status');
// An override on a not-determined product unblocks it (operator made the determination)
$r = $s14->invoke($gen, $fg, $calc(null), $hz([]), [14 => ['un_number' => 'UN1210', 'hazard_class' => '3']]);
check($r['status'] === 'override', 'override on not-determined → status override', $r['status']);
check(\SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'X'], 'sections' => [14 => $r]]) === null, 'override unblocks publishing');

// ---------------------------------------------------------------------
echo "4. Combustible liquid fp 70 °C → Not regulated + combustible note\n";
$r = $s14->invoke($gen, $fg, $calc(70.0), $hz([]), []);
$nr = $t->get('labels.not_regulated');
check($r['un_number'] === $nr && $r['proper_shipping_name'] === $nr && $r['hazard_class'] === $nr, 'Not regulated x3', $r);
check($r['packing_group'] === $t->get('labels.not_applicable'), 'packing_group Not applicable', $r['packing_group']);
check($r['environmental_hazards'] === $t->get('section14.marine_pollutant_no'), 'marine pollutant no', $r['environmental_hazards']);
check(str_starts_with($r['note'], $t->get('section14.note_combustible')), 'note starts with combustible note', $r['note']);
check($r['status'] === 'not_regulated', 'status not_regulated', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), []);
check($r['note'] === $t->get('section14.note'), '> 95 °C: no combustible note', $r['note']);

// ---------------------------------------------------------------------
echo "5. Section 9 flash point override wins over formula data\n";
$r = $s14->invoke($gen, $fg, $calc(30.0), $hz([]), [9 => ['flash_point' => '> 95 °C (203 °F)']]);
check($r['status'] === 'not_regulated', 'override > 95 °C → not regulated despite formula fp 30', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), [9 => ['flash_point' => '100 °F']]);
check($r['status'] === 'regulated' && $r['hazard_class'] === '3', '100 °F → 37.8 °C → Class 3', $r);
check($r['packing_group'] === 'III', 'PG III', $r['packing_group']);
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), [9 => ['flash_point' => '12 °C (53.6 °F)']]);
check($r['packing_group'] === 'II', '°C with °F in brackets is read as °C → PG II', $r['packing_group']);
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), [9 => ['flash_point' => 'Not determined']]);
check($r['status'] === 'not_regulated', 'unparsable override ignored, formula value used', $r['status']);
// °F-first overrides: the explicit °C value wins wherever it sits (Sections 5 / 9 print the same string).
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), [9 => ['flash_point' => '75 °F (24 °C)']]);
check($r['status'] === 'regulated' && $r['hazard_class'] === '3' && $r['packing_group'] === 'III', '"75 °F (24 °C)" → 24 °C → Class 3 PG III', $r);
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), [9 => ['flash_point' => '59°F (15°C)']]);
check($r['packing_group'] === 'II', '"59°F (15°C)" → 15 °C → PG II', $r['packing_group']);
$r = $s14->invoke($gen, $fg, $calc(30.0), $hz([]), [9 => ['flash_point' => '> 200°F (93°C)']]);
check($r['status'] === 'not_regulated' && $r['note'] === $t->get('section14.note'), '"> 200°F (93°C)" → not regulated, no combustible note', $r);
$r = $s14->invoke($gen, $fg, $calc(30.0), $hz([]), [9 => ['flash_point' => '> 150 °F (65 °C)']]);
check($r['status'] === 'not_regulated' && $r['note'] === $t->get('section14.note'), '"> 150 °F (65 °C)": gt kept → no combustible note', $r);
$r = $s14->invoke($gen, $fg, $calc(30.0), $hz([]), [9 => ['flash_point' => '150 °F (65 °C)']]);
check($r['status'] === 'not_regulated' && str_starts_with($r['note'], $t->get('section14.note_combustible')), '"150 °F (65 °C)" → combustible note', $r);
// "> n" with n < 60 stays Class 3 (conservative, like Section 13 D001).
$r = $s14->invoke($gen, $fg, $calc(55.0, true), $hz([]), []);
check($r['status'] === 'regulated' && $r['hazard_class'] === '3', 'formula "> 55 °C" → Class 3 (conservative)', $r);

// ---------------------------------------------------------------------
echo "6. Product type flag and keyword rule\n";
$fgPaint = $fg;
$fgPaint['transport_product_type'] = 'paint';
$r = $s14->invoke($gen, $fgPaint, $calc(38.0), $hz(['H226']), []);
check($r['un_number'] === 'UN1263', 'flag paint → UN1263', $r['un_number']);
check($r['proper_shipping_name'] === $t->get('section14.psn_paint'), 'psn paint', $r['proper_shipping_name']);
$fgOpv = $fg;
$fgOpv['description'] = 'GLOSS OPV';
$r = $s14->invoke($gen, $fgOpv, $calc(38.0), $hz(['H226']), []);
check($r['un_number'] === 'UN1263', 'keyword OPV → UN1263', $r['un_number']);
$fgWash = $fg;
$fgWash['description'] = 'BLANKET WASH';
$comp = [
    ['cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol', 'concentration_pct' => 50],
    ['cas_number' => '7732-18-5', 'chemical_name' => 'Water', 'concentration_pct' => 50],
];
$r = $s14->invoke($gen, $fgWash, $calc(20.0, false, $comp), $hz(['H225'], [['cas' => '67-63-0', 'h_codes' => ['H225', 'H319']]]), []);
check($r['un_number'] === 'UN1993', 'wash → UN1993', $r['un_number']);
check($r['proper_shipping_name'] === $t->get('section14.psn_flammable_liquid_nos') . ' (Isopropanol)', 'n.o.s. PSN carries technical name', $r['proper_shipping_name']);
check($r['packing_group'] === 'II', 'fp 20 → PG II', $r['packing_group']);
check(!array_key_exists('technical_names_missing', $r), 'technical_names_missing absent when names were derived', array_keys($r));
// n.o.s. entry from the flash point alone (no constituent carries a transport hazard): flag for the operator warning.
$r = $s14->invoke($gen, $fgWash, $calc(40.0, false, $comp), $hz([]), []);
check($r['un_number'] === 'UN1993' && $r['proper_shipping_name'] === $t->get('section14.psn_flammable_liquid_nos'), 'bare n.o.s. PSN (no technical names)', $r);
check(($r['technical_names_missing'] ?? null) === true, 'technical_names_missing => true (generate() warns, then drops the key)', $r);
$r = $s14->invoke($gen, $fgWash, $calc(40.0, false, $comp), $hz([]), [14 => ['proper_shipping_name' => 'Flammable liquids, n.o.s. (Isopropanol)']]);
check(!array_key_exists('technical_names_missing', $r), 'PSN override supplies the names → no flag', array_keys($r));

// ---------------------------------------------------------------------
echo "7. Boiling point → PG I; aquatic → marine pollutant; solid state from formula_props\n";
$r = $s14->invoke($gen, $fg, $calc(-5.0, false, [], 30.0), $hz(['H224']), []);
check($r['packing_group'] === 'I', 'bp 30 °C → PG I', $r['packing_group']);
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226', 'H411']), []);
check($r['environmental_hazards'] === $t->get('section14.marine_pollutant_yes'), 'H411 → marine pollutant yes', $r['environmental_hazards']);
check($r['un_number'] === 'UN1210', 'still UN1210', $r['un_number']);
$fgNoState = $fg;
$fgNoState['physical_state'] = '';
$c = $calc(95.0, true);
$c['formula_props']['physical_state'] = 'Powder';
$r = $s14->invoke($gen, $fgNoState, $c, $hz(['H410']), []);
check($r['un_number'] === 'UN3077', 'formula_props physical_state Powder → UN3077', $r['un_number']);

// ---------------------------------------------------------------------
echo "8. Translation coverage (en/es/fr/de)\n";
$keys = [
    'section14.psn_printing_ink', 'section14.psn_printing_ink_related', 'section14.psn_paint', 'section14.psn_paint_related',
    'section14.psn_flammable_liquid_nos', 'section14.psn_flammable_liquid_corrosive_nos', 'section14.psn_flammable_liquid_toxic_nos',
    'section14.psn_flammable_liquid_toxic_corrosive_nos', 'section14.psn_corrosive_liquid_nos', 'section14.psn_corrosive_liquid_toxic_nos',
    'section14.psn_corrosive_solid_nos', 'section14.psn_corrosive_solid_toxic_nos', 'section14.psn_toxic_liquid_organic_nos',
    'section14.psn_toxic_solid_organic_nos', 'section14.psn_env_hazardous_liquid_nos', 'section14.psn_env_hazardous_solid_nos',
    'section14.psn_corrosive_liquid_flammable_nos', 'section14.psn_toxic_liquid_flammable_organic_nos',   // 173.2a(b): 8 / 6.1 over 3
    'section14.psn_toxic_liquid_corrosive_organic_nos', 'section14.psn_toxic_solid_corrosive_organic_nos', // 173.2a(b): 6.1 over 8
    'section14.marine_pollutant_yes', 'section14.marine_pollutant_no', 'section14.note_combustible', 'section14.note_viscous',
    'labels.environmental_hazards', 'labels.not_determined', 'labels.not_regulated', 'labels.not_applicable',
];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tl = new \SDS\Services\TranslationService($lang);
    $missing = [];
    foreach ($keys as $k) {
        $v = $tl->get($k);
        if ($v === $k || trim((string) $v) === '') {
            $missing[] = $k;
        }
    }
    check($missing === [], "{$lang}: every Section 14 key resolves", $missing);
    // Every language renders the derived section with its own strings (no EN leakage via key echo)
    $genL = new \SDS\Services\SDSGenerator($tl);
    $mL   = new ReflectionMethod($genL, 'section14');
    $mL->setAccessible(true);
    $rL = $mL->invoke($genL, $fg, $calc(38.0), $hz(['H226']), []);
    check($rL['proper_shipping_name'] === $tl->get('section14.psn_printing_ink') && !str_contains($rL['note'], 'section14.'), "{$lang}: PSN and note translated", $rL);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
