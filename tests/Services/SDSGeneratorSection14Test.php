<?php
/**
 * DB-free checks for the SDSGenerator Section 14 builder (SDS content audit
 * item #27):
 *
 *   - Transport data is DERIVED by TransportClassifier from the engine's
 *     H-codes (Q3: Class 3 only from H224-H226, which the engine derives from
 *     the product flash point; never the Section 9 edit), the engine's
 *     flammability block (else formula_props) for the boiling point and the
 *     combustible note, and the finished good's product type.
 *   - Per-product text_overrides WIN over the derivation (inverted precedence).
 *   - "Not determined" (no DOT entry fits, e.g. 3 + 8 + 6.1) carries
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

$expectedKeys = ['title', 'un_number', 'proper_shipping_name', 'hazard_class', 'packing_group', 'environmental_hazards', 'transport_in_bulk', 'special_precautions', 'note', 'status'];

/** A hazard_classes row in the shape HazardEngine emits (finding #3). */
$hcRow = static fn (string $canonical, string $cat, string $cas = 'MIXTURE'): array => [
    'class' => \SDS\Services\GHSHazardClass::displayName($canonical), 'category' => 'Category ' . substr($cat, 4),
    'canonical' => $canonical, 'category_canonical' => $cat, 'cas' => $cas,
    'chemical' => $cas === 'MIXTURE' ? 'Multiple components (summation)' : 'Test constituent',
    'concentration_pct' => 5.0, 'cutoff_pct' => 1.0,
];
$gate = static fn (array $s14, string $code = 'X', string $lang = 'en', array $s2 = []): ?string =>
    \SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => $code, 'language' => $lang], 'sections' => [2 => $s2, 14 => $s14]]);

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
check($r['transport_in_bulk'] === $t->get('section14.transport_in_bulk_text'), 'transport_in_bulk standard wording (#43)', $r['transport_in_bulk']);
check($r['special_precautions'] === $t->get('section14.special_precautions_text'), 'special_precautions standard wording (#43)', $r['special_precautions']);
check(($r['status_reason'] ?? null) === null, 'no status_reason when decided', $r);
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
check($r['status'] === 'override_incomplete' && ($r['status_reason'] ?? null) === 'override_incomplete', '#7: UN + class only → override_incomplete', $r);
$err = $gate($r, 'X', 'en');
check(is_string($err) && str_contains($err, 'incomplete') && str_contains($err, 'X (EN sheet)'), '#7: gate blocks, names the language', $err);
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226', 'H319']), [14 => ['un_number' => 'UN1263', 'proper_shipping_name' => 'Paint', 'hazard_class' => '3', 'packing_group' => 'III']]);
check($r['status'] === 'override' && $gate($r) === null, '#7: full set → status override, no block', $r);
check($r['note'] === $t->get('section14.note'), 'override: carrier note only', $r['note']);
check(!array_key_exists('status_reason', $r), 'complete override: no status_reason', array_keys($r));
$r = $s14->invoke($gen, $fg, $calc(15.0), $hz(['H225']), [14 => ['packing_group' => 'III']]);
check($r['status'] === 'override' && $r['packing_group'] === 'III' && $r['un_number'] === 'UN1210', '#7: PG-only override on a regulated product (viscous reassignment) → override', $r);
check($r['note'] === $t->get('section14.note'), '#7: derived viscous note dropped under an override', $r['note']);
$r = $s14->invoke($gen, $fg, $calc(15.0), $hz(['H225']), [14 => ['un_number' => 'UN1263']]);
check($r['status'] === 'override_incomplete' && is_string($gate($r)), '#7: UN-only override on a regulated product → blocked', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), [14 => ['packing_group' => 'III']]);
check($r['status'] === 'override_incomplete', '#7: PG-only override on a not-regulated product → blocked', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226']), [14 => ['packing_group' => '  ', 'environmental_hazards' => 'Marine pollutant: Yes (operator)']]);
check($r['packing_group'] === 'III', 'blank override falls back to derived', $r['packing_group']);
check($r['environmental_hazards'] === 'Marine pollutant: Yes (operator)', 'environmental_hazards override wins', $r['environmental_hazards']);
check($r['status'] === 'regulated', 'status stays regulated when only minor fields overridden', $r['status']);

// ---------------------------------------------------------------------
echo "3. Q2: no flash point → Not regulated, no gate; 3 + 8 + 6.1 with 8 outranking 3 → Not determined (publish gate)\n";
$r = $s14->invoke($gen, $fg, $calc(null), $hz([]), []);
$nd   = $t->get('labels.not_determined');
$nrQ2 = $t->get('labels.not_regulated');
check($r['un_number'] === $nrQ2 && $r['proper_shipping_name'] === $nrQ2 && $r['hazard_class'] === $nrQ2, 'no flash point data → Not regulated x3 (Q2)', $r);
check($r['packing_group'] === $t->get('labels.not_applicable'), 'packing_group Not applicable', $r['packing_group']);
check($r['environmental_hazards'] === $t->get('section14.marine_pollutant_no'), 'environmental_hazards marine pollutant no', $r['environmental_hazards']);
check($r['status'] === 'not_regulated', 'status not_regulated', $r['status']);
check($r['note'] === $t->get('section14.note'), 'only the carrier note', $r['note']);
check(\SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'X'], 'sections' => [14 => $r]]) === null, 'no publish block for a missing flash point (Q2)');
$r = $s14->invoke($gen, $fg, $calc(null), $hz(['H319']), []);
check($r['status'] === 'not_regulated', 'no flash point + H319 → not_regulated', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(40.0), $hz(['H226', 'H314', 'H301']), []);
check($r['un_number'] === $nd && $r['proper_shipping_name'] === $nd && $r['hazard_class'] === $nd && $r['packing_group'] === $nd, 'four lines Not determined', $r);
check($r['environmental_hazards'] === $nd, 'environmental_hazards Not determined', $r['environmental_hazards']);
check($r['status'] === 'not_determined', 'status not_determined', $r['status']);
check($r['note'] === $t->get('section14.note'), 'only the carrier note', $r['note']);
$err = \SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'X'], 'sections' => [14 => $r]]);
check(is_string($err) && str_contains($err, 'Publishing blocked') && str_contains($err, 'X') && !str_contains($err, 'carries a flash point'), 'transportNotDeterminedError message (no flash-point cause)', $err);
$ok = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226']), []);
check(\SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'X'], 'sections' => [14 => $ok]]) === null, 'null for status regulated');
check(\SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => [], 'sections' => []]) === null, 'null for a snapshot without status');
// #7: a partial override on a not-determined product stays blocked; the full set unblocks it.
$r = $s14->invoke($gen, $fg, $calc(40.0), $hz(['H226', 'H314', 'H301']), [14 => ['un_number' => 'UN2920', 'hazard_class' => '8 (3, 6.1)']]);
check($r['status'] === 'override_incomplete' && is_string($gate($r)), 'UN + class only on not-determined → override_incomplete, blocked', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(40.0), $hz(['H226', 'H314', 'H301']), [14 => ['un_number' => 'UN2920', 'proper_shipping_name' => 'Corrosive liquids, flammable, n.o.s. (X)', 'hazard_class' => '8 (3, 6.1)', 'packing_group' => 'II']]);
check($r['status'] === 'override' && $gate($r) === null, 'full override on not-determined → override, unblocked', $r['status']);
check($r['environmental_hazards'] === $t->get('section14.marine_pollutant_no'), 'environmental line from the H-codes, not "Not determined"', $r['environmental_hazards']);

echo "3b. Unsupported class (Q3, #4)\n";
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226', 'H242']), []);
check($r['un_number'] === $nd && $r['proper_shipping_name'] === $nd && $r['hazard_class'] === $nd && $r['packing_group'] === $nd && $r['environmental_hazards'] === $nd, 'five lines Not determined', $r);
check($r['status'] === 'not_determined' && ($r['status_reason'] ?? null) === 'unsupported_class' && ($r['status_codes'] ?? null) === ['H242'], 'status not_determined, reason unsupported_class, codes [H242]', $r);
check($r['note'] === $t->get('section14.note'), 'carrier note only', $r['note']);
$err = $gate($r, 'X', 'es', ['h_statements' => [['code' => 'H226'], ['code' => 'H242']]]);
check(is_string($err) && str_contains($err, 'Publishing blocked') && str_contains($err, 'X (ES sheet)') && str_contains($err, 'H242') && !str_contains($err, 'flash point'), 'gate message names the code and the language', $err);
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226', 'H242']), [14 => ['un_number' => 'UN3105', 'hazard_class' => '5.2']]);
check($r['status'] === 'override_incomplete' && is_string($gate($r)), 'UN3105 + 5.2 only → still blocked', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226', 'H242']), [14 => ['un_number' => 'UN3105', 'proper_shipping_name' => 'Organic peroxide type D, liquid', 'hazard_class' => '5.2', 'packing_group' => 'Not applicable']]);
check($r['status'] === 'override' && $gate($r) === null, 'full set → override, publishes', $r['status']);
check($r['environmental_hazards'] === $t->get('section14.marine_pollutant_no'), 'environmental line reads marine pollutant no, not Not determined', $r['environmental_hazards']);
$fgGas = $fg;
$fgGas['physical_state'] = 'Gas';
$r = $s14->invoke($gen, $fgGas, $calc(null), $hz([]), []);
check($r['status'] === 'not_determined' && ($r['status_reason'] ?? null) === 'unsupported_class', 'Gas physical state → not determined', $r);
check(str_contains((string) $gate($r), 'Gas physical state'), 'gate message names the Gas state', $gate($r));
// Multi-hazard with a real engine Skin Corr. row
$r = $s14->invoke($gen, $fg, $calc(30.0), $hz(['H226', 'H331', 'H314'], [$hcRow(\SDS\Services\GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1B')]), []);
check($r['status'] === 'not_determined' && ($r['status_reason'] ?? null) === \SDS\Services\TransportClassifier::REASON_THREE_CLASS, '3 + 8 (1B) + 6.1 → three_class_precedence', $r);
check(str_contains((string) $gate($r), '173.2a'), 'multi-hazard gate message cites 173.2a', $gate($r));

// ---------------------------------------------------------------------
echo "4. Combustible liquid fp 70 °C (engine H227) → Not regulated + combustible note\n";
$r = $s14->invoke($gen, $fg, $calc(70.0), $hz(['H227']), []);
$nr = $t->get('labels.not_regulated');
check($r['un_number'] === $nr && $r['proper_shipping_name'] === $nr && $r['hazard_class'] === $nr, 'Not regulated x3', $r);
check($r['packing_group'] === $t->get('labels.not_applicable'), 'packing_group Not applicable', $r['packing_group']);
check($r['environmental_hazards'] === $t->get('section14.marine_pollutant_no'), 'marine pollutant no', $r['environmental_hazards']);
check(str_starts_with($r['note'], $t->get('section14.note_combustible')), 'note starts with combustible note', $r['note']);
check($r['status'] === 'not_regulated', 'status not_regulated', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), []);
check($r['note'] === $t->get('section14.note'), '> 95 °C: no combustible note', $r['note']);

// ---------------------------------------------------------------------
echo "5. One flammability source (Q3): Section 14 follows the engine codes, never the Section 9 edit\n";
$r = $s14->invoke($gen, $fg, $calc(30.0), $hz(['H226']), [9 => ['flash_point' => '> 95 °C (203 °F)']]);
check($r['status'] === 'regulated' && $r['hazard_class'] === '3', 'edit "> 95 °C" ignored: engine H226 → Class 3', $r);
$r = $s14->invoke($gen, $fg, $calc(95.0, true), $hz([]), [9 => ['flash_point' => '100 °F']]);
check($r['status'] === 'not_regulated', 'edit "100 °F" ignored: no engine H224-H226 → not regulated', $r['status']);
$r = $s14->invoke($gen, $fg, $calc(55.0, true), $hz(['H226']), []);
check($r['status'] === 'regulated' && $r['hazard_class'] === '3' && $r['packing_group'] === 'III', 'formula "> 55 °C" / H226 → Class 3 PG III', $r);
$r = $s14->invoke($gen, $fg, $calc(40.0), $hz(['H225']), []);
check($r['packing_group'] === 'II', '#5: H225 → PG II even at fp 40', $r['packing_group']);
$r = $s14->invoke($gen, $fg, $calc(60.0), $hz(['H226']), []);
check($r['status'] === 'regulated' && $r['hazard_class'] === '3' && $r['packing_group'] === 'III', '#44(1): fp 60.0 / H226 → regulated Class 3 PG III', $r);
$r = $s14->invoke($gen, $fg, $calc(92.0), $hz(['H227']), []);
check(str_starts_with($r['note'], $t->get('section14.note_combustible')), 'fp 92 / H227 → combustible note', $r['note']);
$r = $s14->invoke($gen, $fg, $calc(65.0, true), $hz(['H227']), []);
check($r['status'] === 'not_regulated' && str_starts_with($r['note'], $t->get('section14.note_combustible')), '"> 65" / H227 counts as 65 (Q1) → combustible note', $r);
$fgPaste = $fg;
$fgPaste['physical_state'] = 'Paste';
$r = $s14->invoke($gen, $fgPaste, $calc(30.0), $hz([]), []);
check($r['status'] === 'not_regulated' && $r['note'] === $t->get('section14.note'), 'Paste fp 30 (engine: not a flammable liquid) → not regulated, carrier note only', $r);
// The engine's flammability block wins over formula_props (the value the engine classified from).
$hzBlock = $hz(['H227']) + ['flammability' => \SDS\Services\HazardEngine::flammabilityFromProps(['flash_point_c' => 95.0, 'physical_state' => 'Liquid'])];
$r = $s14->invoke($gen, $fg, $calc(75.0), $hzBlock, []);
check($r['note'] === $t->get('section14.note'), 'flammability block fp 95 used (not props 75) → no combustible note', $r['note']);

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
$r = $s14->invoke($gen, $fgWash, $calc(40.0, false, $comp), $hz(['H226']), []);
check($r['un_number'] === 'UN1993' && $r['proper_shipping_name'] === $t->get('section14.psn_flammable_liquid_nos'), 'bare n.o.s. PSN (no technical names)', $r);
check(($r['technical_names_missing'] ?? null) === true, 'technical_names_missing => true (generate() warns, then drops the key)', $r);
$r = $s14->invoke($gen, $fgWash, $calc(40.0, false, $comp), $hz(['H226']), [14 => ['proper_shipping_name' => 'Flammable liquids, n.o.s. (Isopropanol)']]);
check(!array_key_exists('technical_names_missing', $r), 'PSN override supplies the names → no flag', array_keys($r));
check($r['status'] === 'override', 'PSN-only override on a regulated product → complete (#7)', $r['status']);
// #44(3): the family name no longer switches the entry.
$fgFam = ['description' => 'PROCESS BLUE', 'family' => 'Water-Based Coating', 'physical_state' => 'Liquid', 'transport_product_type' => null];
$r = $s14->invoke($gen, $fgFam, $calc(30.0), $hz(['H226']), []);
check($r['un_number'] === 'UN1210', 'family "Water-Based Coating" member PROCESS BLUE → UN1210 (#44(3))', $r['un_number']);
// Class 3 technical-name fallback: constituents of raw materials with fp <= 60 °C.
$compFlam = [
    ['cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol', 'concentration_pct' => 30, 'contributing_materials' => [['raw_material_id' => 11]]],
    ['cas_number' => '7732-18-5', 'chemical_name' => 'Water', 'concentration_pct' => 70, 'contributing_materials' => [['raw_material_id' => 11], ['raw_material_id' => 12]]],
];
$c = $calc(40.0, false, $compFlam);
$c['formula_props']['enriched_lines'] = [['raw_material_id' => 11, 'flash_point_c' => 12.0], ['raw_material_id' => 12, 'flash_point_c' => 100.0]];
$r = $s14->invoke($gen, $fgWash, $c, $hz(['H226']), []);
check($r['proper_shipping_name'] === $t->get('section14.psn_flammable_liquid_nos') . ' (Isopropanol)', 'fallback names Isopropanol (water never)', $r['proper_shipping_name']);
check(!array_key_exists('technical_names_missing', $r), 'no technical_names_missing flag', array_keys($r));

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
    'section14.transport_in_bulk_text', 'section14.special_precautions_text', 'labels.transport_in_bulk', 'labels.special_precautions',   // #43
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
    check(!str_contains($tl->get('section14.note_viscous'), '≤'), "{$lang}: viscous note has no '≤' (#51, core font)", $tl->get('section14.note_viscous'));
    check(str_contains($rL['transport_in_bulk'], 'MARPOL') && str_contains($rL['transport_in_bulk'], 'IBC'), "{$lang}: transport_in_bulk cites MARPOL and IBC", $rL['transport_in_bulk']);
}

// ---------------------------------------------------------------------
echo "9. Reason-coded Not determined (audit #45)\n";
$r = $s14->invoke($gen, $fg, $calc(40.0), $hz(['H226', 'H314', 'H301']), []);
check($r['status'] === 'not_determined' && ($r['status_reason'] ?? null) === \SDS\Services\TransportClassifier::REASON_THREE_CLASS, '3 + 8 + 6.1 with 8 outranking 3 → status_reason three_class_precedence', $r);
$ok = $s14->invoke($gen, $fg, $calc(38.0), $hz(['H226']), []);
check(!array_key_exists('status_reason', $ok), 'no status_reason when decided', array_keys($ok));
$errFg = \SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'TRI-1', 'finished_good_id' => 7], 'sections' => [14 => $r]]);
check(is_string($errFg) && str_contains($errFg, 'TRI-1') && str_contains($errFg, '173.2a') && str_contains($errFg, 'SDS > Edit') && !str_contains($errFg, 'flash point'), 'FG three-class message', $errFg);
$errRs = \SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'RS-1', 'finished_good_id' => 0], 'sections' => [14 => $r]]);
check(is_string($errRs) && str_contains($errRs, 'Edit SDS text') && !str_contains($errRs, 'SDS > Edit ('), 'resale message points to the resale editor', $errRs);
$errUn = \SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'AE-1'], 'sections' => [14 => ['status' => 'not_determined', 'status_reason' => 'unsupported_class', 'status_codes' => ['H222', 'H229']]]]);
check(is_string($errUn) && str_contains($errUn, 'H222, H229') && str_contains($errUn, 'aerosol'), 'unsupported-class message lists the codes', $errUn);
$errLg = \SDS\Services\SDSReadinessService::transportNotDeterminedError(['meta' => ['product_code' => 'OLD'], 'sections' => [14 => ['status' => 'not_determined']]]);
check(is_string($errLg) && str_contains($errLg, 'Publishing blocked') && !str_contains($errLg, 'flash point'), 'legacy snapshot without reason → generic message', $errLg);
$src = (string) file_get_contents($basePath . '/src/Services/SDSGenerator.php');
check(substr_count($src, 'SDSReadinessService::transportNotDeterminedError($sds)') === 2, 'generate() and generateFromBase() both add the preview warning');

// ---------------------------------------------------------------------
echo "#7 round trip: editor save (TextOverrideService::plan) → section14()\n";
$TOS = \SDS\Services\TextOverrideService::class;
$derived = $s14->invoke($gen, $fg, $calc(15.0), $hz(['H225']), []);
check($derived['un_number'] === 'UN1210' && $derived['hazard_class'] === '3' && $derived['packing_group'] === 'II', 'derived UN1210 / 3 / II', $derived);
$posted = [14 => ['un_number' => 'UN1263', 'proper_shipping_name' => 'Paint', 'hazard_class' => '3', 'packing_group' => 'II']];
$plan = $TOS::plan($posted, [14 => $derived], []);
$stored = [];
foreach ($plan['upsert'] as $u) {
    $stored[$u['section']][$u['key']] = $u['text'];
}
check(isset($stored[14]['hazard_class'], $stored[14]['packing_group'], $stored[14]['un_number'], $stored[14]['proper_shipping_name']), 'plan() stores all four core fields, incl. class / PG equal to the derived ones', $plan);
$r = $s14->invoke($gen, $fg, $calc(15.0), $hz(['H225']), $TOS::effective($stored));
check($r['status'] === 'override' && $gate($r) === null, 'stored set → status override, publish not blocked', $r['status']);
$plan2 = $TOS::plan($posted, [14 => $derived], $stored);
check($plan2['delete'] === [] && $plan2['upsert'] === [] && $plan2['counts']['unchanged'] === 4, 're-save keeps all four rows', $plan2);
$plan3 = $TOS::plan([14 => ['un_number' => '', 'proper_shipping_name' => '', 'hazard_class' => '3', 'packing_group' => 'II']], [14 => $derived], $stored);
check(count($plan3['delete']) === 4 && $plan3['upsert'] === [], 'UN / PSN cleared → the default-equal class / PG rows go too', $plan3);
$plan4 = $TOS::plan([14 => ['un_number' => 'UN1210', 'proper_shipping_name' => '', 'hazard_class' => '3', 'packing_group' => 'II']], [14 => $derived], []);
check($plan4['upsert'] === [], 'only derived values posted → nothing stored', $plan4);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
