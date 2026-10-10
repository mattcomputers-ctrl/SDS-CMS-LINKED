<?php
/**
 * DB-free checks for TransportClassifier (SDS content audit item #27):
 * Section 14 DOT classification derived from the flash point, the initial
 * boiling point, the engine's H-codes and the product type.
 *
 * Same bootstrap/check() helper as SDSGeneratorSection5Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/TransportClassifierTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\TransportClassifier as TC;
use SDS\Services\GHSHazardClass;

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

/** Builds a classifier input: description 'TEST INK', physical_state 'Liquid' unless overridden in $extra. */
$in = static fn (?float $fp, bool $gt = false, ?float $bp = null, array $codes = [], array $extra = []): array => array_merge([
    'flash_point_c'            => $fp,
    'flash_point_greater_than' => $gt,
    'boiling_point_c'          => $bp,
    'h_codes'                  => $codes,
    'hazard_classes'           => [],
    'cas_h_codes'              => [],
    'composition'              => [],
    'physical_state'           => 'Liquid',
    'product_type'             => null,
    'description'              => 'TEST INK',
    'family'                   => null,
], $extra);

/** A hazard_classes row in the shape HazardEngine emits (finding #3: canonical = GHSHazardClass constant). */
$hc = static fn (string $canonical, string $cat, string $cas = 'MIXTURE'): array => [
    'class' => GHSHazardClass::displayName($canonical), 'category' => 'Category ' . substr($cat, 4),
    'canonical' => $canonical, 'category_canonical' => $cat, 'cas' => $cas,
    'chemical' => $cas === 'MIXTURE' ? 'Multiple components (summation)' : 'Test constituent',
    'concentration_pct' => 5.0, 'cutoff_pct' => 1.0,
];

// ---------------------------------------------------------------------
echo "a. fp > 95 °C, no codes → not regulated\n";
$r = TC::classify($in(95.0, true));
check($r['status'] === TC::STATUS_NOT_REGULATED, 'status not_regulated', $r['status']);
check($r['notes'] === [], 'no notes', $r['notes']);
check($r['marine_pollutant'] === false, 'marine_pollutant false', $r['marine_pollutant']);
check($r['un_number'] === null && $r['hazard_class'] === null && $r['packing_group'] === null, 'no UN / class / PG', $r);
check($r['reason'] === null && $r['reason_codes'] === [], 'reason null when decided', $r['reason']);

// ---------------------------------------------------------------------
echo "b. fp 65 °C combustible liquid (engine H227) with a non-transport code\n";
$r = TC::classify($in(65.0, false, null, ['H227', 'H319']));
check($r['status'] === TC::STATUS_NOT_REGULATED, 'status not_regulated', $r['status']);
check($r['notes'] === ['combustible'], 'combustible note (engine H227)', $r['notes']);
$r = TC::classify($in(65.0, false, null, ['H319']));
check($r['notes'] === [], 'Q3: no H227 in the codes → no combustible note (one flammability source)', $r['notes']);

// ---------------------------------------------------------------------
echo "c. Solvent ink fp 38 °C H226 → UN1210 Class 3 PG III, no viscous note (PG III gains nothing from 173.121(b)(1))\n";
$r = TC::classify($in(38.0, false, null, ['H226', 'H319'], ['description' => 'SOLVENT INK']));
check($r['status'] === TC::STATUS_REGULATED, 'status regulated', $r['status']);
check($r['un_number'] === 'UN1210', 'UN1210', $r['un_number']);
check($r['psn_key'] === 'printing_ink', 'psn_key printing_ink', $r['psn_key']);
check($r['hazard_class'] === '3', 'hazard_class 3', $r['hazard_class']);
check($r['packing_group'] === 'III', 'PG III', $r['packing_group']);
check($r['notes'] === [], 'no viscous note on PG III', $r['notes']);
check($r['technical_names'] === [], 'no technical names for UN1210', $r['technical_names']);
check($r['technical_names_missing'] === false, 'technical_names_missing false for UN1210', $r['technical_names_missing']);
check($r['product_type'] === TC::TYPE_INK, 'product_type ink', $r['product_type']);

// ---------------------------------------------------------------------
echo "d. OPV coating fp 10 °C bp 80 °C H225 → UN1263 PG II + viscous note (173.121(b)(1) reassignment candidate)\n";
$r = TC::classify($in(10.0, false, 80.0, ['H225'], ['description' => 'GLOSS OPV COATING']));
check($r['un_number'] === 'UN1263', 'UN1263', $r['un_number']);
check($r['psn_key'] === 'paint', 'psn_key paint', $r['psn_key']);
check($r['packing_group'] === 'II', 'PG II', $r['packing_group']);
check($r['notes'] === ['viscous'], 'viscous note on Class 3 PG II with no subsidiary', $r['notes']);
check($r['hazard_class'] === '3', 'hazard_class 3', $r['hazard_class']);
$r = TC::classify($in(10.0, false, 80.0, ['H225', 'H314'], ['description' => 'GLOSS OPV COATING']));
check($r['notes'] === [], 'no viscous note when a subsidiary (8) is present (173.121(b)(1)(ii))', $r['notes']);

// ---------------------------------------------------------------------
echo "e. fp -5 °C bp 30 °C H224 → PG I\n";
$r = TC::classify($in(-5.0, false, 30.0, ['H224'], ['description' => 'INK']));
check($r['packing_group'] === 'I', 'PG I from boiling point <= 35', $r['packing_group']);
check($r['un_number'] === 'UN1210', 'UN1210', $r['un_number']);
check($r['notes'] === [], 'no viscous note for PG I', $r['notes']);

// ---------------------------------------------------------------------
echo "e2. H224 (Flam. Liq. 1 = IBP <= 35 °C) with a known flash point and no IBP → PG I\n";
$r = TC::classify($in(-5.0, false, null, ['H224'], ['description' => 'INK']));
check($r['packing_group'] === 'I', 'fp -5 / bp null / H224 → PG I', $r['packing_group']);
$r = TC::classify($in(10.0, false, null, ['H224', 'H336'], ['description' => 'BLACK INK']));
check($r['packing_group'] === 'I', 'fp 10 / bp null / H224 → PG I (not PG II from fp < 23)', $r['packing_group']);
$r = TC::classify($in(30.0, false, null, ['H224'], ['description' => 'INK']));
check($r['packing_group'] === 'I' && $r['notes'] === [], 'fp 30 / bp null / H224 → PG I, no viscous note', $r);
$r = TC::classify($in(-5.0, false, 80.0, ['H224'], ['description' => 'INK']));
check($r['packing_group'] === 'II', 'measured IBP 80 > 35 wins over H224 → PG II', $r['packing_group']);

// ---------------------------------------------------------------------
echo "f. Flam. Liq. code (engine / finished-good override) is Class 3 without a flash point\n";
$r = TC::classify($in(null, false, null, ['H225']));
check($r['status'] === TC::STATUS_REGULATED, 'H225 without fp → regulated', $r['status']);
check($r['hazard_class'] === '3', 'class 3', $r['hazard_class']);
check($r['packing_group'] === 'II', 'H225 → PG II', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H226']));
check($r['packing_group'] === 'III', 'H226 → PG III', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H224']));
check($r['packing_group'] === 'I', 'H224 → PG I', $r['packing_group']);

// ---------------------------------------------------------------------
echo "g. Q2: no flash point and no codes → not regulated (a missing flash point is no flash point; no publish block)\n";
$r = TC::classify($in(null));
check($r['status'] === TC::STATUS_NOT_REGULATED, 'status not_regulated', $r['status']);
check($r['un_number'] === null && $r['notes'] === [], 'no UN, no notes', $r);
$r = TC::classify($in(null, false, null, ['H317', 'H319']));
check($r['status'] === TC::STATUS_NOT_REGULATED && $r['notes'] === [], 'no flash point + non-transport codes → not regulated, no combustible note', $r);

// ---------------------------------------------------------------------
echo "h. Flammable PG III + corrosive PG II → 49 CFR 173.2a(b): Class 8 primary, UN2920 '8 (3)' PG II\n";
$comp = [
    ['cas_number' => '64-17-5', 'chemical_name' => 'Ethanol', 'concentration_pct' => 40],
    ['cas_number' => '1310-73-2', 'chemical_name' => 'Sodium hydroxide', 'concentration_pct' => 5],
];
$h = ['composition' => $comp, 'cas_h_codes' => ['64-17-5' => ['H225'], '1310-73-2' => ['H314']]];
$corr1b = ['hazard_classes' => [$hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1B')]];
$corr1a = ['hazard_classes' => [$hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1A')]];
$corr1c = ['hazard_classes' => [$hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1C')]];
$r = TC::classify($in(30.0, false, null, ['H226', 'H314'], $h + $corr1b));
check($r['un_number'] === 'UN2920', 'UN2920', $r['un_number']);
check($r['psn_key'] === 'corrosive_liquid_flammable_nos', 'psn_key corrosive_liquid_flammable_nos', $r['psn_key']);
check($r['hazard_class'] === '8 (3)', 'hazard_class 8 (3)', $r['hazard_class']);
check($r['primary_class'] === '8' && $r['subsidiary'] === ['3'], 'primary 8, subsidiary [3]', $r);
check($r['packing_group'] === 'II', 'PG II (most stringent)', $r['packing_group']);
check($r['technical_names'] === ['Sodium hydroxide'], 'technical names = corrosive constituents (primary hazard)', $r['technical_names']);
check($r['notes'] === [], 'no viscous note', $r['notes']);
// 3 PG III vs 8 PG II with no sub-category entry (defaults to PG II) → still 8 primary.
$r = TC::classify($in(30.0, false, null, ['H226', 'H314'], $h));
check($r['un_number'] === 'UN2920' && $r['hazard_class'] === '8 (3)', 'unknown Skin Corr. sub-category (PG II) → UN2920 8 (3)', $r);
// 3 PG III vs 8 PG III (1C) → Class 3 stays primary: UN2924 3 (8) PG III.
$r = TC::classify($in(30.0, false, null, ['H226', 'H314'], $h + $corr1c));
check($r['un_number'] === 'UN2924' && $r['hazard_class'] === '3 (8)' && $r['packing_group'] === 'III', 'fp 30 + Skin Corr. 1C → UN2924 3 (8) III', $r);
check($r['technical_names'] === ['Ethanol'], 'technical names = flammable constituents when 3 is primary', $r['technical_names']);
check($r['notes'] === [], 'no viscous note on 3 (8)', $r['notes']);
// 3 PG II (fp 15) vs 8 PG II (1B) → Class 3 stays primary: UN2924 3 (8) PG II.
$r = TC::classify($in(15.0, false, null, ['H225', 'H314'], $h + $corr1b));
check($r['un_number'] === 'UN2924' && $r['hazard_class'] === '3 (8)' && $r['packing_group'] === 'II', 'fp 15 / H225 + Skin Corr. 1B → UN2924 3 (8) II (control)', $r);
// 3 PG II (fp 15) vs 8 PG I (1A) → Class 8 primary: UN2920 8 (3) PG I.
$r = TC::classify($in(15.0, false, null, ['H225', 'H314'], $h + $corr1a));
check($r['un_number'] === 'UN2920' && $r['hazard_class'] === '8 (3)' && $r['packing_group'] === 'I', 'fp 15 / H225 + Skin Corr. 1A → UN2920 8 (3) I', $r);

// ---------------------------------------------------------------------
echo "i. Flammable + toxic (+ corrosive)\n";
$r = TC::classify($in(30.0, false, null, ['H226', 'H331']));
check($r['un_number'] === 'UN1992', 'UN1992 (3 PG III vs 6.1 PG III stays Class 3)', $r['un_number']);
check($r['hazard_class'] === '3 (6.1)', 'hazard_class 3 (6.1)', $r['hazard_class']);
check($r['packing_group'] === 'III', 'PG III', $r['packing_group']);
check($r['psn_key'] === 'flammable_liquid_toxic_nos', 'psn_key', $r['psn_key']);
// 3 PG III vs 6.1 PG II (Acute Tox. 2 inhalation) → 6.1 primary: UN2929 6.1 (3) PG II.
$tox2 = ['hazard_classes' => [$hc(GHSHazardClass::ACUTE_TOXICITY_INHALATION, 'Cat 2')]];
$r = TC::classify($in(30.0, false, null, ['H226', 'H330'], $tox2 + [
    'composition' => [['cas_number' => '7664-41-7', 'chemical_name' => 'Ammonia', 'concentration_pct' => 3]],
    'cas_h_codes' => ['7664-41-7' => ['H330']],
]));
check($r['un_number'] === 'UN2929', 'fp 30 + H330 cat 2 → UN2929', $r['un_number']);
check($r['psn_key'] === 'toxic_liquid_flammable_organic_nos', 'psn_key toxic_liquid_flammable_organic_nos', $r['psn_key']);
check($r['hazard_class'] === '6.1 (3)' && $r['packing_group'] === 'II', 'hazard_class 6.1 (3) PG II', $r);
check($r['technical_names'] === ['Ammonia'], 'technical names = toxic constituents (primary hazard)', $r['technical_names']);
check($r['notes'] === [], 'no viscous note', $r['notes']);
// 3 PG III vs 6.1 PG II from the H300 code fallback (no class entry) → UN2929.
$r = TC::classify($in(30.0, false, null, ['H226', 'H300']));
check($r['un_number'] === 'UN2929' && $r['hazard_class'] === '6.1 (3)' && $r['packing_group'] === 'II', 'fp 30 + H300 (PG II fallback) → UN2929 6.1 (3) II', $r);
// 3 PG II (fp 15) vs 6.1 PG II → Class 3 stays primary.
$r = TC::classify($in(15.0, false, null, ['H225', 'H300']));
check($r['un_number'] === 'UN1992' && $r['hazard_class'] === '3 (6.1)' && $r['packing_group'] === 'II', 'fp 15 / H225 + H300 → UN1992 3 (6.1) II (control)', $r);
// 6.1 PG I by inhalation outranks Class 3 at any flash point (173.2a(a)).
$tox1inh = ['hazard_classes' => [$hc(GHSHazardClass::ACUTE_TOXICITY_INHALATION, 'Cat 1')]];
$r = TC::classify($in(15.0, false, null, ['H225', 'H330'], $tox1inh));
check($r['un_number'] === 'UN2929' && $r['hazard_class'] === '6.1 (3)' && $r['packing_group'] === 'I', 'fp 15 + inhalation cat 1 → UN2929 6.1 (3) I', $r);
// Three hazards: table keeps Class 3 (6.1 PG III, 8 PG III) → UN3286; otherwise not determined.
$r = TC::classify($in(30.0, false, null, ['H226', 'H331', 'H314'], $corr1c));
check($r['un_number'] === 'UN3286', 'UN3286 when 3 outranks both 6.1 III and 8 III', $r['un_number']);
check($r['hazard_class'] === '3 (6.1, 8)', 'hazard_class 3 (6.1, 8)', $r['hazard_class']);
check($r['psn_key'] === 'flammable_liquid_toxic_corrosive_nos', 'psn_key', $r['psn_key']);
$r = TC::classify($in(30.0, false, null, ['H226', 'H331', 'H314'], $corr1b));
check($r['status'] === TC::STATUS_NOT_DETERMINED && $r['un_number'] === null, '3 III + 6.1 III + 8 II (8 outranks 3): not determined, no invented entry', $r);
check($r['reason'] === TC::REASON_THREE_CLASS, 'reason three_class_precedence', $r['reason']);

// ---------------------------------------------------------------------
echo "j. Aquatic only → Class 9 UN3082 / UN3077, marine pollutant\n";
$r = TC::classify($in(95.0, true, null, ['H410']));
check($r['un_number'] === 'UN3082', 'UN3082 liquid', $r['un_number']);
check($r['psn_key'] === 'env_hazardous_liquid_nos', 'psn_key liquid', $r['psn_key']);
check($r['hazard_class'] === '9', 'class 9', $r['hazard_class']);
check($r['packing_group'] === 'III', 'PG III', $r['packing_group']);
check($r['marine_pollutant'] === true, 'marine pollutant', $r['marine_pollutant']);
check($r['notes'] === [], 'no notes (combustible note only when not regulated)', $r['notes']);
$r = TC::classify($in(95.0, true, null, ['H410'], ['physical_state' => 'Powder']));
check($r['un_number'] === 'UN3077', 'UN3077 solid', $r['un_number']);
check($r['psn_key'] === 'env_hazardous_solid_nos', 'psn_key solid', $r['psn_key']);

// ---------------------------------------------------------------------
echo "k. Flammable + aquatic → UN1210 class 3 with marine pollutant mark\n";
$r = TC::classify($in(30.0, false, null, ['H226', 'H411']));
check($r['un_number'] === 'UN1210', 'UN1210', $r['un_number']);
check($r['hazard_class'] === '3', 'class 3 (aquatic is not a subsidiary class)', $r['hazard_class']);
check($r['marine_pollutant'] === true, 'marine pollutant true', $r['marine_pollutant']);

// ---------------------------------------------------------------------
echo "l. Toxic only → UN2810 6.1; PG from class entry\n";
$r = TC::classify($in(null, false, null, ['H331']));
check($r['un_number'] === 'UN2810', 'UN2810', $r['un_number']);
check($r['hazard_class'] === '6.1', 'class 6.1', $r['hazard_class']);
check($r['packing_group'] === 'III', 'H331 without class entry → PG III', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H330'], [
    'hazard_classes' => [$hc(GHSHazardClass::ACUTE_TOXICITY_INHALATION, 'Cat 1')],
]));
check($r['packing_group'] === 'I', 'Acute Tox Cat 1 → PG I', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H330']));
check($r['packing_group'] === 'II', 'H330 without class entry → PG II', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H301'], ['physical_state' => 'Solid']));
check($r['un_number'] === 'UN2811', 'solid → UN2811', $r['un_number']);

// ---------------------------------------------------------------------
echo "m. Corrosive only → UN1760 8; PG from Skin Corr. sub-category\n";
$r = TC::classify($in(95.0, true, null, ['H314'], [
    'hazard_classes' => [$hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1A')],
]));
check($r['un_number'] === 'UN1760', 'UN1760', $r['un_number']);
check($r['hazard_class'] === '8', 'class 8', $r['hazard_class']);
check($r['packing_group'] === 'I', 'Cat 1A → PG I', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314'], [
    'hazard_classes' => [$hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1C')],
]));
check($r['packing_group'] === 'III', 'Cat 1C → PG III', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314']));
check($r['packing_group'] === 'II', 'no entry → PG II', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314', 'H331'], ['physical_state' => 'Solid']));
check($r['un_number'] === 'UN2923' && $r['hazard_class'] === '8 (6.1)', 'corrosive + toxic solid → UN2923 8 (6.1)', $r);

// ---------------------------------------------------------------------
echo "m2. Non-flammable 8 vs 6.1 (173.2a(b))\n";
$oral1 = ['hazard_classes' => [$hc(GHSHazardClass::ACUTE_TOXICITY_ORAL, 'Cat 1'), $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1B')]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H300'], $oral1));
check($r['un_number'] === 'UN2927' && $r['hazard_class'] === '6.1 (8)' && $r['packing_group'] === 'I', '6.1 I oral vs 8 II liquid → UN2927 6.1 (8) I', $r);
check($r['psn_key'] === 'toxic_liquid_corrosive_organic_nos', 'psn_key toxic_liquid_corrosive_organic_nos', $r['psn_key']);
$r = TC::classify($in(95.0, true, null, ['H314', 'H300'], $oral1 + ['physical_state' => 'Solid']));
check($r['un_number'] === 'UN2928' && $r['psn_key'] === 'toxic_solid_corrosive_organic_nos', 'solid → UN2928', $r);
$oral1corr1a = ['hazard_classes' => [$hc(GHSHazardClass::ACUTE_TOXICITY_ORAL, 'Cat 1'), $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1A')]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H300'], $oral1corr1a));
check($r['un_number'] === 'UN2922' && $r['hazard_class'] === '8 (6.1)' && $r['packing_group'] === 'I', '6.1 I vs 8 I liquid → 8 primary (UN2922)', $r);
$inh2corr3 = ['hazard_classes' => [$hc(GHSHazardClass::ACUTE_TOXICITY_INHALATION, 'Cat 2'), $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1C')]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H330'], $inh2corr3));
check($r['un_number'] === 'UN2927' && $r['hazard_class'] === '6.1 (8)' && $r['packing_group'] === 'II', '6.1 II inhalation vs 8 III → 6.1 primary', $r);
$inh2corr2 = ['hazard_classes' => [$hc(GHSHazardClass::ACUTE_TOXICITY_INHALATION, 'Cat 2'), $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1B')]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H330'], $inh2corr2));
check($r['un_number'] === 'UN2922' && $r['hazard_class'] === '8 (6.1)', '6.1 II inhalation vs 8 II liquid → 8 primary', $r);
$r = TC::classify($in(95.0, true, null, ['H314', 'H330'], $inh2corr2 + ['physical_state' => 'Powder']));
check($r['un_number'] === 'UN2928' && $r['hazard_class'] === '6.1 (8)', '6.1 II inhalation vs 8 II solid → 6.1 primary', $r);
$oral2corr2 = ['hazard_classes' => [$hc(GHSHazardClass::ACUTE_TOXICITY_ORAL, 'Cat 2'), $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1B')]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H300'], $oral2corr2 + ['physical_state' => 'Powder']));
check($r['un_number'] === 'UN2923' && $r['hazard_class'] === '8 (6.1)', '6.1 II oral vs 8 II solid → 8 primary', $r);

// ---------------------------------------------------------------------
echo "m3. \"> n\" counts as n (Q1): combustible note agrees with the engine's H227\n";
$r = TC::classify($in(65.0, true, null, ['H227', 'H319']));
check($r['status'] === TC::STATUS_NOT_REGULATED && $r['notes'] === ['combustible'], '"> 65 °C" counts as 65 °C (Q1) → combustible note, agrees with H227', $r);
check(\SDS\Services\HazardEngine::flammableLiquidCategory(65.0, true, null, 'Liquid') === 4, 'engine: "> 65 °C" → Flam. Liq. 4 (H227)');
$r = TC::classify($in(93.0, true, null, ['H319']));
check($r['notes'] === [], '"> 93 °C": engine assigns no H227 → no note', $r['notes']);

// ---------------------------------------------------------------------
echo "m4. n.o.s. entry with no derivable technical names → technical_names_missing\n";
$r = TC::classify($in(40.0, false, null, ['H226'], ['description' => 'BLANKET WASH', 'composition' => [
    ['cas_number' => '64742-48-9', 'chemical_name' => 'Naphtha (petroleum), hydrotreated heavy', 'concentration_pct' => 99.5],
], 'cas_h_codes' => ['64742-48-9' => ['H304']]]));
check($r['un_number'] === 'UN1993' && $r['status'] === TC::STATUS_REGULATED, 'engine H226, no constituent credited → UN1993', $r);
check($r['technical_names'] === [] && $r['technical_names_missing'] === true, 'no constituent carries any transport hazard → missing flag', $r);
// 172.203(k)(3) fallback: a constituent carrying ANOTHER transport hazard still names the entry.
$r = TC::classify($in(40.0, false, null, ['H226', 'H411'], ['description' => 'BLANKET WASH', 'composition' => [
    ['cas_number' => '64742-48-9', 'chemical_name' => 'Naphtha (petroleum), hydrotreated heavy', 'concentration_pct' => 99.5],
], 'cas_h_codes' => ['64742-48-9' => ['H304', 'H411']]]));
check($r['technical_names'] === ['Naphtha (petroleum), hydrotreated heavy'] && $r['technical_names_missing'] === false, 'fallback to any-hazard constituents', $r);
$r = TC::classify($in(40.0, false, null, ['H226'], ['description' => 'BLANKET WASH', 'composition' => [
    ['cas_number' => '111-76-2', 'chemical_name' => 'Secret', 'concentration_pct' => 60, 'is_trade_secret' => 1],
], 'cas_h_codes' => ['111-76-2' => ['H226']]]));
check($r['technical_names'] === [] && $r['technical_names_missing'] === true, 'trade-secret-only contributors → missing flag (identity withheld)', $r);

// ---------------------------------------------------------------------
echo "n. resolveProductType\n";
check(TC::resolveProductType('paint', 'SOLVENT INK', null) === 'paint', 'flag wins', TC::resolveProductType('paint', 'SOLVENT INK', null));
check(TC::resolveProductType(null, 'SOLVENT INK', 'Solvent') === 'ink', 'solvent ink is an ink', TC::resolveProductType(null, 'SOLVENT INK', 'Solvent'));
check(TC::resolveProductType(null, 'VARNISH REDUCER', null) === 'paint_related', 'varnish reducer → paint_related', TC::resolveProductType(null, 'VARNISH REDUCER', null));
check(TC::resolveProductType(null, 'INK ADDITIVE', null) === 'ink_related', 'ink additive → ink_related', TC::resolveProductType(null, 'INK ADDITIVE', null));
check(TC::resolveProductType(null, 'BLANKET WASH', null) === 'nos', 'blanket wash → nos', TC::resolveProductType(null, 'BLANKET WASH', null));
check(TC::resolveProductType(null, 'uv flexo ink', 'UV Flexo') === 'ink', 'lower-case ink → ink', TC::resolveProductType(null, 'uv flexo ink', 'UV Flexo'));
check(TC::resolveProductType('bogus', 'OPV', null) === 'paint', 'unknown flag falls back to keywords', TC::resolveProductType('bogus', 'OPV', null));
check(TC::resolveProductType(null, 'PROCESS BLUE', 'Water-Based Coating') === 'ink', 'family name ignored (finding #44(3))', TC::resolveProductType(null, 'PROCESS BLUE', 'Water-Based Coating'));
foreach ([
    ['TOPVIEW BLACK', 'ink', 'OPV inside a word no longer matches'],
    ['OVERPRINT VARNISHES', 'paint', 'plural ES'],
    ['AQUEOUS COATINGS', 'paint', 'plural S'],
    ['PRESS WASHUP', 'nos', 'WASHUP whole word'],
    ['WASHINGTON RED', 'ink', 'WASH inside a word no longer matches'],
    ['UV-COATING ADDITIVE', 'paint_related', 'hyphen is a word boundary'],
    ['PRIMEROSE RED', 'ink', 'PRIMER inside a word no longer matches'],
] as [$desc, $want, $label]) {
    $got = TC::resolveProductType(null, $desc, null);
    check($got === $want, "{$desc} → {$want} ({$label})", $got);
}

// ---------------------------------------------------------------------
echo "o. casHCodeMap attribution\n";
$map = TC::casHCodeMap(['hazard_classes' => [
    ['cas' => '64-17-5', 'h_codes' => ['H225', 'H319']],
    ['cas' => 'MIXTURE', 'h_codes' => ['H314'], 'contributors' => ['1310-73-2', 'TRADE_SECRET', '']],
    ['cas' => 'FG_OVERRIDE', 'h_codes' => ['H330']],
    ['cas' => 'TRADE_SECRET', 'h_codes' => ['H301']],
    ['cas' => '64-17-5', 'h_codes' => ['H225', 'H336']],
]]);
check(($map['64-17-5'] ?? null) === ['H225', 'H319', 'H336'], 'direct entries merged and de-duplicated', $map['64-17-5'] ?? null);
check(($map['1310-73-2'] ?? null) === ['H314'], 'MIXTURE credited to contributor', $map['1310-73-2'] ?? null);
check(!isset($map['MIXTURE']) && !isset($map['FG_OVERRIDE']) && !isset($map['TRADE_SECRET']) && !isset($map['']), 'pseudo-CAS keys absent', array_keys($map));

// ---------------------------------------------------------------------
echo "p. Technical names: top two by wt%, trade secrets skipped, n.o.s. only\n";
$r = TC::classify($in(30.0, false, null, ['H226'], [
    'description' => 'BLANKET WASH',
    'composition' => [
        ['cas_number' => '64-17-5', 'chemical_name' => 'Ethanol', 'concentration_pct' => 20],
        ['cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol', 'concentration_pct' => 50],
        ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'concentration_pct' => 25],
        ['cas_number' => '111-76-2', 'chemical_name' => 'Secret', 'concentration_pct' => 60, 'is_trade_secret' => 1],
    ],
    'cas_h_codes' => ['64-17-5' => ['H225'], '67-63-0' => ['H225'], '108-88-3' => ['H225'], '111-76-2' => ['H226']],
]));
check($r['un_number'] === 'UN1993', 'wash → UN1993', $r['un_number']);
check($r['technical_names'] === ['Isopropanol', 'Toluene'], 'top two flammable constituents, trade secret skipped', $r['technical_names']);

// ---------------------------------------------------------------------
echo "q. Q3 one flammability source: Class 3 / PG / combustible note from the engine codes (#9, #5, #44(1))\n";
$r = TC::classify($in(40.0, false, null, ['H225']));
check($r['hazard_class'] === '3' && $r['packing_group'] === 'II', '#5: H225 at fp 40 → Class 3 PG II (H225 is always at least PG II)', $r);
$r = TC::classify($in(60.0, false, 100.0, ['H226']));
check($r['status'] === TC::STATUS_REGULATED && $r['hazard_class'] === '3' && $r['packing_group'] === 'III', '#44(1): H226 at fp 60.0 → regulated Class 3 PG III', $r);
$r = TC::classify($in(30.0, false, null, ['H319']));
check($r['status'] === TC::STATUS_NOT_REGULATED && $r['hazard_class'] === null, '#9: fp 30 without engine H224-H226 → not regulated (flash point is not re-tested)', $r);
$r = TC::classify($in(25.0, false, 30.0, ['H226']));
check($r['packing_group'] === 'I', 'H226 fp 25 bp 30 → PG I (173.121(a))', $r['packing_group']);
$r = TC::classify($in(92.0, false, null, ['H227']));
check($r['notes'] === ['combustible'], 'H227 fp 92 → combustible note', $r['notes']);
$r = TC::classify($in(93.0, false, null, ['H227']));
check($r['notes'] === [], 'H227 fp 93 → no combustible note (173.120(b): below 93 °C)', $r['notes']);
$r = TC::classify($in(null, false, null, ['H227']));
check($r['notes'] === ['combustible'], 'H227 with no flash point (FG override) → combustible note', $r['notes']);

// ---------------------------------------------------------------------
echo "r. Class 3 boundaries and physical state from real engine output (Q3, #44(1))\n";
// The engine's Flammable Liquids category → H-code, exactly as HazardEngine::classify() emits it.
$engineCodes = static function (?float $fp, bool $gt = false, ?float $bp = null, string $state = 'Liquid'): array {
    $cat = \SDS\Services\HazardEngine::flammableLiquidCategory($fp, $gt, $bp, $state);
    return $cat === 0 ? [] : [[1 => 'H224', 2 => 'H225', 3 => 'H226', 4 => 'H227'][$cat]];
};
$r = TC::classify($in(60.0, false, null, $engineCodes(60.0)));
check($r['status'] === TC::STATUS_REGULATED && $r['hazard_class'] === '3' && $r['packing_group'] === 'III', 'fp 60.0 → engine H226 → Class 3 PG III', $r);
$r = TC::classify($in(60.5, false, null, $engineCodes(60.5)));
check($r['status'] === TC::STATUS_NOT_REGULATED && $r['notes'] === ['combustible'], 'fp 60.5 → engine H227 → not regulated + combustible note', $r);
$r = TC::classify($in(93.0, false, null, $engineCodes(93.0)));
check($r['status'] === TC::STATUS_NOT_REGULATED && $r['notes'] === [], 'fp 93.0 → engine H227 (Cat 4 <= 93), but 173.120(b) combustible is below 93 °C → no note', $r);
$r = TC::classify($in(30.0, false, null, $engineCodes(30.0, false, null, 'Paste'), ['physical_state' => 'Paste']));
check($r['status'] === TC::STATUS_NOT_REGULATED && $r['notes'] === [], 'Paste fp 30 → engine no code → not regulated, no note', $r);
$r = TC::classify($in(30.0, false, null, $engineCodes(30.0, false, null, 'Powder'), ['physical_state' => 'Powder']));
check($r['status'] === TC::STATUS_NOT_REGULATED, 'Powder fp 30 → not regulated', $r['status']);
$r = TC::classify($in(70.0, false, null, ['H227'], ['physical_state' => 'Paste']));
check($r['notes'] === [], 'H227 (FG override) on a Paste → no combustible-liquid note', $r['notes']);
$r = TC::classify($in(40.0, false, null, ['H225']));
check($r['packing_group'] === 'II', 'H225 → PG II whatever the flash point (#5)', $r['packing_group']);

// ---------------------------------------------------------------------
echo "s. Unsupported classes → not_determined (Q3, #4)\n";
foreach (['H242', 'H272', 'H228', 'H222', 'H229', 'H280', 'H251', 'H261', 'H205'] as $code) {
    $r = TC::classify($in(95.0, true, null, ['H319', $code]));
    check($r['status'] === TC::STATUS_NOT_DETERMINED && $r['reason'] === TC::REASON_UNSUPPORTED_CLASS
        && $r['reason_codes'] === [$code] && $r['un_number'] === null, "{$code} → not determined (unsupported_class)", $r);
}
$r = TC::classify($in(30.0, false, null, ['H226', 'H242']));
check($r['status'] === TC::STATUS_NOT_DETERMINED && $r['reason_codes'] === ['H242'], 'an unsupported class wins over Class 3', $r);
$r = TC::classify($in(null, false, null, [], ['physical_state' => 'Gas']));
check($r['status'] === TC::STATUS_NOT_DETERMINED && $r['reason'] === TC::REASON_UNSUPPORTED_CLASS && $r['reason_codes'] === [], 'Gas physical state → not determined, no codes', $r);
$r = TC::classify($in(30.0, false, null, ['H226', 'H331', 'H314'], $corr1b));
check($r['reason'] === TC::REASON_THREE_CLASS, '3 + 8 + 6.1 precedence keeps reason three_class_precedence', $r['reason']);

// ---------------------------------------------------------------------
echo "t. Real engine output shapes (finding #3)\n";
$r = TC::classify($in(95.0, true, null, ['H314'], ['hazard_classes' => [
    $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1C', '111-11-1'),
    $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1A', '1310-73-2'),
]]));
check($r['packing_group'] === 'I', 'most stringent Skin Corr. row wins, order independent → PG I', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314'], ['hazard_classes' => [
    $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 2'),
    $hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1C'),
]]));
check($r['packing_group'] === 'III', 'irritation Cat 2 ignored → PG III from 1C', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314'], ['hazard_classes' => [['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1A']]]));
check($r['packing_group'] === 'II', 'display-name row (pre-fix shape) no longer matches → PG II default', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314'], ['hazard_classes' => [$hc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 1')]]));
check($r['packing_group'] === 'II', 'Skin Corr. Cat 1 (no sub-category) → PG II', $r['packing_group']);

// ---------------------------------------------------------------------
echo "u. HazardEngine ATE output drives 6.1 (DB-free, reflection like HazardEngineAteResultsTest)\n";
$engine = new \SDS\Services\HazardEngine();
$buf = new ReflectionProperty($engine, 'ateBuffer');  $buf->setAccessible(true);
$res = new ReflectionProperty($engine, 'ateResults'); $res->setAccessible(true);
$run = new ReflectionMethod($engine, 'applyATECalculation'); $run->setAccessible(true);
$buf->setValue($engine, ['inhalation_vapor' => [['cas' => '7664-41-7', 'name' => 'Ammonia', 'conc' => 100.0, 'category' => 'Cat 1', 'ate' => 0.05, 'ate_source' => 'vendor', 'source' => 'hazard_classification']]]);
$res->setValue($engine, []);
$classes = []; $hS = []; $pS = []; $pic = []; $sw = null; $haz = [];
$args = [&$classes, &$hS, &$pS, &$pic, &$sw, &$haz];
$run->invokeArgs($engine, $args);
check(($classes[0]['canonical'] ?? null) === GHSHazardClass::ACUTE_TOXICITY_INHALATION && ($classes[0]['category_canonical'] ?? null) === 'Cat 1',
    'engine ATEmix row: canonical acute_toxicity_inhalation, Cat 1', $classes[0] ?? null);
$ateCodes = [];
foreach ($hS as $k => $v) {
    $ateCodes[] = is_string($k) ? $k : (string) (is_array($v) ? ($v['code'] ?? '') : $v);
}
$ateCodes = array_values(array_filter($ateCodes, static fn ($c) => preg_match('/^H\d{3}$/', $c) === 1));
check(in_array('H330', $ateCodes, true), 'engine emits H330', $ateCodes);
$r = TC::classify($in(15.0, false, null, array_merge(['H225'], $ateCodes), ['hazard_classes' => $classes]));
check($r['un_number'] === 'UN2929' && $r['hazard_class'] === '6.1 (3)' && $r['packing_group'] === 'I', 'fp 15 + engine inhalation Cat 1 → UN2929 6.1 (3) PG I (173.2a(a))', $r);
$r = TC::classify($in(95.0, true, null, $ateCodes, ['hazard_classes' => $classes]));
check($r['packing_group'] === 'I' && $r['un_number'] === 'UN2810', 'toxic only → UN2810 PG I', $r);

// ---------------------------------------------------------------------
echo "v. Class 3 technical-name fallback (raw materials with fp <= 60 °C)\n";
$r = TC::classify($in(40.0, false, null, ['H226'], ['description' => 'BLANKET WASH', 'composition' => [
    ['cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol', 'concentration_pct' => 30],
    ['cas_number' => '7732-18-5', 'chemical_name' => 'Water', 'concentration_pct' => 70],
], 'flammable_cas' => ['67-63-0', '7732-18-5']]));
check($r['technical_names'] === ['Isopropanol'] && $r['technical_names_missing'] === false, 'Isopropanol named, water never', $r);
$r = TC::classify($in(40.0, false, null, ['H226'], ['description' => 'BLANKET WASH', 'composition' => [
    ['cas_number' => '67-63-0', 'chemical_name' => 'Secret solvent', 'concentration_pct' => 30, 'is_trade_secret' => 1],
], 'flammable_cas' => ['67-63-0']]));
check($r['technical_names'] === [] && $r['technical_names_missing'] === true, 'trade secret never named by the fallback', $r);

// ---------------------------------------------------------------------
echo "w. Met. Corr. 1 (H290) on a liquid → Class 8 PG III (49 CFR 173.137(c)(2)); agrees with Section 13 D002\n";
$metRow = ['canonical' => GHSHazardClass::CORROSIVE_TO_METALS, 'category_canonical' => 'Cat 1', 'h_codes' => ['H290'], 'cas' => '1336-21-6'];
$r = TC::classify($in(null, false, null, ['H290', 'H315'], ['hazard_classes' => [$metRow], 'description' => 'FOUNTAIN CONCENTRATE',
    'composition' => [['cas_number' => '1336-21-6', 'chemical_name' => 'Ammonium hydroxide', 'concentration_pct' => 3]],
    'cas_h_codes' => ['1336-21-6' => ['H290']]]));
check($r['status'] === TC::STATUS_REGULATED && $r['un_number'] === 'UN1760' && $r['hazard_class'] === '8' && $r['packing_group'] === 'III', 'H290 only, liquid → UN1760 8 PG III', $r);
check($r['technical_names'] === ['Ammonium hydroxide'], 'H290 constituent named as the Class 8 technical name', $r['technical_names']);
$r = TC::classify($in(40.0, false, null, ['H226', 'H290'], ['hazard_classes' => [$metRow]]));
check($r['un_number'] === 'UN2924' && $r['hazard_class'] === '3 (8)' && $r['packing_group'] === 'III', 'H226 + H290 → UN2924 3 (8) PG III (8 PG III never outranks 3)', $r);
$r = TC::classify($in(10.0, false, 80.0, ['H225', 'H290'], ['hazard_classes' => [$metRow]]));
check($r['un_number'] === 'UN2924' && $r['hazard_class'] === '3 (8)' && $r['packing_group'] === 'II', 'H225 + H290 → UN2924 3 (8) PG II', $r);
$skin1b = ['canonical' => GHSHazardClass::SKIN_CORROSION_IRRITATION, 'category_canonical' => 'Cat 1B', 'h_codes' => ['H314']];
$r = TC::classify($in(null, false, null, ['H290', 'H314'], ['hazard_classes' => [$metRow, $skin1b]]));
check($r['un_number'] === 'UN1760' && $r['packing_group'] === 'II', 'H290 + H314 Skin Corr. 1B → PG II (Skin Corr. PG wins)', $r);
foreach (['Solid', 'Powder', 'Paste'] as $st) {
    $r = TC::classify($in(null, false, null, ['H290'], ['hazard_classes' => [$metRow], 'physical_state' => $st]));
    check($r['status'] === TC::STATUS_NOT_REGULATED, "H290 on {$st} → Not regulated (no D002 for solids either)", $r['status']);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
