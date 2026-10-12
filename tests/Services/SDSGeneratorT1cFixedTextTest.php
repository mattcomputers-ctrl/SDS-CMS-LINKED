<?php
/**
 * DB-free checks for fixed-text batch T1c:
 *   A5  Section 13: contaminated-packaging line (own key) + UV cure-residue sentence
 *       (UV family, rcra_none, complete composition only).
 *   A6  Section 14: "Special precautions for user" fragments from the PRINTED class
 *       (3 / 8), marine pollutant = Yes and the UV family; 14.special_precautions override.
 *   A7  Prop 65 safe-harbor warning: is/are by count, cancer clause first.
 *   A8  Abbreviations SDS/HDS/FDS, ISEA, ASTM, PBT; English HAP heading.
 *
 * Same Reflection App bootstrap as SDSGeneratorSection13Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorT1cFixedTextTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);
$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, ['paths' => []]);

use SDS\Services\TranslationService;
use SDS\Services\SDSGenerator;
use SDS\Services\Prop65Service;
use SDS\Services\TextOverrideService;
use SDS\Services\AbbreviationService;

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

$t   = new TranslationService('en');
$gen = new SDSGenerator($t);

$invoke = static function (SDSGenerator $g, string $name, array $args) {
    $m = new ReflectionMethod($g, $name);
    $m->setAccessible(true);
    return $m->invokeArgs($g, $args);
};
$uv = static function (SDSGenerator $g, bool $family) use ($invoke): void {
    $invoke($g, 'setUvState', [false, $family, []]);
};

$hz = static fn (array $codes): array => [
    'signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'p_statements' => [], 'exposure_limits' => [],
    'h_statements' => array_map(static fn ($c) => ['code' => $c, 'text' => ''], $codes),
];
$calc = static fn (?float $fp, bool $gt = false, array $composition = []): array => [
    'formula'       => ['lines' => []],
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => $gt, 'boiling_point_c' => null, 'enriched_lines' => []],
    'voc'           => ['total_voc_wt_pct' => 0, 'mixture_sg' => 1.0, 'voc_lb_per_gal' => 0, 'voc_lb_per_gal_less_water_exempt' => 0, 'solids_wt_pct' => 0, 'solids_vol_pct' => null],
    'composition'   => $composition, 'warnings' => [],
];
$rcra = static fn (array $components) => ['components' => $components, 'has_matches' => $components !== []];

$tmpta = [['cas_number' => '15625-89-5', 'chemical_name' => 'TMPTA']];
$s13 = static fn (SDSGenerator $g, array $codes, ?float $fp, bool $gt, array $composition, array $components = [], array $overrides = []) =>
    $invoke($g, 'section13', [$hz($codes), $calc($fp, $gt, $composition), $overrides, $rcra($components), []]);

$methods = $t->get('section13.methods');
$cure    = $t->get('section13.uv_cure_residue');
$pack    = $t->get('section13.packaging');

// ---------------------------------------------------------------------------
echo "A5. Section 13 contaminated packaging + UV cure-residue sentence\n";
$uv($gen, false);
$r = $s13($gen, [], 93.0, true, $tmpta);
check($r['methods'] === $methods, '1. not UV: methods = generic sentence', $r['methods']);
check($r['packaging'] === $pack && $pack !== '' && $pack !== 'section13.packaging', '1. packaging printed', $r['packaging']);
check(array_keys($r) === ['title', 'methods', 'packaging', 'rcra_classification'], '1. keys exactly title/methods/packaging/rcra_classification', array_keys($r));

$uv($gen, true);
$r = $s13($gen, [], 93.0, true, $tmpta);
check($r['methods'] === $methods . ' ' . $cure, '2. UV + rcra_none + complete composition: cure sentence appended', $r['methods']);

$r = $s13($gen, ['H226'], 38.0, false, $tmpta);
check(!str_contains($r['methods'], $cure) && str_contains($r['rcra_classification'], 'D001'), '3. UV + D001: no cure sentence', $r['methods']);

$r = $s13($gen, [], 93.0, true, array_merge($tmpta, [['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade Secret']]));
check($r['methods'] === $methods, '4. UV + TRADE_SECRET bucket row: no cure sentence', $r['methods']);
$r = $s13($gen, [], 93.0, true, array_merge($tmpta, [['cas_number' => '', 'chemical_name' => 'Unknown']]));
check($r['methods'] === $methods, '4. UV + blank-CAS row: no cure sentence', $r['methods']);

$r = $s13($gen, [], 93.0, true, []);
check($r['methods'] === $methods, '5. UV + empty composition: no cure sentence', $r['methods']);

$methanol = ['cas_number' => '67-56-1', 'chemical_name' => 'Methanol', 'is_trade_secret' => false, 'trade_secret_description' => null,
    'codes' => [['waste_code' => 'U154', 'kind' => 'U', 'description' => 'x', 'limit_mg_l' => null]]];
$r = $s13($gen, [], 93.0, true, $tmpta, [$methanol]);
check($r['methods'] === $methods && str_starts_with($r['rcra_classification'], $t->get('section13.rcra_none_characteristic')), '6. UV + listed-only component (rcra_none_characteristic): no cure sentence', $r);

$r = $s13($gen, [], 93.0, true, $tmpta, [], [13 => ['methods' => 'Custom']]);
check($r['methods'] === 'Custom', '7. 13.methods override replaces the whole field', $r['methods']);
check($r['packaging'] === $pack, '7. packaging still printed under a methods override', $r['packaging']);
$uv($gen, false);

foreach (['es', 'fr', 'de'] as $lang) {
    $tl = new TranslationService($lang);
    foreach (['packaging', 'uv_cure_residue'] as $k) {
        $v = $tl->get('section13.' . $k);
        check($v !== '' && $v !== 'section13.' . $k && $v !== $t->get('section13.' . $k), "8. {$lang}: section13.{$k} translated", $v);
    }
    $gl = new SDSGenerator($tl);
    $uv($gl, true);
    $rl = $s13($gl, [], 93.0, true, $tmpta);
    check($rl['methods'] === $tl->get('section13.methods') . ' ' . $tl->get('section13.uv_cure_residue') && $rl['packaging'] === $tl->get('section13.packaging'), "8. {$lang}: Section 13 in the sheet language", $rl);
}

$map = (new ReflectionClassConstant(\SDS\Services\PDFService::class, 'FIELD_LABEL_MAP'))->getValue();
check(($map['packaging'] ?? null) === 'contaminated_packaging', '9. PDFService::FIELD_LABEL_MAP maps packaging');
$preview = (string) file_get_contents($basePath . '/src/Views/sds/preview.php');
check(str_contains($preview, "'packaging'            => 'contaminated_packaging'"), '9. preview.php genericLabelMap maps packaging');
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tl = new TranslationService($lang);
    $labels = $invoke(new SDSGenerator($tl), 'getLabels', []);
    $v = $labels['contaminated_packaging'] ?? '';
    check($v !== '' && $v !== 'labels.contaminated_packaging' && ($lang === 'en' || $v !== 'Contaminated Packaging'), "9. {$lang}: labels.contaminated_packaging translated", $v);
}

// ---------------------------------------------------------------------------
echo "A6. Section 14 special precautions for user\n";
$fg  = ['description' => 'SOLVENT INK', 'family' => 'Solvent', 'physical_state' => 'Liquid', 'transport_product_type' => null];
$s14 = static fn (SDSGenerator $g, array $codes, ?float $fp, array $overrides = []) =>
    $invoke($g, 'section14', [$fg, $calc($fp), $hz($codes), $overrides]);
$B    = $t->get('section14.special_precautions_text');
$flam = $t->get('section14.special_precautions_flammable');
$corr = $t->get('section14.special_precautions_corrosive');
$mar  = $t->get('section14.special_precautions_marine');
$uvF  = $t->get('section14.special_precautions_uv');

$r = $s14($gen, ['H226'], 38.0);
check($r['un_number'] === 'UN1210' && $r['hazard_class'] === '3', '10. H226 fp 38 -> UN1210 class 3', [$r['un_number'], $r['hazard_class']]);
check($r['special_precautions'] === $B . ' ' . $flam, '10. standard + flammable fragment', $r['special_precautions']);

$r = $s14($gen, ['H314'], null);
check($r['hazard_class'] === '8', '11. H314 -> class 8', [$r['un_number'], $r['hazard_class']]);
check($r['special_precautions'] === $B . ' ' . $corr, '11. standard + corrosive fragment', $r['special_precautions']);

$r = $s14($gen, ['H226', 'H314'], 38.0);
check($r['hazard_class'] === '8 (3)', '12. H226 + H314 fp 38 -> class "8 (3)"', $r['hazard_class']);
check($r['special_precautions'] === $B . ' ' . $flam . ' ' . $corr, '12. standard + flammable + corrosive, in that order', $r['special_precautions']);

$r = $s14($gen, ['H411'], null);
check($r['un_number'] === 'UN3082' && $r['hazard_class'] === '9' && $r['environmental_hazards'] === $t->get('section14.marine_pollutant_yes'), '13. H411 -> UN3082 / 9 / marine pollutant yes', [$r['un_number'], $r['hazard_class'], $r['environmental_hazards']]);
check($r['special_precautions'] === $B . ' ' . $mar, '13. standard + marine fragment', $r['special_precautions']);

$r = $s14($gen, ['H411'], null, [14 => ['environmental_hazards' => 'Marine pollutant: No']]);
check($r['special_precautions'] === $B, '14. environmental_hazards override "No" -> no marine fragment', $r['special_precautions']);

$uv($gen, true);
$r = $s14($gen, [], null);
check($r['hazard_class'] === $t->get('labels.not_regulated') && $r['special_precautions'] === $B . ' ' . $uvF, '15. not regulated, UV family -> standard + UV fragment', $r['special_precautions']);
$r = $s14($gen, ['H411'], null);
check($r['special_precautions'] === $B . ' ' . $mar . ' ' . $uvF, '15. UV + marine -> marine then UV', $r['special_precautions']);
$uv($gen, false);
$r = $s14($gen, [], null);
check($r['special_precautions'] === $B, '15. not regulated, not UV -> standard only', $r['special_precautions']);

$r = $s14($gen, ['H226'], 38.0, [14 => ['special_precautions' => 'Custom precaution.']]);
check($r['special_precautions'] === 'Custom precaution.', '16. 14.special_precautions override replaces the line', $r['special_precautions']);
check($r['status'] === 'regulated', '16. override leaves the transport status alone', $r['status']);

$r = $s14($gen, [], null, [14 => ['hazard_class' => '3']]);
check(str_contains($r['special_precautions'], $flam), '17. partial class override "3" -> flammable fragment follows the printed class', $r['special_precautions']);

$tok = static fn (string $s) => $invoke($gen, 'transportClassTokens', [$s]);
check($tok('8 (3, 6.1)') === ['8', '3', '6.1'], '18. "8 (3, 6.1)" -> 8, 3, 6.1', $tok('8 (3, 6.1)'));
check($tok('6.1') === ['6.1'], '18. "6.1" -> 6.1 only (no "1")', $tok('6.1'));
check($tok('Not regulated') === [], '18. "Not regulated" -> []', $tok('Not regulated'));
check($tok('Class 3.') === ['3'], '18. sentence-final "Class 3." -> 3', $tok('Class 3.'));

check(TextOverrideService::isEditable(14, 'special_precautions'), '19. 14.special_precautions editable');
check((TextOverrideService::CROSS_FED_SOURCES[14] ?? null) === ['hazard_class', 'environmental_hazards'], '19. CROSS_FED_SOURCES[14]', TextOverrideService::CROSS_FED_SOURCES[14] ?? null);
$p = TextOverrideService::plan([14 => ['special_precautions' => $B]], [14 => ['special_precautions' => $B]], []);
check($p['upsert'] === [] && $p['counts']['auto'] === 1, '19. posted = automatic -> no upsert, auto 1', $p);
$p = TextOverrideService::plan([14 => ['special_precautions' => 'Different.']], [14 => ['special_precautions' => $B]], []);
check(count($p['upsert']) === 1 && $p['upsert'][0]['key'] === 'special_precautions', '19. posted different text -> 1 upsert', $p);

$extras = [0x20AC, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0160, 0x2039, 0x0152, 0x017D,
    0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, 0x017E, 0x0178];
$cp1252 = static function (string $s) use ($extras): bool {
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $o = mb_ord($ch, 'UTF-8');
        if ($o > 0xFF && !in_array($o, $extras, true)) {
            return false;
        }
    }
    return mb_convert_encoding(mb_convert_encoding($s, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252') === $s;
};
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tl = new TranslationService($lang);
    foreach (['flammable', 'corrosive', 'marine', 'uv'] as $k) {
        $v = $tl->get('section14.special_precautions_' . $k);
        check($v !== '' && !str_contains($v, 'section14.') && $cp1252($v), "20. {$lang}: special_precautions_{$k} resolves, cp1252-safe", $v);
    }
    if ($lang !== 'en') {
        check(str_contains($tl->get('section14.special_precautions_flammable'), '3') && str_contains($tl->get('section14.special_precautions_corrosive'), '8'), "20. {$lang}: flammable names class 3, corrosive class 8");
    }
}

// ---------------------------------------------------------------------------
echo "A7. Prop 65 safe-harbor warning\n";
$w = static fn (string $lang, array $c, array $r) => Prop65Service::safeHarborWarning($c, $r, new TranslationService($lang));
check($w('en', ['Lead'], []) === 'WARNING: This product can expose you to chemicals including Lead, which is known to the State of California to cause cancer. For more information go to www.P65Warnings.ca.gov.', '21. EN one cancer name -> "is"', $w('en', ['Lead'], []));
check(str_contains($w('en', ['Lead', 'Benzene'], []), 'Lead, Benzene, which are known'), '22. EN two cancer names -> "are"', $w('en', ['Lead', 'Benzene'], []));
check($w('en', ['Lead'], ['Lead', 'Toluene']) === 'WARNING: This product can expose you to chemicals including Lead, which is known to the State of California to cause cancer, and Lead, Toluene, which are known to the State of California to cause birth defects or other reproductive harm. For more information go to www.P65Warnings.ca.gov.', '23. EN both: cancer first, is / are per clause', $w('en', ['Lead'], ['Lead', 'Toluene']));
check($w('en', [], []) === '', '24. both empty -> ""');
check(str_contains($w('es', ['Lead'], []), 'causante de cáncer') && str_contains($w('es', ['Lead', 'Benzene'], []), 'causantes de cáncer'), '25. ES causante / causantes');
check(str_contains($w('fr', ['Lead'], []), 'reconnue par') && str_contains($w('fr', ['Lead', 'Benzene'], []), 'reconnues par'), '25. FR reconnue / reconnues');
check(str_contains($w('de', ['Lead'], []), 'Krebs verursacht.') && str_contains($w('de', ['Lead', 'Benzene'], []), 'Krebs verursachen.'), '25. DE verursacht / verursachen');
$bad = [];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    foreach ([[['A'], []], [['A', 'B'], []], [[], ['A']], [[], ['A', 'B']], [['A'], ['B']], [['A', 'B'], ['C', 'D']]] as [$c, $r]) {
        $s = $w($lang, $c, $r);
        if ($s === '' || str_contains($s, 'is/are') || str_contains($s, '(s)') || str_contains($s, ':names') || str_contains($s, ':clause') || str_contains($s, '_part')) {
            $bad[] = "{$lang}: {$s}";
        }
    }
}
check($bad === [], '26. no "is/are", "(s)" or placeholder echo in any language', $bad);
$hz0 = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'exposure_limits' => []];
$gEs = new SDSGenerator(new TranslationService('es'));
$ghsNoteProp = new ReflectionProperty(SDSGenerator::class, 'showGhsSectionNote');   // keep ghsSectionNote() DB-free
$ghsNoteProp->setAccessible(true);
$ghsNoteProp->setValue(null, false);
$s15 = $invoke($gEs, 'section15', [$hz0, ['reportable' => []],
    ['requires_warning' => true, 'warning_text' => 'WARNING: English from base', 'cancer_chemicals' => ['Lead (trace)'], 'repro_chemicals' => [], 'listed_chemicals' => []],
    ['has_haps' => false, 'total_hap_pct' => 0, 'hap_chemicals' => []], ['composition' => []], []]);
$ghsNoteProp->setValue(null, null);
check(str_contains($s15['prop65']['warning_text'], 'Lead (trazas), que el Estado de California reconoce como causante de cáncer'), '27. ES section15: trace name translated, singular clause', $s15['prop65']['warning_text']);

// ---------------------------------------------------------------------------
echo "A8. Abbreviations and English HAP heading\n";
$want = ['en' => ['SDS', 'ISEA', 'ASTM', 'PBT'], 'es' => ['HDS', 'ISEA', 'ASTM', 'PBT'], 'fr' => ['FDS', 'ISEA', 'ASTM', 'PBT'], 'de' => ['ISEA', 'ASTM', 'PBT']];
foreach ($want as $lang => $terms) {
    $table = AbbreviationService::table(new TranslationService($lang));
    $missing = array_values(array_filter($terms, static fn ($k) => trim((string) ($table[$k] ?? '')) === ''));
    check($missing === [], "28. {$lang}: table has " . implode(', ', $terms), $missing);
    $keys = array_map('strval', array_keys($table));
    $sorted = $keys;
    usort($sorted, 'strcasecmp');
    check($keys === $sorted, "28. {$lang}: table keys strcasecmp-sorted");
    if ($lang === 'de') {
        check(!isset($table['SDB']), '28. de: no SDB entry');
    }
}
$fixture = static function (TranslationService $tl): array {
    return ['meta' => ['labels' => []], 'sections' => [
        4  => ['notes' => $tl->get('section4.notes')],
        8  => ['eye_protection' => 'Safety glasses (ANSI/ISEA Z87.1).', 'hand_protection' => 'Nitrile gloves (ASTM F739).'],
        12 => ['persistence' => 'Not a persistent, bioaccumulative and toxic (PBT) substance.'],
    ]];
};
$line = AbbreviationService::build($fixture($t), $t);
check(str_contains($line, 'SDS = Safety Data Sheet') && str_contains($line, 'ISEA = ') && str_contains($line, 'ASTM = ') && str_contains($line, 'PBT = '), '29. EN line defines SDS, ISEA, ASTM, PBT', $line);
$tEs = new TranslationService('es');
check(str_contains(AbbreviationService::build($fixture($tEs), $tEs), 'HDS = '), '29. ES line defines HDS');
$tFr = new TranslationService('fr');
check(str_contains(AbbreviationService::build($fixture($tFr), $tFr), 'FDS = '), '29. FR line defines FDS');

check($t->get('labels.hap_title') === 'Clean Air Act Section 112(b) — Hazardous Air Pollutants (HAP)', '30. EN labels.hap_title', $t->get('labels.hap_title'));
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $v = (new TranslationService($lang))->get('labels.hap_title');
    check(str_contains($v, '112(b)') && str_contains($v, '(HAP)'), "30. {$lang}: hap_title names 112(b) and (HAP)", $v);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
