<?php
/**
 * DB-free checks for the SDSGenerator Section 5 builder changed by the SDS
 * content audit (item #11):
 *
 *   - Fire-fighting text is keyed off the engine's Flammable Liquids category
 *     (H224 = Cat 1, H225 = Cat 2, H226 = Cat 3, H227 = Cat 4), never the
 *     direct-line flash-point scan; the most severe category wins.
 *   - The embedded flash point is the SAME string Section 9 prints
 *     (Section 9 override first, then formula_props.flash_point_c,
 *     recursive, ">" flag honoured).
 *   - Water-reactive products (H260/H261) never list water spray or foam as
 *     suitable media and get the dry-agent wording.
 *   - The EN oxidizer sentence is no longer duplicated.
 *   - 'flash_point_c' is no longer returned (it leaked into the HTML preview).
 *   - Every new section5.* key exists in all four language files.
 *
 * Same Reflection bootstrap as SDSGeneratorSection4Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection5Test.php
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

$s5 = $method('section5');
$s9 = $method('section9');

$hz = static fn (array $codes): array => [
    'signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'p_statements' => [], 'exposure_limits' => [],
    'h_statements' => array_map(static fn ($c) => ['code' => $c, 'text' => ''], $codes),
];

// formula.lines = DIRECT lines only (old scan source); formula_props = recursive roll-up (new source)
$calc = static fn (?float $fp, bool $gt = false, array $directLines = []): array => [
    'formula'       => ['lines' => $directLines],
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'enriched_lines' => []],
    'voc'           => ['total_voc_wt_pct' => 0, 'mixture_sg' => 1.0, 'voc_lb_per_gal' => 0, 'voc_lb_per_gal_less_water_exempt' => 0, 'solids_wt_pct' => 0, 'solids_vol_pct' => null],
    'composition'   => [], 'warnings' => [],
];
$fg = ['physical_state' => 'Liquid', 'color' => ''];

$combustion = $t->get('section5.specific_hazards');
$cat1 = $t->get('section5.specific_hazards_flammable_cat1');
$cat2 = $t->get('section5.specific_hazards_flammable_cat2');
$cat3 = $t->get('section5.specific_hazards_flammable_cat3');

// ---------------------------------------------------------------------
echo "a. Unclassified water-based product: four defaults, no flash_point_c key\n";
$r = $s5->invoke($gen, $calc(93.0, true), $hz([]), []);
check($r['suitable_media'] === $t->get('section5.suitable_media'), 'suitable_media default', $r['suitable_media']);
check($r['unsuitable_media'] === $t->get('section5.unsuitable_media'), 'unsuitable_media default', $r['unsuitable_media']);
check($r['specific_hazards'] === $combustion, 'specific_hazards default (no flammability sentence, no flash point line)', $r['specific_hazards']);
check($r['firefighter_advice'] === $t->get('section5.firefighter_advice'), 'firefighter_advice default', $r['firefighter_advice']);
check(!array_key_exists('flash_point_c', $r), 'no flash_point_c key returned', array_keys($r));
check(array_keys($r) === ['title', 'suitable_media', 'unsuitable_media', 'specific_hazards', 'firefighter_advice'], 'exact key set', array_keys($r));

// ---------------------------------------------------------------------
echo "b. Direct-line scan removed\n";
$r = $s5->invoke($gen, $calc(null, false, [['flash_point_c' => 10.0]]), $hz([]), []);
check($r['specific_hazards'] === $combustion, 'direct line fp 10 °C without H-code adds nothing', $r['specific_hazards']);

// ---------------------------------------------------------------------
echo "c. Category 3 (H226) with fp 38 °C and Section 9 parity\n";
$r = $s5->invoke($gen, $calc(38.0), $hz(['H226', 'H319']), []);
$expected = $cat3 . ' Flash point: 38 °C (100.4 °F). ' . $combustion;
check($r['specific_hazards'] === $expected, 'specific_hazards = cat3 + flash point line + combustion', $r['specific_hazards']);
check($r['suitable_media'] === $t->get('section5.suitable_flammable'), 'suitable_media flammable', $r['suitable_media']);
check($r['unsuitable_media'] === $t->get('section5.unsuitable_flammable'), 'unsuitable_media flammable', $r['unsuitable_media']);
check($r['firefighter_advice'] === $t->get('section5.firefighter_advice_flammable'), 'firefighter_advice flammable', $r['firefighter_advice']);
$s9r = $s9->invoke($gen, $fg, $calc(38.0), []);
check(str_contains($r['specific_hazards'], 'Flash point: ' . $s9r['flash_point'] . '.'), 'Section 5 embeds the identical Section 9 flash point string', ['s5' => $r['specific_hazards'], 's9' => $s9r['flash_point']]);

// ---------------------------------------------------------------------
echo "d. Category 2 from sub-FG only, '>' flag, Cat 1 without flash point, most severe wins\n";
$r = $s5->invoke($gen, $calc(12.0), $hz(['H225']), []);
check(str_starts_with($r['specific_hazards'], $cat2), 'H225 with empty direct lines starts with cat2 text', $r['specific_hazards']);
check(str_contains($r['specific_hazards'], 'Flash point: 12 °C (53.6 °F).'), 'sub-FG flash point 12 °C embedded', $r['specific_hazards']);

$r = $s5->invoke($gen, $calc(55.0, true), $hz(['H226']), []);
check(str_contains($r['specific_hazards'], 'Flash point: > 55 °C (131 °F).'), '> flag honoured', $r['specific_hazards']);
$s9r = $s9->invoke($gen, $fg, $calc(55.0, true), []);
check($s9r['flash_point'] === '> 55 °C (131 °F)', 'Section 9 prints > 55 °C (131 °F)', $s9r['flash_point']);

$r = $s5->invoke($gen, $calc(null), $hz(['H224']), []);
check($r['specific_hazards'] === $cat1 . ' ' . $combustion, 'H224 with no flash point: cat1 sentence, no flash point line', $r['specific_hazards']);

$r = $s5->invoke($gen, $calc(-4.0), $hz(['H226', 'H225', 'H224']), []);
check(str_starts_with($r['specific_hazards'], $cat1), 'H226+H225+H224: cat1 wins', $r['specific_hazards']);
check(str_contains($r['specific_hazards'], 'Flash point: -4 °C (24.8 °F).'), 'negative flash point formatted', $r['specific_hazards']);

$s9r = $s9->invoke($gen, $fg, $calc(null), []);
check($s9r['flash_point'] === $t->get('labels.not_determined'), 'Section 9 falls back to Not determined', $s9r['flash_point']);

// ---------------------------------------------------------------------
echo "e. Water-reactive (H260 / H261)\n";
$r = $s5->invoke($gen, $calc(null), $hz(['H260']), []);
check($r['suitable_media'] === $t->get('section5.suitable_water_reactive'), 'H260 suitable_media water-reactive', $r['suitable_media']);
check(stripos($r['suitable_media'], 'water spray') === false, 'H260 suitable_media has no water spray', $r['suitable_media']);
check($r['unsuitable_media'] === $t->get('section5.unsuitable_water_reactive'), 'H260 unsuitable_media water-reactive', $r['unsuitable_media']);
check($r['specific_hazards'] === $t->get('section5.specific_hazards_water_reactive_h260') . ' ' . $combustion, 'H260 specific_hazards', $r['specific_hazards']);
check($r['firefighter_advice'] === $t->get('section5.firefighter_advice_water_reactive'), 'H260 firefighter_advice water-reactive', $r['firefighter_advice']);

$r = $s5->invoke($gen, $calc(null), $hz(['H261', 'H272']), []);
check($r['suitable_media'] === $t->get('section5.suitable_water_reactive'), 'H261+H272: water-reactive beats oxidizer for suitable media', $r['suitable_media']);
check($r['specific_hazards'] === $t->get('section5.specific_hazards_water_reactive') . ' ' . $t->get('section5.specific_hazards_oxidizer'), 'H261+H272 specific_hazards = H261 fragment + oxidizer', $r['specific_hazards']);

$r = $s5->invoke($gen, $calc(20.0), $hz(['H225', 'H261']), []);
check($r['suitable_media'] === $t->get('section5.suitable_water_reactive'), 'H225+H261 suitable_media water-reactive', $r['suitable_media']);
check($r['unsuitable_media'] === $t->get('section5.unsuitable_water_reactive'), 'H225+H261 unsuitable_media water-reactive', $r['unsuitable_media']);
check($r['firefighter_advice'] === $t->get('section5.firefighter_advice_water_reactive'), 'H225+H261 firefighter_advice water-reactive', $r['firefighter_advice']);
check(str_starts_with($r['specific_hazards'], $cat2), 'H225+H261 specific starts with cat2', $r['specific_hazards']);
check(str_contains($r['specific_hazards'], $t->get('section5.specific_hazards_water_reactive')), 'H225+H261 specific contains H261 fragment', $r['specific_hazards']);

// ---------------------------------------------------------------------
echo "f. Oxidizer / organic peroxide / explosive\n";
$r = $s5->invoke($gen, $calc(null), $hz(['H272']), []);
check($r['suitable_media'] === $t->get('section5.suitable_oxidizer'), 'H272 suitable_media oxidizer', $r['suitable_media']);
check($r['specific_hazards'] === $t->get('section5.specific_hazards_oxidizer'), 'H272 specific_hazards oxidizer', $r['specific_hazards']);
check(substr_count(strtolower($r['specific_hazards']), 'intensify') === 1, 'oxidizer sentence not duplicated', $r['specific_hazards']);
check(!str_contains($r['specific_hazards'], '; oxidizer.'), 'old duplicated fragment gone', $r['specific_hazards']);

$r = $s5->invoke($gen, $calc(null), $hz(['H242']), []);
check($r['specific_hazards'] === $t->get('section5.specific_hazards_organic_peroxide'), 'H242 specific_hazards organic peroxide', $r['specific_hazards']);

$r = $s5->invoke($gen, $calc(0.0), $hz(['H201', 'H225']), []);
check($r['firefighter_advice'] === $t->get('section5.firefighter_advice_explosive'), 'H201+H225: explosive advice wins', $r['firefighter_advice']);
check(str_contains($r['specific_hazards'], 'Flash point: 0 °C (32 °F).'), 'fp 0.0 is printed (not treated as missing)', $r['specific_hazards']);

// ---------------------------------------------------------------------
echo "g. Overrides win\n";
$ov = [5 => ['suitable_media' => 'A', 'unsuitable_media' => 'B', 'specific_hazards' => 'C', 'firefighter_advice' => 'D']];
$r = $s5->invoke($gen, $calc(-4.0), $hz(['H224', 'H260']), $ov);
check($r['suitable_media'] === 'A' && $r['unsuitable_media'] === 'B' && $r['specific_hazards'] === 'C' && $r['firefighter_advice'] === 'D', 'all four overrides applied', $r);

// ---------------------------------------------------------------------
echo "g2. Section 9 flash_point override is the value Section 5 embeds\n";
$ov9 = [9 => ['flash_point' => '41 °C (105.8 °F)']];
$r   = $s5->invoke($gen, $calc(38.0), $hz(['H226']), $ov9);
$s9r = $s9->invoke($gen, $fg, $calc(38.0), $ov9);
check($s9r['flash_point'] === '41 °C (105.8 °F)', 'Section 9 prints the override', $s9r['flash_point']);
check($r['specific_hazards'] === $cat3 . ' Flash point: 41 °C (105.8 °F). ' . $combustion, 'Section 5 embeds the Section 9 override', $r['specific_hazards']);
check(!str_contains($r['specific_hazards'], '38 °C'), 'computed 38 °C not printed when overridden', $r['specific_hazards']);
check(str_contains($r['specific_hazards'], 'Flash point: ' . $s9r['flash_point'] . '.'), 'parity with Section 9 under override', ['s5' => $r['specific_hazards'], 's9' => $s9r['flash_point']]);
$r = $s5->invoke($gen, $calc(null), $hz(['H226']), $ov9);
check(str_contains($r['specific_hazards'], 'Flash point: 41 °C (105.8 °F).'), 'override prints even when formula_props has no flash point', $r['specific_hazards']);
$r   = $s5->invoke($gen, $calc(38.0), $hz(['H226']), [9 => ['flash_point' => '']]);
$s9r = $s9->invoke($gen, $fg, $calc(38.0), [9 => ['flash_point' => '']]);
check(str_contains($r['specific_hazards'], 'Flash point: 38 °C (100.4 °F).') && $s9r['flash_point'] === '38 °C (100.4 °F)', 'empty override falls back to formula_props in both sections', ['s5' => $r['specific_hazards'], 's9' => $s9r['flash_point']]);
$r = $s5->invoke($gen, $calc(93.0, true), $hz([]), $ov9);
check($r['specific_hazards'] === $combustion, 'override does not add a flash point line to a non-flammable product', $r['specific_hazards']);

// ---------------------------------------------------------------------
echo "g3. Flammable Liquids Category 4 (H227, combustible liquid)\n";
$cat4 = $t->get('section5.specific_hazards_flammable_cat4');
$r = $s5->invoke($gen, $calc(75.0), $hz(['H227']), []);
check($r['specific_hazards'] === $cat4 . ' Flash point: 75 °C (167 °F). ' . $combustion, 'H227: cat4 sentence + flash point line + combustion', $r['specific_hazards']);
check($r['suitable_media'] === $t->get('section5.suitable_flammable') && $r['firefighter_advice'] === $t->get('section5.firefighter_advice_flammable'), 'H227 takes the flammable media/advice branches', [$r['suitable_media'], $r['firefighter_advice']]);
$s9r = $s9->invoke($gen, $fg, $calc(75.0), []);
check(str_contains($r['specific_hazards'], 'Flash point: ' . $s9r['flash_point'] . '.'), 'H227 Section 5/9 flash point parity', $s9r['flash_point']);
$r = $s5->invoke($gen, $calc(38.0), $hz(['H227', 'H226']), []);
check(str_starts_with($r['specific_hazards'], $cat3), 'H226 + H227: cat3 wins', $r['specific_hazards']);
check(str_contains($cat4, 'Category 4') && !str_contains($cat4, 'flash back'), 'cat4 sentence names Category 4 and has no flash-back wording', $cat4);

// ---------------------------------------------------------------------
echo "h. Translation keys in all four languages\n";
$keys = [
    'suitable_flammable', 'unsuitable_flammable', 'suitable_water_reactive', 'unsuitable_water_reactive',
    'specific_hazards_oxidizer', 'specific_hazards_flammable_cat1', 'specific_hazards_flammable_cat2',
    'specific_hazards_flammable_cat3', 'specific_hazards_flammable_cat4', 'flash_point_line', 'specific_hazards_water_reactive',
    'specific_hazards_water_reactive_h260', 'firefighter_advice_flammable', 'firefighter_advice_water_reactive',
];
$trEn = null;
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tr = require $basePath . '/templates/translations/' . $lang . '.php';
    if ($lang === 'en') {
        $trEn = $tr;
    }
    foreach ($keys as $k) {
        $v = $tr['section5'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang}: section5.{$k} is a non-empty string", $v);
    }
    check(str_contains($tr['section5']['flash_point_line'], ':fp'), "{$lang}: flash_point_line has :fp placeholder", $tr['section5']['flash_point_line']);
    check(stripos($tr['section5']['suitable_water_reactive'], 'CO2') !== false, "{$lang}: suitable_water_reactive mentions CO2", $tr['section5']['suitable_water_reactive']);
}
check(substr_count(strtolower($trEn['section5']['specific_hazards_oxidizer']), 'intensify') === 1, 'en: specific_hazards_oxidizer has a single intensify sentence', $trEn['section5']['specific_hazards_oxidizer']);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
