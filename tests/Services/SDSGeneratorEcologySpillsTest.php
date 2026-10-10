#!/usr/bin/env php
<?php
/**
 * Ecology and spills (audit #24, #25, #32, #66 Section 12 parts), DB-free.
 *
 *   1. Engine (#25): dropLessSevereAquaticCodes() keeps one aquatic H-code
 *      per route; aquatic_basis records summation / override codes and mode.
 *      Driven through the real private aquatic phases (applyAquaticSummation,
 *      applyFinishedGoodOverride, buildAquaticComponentSummary) so the
 *      fixtures carry real engine output shapes.
 *   2. Section 12 (#25, #66): lead-in follows the real source of the codes
 *      (summation / manufacturer override / neutral); replace mode drops the
 *      component table; the table lists only CAS Section 3 lists; defaulted
 *      M-factors are marked "(M = 1, default)".
 *   3. Section 3 parity (#66): section3ListedCas() equals the CAS set
 *      section3() prints.
 *   4. Section 6 (#24, #32): independent acute / chronic sentences; H412 /
 *      H413 sentences; acute tox + H314 appends the corrosive fragment.
 *   5. New translation keys in en / es / fr / de.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorEcologySpillsTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\GHSHazardClass;
use SDS\Services\GHSStatements;
use SDS\Services\HazardEngine;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

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

// Keep section3() / section3ListedCas() DB-free (Settings inhalation-only list).
$inh = new ReflectionProperty(SDSGenerator::class, 'inhalationOnlyCas');
$inh->setAccessible(true);
$inhSaved = $inh->getValue();
$inh->setValue(null, ['1333-86-4' => 'Carbon Black']);
// ghsSectionNote() reads settings through the DB unless its cache is set.
$gsn = new ReflectionProperty(SDSGenerator::class, 'showGhsSectionNote');
$gsn->setAccessible(true);
$gsnSaved = $gsn->getValue();
$gsn->setValue(null, false);

// Real engine output: drive the private aquatic phases (no DB) and assemble
// the classify() keys Sections 6 / 12 read.
$runEngine = static function (array $buffer, ?array $override = null): array {
    $e = new HazardEngine();
    $call = static function (string $method, array $args) use ($e) {
        $m = new ReflectionMethod($e, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($e, $args);
    };
    $p = new ReflectionProperty($e, 'aquaticBuffer');
    $p->setAccessible(true);
    $p->setValue($e, $buffer);
    $classes = []; $h = []; $ps = []; $pic = []; $sw = null; $haz = [];
    $call('applyAquaticSummation', [&$classes, &$h, &$ps, &$pic, &$sw, &$haz]);
    if ($override !== null) {
        $call('applyFinishedGoodOverride', [$override, &$classes, &$h, &$ps, &$pic, &$sw]);
    }
    $call('dropLessSevereAquaticCodes', [&$h]);
    ksort($h);
    return [
        'hazard_classes'     => $classes,
        'h_statements'       => array_values(array_map(static fn(array $s): array => ['code' => $s['code'], 'text' => GHSStatements::hText($s['code'])], $h)),
        'p_statements'       => array_values($ps),
        'pictograms'         => array_keys($pic),
        'signal_word'        => $sw,
        'hazardous_cas'      => array_map('strval', array_keys($haz)),
        'exposure_limits'    => [],
        'aquatic_components' => $call('buildAquaticComponentSummary', []),
        'aquatic_basis'      => $call('aquaticBasisResult', []),
    ];
};
$row = static fn(string $cas, string $name, float $conc, string $cat, float $m = 1.0, string $src = 'default'): array => [
    'cas' => $cas, 'name' => $name, 'conc' => $conc, 'category' => $cat,
    'm_factor' => $m, 'm_factor_source' => $src, 'source' => 'hazard_classification',
];
$codesOf = static fn(array $hz): array => array_column($hz['h_statements'], 'code');

// Water-based ink: 3 % Biocide A (Chronic 1, no M on file) + 0.05 % Dispersant B (Chronic 2) + water.
$inkComp = [
    ['cas_number' => '7732-18-5', 'chemical_name' => 'Water',        'concentration_pct' => 96.95],
    ['cas_number' => '9999-01-1', 'chemical_name' => 'Biocide A',    'concentration_pct' => 3.0],
    ['cas_number' => '9999-02-2', 'chemical_name' => 'Dispersant B', 'concentration_pct' => 0.05],
];
$inkBuf = ['chronic' => [$row('9999-01-1', 'Biocide A', 3.0, 'Cat 1'), $row('9999-02-2', 'Dispersant B', 0.05, 'Cat 2')]];

// ---------------------------------------------------------------------
echo "1. Engine (#25)\n";
$drop = new ReflectionMethod(HazardEngine::class, 'dropLessSevereAquaticCodes');
$drop->setAccessible(true);
$dropKeys = static function (array $codes) use ($drop): array {
    $map = array_combine($codes, array_map(static fn(string $c): array => ['code' => $c, 'text' => ''], $codes));
    $drop->invokeArgs(new HazardEngine(), [&$map]);
    return array_keys($map);
};
check($dropKeys(['H315', 'H400', 'H401', 'H402', 'H410', 'H411', 'H412', 'H413']) === ['H315', 'H400', 'H410'], 'full ladder -> H400 + H410 (non-aquatic kept)', $dropKeys(['H315', 'H400', 'H401', 'H402', 'H410', 'H411', 'H412', 'H413']));
check($dropKeys(['H402', 'H412']) === ['H402', 'H412'], 'H402 + H412 unchanged (routes independent)');
check($dropKeys(['H401', 'H413', 'H411']) === ['H401', 'H411'], 'H401 + H413 + H411 -> H401 + H411', $dropKeys(['H401', 'H413', 'H411']));
check($dropKeys(['H319']) === ['H319'], 'no aquatic codes -> unchanged');

$sum = $runEngine($inkBuf);
check($codesOf($sum) === ['H411'], 'ink summation: Chronic 2 (10 x 3 + 0.05 = 30.05 >= 25) -> H411', $codesOf($sum));
check($sum['aquatic_basis'] === ['summation_codes' => ['H411'], 'override_mode' => null, 'override_codes' => []], 'ink basis: summation H411, no override', $sum['aquatic_basis']);
check(in_array('9999-02-2', $sum['hazardous_cas'], true), 'summation contributor marked hazardous', $sum['hazardous_cas']);

$add = $runEngine($inkBuf, ['mode' => 'additive', 'hazards' => [
    'hazard_classes' => [['class' => GHSHazardClass::displayName(GHSHazardClass::AQUATIC_CHRONIC), 'category' => 'Category 1']],
    'h_statements'   => 'H410',
]]);
check($codesOf($add) === ['H410'], 'additive override H410 over summation H411 -> H410 only', $codesOf($add));
check($add['aquatic_basis'] === ['summation_codes' => ['H411'], 'override_mode' => 'additive', 'override_codes' => ['H410']], 'additive basis', $add['aquatic_basis']);

$rep = $runEngine($inkBuf, ['mode' => 'replace', 'hazards' => [
    'hazard_classes' => [['class' => GHSHazardClass::displayName(GHSHazardClass::EYE_DAMAGE_IRRITATION), 'category' => 'Category 2A']],
    'h_statements'   => ['H319'],
]]);
check($codesOf($rep) === ['H319'], 'replace override (H319 only) discards the summation H411', $codesOf($rep));
check(($rep['aquatic_basis']['override_mode'] ?? null) === 'replace' && ($rep['aquatic_basis']['override_codes'] ?? null) === [], 'replace basis: mode replace, no override aquatic codes', $rep['aquatic_basis']);
check(count($rep['aquatic_components']) === 2, 'replace: engine still reports the buffered components', $rep['aquatic_components']);

$rep411 = $runEngine($inkBuf, ['mode' => 'replace', 'hazards' => ['h_statements' => 'H411']]);
check($codesOf($rep411) === ['H411'] && ($rep411['aquatic_basis']['override_codes'] ?? null) === ['H411'], 'replace override naming H411 -> H411, override code recorded', $rep411);

// ---------------------------------------------------------------------
echo "2. Section 12 lead-in / replace / filter / default M (#25, #66)\n";
$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$s12 = new ReflectionMethod($gen, 'section12');
$s12->setAccessible(true);
$tt = static fn(string $k): string => $t->get('section12.' . $k);

$r = $s12->invoke($gen, $sum, $inkComp, []);
check(str_starts_with($r['ecotoxicity'], $tt('ecotoxicity_classified') . ' H411: '), 'summation-only codes + table -> summation lead-in', $r['ecotoxicity']);
check(count($r['component_aquatic']) === 1 && $r['component_aquatic'][0]['cas_number'] === '9999-01-1', 'table: 0.05 % dispersant withheld (not in Section 3)', $r['component_aquatic']);
check(($r['component_aquatic'][0]['chronic'] ?? null) === 'Category 1 (M = 1, default)', 'defaulted M-factor marked as default', $r['component_aquatic'][0] ?? null);
check(!str_contains((string) json_encode($r), 'Dispersant B'), 'Section 12 never names the withheld dispersant');

$r = $s12->invoke($gen, $add, $inkComp, []);
check(str_starts_with($r['ecotoxicity'], $tt('ecotoxicity_classified_manufacturer') . ' H410: '), 'additive override code -> manufacturer lead-in', $r['ecotoxicity']);
check(!str_contains($r['ecotoxicity'], 'H411') && !str_contains($r['ecotoxicity'], 'summation'), 'no H411, no summation claim', $r['ecotoxicity']);
check(count($r['component_aquatic']) === 1, 'additive mode keeps the component table', $r['component_aquatic']);

$r = $s12->invoke($gen, $rep, $inkComp, []);
check($r['ecotoxicity'] === $tt('ecotoxicity_not_classified_manufacturer'), 'replace, no aquatic code -> manufacturer not-classified sentence', $r['ecotoxicity']);
check($r['component_aquatic'] === [], 'replace mode drops the component table', $r['component_aquatic']);
check(!str_contains($r['ecotoxicity'], 'component data'), 'no "component data" claim under replace', $r['ecotoxicity']);

$r = $s12->invoke($gen, $rep411, $inkComp, []);
check(str_starts_with($r['ecotoxicity'], $tt('ecotoxicity_classified_manufacturer') . ' H411: ') && $r['component_aquatic'] === [], 'replace naming H411 -> manufacturer lead-in, no table', $r);

// CPD / trade-secret origin: the CAS-determination path runs inside classify()
// and adds the code directly, leaving the basis empty.
$noSum = $runEngine(['chronic' => [$row('9999-01-1', 'Biocide A', 2.0, 'Cat 2')]]);
check($codesOf($noSum) === [], 'Chronic 2 at 2 % (Cat 3 rule 10 x 2 = 20 < 25) -> nothing fires', $codesOf($noSum));
$cpd = $noSum;
$cpd['h_statements'][] = ['code' => 'H411', 'text' => GHSStatements::hText('H411')];
$cpd['hazardous_cas']  = ['9999-01-1'];
$biocideComp = [['cas_number' => '9999-01-1', 'chemical_name' => 'Biocide A', 'concentration_pct' => 2.0]];
$r = $s12->invoke($gen, $cpd, $biocideComp, []);
check(str_starts_with($r['ecotoxicity'], $tt('ecotoxicity_classified_no_table') . ' H411: ') && count($r['component_aquatic']) === 1, 'code not from summation or override -> neutral lead-in even with a table', $r);

$r = $s12->invoke($gen, $noSum, $biocideComp, []);
check($r['component_aquatic'] === [] && $r['ecotoxicity'] === $tt('ecotoxicity_not_classified_no_table'), 'aquatic data only on unlisted components -> no table, no-table sentence', $r);

$vend = $runEngine(['acute' => [$row('9999-03-3', 'Biocide C', 3.0, 'Cat 1', 10.0, 'vendor')]]);
$r = $s12->invoke($gen, $vend, [['cas_number' => '9999-03-3', 'chemical_name' => 'Biocide C', 'concentration_pct' => 3.0]], []);
check($codesOf($vend) === ['H400'] && ($r['component_aquatic'][0]['acute'] ?? null) === 'Category 1 (M = 10)', 'vendor M-factor prints without the default marker', $r['component_aquatic'] ?? null);

$es = new SDSGenerator(new TranslationService('es'));
$r = $s12->invoke($es, $sum, $inkComp, []);
check(str_ends_with((string) ($r['component_aquatic'][0]['chronic'] ?? ''), ', valor por defecto)'), 'ES default M-factor marker', $r['component_aquatic'][0] ?? null);

// ---------------------------------------------------------------------
echo "3. Section 3 parity (#66)\n";
$parityComp = [
    ['cas_number' => '1111-11-1', 'chemical_name' => 'Classified resin',      'concentration_pct' => 5.0],
    ['cas_number' => '2222-22-2', 'chemical_name' => 'Trace classified',      'concentration_pct' => 0.05],
    ['cas_number' => '3333-33-3', 'chemical_name' => 'OEL-only solvent',      'concentration_pct' => 2.0],
    ['cas_number' => '4444-44-4', 'chemical_name' => 'Unclassified additive', 'concentration_pct' => 10.0],
    ['cas_number' => '1333-86-4', 'chemical_name' => 'Carbon black',          'concentration_pct' => 3.0],
    ['cas_number' => '5555-55-5', 'chemical_name' => 'Hidden',                'concentration_pct' => 4.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary resin'],
];
$pc = static fn(string $canon, string $cat, string $cas, array $codes): array => [
    'class' => GHSHazardClass::displayName($canon), 'category' => 'Category ' . substr($cat, 4),
    'canonical' => $canon, 'category_canonical' => $cat, 'cas' => $cas, 'chemical' => $cas,
    'concentration_pct' => 1.0, 'cutoff_pct' => 1.0, 'h_codes' => $codes,
];
$parityHz = [
    'hazardous_cas'   => ['1111-11-1', '2222-22-2', '1333-86-4', '5555-55-5'],
    'exposure_limits' => [['cas_number' => '3333-33-3', 'chemical_name' => 'OEL-only solvent', 'concentration_pct' => 2.0, 'limit_type' => 'PEL-TWA', 'value' => '100', 'units' => 'ppm']],
    'hazard_classes'  => [
        $pc(GHSHazardClass::SKIN_CORROSION_IRRITATION, 'Cat 2', '1111-11-1', ['H315']),
        $pc(GHSHazardClass::EYE_DAMAGE_IRRITATION, 'Cat 2A', '2222-22-2', ['H319']),
        $pc(GHSHazardClass::SKIN_SENSITIZATION, 'Cat 1', '5555-55-5', ['H317']),
    ],
];
$lm = new ReflectionMethod(SDSGenerator::class, 'section3ListedCas');
$lm->setAccessible(true);
$listed = array_map('strval', array_keys($lm->invoke(null, $parityComp, $parityHz)));
sort($listed);
check($listed === ['1111-11-1', '3333-33-3', '5555-55-5'], 'listed set: trace, unclassified and unattributed carbon black withheld', $listed);

$s3 = new ReflectionMethod($gen, 'section3');
$s3->setAccessible(true);
$rows3 = $s3->invoke($gen, $parityComp, $parityHz, [])['components'];
$plain = array_values(array_filter(array_column($rows3, 'cas_number'), static fn($c): bool => $c !== $t->get('labels.trade_secret_cas')));
sort($plain);
check($plain === ['1111-11-1', '3333-33-3'] && count($rows3) === 3, 'section3() lists the same set (trade-secret row masked)', $rows3);

// Substance sheet: the identity (largest constituent) is listed like section3() #36(3).
$subListed = array_keys($lm->invoke(null, $parityComp, $parityHz, \SDS\Services\SubstanceMixtureResolver::SUBSTANCE));
$subRows   = $s3->invoke($gen, $parityComp, $parityHz, [], \SDS\Services\SubstanceMixtureResolver::SUBSTANCE)['components'];
check(in_array('4444-44-4', $subListed, true) && in_array('4444-44-4', array_column($subRows, 'cas_number'), true), 'Substance identity listed by both', [$subListed, $subRows]);

$p12 = array_merge($runEngine(['chronic' => [
    $row('1111-11-1', 'Classified resin', 5.0, 'Cat 3'),
    $row('2222-22-2', 'Trace classified', 0.05, 'Cat 3'),
    $row('4444-44-4', 'Unclassified additive', 10.0, 'Cat 3'),
    $row('5555-55-5', 'Hidden', 4.0, 'Cat 3'),
]]), $parityHz);
$r = $s12->invoke($gen, $p12, $parityComp, []);
check(array_column($r['component_aquatic'], 'cas_number') === ['1111-11-1', $t->get('labels.trade_secret_cas')], 'Section 12 table = Section 3 CAS set, conc order', $r['component_aquatic']);
$j = (string) json_encode($r);
check(!str_contains($j, '4444-44-4') && !str_contains($j, '2222-22-2') && !str_contains($j, 'Hidden') && !str_contains($j, '5555-55-5'), 'no withheld or trade-secret identity in Section 12', $j);
check($r['ecotoxicity'] === $tt('ecotoxicity_not_classified'), 'Cat 3 sum 19.05 < 25 + table -> not-classified (component data) sentence', $r['ecotoxicity']);

// ---------------------------------------------------------------------
echo "4. Section 6 (#24, #32)\n";
$s6 = new ReflectionMethod($gen, 'section6');
$s6->setAccessible(true);
$t6 = static fn(string $k): string => $t->get('section6.' . $k);
$env = static fn(array $hz): string => $s6->invoke($gen, $hz, ['physical_state' => 'Liquid'], [])['environmental'];
$base = $t6('environmental');
$notify = $t6('environmental_notify');

$h412 = $runEngine(['chronic' => [$row('9999-04-4', 'Dispersant D', 4.0, 'Cat 2')]]);
check($codesOf($h412) === ['H412'], 'Chronic 2 at 4 % -> Chronic 3 (10 x 4 = 40) H412', $codesOf($h412));
check($env($h412) === $base . ' ' . $t6('environmental_aquatic_chronic_harmful') . ' ' . $notify && str_contains($env($h412), 'with long lasting effects'), 'H412 sentence keeps "with long lasting effects"', $env($h412));

$h413 = $runEngine([], ['mode' => 'additive', 'hazards' => ['h_statements' => 'H413']]);
check($env($h413) === $base . ' ' . $t6('environmental_aquatic_chronic_may_harm') . ' ' . $notify && !str_contains($env($h413), 'Harmful to aquatic life'), 'H413 sentence, never "Harmful to aquatic life"', $env($h413));

$h400412 = $runEngine([
    'acute'   => [$row('9999-03-3', 'Biocide C', 3.0, 'Cat 1', 10.0, 'vendor')],
    'chronic' => [$row('9999-04-4', 'Dispersant D', 4.0, 'Cat 2')],
]);
check($codesOf($h400412) === ['H400', 'H412'], 'H400 + H412 product', $codesOf($h400412));
check($env($h400412) === $base . ' ' . $t6('environmental_aquatic_acute') . ' ' . $t6('environmental_aquatic_chronic_harmful') . ' ' . $notify, 'acute + chronic sentences both print', $env($h400412));

check($env($add) === $base . ' ' . $t6('environmental_aquatic_chronic_very') . ' ' . $notify, 'additive H410 product -> very toxic sentence only (no H411 sentence)', $env($add));

$hzc = static fn(array $c): array => ['h_statements' => array_map(static fn($x) => ['code' => $x, 'text' => GHSStatements::hText($x)], $c)];
$pp = static fn(array $c): string => $s6->invoke($gen, $hzc($c), ['physical_state' => 'Liquid'], [])['personal_precautions'];
check($pp(['H301', 'H314']) === $t6('precautions_acute_toxic') . ' ' . $t6('precautions_corrosive_addon') && str_contains($pp(['H301', 'H314']), 'face shield') && str_contains($pp(['H301', 'H314']), 'SCBA'), 'H301 + H314 -> SCBA paragraph + corrosive fragment', $pp(['H301', 'H314']));
check($pp(['H314']) === $t6('precautions_corrosive'), 'H314 only -> corrosive paragraph', $pp(['H314']));
check($pp(['H331']) === $t6('precautions_acute_toxic'), 'H331 only -> acute paragraph', $pp(['H331']));

// ---------------------------------------------------------------------
echo "5. Translation keys\n";
$s6Keys  = ['environmental_aquatic_chronic_harmful', 'environmental_aquatic_chronic_may_harm', 'precautions_corrosive_addon'];
$s12Sent = ['ecotoxicity_classified_manufacturer', 'ecotoxicity_not_classified_manufacturer', 'ecotoxicity_not_classified_no_table'];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($s6Keys as $k) {
        $v = $trFile['section6'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section6.{$k}", $v);
    }
    foreach (array_merge($s12Sent, ['m_factor_default']) as $k) {
        $v = $trFile['section12'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section12.{$k}", $v);
    }
    check(str_contains((string) ($trFile['section12']['m_factor_default'] ?? ''), ':value'), "{$lang} section12.m_factor_default has :value");
    if ($lang !== 'en') {
        foreach ($s12Sent as $k) {
            $v = (string) ($trFile['section12'][$k] ?? '');
            $ok = !str_contains($v, 'Chapter') && !str_contains($v, 'summation method');
            if ($lang === 'es') {
                $ok = $ok && str_contains($v, 'SGA') && !str_contains($v, 'GHS');
            } elseif ($lang === 'fr') {
                $ok = $ok && str_contains($v, 'SGH') && !str_contains($v, 'GHS');
            }
            check($ok, "{$lang} section12.{$k} localised (no English cite words; ES SGA / FR SGH)", $v);
        }
    }
}

$inh->setValue(null, $inhSaved);
$gsn->setValue(null, $gsnSaved);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
