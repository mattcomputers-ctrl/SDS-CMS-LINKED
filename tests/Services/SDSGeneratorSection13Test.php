<?php
/**
 * DB-free checks for the SDSGenerator Section 13 builder changed by the SDS
 * content audit (item #26):
 *
 *   - Section 13 returns title / methods / rcra_classification only; the
 *     generic disposal sentence stays (operator override wins) and the five
 *     old methods_* canned variants are gone.
 *   - rcra_classification is computed only and lists EVERY applicable RCRA
 *     code: D001 only with Flam. Liq. 1-3 (Q3), reason from the engine flash
 *     point (< 60 °C; "> n" the not-determined reason), oxidizers and pyrophoric / self-heating
 *     materials; D002 from H314 / H290 (not for Solid / Powder / Paste); D003 from water-reactive / explosive /
 *     self-reactive codes; D004–D043 and F / K / P / U from the RCRAService
 *     component matches, printed by name only (never CAS or percentage),
 *     trade-secret components masked.
 *   - T1d: #11 D001 safety-net reasons (H220-H223, H228), #12 metal
 *     compounds / reworded rcra_none, #66 D002 state rule, H290, no data-gap wording.
 *   - Both renderers map the new key; labels and translation keys exist in
 *     all four languages; Section 16 abbreviations pick up RCRA and TCLP.
 *
 * Same Reflection bootstrap as SDSGeneratorSection5Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection13Test.php
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

$s13 = $method('section13');

$hz = static fn (array $codes): array => [
    'signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'p_statements' => [], 'exposure_limits' => [],
    'h_statements' => array_map(static fn ($c) => ['code' => $c, 'text' => ''], $codes),
];

$calc = static fn (?float $fp, bool $gt = false): array => [
    'formula'       => ['lines' => []],
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'enriched_lines' => []],
    'voc'           => ['total_voc_wt_pct' => 0, 'mixture_sg' => 1.0, 'voc_lb_per_gal' => 0, 'voc_lb_per_gal_less_water_exempt' => 0, 'solids_wt_pct' => 0, 'solids_vol_pct' => null],
    'composition'   => [], 'warnings' => [],
];

$rcra = static fn (array $components) => ['components' => $components, 'has_matches' => $components !== []];

$run = static fn (array $codes, ?float $fp, bool $gt = false, array $components = [], array $overrides = []) =>
    $s13->invoke($gen, $hz($codes), $calc($fp, $gt), $overrides, $rcra($components));

$intro     = $t->get('section13.rcra_intro');
$none      = $t->get('section13.rcra_none');
$generator = $t->get('section13.rcra_generator');

// ---------------------------------------------------------------------------
echo "1. Unclassified, fp > 93, no components\n";
$r = $run([], 93.0, true);
check(array_keys($r) === ['title', 'methods', 'rcra_classification'], 'keys exactly title/methods/rcra_classification', array_keys($r));
check($r['methods'] === $t->get('section13.methods'), 'methods = generic sentence');
check($r['rcra_classification'] === $none . ' ' . $generator, 'rcra_none + generator', $r['rcra_classification']);
check(!str_contains($r['rcra_classification'], 'D001') && !str_contains($r['rcra_classification'], 'D002') && !str_contains($r['rcra_classification'], 'D003'), 'no D001/D002/D003');
check($r['title'] === $t->get('section13.title'), 'title');

// ---------------------------------------------------------------------------
echo "2. D001 ignitable (Q3: only with Flam. Liq. 1-3 in the final codes; reason from the engine flash point)\n";
$r = $run(['H226'], 38.0);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — flash point below 60 °C (140 °F)'), 'H226 + fp 38 -> D001 flash point', $r['rcra_classification']);
check(str_starts_with($r['rcra_classification'], $intro . ' '), 'starts with rcra_intro');
check(str_ends_with($r['rcra_classification'], '. ' . $generator), 'ends with ". " + generator');
check(!str_contains($r['rcra_classification'], $none), 'rcra_none not printed when items exist');

// Q3: no Flam. Liq. 1-3 in the final codes -> no flammable-liquid D001 (Section 13 follows Section 2).
$r = $run([], 38.0);
check(!str_contains($r['rcra_classification'], 'D001'), 'fp 38 without H224-H226 -> no D001', $r['rcra_classification']);

// "> n" with n < 60: printed with the "not determined to be at or above 60 °C" reason, never "below 60 °C".
$r = $run(['H226'], 55.0, true);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — ' . $t->get('section13.rcra_reason_flash_point_gt')), 'H226 + > 55 flag -> D001 with the "not determined >= 60" reason', $r['rcra_classification']);
check(!str_contains($r['rcra_classification'], 'flash point below 60'), '> 55 flag never prints "below 60 °C"', $r['rcra_classification']);
$r = $run([], 60.0, true);
check(!str_contains($r['rcra_classification'], 'D001'), '> 60 flag -> no D001', $r['rcra_classification']);

// #10: exactly 60 °C is Cat 3 / Class 3 (FP <= 60) but not D001 (FP < 60).
$r = $run(['H226'], 60.0);
check(!str_contains($r['rcra_classification'], 'D001'), 'H226 + fp 60.0 -> no D001 (strict <)', $r['rcra_classification']);
$r = $run(['H226'], 59.9);
check(str_contains($r['rcra_classification'], 'D001'), 'H226 + fp 59.9 -> D001', $r['rcra_classification']);

// The Section 9 edit never drives D001 (vetFlashPointEdit drops a contradicting one).
$r = $run(['H226'], 38.0, false, [], [9 => ['flash_point' => '> 95 °C (203 °F)']]);
check(str_contains($r['rcra_classification'], 'flash point below 60 °C (140 °F)'), 'edit "> 95 °C" ignored: H226 + fp 38 -> D001 below 60', $r['rcra_classification']);
$r = $run([], 95.0, false, [], [9 => ['flash_point' => '38 °C (100.4 °F)']]);
check(!str_contains($r['rcra_classification'], 'D001'), 'edit "38 °C" ignored: fp 95, no codes -> no D001', $r['rcra_classification']);

// Flam. Liq. codes that did not come from the flash point (finished-good override): hazard-based reason.
$fl = $t->get('section13.rcra_reason_flammable_liquid');
$r = $run(['H226'], null);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — ' . $fl) && !str_contains($r['rcra_classification'], 'flash point below 60'), 'fp null + H226 -> D001 flammable-liquid reason', $r['rcra_classification']);
$r = $run(['H226'], 99.0);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — ' . $fl), 'fp 99 + H226 (override) -> D001 flammable-liquid reason', $r['rcra_classification']);

$r = $run(['H227'], 70.0);
check(!str_contains($r['rcra_classification'], 'D001'), 'fp 70 + H227 -> no D001', $r['rcra_classification']);

$r = $run(['H225'], 38.0);
check(substr_count($r['rcra_classification'], 'flash point below 60') === 1, 'fp + H225 gives the flash-point reason once');

// ---------------------------------------------------------------------------
echo "3. D002 corrosive\n";
$r = $run(['H314'], 93.0, true);
check(str_contains($r['rcra_classification'], $t->get('section13.rcra_d002', ['reasons' => $t->get('section13.rcra_reason_skin_corrosion')])), 'H314 -> D002 sentence with the skin-corrosion reason', $r['rcra_classification']);
check(!str_contains($r['rcra_classification'], 'pH not determined'), 'D002 no longer states a data gap (#66)');
$r = $run(['H315'], 93.0, true);
check(!str_contains($r['rcra_classification'], 'D002'), 'H315 alone -> no D002');

// ---------------------------------------------------------------------------
echo "4. D003 reactive vs D001 oxidizer / pyrophoric\n";
$r = $run(['H260', 'H242'], null);
check(str_contains($r['rcra_classification'], 'reactive (D003) — reacts with water, self-reactive substance or organic peroxide'), 'H260 + H242 -> D003 with both reasons', $r['rcra_classification']);
check(!str_contains($r['rcra_classification'], 'D001'), 'no D001 from H260/H242');

$r = $run(['H272'], null);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — oxidizer'), 'H272 -> D001 oxidizer', $r['rcra_classification']);
check(!str_contains($r['rcra_classification'], 'D003'), 'H272 -> no D003');

$r = $run(['H250'], null);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — pyrophoric or self-heating material'), 'H250 -> D001 pyrophoric', $r['rcra_classification']);

$r = $run(['H203'], null);
check(str_contains($r['rcra_classification'], 'reactive (D003) — explosive'), 'H203 -> D003 explosive');

$r = $run(['H226', 'H272', 'H251'], null);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — classified as a flammable liquid (Category 1, 2 or 3), oxidizer, pyrophoric or self-heating material'), 'three D001 reasons joined by ", "', $r['rcra_classification']);
check(substr_count($r['rcra_classification'], 'D001') === 1, 'D001 printed once');

// ---------------------------------------------------------------------------
echo "5. All three characteristics in order\n";
$r = $run(['H225', 'H314', 'H261'], 10.0);
$s = $r['rcra_classification'];
$p1 = strpos($s, 'D001'); $p2 = strpos($s, 'D002'); $p3 = strpos($s, 'D003');
check($p1 !== false && $p2 !== false && $p3 !== false && $p1 < $p2 && $p2 < $p3, 'D001 < D002 < D003', $s);
check(str_contains($s, '); corrosive (D002)') && str_contains($s, '; reactive (D003)'), 'items joined by "; "', $s);
check(str_starts_with($s, $intro . ' '), 'starts with intro');
check(str_ends_with($s, '. ' . $generator), 'ends with ". " + generator');

// ---------------------------------------------------------------------------
echo "6. Components\n";
$toluene = [
    'cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [
        ['waste_code' => 'U220', 'kind' => 'U', 'description' => 'Toluene', 'limit_mg_l' => null],
        ['waste_code' => 'F005', 'kind' => 'F', 'description' => 'Toluene — spent solvent', 'limit_mg_l' => null],
    ],
];
$mek = [
    'cas_number' => '78-93-3', 'chemical_name' => 'Methyl ethyl ketone', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [
        ['waste_code' => 'D035', 'kind' => 'D', 'description' => 'MEK', 'limit_mg_l' => 200.0],
        ['waste_code' => 'U159', 'kind' => 'U', 'description' => 'MEK', 'limit_mg_l' => null],
    ],
];
$r = $run(['H226'], 38.0, false, [$toluene, $mek]);
$s = $r['rcra_classification'];
$listedIntro = $t->get('section13.rcra_listed_intro');
// F / K / P / U listings cannot apply to the product as sold (261.31 spent solvents; 261.33(d)
// sole-active-ingredient commercial chemical products): they print in a separate reference
// sentence after the "as sold" sentence; D codes stay inside the "as sold" sentence.
check(str_contains($s, 'contains Toluene (U220 applies only to the unused chemical itself or a formulation in which it is the sole active ingredient; F005 applies to spent solvent containing it)'), 'toluene fragment (codes in given order, joined "; ", reworded conditions)', $s);
check(str_contains($s, 'D035 if the toxicity characteristic regulatory level of 200 mg/L (TCLP) is exceeded'), 'MEK D035 with 200 mg/L', $s);
check(str_contains($s, 'contains Methyl ethyl ketone (D035'), 'MEK name printed (D code, as-sold sentence)');
check(str_contains($s, 'contains Methyl ethyl ketone (U159'), 'MEK U159 printed separately in the reference sentence');
check(!str_contains($s, '%'), 'no percentage character');
check(!str_contains($s, '108-88-3') && !str_contains($s, '78-93-3'), 'no CAS printed');
check(strpos($s, 'D001') < strpos($s, 'contains Methyl ethyl ketone (D035') && strpos($s, 'contains Methyl ethyl ketone (D035') < strpos($s, $listedIntro)
    && strpos($s, $listedIntro) < strpos($s, 'contains Toluene') && strpos($s, 'contains Toluene') < strpos($s, 'contains Methyl ethyl ketone (U159'), 'characteristics + D codes first, then the reference sentence with components in given order', $s);
check(str_contains($s, '(140 °F); contains Methyl ethyl ketone (D035') && str_contains($s, 'containing it); contains Methyl ethyl ketone (U159'), 'items joined by "; " inside each sentence', $s);
check(str_contains($s, 'exceeded). ' . $listedIntro . ' contains Toluene'), 'as-sold sentence ends with ". " then the reference intro', $s);
check(str_ends_with($s, 'ingredient). ' . $generator), 'reference sentence ends with ". " + generator', $s);
check(!str_contains($s, 'if discarded as an unused commercial chemical product') && !str_contains($s, 'if discarded as a spent solvent'), 'old "if discarded as" conditions gone', $s);

// Listed-waste matches only: the product is NOT asserted to "may be regulated as hazardous waste".
$methanol = ['cas_number' => '67-56-1', 'chemical_name' => 'Methanol', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [['waste_code' => 'F003', 'kind' => 'F', 'description' => 'x', 'limit_mg_l' => null], ['waste_code' => 'U154', 'kind' => 'U', 'description' => 'x', 'limit_mg_l' => null]]];
$r = $run(['H319'], null, false, [$methanol]);
$s = $r['rcra_classification'];
check(!str_contains($s, $intro), 'F/U-only match: no "If discarded as sold ... may be regulated" sentence', $s);
check(str_starts_with($s, $t->get('section13.rcra_none_characteristic') . ' ' . $listedIntro . ' contains Methanol (F003'), 'F/U-only match: "no characteristic" sentence + reference sentence', $s);
check(!str_contains($s, $none), 'rcra_none (which also denies listed components) not printed', $s);
check(str_ends_with($s, '. ' . $generator), 'ends with generator', $s);
// D-code-only match stays inside the "as sold" sentence with no reference sentence.
$r = $run([], null, false, [$mek]);
$s = $r['rcra_classification'];
check(str_starts_with($s, $intro . ' contains Methyl ethyl ketone (D035') && str_contains($s, 'exceeded). ' . $listedIntro . ' contains Methyl ethyl ketone (U159'), 'MEK alone: D035 as sold, U159 for reference', $s);
$r = $run([], null, false, [['cas_number' => '7440-43-9', 'chemical_name' => 'Cadmium', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [['waste_code' => 'D006', 'kind' => 'D', 'description' => 'x', 'limit_mg_l' => 1.0]]]]);
check($r['rcra_classification'] === $intro . ' contains Cadmium (D006 if the toxicity characteristic regulatory level of 1 mg/L (TCLP) is exceeded). ' . $generator, 'D-only: no reference sentence', $r['rcra_classification']);

$mercury = ['cas_number' => '7439-97-6', 'chemical_name' => 'Mercury', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [['waste_code' => 'D009', 'kind' => 'D', 'description' => 'Mercury', 'limit_mg_l' => 0.2]]];
$r = $run([], null, false, [$mercury]);
check(str_contains($r['rcra_classification'], 'D009 if the toxicity characteristic regulatory level of 0.2 mg/L (TCLP) is exceeded'), 'limit 0.2 -> "0.2 mg/L"', $r['rcra_classification']);

$half = ['cas_number' => '71-43-2', 'chemical_name' => 'Benzene', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [['waste_code' => 'D018', 'kind' => 'D', 'description' => 'Benzene', 'limit_mg_l' => 0.5]]];
$r = $run([], null, false, [$half]);
check(str_contains($r['rcra_classification'], 'of 0.5 mg/L'), 'limit 0.5 -> "0.5 mg/L"');

$noLimit = ['cas_number' => '7439-92-1', 'chemical_name' => 'Lead', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [['waste_code' => 'D008', 'kind' => 'D', 'description' => 'Lead', 'limit_mg_l' => null]]];
$r = $run([], null, false, [$noLimit]);
check(str_contains($r['rcra_classification'], $t->get('section13.rcra_code_d_nolimit', ['code' => 'D008'])), 'D row without limit -> rcra_code_d_nolimit', $r['rcra_classification']);
check(!str_contains($r['rcra_classification'], 'mg/L'), 'no mg/L when limit missing');

$kp = ['cas_number' => '75-15-0', 'chemical_name' => 'Carbon disulfide', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [
        ['waste_code' => 'K001', 'kind' => 'K', 'description' => 'x', 'limit_mg_l' => null],
        ['waste_code' => 'P022', 'kind' => 'P', 'description' => 'x', 'limit_mg_l' => null],
        ['waste_code' => 'U999', 'kind' => '',  'description' => 'x', 'limit_mg_l' => null],
    ]];
$r = $run([], null, false, [$kp]);
$s = $r['rcra_classification'];
check(str_contains($s, 'K001 applies only to the listed process waste'), 'K fragment', $s);
check(str_contains($s, 'P022 (acutely hazardous) applies only to the unused chemical itself or a formulation in which it is the sole active ingredient'), 'P fragment', $s);
check(str_contains($s, 'U999 applies only to the unused chemical itself or a formulation in which it is the sole active ingredient'), 'blank kind falls back to code letter (U)', $s);
check(str_starts_with($s, $t->get('section13.rcra_none_characteristic') . ' ' . $t->get('section13.rcra_listed_intro') . ' contains Carbon disulfide (K001'), 'K/P/U only -> no "may be regulated" sentence', $s);

// Component with no usable codes is skipped entirely.
$empty = ['cas_number' => '1-1-1', 'chemical_name' => 'Nothing', 'is_trade_secret' => false, 'trade_secret_description' => null, 'codes' => []];
$r = $run([], null, false, [$empty]);
check($r['rcra_classification'] === $none . ' ' . $generator, 'component without codes -> rcra_none path');

// ---------------------------------------------------------------------------
echo "7. Trade secret masking (audit #13, decision Q4: no name, no code, no TCLP level)\n";
$ts = ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary resin',
    'codes' => [['waste_code' => 'U220', 'kind' => 'U', 'description' => 'Toluene', 'limit_mg_l' => null]]];
$r = $run([], null, false, [$ts]);
$tsListed = $t->get('section13.rcra_component_ts_listed', ['name' => 'Proprietary resin']);
check(str_contains($r['rcra_classification'], $tsListed), 'listed trade secret -> generic fragment with the trade-secret name', $r['rcra_classification']);
check(!str_contains($r['rcra_classification'], 'U220') && !str_contains($r['rcra_classification'], 'Toluene'), 'no waste code and no chemical name for a trade secret', $r['rcra_classification']);
check(str_starts_with($r['rcra_classification'], $t->get('section13.rcra_none_characteristic') . ' ' . $t->get('section13.rcra_listed_intro') . ' ' . $tsListed), 'listed-only trade secret stays in the reference sentence', $r['rcra_classification']);
$ts['trade_secret_description'] = '';
$r = $run([], null, false, [$ts]);
check(str_contains($r['rcra_classification'], $t->get('section13.rcra_component_ts_listed', ['name' => 'Trade Secret'])), 'blank description -> "Trade Secret"', $r['rcra_classification']);
$tsTc = ['cas_number' => '71-43-2', 'chemical_name' => 'Benzene', 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary solvent',
    'codes' => [['waste_code' => 'D018', 'kind' => 'D', 'description' => 'Benzene', 'limit_mg_l' => 0.5]]];
$r = $run([], null, false, [$tsTc]);
$s = $r['rcra_classification'];
check($s === $intro . ' ' . $t->get('section13.rcra_component_ts_tc', ['name' => 'Proprietary solvent']) . '. ' . $generator, 'trade-secret TC constituent -> generic toxicity-characteristic item only', $s);
check(!str_contains($s, 'D018') && !str_contains($s, '0.5 mg/L') && !str_contains($s, 'Benzene'), 'no D-code, no TCLP level, no name', $s);
check(!str_contains($s, $none), 'rcra_none not printed', $s);
$tsTc2 = $tsTc; $tsTc2['cas_number'] = '127-18-4'; $tsTc2['codes'] = [['waste_code' => 'D039', 'kind' => 'D', 'description' => 'x', 'limit_mg_l' => 0.7]];
$r = $run([], null, false, [$tsTc, $tsTc2]);
check(substr_count($r['rcra_classification'], $t->get('section13.rcra_component_ts_tc', ['name' => 'Proprietary solvent'])) === 1, 'same description twice -> one fragment', $r['rcra_classification']);

$anon = ['cas_number' => '108-88-3', 'chemical_name' => '', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [['waste_code' => 'U220', 'kind' => 'U', 'description' => 'Toluene', 'limit_mg_l' => null]]];
$r = $run([], null, false, [$anon]);
check(str_contains($r['rcra_classification'], 'contains 108-88-3 (U220'), 'empty name falls back to CAS (last resort)');

// ---------------------------------------------------------------------------
echo "8. Override\n";
$r = $run(['H226'], 38.0, false, [$toluene], [13 => ['methods' => 'Custom text']]);
check($r['methods'] === 'Custom text', 'methods override wins');
check($r['rcra_classification'] !== 'Custom text' && str_contains($r['rcra_classification'], 'D001'), 'rcra_classification still computed under override');
$r = $run(['H226'], 38.0, false, [$toluene], [13 => ['rcra_classification' => 'Hacked']]);
check(!str_contains($r['rcra_classification'], 'Hacked'), 'rcra_classification cannot be overridden');

// ---------------------------------------------------------------------------
echo "9. Missing 4th argument\n";
$r = $s13->invoke($gen, $hz([]), $calc(93.0, true), []);
check($r['rcra_classification'] === $none . ' ' . $generator, 'three-arg call -> rcra_none path, no error');

// ---------------------------------------------------------------------------
echo "9b. T1d: #11 D001 safety net, #66 D002 state / H290 / data-gap wording, #12 rcra_none and metal compounds\n";
$runFg = static fn (array $codes, ?float $fp, array $fg, array $components = []) =>
    $s13->invoke($gen, $hz($codes), $calc($fp), [], $rcra($components), $fg);
$gas   = $t->get('section13.rcra_reason_flammable_gas');
$fsol  = $t->get('section13.rcra_reason_flammable_solid');
$skin  = $t->get('section13.rcra_reason_skin_corrosion');
$metal = $t->get('section13.rcra_reason_metal_corrosion');

$r = $run(['H220'], null);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — ' . $gas), 'H220 -> D001 compressed-gas reason', $r['rcra_classification']);
$r = $run(['H222', 'H229'], null);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — ' . $gas), 'H222 aerosol -> D001', $r['rcra_classification']);
$r = $run(['H228'], null);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — ' . $fsol), 'H228 -> D001 flammable solid', $r['rcra_classification']);
$r = $run(['H221', 'H223', 'H228'], null);
check(substr_count($r['rcra_classification'], $gas) === 1 && substr_count($r['rcra_classification'], 'D001') === 1, 'gas codes give one reason; D001 once', $r['rcra_classification']);
$r = $run(['H272', 'H228'], null);
check(str_contains($r['rcra_classification'], 'ignitable (D001) — oxidizer, ' . $fsol), 'new reasons follow oxidizer / pyrophoric', $r['rcra_classification']);
$r = $run(['H229'], null);
check(!str_contains($r['rcra_classification'], 'D001'), 'H229 alone -> no D001', $r['rcra_classification']);

$r = $run(['H290'], 93.0, true);
check(str_contains($r['rcra_classification'], 'corrosive (D002) — ' . $metal), 'H290 liquid -> D002 metal-corrosion reason', $r['rcra_classification']);
$r = $run(['H314', 'H290'], 93.0, true);
check(str_contains($r['rcra_classification'], 'corrosive (D002) — ' . $skin . ', ' . $metal) && substr_count($r['rcra_classification'], 'D002') === 1, 'H314 + H290 -> one D002, both reasons', $r['rcra_classification']);
foreach (['Solid', 'Powder', 'paste', ' PASTE '] as $st) {
    $r = $runFg(['H314', 'H290'], 93.0, ['physical_state' => $st]);
    check(!str_contains($r['rcra_classification'], 'D002'), "state '{$st}' -> no D002", $r['rcra_classification']);
}
$r = $runFg(['H314'], 93.0, ['physical_state' => 'Solid']);
check($r['rcra_classification'] === $none . ' ' . $generator, 'solid H314-only product -> rcra_none path', $r['rcra_classification']);
$r = $runFg(['H314'], 93.0, ['physical_state' => 'Liquid']);
check(str_contains($r['rcra_classification'], 'D002'), 'Liquid -> D002');
$r = $runFg(['H314'], 93.0, ['physical_state' => 'Gel']);
check(str_contains($r['rcra_classification'], 'D002'), 'Gel (not Solid/Powder/Paste) -> D002');
$calcPowder = $calc(93.0, true);
$calcPowder['formula_props']['physical_state'] = 'Powder';
$r = $s13->invoke($gen, $hz(['H314']), $calcPowder, [], $rcra([]), []);
check(!str_contains($r['rcra_classification'], 'D002'), 'no own state: derived Powder (highest-wt% raw) -> no D002', $r['rcra_classification']);

// H226 with a "> 55" product flash point prints the ">" D001 reason; neither it nor D002 states a data gap.
$r = $run(['H226', 'H314'], 55.0, true);
check(str_contains($r['rcra_classification'], 'D001') && !str_contains($r['rcra_classification'], 'not determined') && !str_contains($r['rcra_classification'], 'pH'), 'no data-gap wording in D001 ">" reason or D002', $r['rcra_classification']);
check(!str_contains($none, 'no component is a') && str_contains($none, 'has been identified as a toxicity characteristic constituent'), 'rcra_none no longer positively denies TC constituents', $none);

$pbcr = ['cas_number' => '7758-97-6', 'chemical_name' => 'Lead chromate', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [
        ['waste_code' => 'D007', 'kind' => 'D', 'description' => 'Chromium', 'limit_mg_l' => 5.0],
        ['waste_code' => 'D008', 'kind' => 'D', 'description' => 'Lead', 'limit_mg_l' => 5.0],
    ]];
$r = $run([], 93.0, true, [$pbcr]);
check($r['rcra_classification'] === $intro . ' contains Lead chromate (D007 if the toxicity characteristic regulatory level of 5 mg/L (TCLP) is exceeded; D008 if the toxicity characteristic regulatory level of 5 mg/L (TCLP) is exceeded). ' . $generator, 'metal compound prints both inherited D codes', $r['rcra_classification']);

// ---------------------------------------------------------------------------
echo "10. getLabels()\n";
$labels = $method('getLabels')->invoke($gen);
check(isset($labels['rcra_classification']) && $labels['rcra_classification'] === $t->get('labels.rcra_classification'), 'labels.rcra_classification present', $labels['rcra_classification'] ?? null);
check(($labels['rcra_classification'] ?? '') !== '' && $labels['rcra_classification'] !== 'labels.rcra_classification', 'label non-empty and translated');
check(isset($labels['disposal_methods']), 'disposal_methods label still present');

// ---------------------------------------------------------------------------
echo "11. Renderer parity\n";
$map = (new ReflectionClassConstant(\SDS\Services\PDFService::class, 'FIELD_LABEL_MAP'))->getValue();
check(($map['rcra_classification'] ?? null) === 'rcra_classification', 'PDFService::FIELD_LABEL_MAP maps rcra_classification');
check(($map['methods'] ?? null) === 'disposal_methods', 'PDFService::FIELD_LABEL_MAP still maps methods');
$preview = (string) file_get_contents($basePath . '/src/Views/sds/preview.php');
check(str_contains($preview, "'rcra_classification'  => 'rcra_classification'"), 'preview.php genericLabelMap maps rcra_classification');

// ---------------------------------------------------------------------------
echo "12. Translation keys in all four languages\n";
$keys = [
    'rcra_intro', 'rcra_none', 'rcra_none_characteristic', 'rcra_listed_intro', 'rcra_generator', 'rcra_d001', 'rcra_d002', 'rcra_d003',
    'rcra_reason_flash_point', 'rcra_reason_flash_point_gt', 'rcra_reason_flammable_liquid', 'rcra_reason_oxidizer', 'rcra_reason_ignitable_solid',
    'rcra_reason_water_reactive', 'rcra_reason_explosive', 'rcra_reason_unstable',
    'rcra_reason_flammable_gas', 'rcra_reason_flammable_solid', 'rcra_reason_skin_corrosion', 'rcra_reason_metal_corrosion',
    'rcra_component', 'rcra_code_d', 'rcra_code_d_nolimit', 'rcra_code_f', 'rcra_code_k', 'rcra_code_p', 'rcra_code_u',
];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $all = (new \SDS\Services\TranslationService($lang))->all();
    $s13t = $all['section13'] ?? [];
    $missing = [];
    foreach ($keys as $k) {
        if (!isset($s13t[$k]) || !is_string($s13t[$k]) || trim($s13t[$k]) === '') {
            $missing[] = $k;
        }
    }
    check($missing === [], "{$lang}: every section13.rcra_* key present and non-empty", $missing);
    check(isset($s13t['methods']) && $s13t['methods'] !== '' && isset($s13t['title']), "{$lang}: section13.methods / title kept");
    check(str_contains($s13t['rcra_d001'] ?? '', ':reasons') && str_contains($s13t['rcra_d002'] ?? '', ':reasons') && str_contains($s13t['rcra_d003'] ?? '', ':reasons'), "{$lang}: rcra_d001/d002/d003 carry :reasons");
    check(str_contains($s13t['rcra_component'] ?? '', ':name') && str_contains($s13t['rcra_component'] ?? '', ':list'), "{$lang}: rcra_component carries :name and :list");
    check(str_contains($s13t['rcra_code_d'] ?? '', ':code') && str_contains($s13t['rcra_code_d'] ?? '', ':limit'), "{$lang}: rcra_code_d carries :code and :limit");
    $okCodes = true;
    foreach (['rcra_code_d_nolimit', 'rcra_code_f', 'rcra_code_k', 'rcra_code_p', 'rcra_code_u'] as $k) {
        $okCodes = $okCodes && str_contains($s13t[$k] ?? '', ':code');
    }
    check($okCodes, "{$lang}: other rcra_code_* carry :code");
    check(!empty($all['labels']['rcra_classification']), "{$lang}: labels.rcra_classification non-empty");
    $abbr = $all['section16']['abbreviation_table'] ?? [];
    check(!empty($abbr['RCRA']) && !empty($abbr['TCLP']), "{$lang}: abbreviation_table has RCRA and TCLP");
    $legacy = array_intersect(['methods_ignitable', 'methods_corrosive', 'methods_toxic', 'methods_reactive', 'methods_aquatic'], array_keys($s13t));
    check($legacy === [], "{$lang}: legacy methods_* keys removed", $legacy);
}

// Non-EN generator renders the same structure with its own strings.
$tDe   = new \SDS\Services\TranslationService('de');
$genDe = new \SDS\Services\SDSGenerator($tDe);
$mDe   = new ReflectionMethod($genDe, 'section13');
$mDe->setAccessible(true);
$rDe = $mDe->invoke($genDe, $hz(['H226', 'H314']), $calc(38.0), [], $rcra([$mek]));
check(str_contains($rDe['rcra_classification'], 'entzündbar (D001)') && str_contains($rDe['rcra_classification'], 'ätzend (D002)') && str_contains($rDe['rcra_classification'], 'enthält Methyl ethyl ketone (D035'), 'DE renders D001 + D002 + component', $rDe['rcra_classification']);
check(str_ends_with($rDe['rcra_classification'], '. ' . $tDe->get('section13.rcra_generator')), 'DE ends with generator sentence');

// ---------------------------------------------------------------------------
echo "13. Section 16 abbreviations pick up RCRA / TCLP\n";
$r = $run(['H226'], 38.0, false, [$toluene, $mek]);
$sds = [
    'meta'     => ['labels' => $labels],
    'sections' => [13 => $r],
];
$line = \SDS\Services\AbbreviationService::build($sds, $t);
check(str_contains($line, 'RCRA'), 'abbreviations line defines RCRA', $line);
check(str_contains($line, 'TCLP'), 'abbreviations line defines TCLP', $line);
check(str_contains($line, 'EPA'), 'abbreviations line defines EPA', $line);

$sdsNone = ['meta' => ['labels' => $labels], 'sections' => [13 => $run([], 93.0, true)]];
$lineNone = \SDS\Services\AbbreviationService::build($sdsNone, $t);
check(str_contains($lineNone, 'RCRA') && !str_contains($lineNone, 'TCLP'), 'rcra_none line defines RCRA but not TCLP', $lineNone);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
