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

// ---------------------------------------------------------------------
echo "a. fp > 95 °C, no codes → not regulated\n";
$r = TC::classify($in(95.0, true));
check($r['status'] === TC::STATUS_NOT_REGULATED, 'status not_regulated', $r['status']);
check($r['notes'] === [], 'no notes', $r['notes']);
check($r['marine_pollutant'] === false, 'marine_pollutant false', $r['marine_pollutant']);
check($r['un_number'] === null && $r['hazard_class'] === null && $r['packing_group'] === null, 'no UN / class / PG', $r);

// ---------------------------------------------------------------------
echo "b. fp 65 °C combustible liquid with a non-transport code\n";
$r = TC::classify($in(65.0, false, null, ['H319']));
check($r['status'] === TC::STATUS_NOT_REGULATED, 'status not_regulated', $r['status']);
check($r['notes'] === ['combustible'], 'combustible note', $r['notes']);

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
echo "f. No flash point, Flam. Liq. code stands in for it\n";
$r = TC::classify($in(null, false, null, ['H225']));
check($r['status'] === TC::STATUS_REGULATED, 'H225 without fp → regulated', $r['status']);
check($r['hazard_class'] === '3', 'class 3', $r['hazard_class']);
check($r['packing_group'] === 'II', 'H225 → PG II', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H226']));
check($r['packing_group'] === 'III', 'H226 → PG III', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H224']));
check($r['packing_group'] === 'I', 'H224 → PG I', $r['packing_group']);

// ---------------------------------------------------------------------
echo "g. No flash point and no codes → not determined\n";
$r = TC::classify($in(null));
check($r['status'] === TC::STATUS_NOT_DETERMINED, 'status not_determined', $r['status']);
check($r['un_number'] === null, 'no UN', $r['un_number']);

// ---------------------------------------------------------------------
echo "h. Flammable PG III + corrosive PG II → 49 CFR 173.2a(b): Class 8 primary, UN2920 '8 (3)' PG II\n";
$comp = [
    ['cas_number' => '64-17-5', 'chemical_name' => 'Ethanol', 'concentration_pct' => 40],
    ['cas_number' => '1310-73-2', 'chemical_name' => 'Sodium hydroxide', 'concentration_pct' => 5],
];
$h = ['composition' => $comp, 'cas_h_codes' => ['64-17-5' => ['H225'], '1310-73-2' => ['H314']]];
$corr1b = ['hazard_classes' => [['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1B']]];
$corr1a = ['hazard_classes' => [['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1A']]];
$corr1c = ['hazard_classes' => [['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1C']]];
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
$r = TC::classify($in(15.0, false, null, ['H226', 'H314'], $h + $corr1b));
check($r['un_number'] === 'UN2924' && $r['hazard_class'] === '3 (8)' && $r['packing_group'] === 'II', 'fp 15 + Skin Corr. 1B → UN2924 3 (8) II (control)', $r);
// 3 PG II (fp 15) vs 8 PG I (1A) → Class 8 primary: UN2920 8 (3) PG I.
$r = TC::classify($in(15.0, false, null, ['H226', 'H314'], $h + $corr1a));
check($r['un_number'] === 'UN2920' && $r['hazard_class'] === '8 (3)' && $r['packing_group'] === 'I', 'fp 15 + Skin Corr. 1A → UN2920 8 (3) I', $r);

// ---------------------------------------------------------------------
echo "i. Flammable + toxic (+ corrosive)\n";
$r = TC::classify($in(30.0, false, null, ['H226', 'H331']));
check($r['un_number'] === 'UN1992', 'UN1992 (3 PG III vs 6.1 PG III stays Class 3)', $r['un_number']);
check($r['hazard_class'] === '3 (6.1)', 'hazard_class 3 (6.1)', $r['hazard_class']);
check($r['packing_group'] === 'III', 'PG III', $r['packing_group']);
check($r['psn_key'] === 'flammable_liquid_toxic_nos', 'psn_key', $r['psn_key']);
// 3 PG III vs 6.1 PG II (Acute Tox. 2 inhalation) → 6.1 primary: UN2929 6.1 (3) PG II.
$tox2 = ['hazard_classes' => [['canonical' => 'Acute Toxicity (Inhalation)', 'category_canonical' => 'Cat 2']]];
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
$r = TC::classify($in(15.0, false, null, ['H226', 'H300']));
check($r['un_number'] === 'UN1992' && $r['hazard_class'] === '3 (6.1)' && $r['packing_group'] === 'II', 'fp 15 + H300 → UN1992 3 (6.1) II (control)', $r);
// 6.1 PG I by inhalation outranks Class 3 at any flash point (173.2a(a)).
$tox1inh = ['hazard_classes' => [['canonical' => 'Acute Toxicity (Inhalation)', 'category_canonical' => 'Cat 1']]];
$r = TC::classify($in(15.0, false, null, ['H226', 'H330'], $tox1inh));
check($r['un_number'] === 'UN2929' && $r['hazard_class'] === '6.1 (3)' && $r['packing_group'] === 'I', 'fp 15 + inhalation cat 1 → UN2929 6.1 (3) I', $r);
// Three hazards: table keeps Class 3 (6.1 PG III, 8 PG III) → UN3286; otherwise not determined.
$r = TC::classify($in(30.0, false, null, ['H226', 'H331', 'H314'], $corr1c));
check($r['un_number'] === 'UN3286', 'UN3286 when 3 outranks both 6.1 III and 8 III', $r['un_number']);
check($r['hazard_class'] === '3 (6.1, 8)', 'hazard_class 3 (6.1, 8)', $r['hazard_class']);
check($r['psn_key'] === 'flammable_liquid_toxic_corrosive_nos', 'psn_key', $r['psn_key']);
$r = TC::classify($in(30.0, false, null, ['H226', 'H331', 'H314'], $corr1b));
check($r['status'] === TC::STATUS_NOT_DETERMINED && $r['un_number'] === null, '3 III + 6.1 III + 8 II (8 outranks 3): not determined, no invented entry', $r);

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
    'hazard_classes' => [['canonical' => 'Acute Toxicity (Inhalation)', 'category_canonical' => 'Cat 1']],
]));
check($r['packing_group'] === 'I', 'Acute Tox Cat 1 → PG I', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H330']));
check($r['packing_group'] === 'II', 'H330 without class entry → PG II', $r['packing_group']);
$r = TC::classify($in(null, false, null, ['H301'], ['physical_state' => 'Solid']));
check($r['un_number'] === 'UN2811', 'solid → UN2811', $r['un_number']);

// ---------------------------------------------------------------------
echo "m. Corrosive only → UN1760 8; PG from Skin Corr. sub-category\n";
$r = TC::classify($in(95.0, true, null, ['H314'], [
    'hazard_classes' => [['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1A']],
]));
check($r['un_number'] === 'UN1760', 'UN1760', $r['un_number']);
check($r['hazard_class'] === '8', 'class 8', $r['hazard_class']);
check($r['packing_group'] === 'I', 'Cat 1A → PG I', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314'], [
    'hazard_classes' => [['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1C']],
]));
check($r['packing_group'] === 'III', 'Cat 1C → PG III', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314']));
check($r['packing_group'] === 'II', 'no entry → PG II', $r['packing_group']);
$r = TC::classify($in(95.0, true, null, ['H314', 'H331'], ['physical_state' => 'Solid']));
check($r['un_number'] === 'UN2923' && $r['hazard_class'] === '8 (6.1)', 'corrosive + toxic solid → UN2923 8 (6.1)', $r);

// ---------------------------------------------------------------------
echo "m2. Non-flammable 8 vs 6.1 (173.2a(b))\n";
$oral1 = ['hazard_classes' => [['canonical' => 'Acute Toxicity (Oral)', 'category_canonical' => 'Cat 1'], ['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1B']]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H300'], $oral1));
check($r['un_number'] === 'UN2927' && $r['hazard_class'] === '6.1 (8)' && $r['packing_group'] === 'I', '6.1 I oral vs 8 II liquid → UN2927 6.1 (8) I', $r);
check($r['psn_key'] === 'toxic_liquid_corrosive_organic_nos', 'psn_key toxic_liquid_corrosive_organic_nos', $r['psn_key']);
$r = TC::classify($in(95.0, true, null, ['H314', 'H300'], $oral1 + ['physical_state' => 'Solid']));
check($r['un_number'] === 'UN2928' && $r['psn_key'] === 'toxic_solid_corrosive_organic_nos', 'solid → UN2928', $r);
$oral1corr1a = ['hazard_classes' => [['canonical' => 'Acute Toxicity (Oral)', 'category_canonical' => 'Cat 1'], ['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1A']]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H300'], $oral1corr1a));
check($r['un_number'] === 'UN2922' && $r['hazard_class'] === '8 (6.1)' && $r['packing_group'] === 'I', '6.1 I vs 8 I liquid → 8 primary (UN2922)', $r);
$inh2corr3 = ['hazard_classes' => [['canonical' => 'Acute Toxicity (Inhalation)', 'category_canonical' => 'Cat 2'], ['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1C']]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H330'], $inh2corr3));
check($r['un_number'] === 'UN2927' && $r['hazard_class'] === '6.1 (8)' && $r['packing_group'] === 'II', '6.1 II inhalation vs 8 III → 6.1 primary', $r);
$inh2corr2 = ['hazard_classes' => [['canonical' => 'Acute Toxicity (Inhalation)', 'category_canonical' => 'Cat 2'], ['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1B']]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H330'], $inh2corr2));
check($r['un_number'] === 'UN2922' && $r['hazard_class'] === '8 (6.1)', '6.1 II inhalation vs 8 II liquid → 8 primary', $r);
$r = TC::classify($in(95.0, true, null, ['H314', 'H330'], $inh2corr2 + ['physical_state' => 'Powder']));
check($r['un_number'] === 'UN2928' && $r['hazard_class'] === '6.1 (8)', '6.1 II inhalation vs 8 II solid → 6.1 primary', $r);
$oral2corr2 = ['hazard_classes' => [['canonical' => 'Acute Toxicity (Oral)', 'category_canonical' => 'Cat 2'], ['canonical' => 'Skin Corrosion/Irritation', 'category_canonical' => 'Cat 1B']]];
$r = TC::classify($in(95.0, true, null, ['H314', 'H300'], $oral2corr2 + ['physical_state' => 'Powder']));
check($r['un_number'] === 'UN2923' && $r['hazard_class'] === '8 (6.1)', '6.1 II oral vs 8 II solid → 8 primary', $r);

// ---------------------------------------------------------------------
echo "m3. Combustible note never from a \"> n\" value\n";
$r = TC::classify($in(65.0, true, null, ['H319']));
check($r['status'] === TC::STATUS_NOT_REGULATED && $r['notes'] === [], '> 65 °C: not regulated, no combustible note (real fp may be >= 93)', $r);

// ---------------------------------------------------------------------
echo "m4. n.o.s. entry with no derivable technical names → technical_names_missing\n";
$r = TC::classify($in(40.0, false, null, [], ['description' => 'BLANKET WASH', 'composition' => [
    ['cas_number' => '64742-48-9', 'chemical_name' => 'Naphtha (petroleum), hydrotreated heavy', 'concentration_pct' => 99.5],
], 'cas_h_codes' => ['64742-48-9' => ['H304']]]));
check($r['un_number'] === 'UN1993' && $r['status'] === TC::STATUS_REGULATED, 'flash point alone → UN1993', $r);
check($r['technical_names'] === [] && $r['technical_names_missing'] === true, 'no constituent carries any transport hazard → missing flag', $r);
// 172.203(k)(3) fallback: a constituent carrying ANOTHER transport hazard still names the entry.
$r = TC::classify($in(40.0, false, null, ['H411'], ['description' => 'BLANKET WASH', 'composition' => [
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
check(TC::resolveProductType(null, 'PROCESS BLUE', 'Water-Based Coating') === 'paint', 'family keyword counts', TC::resolveProductType(null, 'PROCESS BLUE', 'Water-Based Coating'));

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

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
