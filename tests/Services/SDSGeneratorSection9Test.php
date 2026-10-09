<?php
/**
 * DB-free checks for SDSGenerator Section 9 physical properties
 * (SDS content audit item #18):
 *
 *   (a) nothing "estimated"/"assumed" is printed even when the VOC result
 *       carries assumption / trace entries;
 *   (b) physical state = FG field -> dominant RM state -> 'Liquid'
 *       (SDSGenerator::resolvePhysicalState), and the same resolved value
 *       feeds Section 6 containment;
 *   (c) voc_less_water_exempt / solids_vol_pct are no longer in the payload
 *       and their labels are gone from all four languages;
 *   (d) solubility prints the translated band for formula_props.solubility_key,
 *       "Not determined" for null, and a per-FG override wins.
 *
 * Same Reflection bootstrap as SDSGeneratorSection5Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection9Test.php
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

// Any PHP notice/warning is a failure.
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
$s6 = $method('section6');

$calc = static fn (?string $solKey = null, string $domState = ''): array => [
    'formula'       => ['lines' => []],
    'formula_props' => [
        'flash_point_c'            => null,
        'flash_point_greater_than' => false,
        'boiling_point_c'          => null,
        'solubility_key'           => $solKey,
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
$nd = $t->get('labels.not_determined');

// ---------------------------------------------------------------------
echo "1. (c) Payload keys: no voc_less_water_exempt / solids_vol_pct\n";
$r = $s9->invoke($gen, ['physical_state' => 'Liquid', 'color' => ''], $calc(), []);
check(!array_key_exists('voc_less_water_exempt', $r), 'voc_less_water_exempt absent', array_keys($r));
check(!array_key_exists('solids_vol_pct', $r), 'solids_vol_pct absent', array_keys($r));
foreach (['title', 'physical_state', 'color', 'appearance', 'odor', 'boiling_point', 'flash_point', 'solubility',
          'specific_gravity', 'voc_lb_per_gal', 'solids_wt_pct', 'voc_wt_pct'] as $k) {
    check(array_key_exists($k, $r), "key {$k} present", array_keys($r));
}
check($r['voc_lb_per_gal'] === 0.29, 'voc_lb_per_gal kept', $r['voc_lb_per_gal']);
check($r['solids_wt_pct'] === 40.1, 'solids_wt_pct kept', $r['solids_wt_pct']);
check($r['voc_wt_pct'] === 3.2, 'voc_wt_pct kept', $r['voc_wt_pct']);
check($r['specific_gravity'] === 1.08, 'specific_gravity kept', $r['specific_gravity']);

// ---------------------------------------------------------------------
echo "2. (b) Physical state: FG -> dominant RM -> Liquid\n";
$r = $s9->invoke($gen, ['physical_state' => 'Paste', 'color' => ''], $calc(null, 'Powder'), []);
check($r['physical_state'] === 'Paste', "FG 'Paste' + dominant 'Powder' -> Paste", $r['physical_state']);
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => ''], $calc(null, 'Powder'), []);
check($r['physical_state'] === 'Powder', "FG '' + dominant 'Powder' -> Powder", $r['physical_state']);
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => ''], $calc(null, ''), []);
check($r['physical_state'] === 'Liquid', "FG '' + dominant '' -> Liquid", $r['physical_state']);
$r = $s9->invoke($gen, ['physical_state' => null, 'color' => ''], $calc(null, 'Solid'), []);
check($r['physical_state'] === 'Solid', 'FG null -> dominant', $r['physical_state']);
$r = $s9->invoke($gen, ['color' => ''], $calc(null, ''), []);
check($r['physical_state'] === 'Liquid', 'FG key missing + no dominant -> Liquid', $r['physical_state']);

$rp = \SDS\Services\SDSGenerator::resolvePhysicalState(['physical_state' => ' '], ['formula_props' => ['physical_state' => 'Solid']]);
check($rp === 'Solid', "resolvePhysicalState: whitespace FG state -> 'Solid'", $rp);
$rp = \SDS\Services\SDSGenerator::resolvePhysicalState(['physical_state' => ' Gel '], ['formula_props' => ['physical_state' => 'Solid']]);
check($rp === 'Gel', 'resolvePhysicalState: FG state trimmed', $rp);
$rp = \SDS\Services\SDSGenerator::resolvePhysicalState([], []);
check($rp === 'Liquid', 'resolvePhysicalState: nothing at all -> Liquid', $rp);
$rp = \SDS\Services\SDSGenerator::resolvePhysicalState(['physical_state' => null], ['formula_props' => ['physical_state' => null]]);
check($rp === 'Liquid', 'resolvePhysicalState: nulls -> Liquid', $rp);

// ---------------------------------------------------------------------
echo "3. (b) Appearance uses the resolved state\n";
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => 'Yellow'], $calc(null, 'Powder'), []);
check($r['appearance'] === 'Yellow powder', "FG colour 'Yellow' + dominant 'Powder' -> 'Yellow powder'", $r['appearance']);
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => 'Blue'], $calc(null, ''), []);
check($r['appearance'] === 'Blue liquid', "FG colour 'Blue' + nothing -> 'Blue liquid'", $r['appearance']);
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => ''], $calc(null, ''), [9 => ['appearance' => 'Opaque white paste']]);
check($r['appearance'] === 'Opaque white paste', 'appearance override wins', $r['appearance']);
// #17: with a blank FG colour the dominant raw material's appearance wins over the bare resolved state.
$c = $calc(null, 'Liquid');
$c['formula_props']['appearance'] = 'Clear viscous liquid';
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => ''], $c, []);
check($r['appearance'] === 'Clear viscous liquid', 'blank FG colour -> dominant RM appearance (#17)', $r['appearance']);
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => 'Blue'], $c, []);
check($r['appearance'] === 'Blue liquid', 'FG colour present -> colour + state, RM appearance ignored', $r['appearance']);
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => ''], $calc(null, ''), []);
check($r['appearance'] === 'liquid', 'blank colour + no RM appearance -> resolved state', $r['appearance']);
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => ''], $c, [9 => ['appearance' => 'Opaque white paste']]);
check($r['appearance'] === 'Opaque white paste', 'override still wins over the RM appearance', $r['appearance']);

// ---------------------------------------------------------------------
echo "4. (b) Section 6 / Section 9 parity through the resolver\n";
$fgBlank = ['physical_state' => '', 'color' => ''];
$calcPowder = $calc(null, 'Powder');
$fgResolved = $fgBlank;
$fgResolved['physical_state'] = \SDS\Services\SDSGenerator::resolvePhysicalState($fgBlank, $calcPowder);
check($fgResolved['physical_state'] === 'Powder', 'resolver gives Powder for blank FG + Powder RM', $fgResolved['physical_state']);
$r9 = $s9->invoke($gen, $fgResolved, $calcPowder, []);
$r6 = $s6->invoke($gen, ['h_statements' => []], $fgResolved, []);
check($r9['physical_state'] === 'Powder', 'section9 prints Powder', $r9['physical_state']);
check($r6['containment'] === $t->get('section6.containment_solid'), 'section6 prints solid containment for the same $fg', $r6['containment']);
$fgLiquid = $fgBlank;
$fgLiquid['physical_state'] = \SDS\Services\SDSGenerator::resolvePhysicalState($fgBlank, $calc(null, ''));
$r6 = $s6->invoke($gen, ['h_statements' => []], $fgLiquid, []);
check($r6['containment'] === $t->get('section6.containment_liquid'), 'fallback Liquid -> liquid containment', $r6['containment']);

// ---------------------------------------------------------------------
echo "5. (d) Solubility bands\n";
$fg = ['physical_state' => 'Liquid', 'color' => ''];
foreach (['soluble', 'partially_soluble', 'negligible', 'not_soluble'] as $key) {
    $r = $s9->invoke($gen, $fg, $calc($key), []);
    $expected = $t->get('section9.solubility_' . $key);
    check($r['solubility'] === $expected && $expected !== 'section9.solubility_' . $key, "{$key} -> {$expected}", $r['solubility']);
}
$r = $s9->invoke($gen, $fg, $calc(null), []);
check($r['solubility'] === $nd, 'null key -> Not determined', $r['solubility']);
check($nd === 'Not determined', 'en labels.not_determined is "Not determined"', $nd);
$legacy = $calc(null);
unset($legacy['formula_props']['solubility_key']);
$r = $s9->invoke($gen, $fg, $legacy, []);
check($r['solubility'] === $nd, 'missing solubility_key -> Not determined, no notice', $r['solubility']);
$legacy['formula_props']['solubility'] = 'Soluble in water'; // pre-#18 snapshot shape
$r = $s9->invoke($gen, $fg, $legacy, []);
check($r['solubility'] === $nd, 'legacy formula_props.solubility string is not consumed', $r['solubility']);
$r = $s9->invoke($gen, $fg, $calc('soluble'), [9 => ['solubility' => 'Miscible']]);
check($r['solubility'] === 'Miscible', 'override wins over the derived band', $r['solubility']);
$r = $s9->invoke($gen, $fg, $calc('negligible'), [9 => ['solubility' => '']]);
check($r['solubility'] === $t->get('section9.solubility_negligible'), 'empty override falls back to the derived band', $r['solubility']);
$r = $s9->invoke($gen, $fg, $calc('negligible'), [9 => ['solubility' => '   ']]);
check($r['solubility'] === $t->get('section9.solubility_negligible'), 'whitespace override falls back to the derived band', $r['solubility']);
$r = $s9->invoke($gen, $fg, $calc('bogus_key'), []);
check($r['solubility'] === $nd, 'unknown key -> Not determined', $r['solubility']);

// English wording per Decision 18
check($t->get('section9.solubility_soluble') === 'Soluble in water', 'en soluble wording');
check($t->get('section9.solubility_partially_soluble') === 'Partially soluble in water', 'en partially soluble wording');
check($t->get('section9.solubility_negligible') === 'Negligible solubility in water', 'en negligible wording');
check($t->get('section9.solubility_not_soluble') === 'Not soluble in water', 'en not soluble wording');

// ---------------------------------------------------------------------
echo "6. Translation completeness (all four languages)\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tl = new \SDS\Services\TranslationService($lang);
    foreach (['soluble', 'partially_soluble', 'negligible', 'not_soluble'] as $key) {
        $k = 'section9.solubility_' . $key;
        $v = $tl->get($k);
        check(is_string($v) && $v !== '' && $v !== $k, "{$lang}: {$k} defined", $v);
    }
    $v = $tl->get('section9.title');
    check(is_string($v) && $v !== '' && $v !== 'section9.title', "{$lang}: section9.title still defined", $v);
    check($tl->get('labels.voc_less_we') === 'labels.voc_less_we', "{$lang}: labels.voc_less_we removed", $tl->get('labels.voc_less_we'));
    check($tl->get('labels.solids_vol_pct') === 'labels.solids_vol_pct', "{$lang}: labels.solids_vol_pct removed", $tl->get('labels.solids_vol_pct'));
    foreach (['voc_lb_gal', 'voc_wt_pct', 'solids_wt_pct', 'solubility', 'physical_state'] as $lbl) {
        $v = $tl->get('labels.' . $lbl);
        check(is_string($v) && $v !== '' && $v !== 'labels.' . $lbl, "{$lang}: labels.{$lbl} kept", $v);
    }
}

// Spanish / French / German sheets print the translated term, not English
$es = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService('es'));
$m  = new ReflectionMethod($es, 'section9');
$m->setAccessible(true);
$r = $m->invoke($es, $fg, $calc('negligible'), []);
check($r['solubility'] === 'Solubilidad insignificante en agua', 'es sheet prints the Spanish band', $r['solubility']);

// ---------------------------------------------------------------------
echo "7. (a) Nothing 'estimated' / 'assumed' is printed\n";
$withAssumptions = $calc('soluble', 'Powder');
$withAssumptions['voc']['assumptions'] = [['message' => 'Specific gravity assumed 1.0 for RM1']];
$withAssumptions['voc']['trace']       = [['note' => 'SG defaulted to 1.0 for RM1'], ['note' => 'solids_vol_estimated']];
$withAssumptions['voc']['solids_vol_estimated'] = true;
$r = $s9->invoke($gen, ['physical_state' => '', 'color' => 'Yellow'], $withAssumptions, []);
$json = strtolower(json_encode($r));
check(strpos($json, 'estimat') === false, 'no "estimated" in the Section 9 payload', $json);
check(strpos($json, 'assum') === false, 'no "assumed"/"assumption" in the Section 9 payload', $json);
check(strpos($json, 'default') === false, 'no "defaulted" trace text in the Section 9 payload', $json);
check(!array_key_exists('assumptions', $r) && !array_key_exists('trace', $r), 'assumptions/trace not forwarded', array_keys($r));

// ---------------------------------------------------------------------
echo "8. getLabels() no longer lists the dropped keys\n";
$gl = $method('getLabels')->invoke($gen);
check(is_array($gl) && !array_key_exists('voc_less_we', $gl), 'voc_less_we not in meta.labels', array_keys($gl));
check(is_array($gl) && !array_key_exists('solids_vol_pct', $gl), 'solids_vol_pct not in meta.labels', array_keys($gl));
check(is_array($gl) && array_key_exists('solids_wt_pct', $gl) && array_key_exists('voc_lb_gal', $gl), 'kept labels still present');

restore_error_handler();

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
