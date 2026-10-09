#!/usr/bin/env php
<?php
/**
 * SDSGenerator::section10() unit test (audit item #19, DB-free)
 *
 * Exercises the Section 10 paragraphs directly through Reflection:
 *   - no hazard -> legacy reactivity / stability / decomposition, additive
 *     conditions default "Excessive heat, contact with strong oxidizing agents.";
 *   - flammable (H226) adds the ignition item only;
 *   - self-reactive / pyrophoric / water-reactive branch Reactivity and
 *     Stability to "Unstable under the following conditions: ..." and add
 *     their own conditions-to-avoid items, in COND_ORDER;
 *   - combined classes keep every fragment;
 *   - oxidizers swap "strong oxidizing agents" for combustibles;
 *   - self-heating (H251) adds the storage-temperature item, not ignition;
 *   - decomposition products from the cas_master element flags carried by
 *     the composition (1 flag -> legacy key, 2+ -> decomposition_multi);
 *   - $isUv (resolved family flag) adds the UV / sunlight item;
 *   - overrides win whole-field;
 *   - every new translation key exists in all four language files;
 *   - localised composition (DE / ES / FR).
 *
 * Run:
 *   php tests/Services/SDSGeneratorSection10Test.php
 *
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
$s10 = new ReflectionMethod($gen, 'section10');
$s10->setAccessible(true);

$hz   = fn(array $codes): array => ['h_statements' => array_map(fn($c) => ['code' => $c, 'text' => ''], $codes)];
$tr   = fn(string $k): string => $t->get('section10.' . $k);
$comp = fn(array $flags): array => [['cas_number' => '64-17-5', 'chemical_name' => 'x', 'concentration_pct' => 10.0] + $flags];
$run  = fn(array $codes, array $composition = [], bool $isUv = false, array $ov = []): array
    => $s10->invoke($gen, $hz($codes), $ov, $isUv, $composition);

// Case-insensitive: the first item is capitalised in the printed sentence.
$inOrder = function (string $haystack, array $needles): bool {
    $haystack = mb_strtolower($haystack);
    $last = -1;
    foreach ($needles as $n) {
        $p = strpos($haystack, mb_strtolower($n));
        if ($p === false || $p <= $last) {
            return false;
        }
        $last = $p;
    }
    return true;
};

$unstable = fn(array $keys): string => 'Unstable under the following conditions: '
    . implode('; ', array_map(fn($k) => $tr('stability_cond_' . $k), $keys)) . '.';

// ---------------------------------------------------------------------
echo "a. No hazard\n";
$s = $run([]);
check(array_keys($s) === ['title', 'reactivity', 'stability', 'conditions_avoid', 'incompatible', 'decomposition'], 'key set unchanged', array_keys($s));
check($s['reactivity'] === $tr('reactivity'), 'reactivity legacy', $s['reactivity']);
check($s['stability'] === $tr('stability'), 'stability legacy', $s['stability']);
check($s['conditions_avoid'] === 'Excessive heat, contact with strong oxidizing agents.', 'conditions default', $s['conditions_avoid']);
check(!str_contains($s['conditions_avoid'], 'flame') && !str_contains($s['conditions_avoid'], 'spark'), 'no ignition wording without ignition class');
check($s['decomposition'] === $tr('decomposition'), 'decomposition legacy', $s['decomposition']);
check($s['incompatible'] === $tr('incompatible'), 'incompatible legacy', $s['incompatible']);
$two = $s10->invoke($gen, $hz([]), []);
check($two === $s, 'two-argument call equals defaults');

// ---------------------------------------------------------------------
echo "b. H226 flammable\n";
$s = $run(['H226']);
check($s['stability'] === $tr('stability'), 'stability legacy');
check($s['reactivity'] === $tr('reactivity'), 'reactivity legacy');
check($s['conditions_avoid'] === 'Excessive heat, sparks, open flames and other ignition sources, contact with strong oxidizing agents.', 'conditions with ignition', $s['conditions_avoid']);

// ---------------------------------------------------------------------
echo "c. H242 self-reactive\n";
$s = $run(['H242']);
check($s['reactivity'] === $tr('reactivity_self_reactive'), 'reactivity self-reactive', $s['reactivity']);
check($s['stability'] === $unstable(['self_reactive']), 'stability unstable (self-reactive)', $s['stability']);
check($inOrder($s['conditions_avoid'], [$tr('cond_heat'), $tr('cond_storage_temp'), $tr('cond_ignition'), $tr('cond_shock'), $tr('cond_oxidizers')]), 'conditions order', $s['conditions_avoid']);
check(!str_contains($s['conditions_avoid'], $tr('cond_air')) && !str_contains($s['conditions_avoid'], $tr('cond_water')), 'no air / water item');
check(str_ends_with($s['conditions_avoid'], '.'), 'ends with period');
check(mb_substr($s['conditions_avoid'], 0, 1) === 'E', 'first letter capitalised');

// ---------------------------------------------------------------------
echo "d. H250 pyrophoric\n";
$s = $run(['H250']);
check($s['reactivity'] === $tr('reactivity_pyrophoric'), 'reactivity pyrophoric', $s['reactivity']);
check(str_contains($s['stability'], $tr('stability_cond_pyrophoric')), 'stability has pyrophoric condition', $s['stability']);
check($inOrder($s['conditions_avoid'], [$tr('cond_ignition'), $tr('cond_air'), $tr('cond_water')]), 'conditions ignition, air, water in order', $s['conditions_avoid']);

// ---------------------------------------------------------------------
echo "e. H261 water-reactive\n";
$s = $run(['H261']);
check($s['reactivity'] === $tr('reactivity_water_reactive'), 'reactivity water-reactive', $s['reactivity']);
check($s['stability'] === $unstable(['water_reactive']), 'stability unstable (water-reactive)', $s['stability']);
check(str_contains($s['conditions_avoid'], $tr('cond_water')), 'conditions has water');
check(!str_contains($s['conditions_avoid'], $tr('cond_air')), 'conditions lacks air');
check(!str_contains($s['conditions_avoid'], $tr('cond_ignition')), 'conditions lacks ignition');

// ---------------------------------------------------------------------
echo "f. Combined H242 + H261 + H226\n";
$s = $run(['H242', 'H261', 'H226']);
check($s['reactivity'] === $tr('reactivity_self_reactive') . ' ' . $tr('reactivity_water_reactive'), 'reactivity self + water', $s['reactivity']);
check($s['stability'] === $unstable(['self_reactive', 'water_reactive']), 'stability self; water', $s['stability']);
check($inOrder($s['conditions_avoid'], [$tr('cond_heat'), $tr('cond_storage_temp'), $tr('cond_ignition'), $tr('cond_water'), $tr('cond_shock'), $tr('cond_oxidizers')]), 'conditions order combined', $s['conditions_avoid']);
check($run(['H260']) === $run(['H261']), 'H260 equals H261');
$all = $run(['H242', 'H250', 'H261']);
check($all['stability'] === $unstable(['self_reactive', 'pyrophoric', 'water_reactive']), 'three reactive classes in fixed order', $all['stability']);
check(substr_count($all['conditions_avoid'], $tr('cond_water')) === 1, 'water item de-duplicated (pyrophoric + water-reactive)', $all['conditions_avoid']);

// ---------------------------------------------------------------------
echo "g. H272 oxidizer\n";
$s = $run(['H272']);
check(str_contains($s['conditions_avoid'], $tr('cond_combustibles')), 'conditions has combustibles', $s['conditions_avoid']);
check(!str_contains($s['conditions_avoid'], $tr('cond_oxidizers')), 'conditions lacks oxidizers');
check($s['stability'] === $tr('stability'), 'stability legacy');
check($s['incompatible'] === 'Combustible materials, reducing agents, organic materials, metals in powder form, strong acids, strong bases.', 'incompatible unchanged from #13', $s['incompatible']);
check(str_contains($run(['H270'])['conditions_avoid'], $tr('cond_combustibles')), 'H270 (oxidizing gas) also an oxidizer');

// ---------------------------------------------------------------------
echo "h. H251 self-heating\n";
$s = $run(['H251']);
check(str_contains($s['conditions_avoid'], $tr('cond_storage_temp')), 'conditions has storage temp', $s['conditions_avoid']);
check(!str_contains($s['conditions_avoid'], $tr('cond_ignition')), 'conditions lacks ignition');
check($s['stability'] === $tr('stability'), 'stability legacy');
check($s['reactivity'] === $tr('reactivity'), 'reactivity legacy');

// ---------------------------------------------------------------------
echo "i. Decomposition products from element flags\n";
check($run([], $comp(['has_nitrogen' => 1]))['decomposition'] === $tr('decomposition_nitrogen'), 'N only -> legacy nitrogen key');
check($run([], $comp(['has_sulfur' => 1]))['decomposition'] === $tr('decomposition_sulfur'), 'S only -> legacy sulfur key');
check($run([], $comp(['has_halogen' => 1]))['decomposition'] === $tr('decomposition_halogen'), 'halogen only -> legacy halogen key');
$ns = $run([], $comp(['has_nitrogen' => 1, 'has_sulfur' => 1]))['decomposition'];
check($ns === 'Carbon monoxide, carbon dioxide, nitrogen oxides, sulfur oxides, and other toxic gases may be released upon thermal decomposition.', 'N+S multi sentence', $ns);
$three = $run([], $comp(['has_nitrogen' => 1, 'has_sulfur' => 1, 'has_halogen' => 1]))['decomposition'];
check(str_contains($three, 'nitrogen oxides, sulfur oxides, hydrogen halides'), 'all three in N, S, halogen order', $three);
$mixed = $run([], [
    ['cas_number' => '1', 'has_sulfur' => true],
    ['cas_number' => '2', 'has_nitrogen' => true],
])['decomposition'];
check($mixed === $ns, 'order independent of composition order', $mixed);
foreach ([true, 1, '1'] as $v) {
    check($run([], $comp(['has_nitrogen' => $v]))['decomposition'] === $tr('decomposition_nitrogen'), 'truthy flag ' . var_export($v, true) . ' counts');
}
foreach ([0, '0', false] as $v) {
    check($run([], $comp(['has_nitrogen' => $v]))['decomposition'] === $tr('decomposition'), 'falsy flag ' . var_export($v, true) . ' ignored');
}
check($run([], $comp([]))['decomposition'] === $tr('decomposition'), 'missing keys -> legacy');
check($run([], [['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade Secret', 'concentration_pct' => 5.0]])['decomposition'] === $tr('decomposition'), 'TRADE_SECRET bucket -> legacy');
check($run([], ['not-an-array'])['decomposition'] === $tr('decomposition'), 'non-array entries skipped');

// ---------------------------------------------------------------------
echo "j. UV / LED family\n";
$s = $run([], [], true);
check(str_ends_with($s['conditions_avoid'], $tr('cond_uv') . '.'), 'UV -> conditions end with UV item', $s['conditions_avoid']);
check(str_contains($s['conditions_avoid'], 'UV'), 'UV item mentions UV (Section 16 abbreviation)');
check($s['conditions_avoid'] === 'Excessive heat, contact with strong oxidizing agents, ' . $tr('cond_uv') . '.', 'UV default sentence', $s['conditions_avoid']);
check(!str_contains($run([], [], false)['conditions_avoid'], $tr('cond_uv')), 'not UV -> no UV item');
check(!str_contains($run([])['conditions_avoid'], $tr('cond_uv')), '$isUv omitted -> no UV item');
$uvFlam = $run(['H226'], [], true)['conditions_avoid'];
check($inOrder($uvFlam, [$tr('cond_heat'), $tr('cond_ignition'), $tr('cond_oxidizers'), $tr('cond_uv')]), 'UV item last after oxidizers', $uvFlam);
$uvOx = $run(['H272'], [], true)['conditions_avoid'];
check($inOrder($uvOx, [$tr('cond_combustibles'), $tr('cond_uv')]), 'UV item last after combustibles', $uvOx);
check($run([], [], true)['reactivity'] === $tr('reactivity'), 'UV leaves reactivity alone');

// ---------------------------------------------------------------------
echo "k. Overrides win whole-field\n";
$ov = [10 => ['reactivity' => 'R', 'stability' => 'S', 'conditions_avoid' => 'C', 'decomposition' => 'D']];
$s = $run(['H242'], $comp(['has_nitrogen' => 1, 'has_sulfur' => 1]), true, $ov);
check($s['reactivity'] === 'R', 'reactivity override');
check($s['stability'] === 'S', 'stability override');
check($s['conditions_avoid'] === 'C', 'conditions override (no UV append)');
check($s['decomposition'] === 'D', 'decomposition override');
$s = $run(['H242'], [], false, [10 => ['incompatible' => 'Strong oxidizers, strong acids.']]);
check($s['incompatible'] === 'Strong oxidizers, strong acids.', 'incompatible override flows');
check($s['stability'] === $unstable(['self_reactive']), 'other fields still computed with partial override');

// ---------------------------------------------------------------------
echo "l. Translation completeness (en/es/fr/de)\n";
$newKeys = [
    'reactivity_self_reactive', 'reactivity_pyrophoric', 'reactivity_water_reactive',
    'stability_unstable', 'stability_cond_self_reactive', 'stability_cond_pyrophoric', 'stability_cond_water_reactive',
    'cond_heat', 'cond_storage_temp', 'cond_ignition', 'cond_air', 'cond_water', 'cond_shock',
    'cond_oxidizers', 'cond_combustibles', 'cond_uv',
    'decomposition_multi', 'decomp_nitrogen', 'decomp_sulfur', 'decomp_halogen',
];
check(count($newKeys) === 20, '20 new keys listed');
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $file = require $basePath . '/templates/translations/' . $lang . '.php';
    $sec  = $file['section10'] ?? [];
    $missing = [];
    foreach ($newKeys as $k) {
        if (!is_string($sec[$k] ?? null) || $sec[$k] === '') {
            $missing[] = $k;
        }
    }
    check($missing === [], "{$lang}: all 20 keys present and non-empty", $missing);
    check(str_contains($sec['stability_unstable'] ?? '', ':conditions'), "{$lang}: stability_unstable has :conditions");
    check(str_contains($sec['decomposition_multi'] ?? '', ':products'), "{$lang}: decomposition_multi has :products");
    foreach (['decomposition_nitrogen', 'decomposition_sulfur', 'decomposition_halogen'] as $k) {
        check(is_string($sec[$k] ?? null) && $sec[$k] !== '', "{$lang}: legacy {$k} still present");
    }
}

// ---------------------------------------------------------------------
echo "m. Localised composition\n";
$mk = function (string $lang): array {
    $tl = new \SDS\Services\TranslationService($lang);
    $gl = new \SDS\Services\SDSGenerator($tl);
    $ml = new ReflectionMethod($gl, 'section10');
    $ml->setAccessible(true);
    return [$gl, $ml, $tl];
};
[$gde, $mde] = $mk('de');
$sde = $mde->invoke($gde, $hz([]), []);
check($sde['conditions_avoid'] === 'Übermäßige Hitze, Kontakt mit starken Oxidationsmitteln.', 'DE default conditions (mb-safe capital)', $sde['conditions_avoid']);
[$ges, $mes] = $mk('es');
$ses = $mes->invoke($ges, $hz(['H260']), []);
check($ses['stability'] === 'Inestable en las siguientes condiciones: contacto con agua o humedad (desprende gases inflamables).', 'ES H260 stability', $ses['stability']);
[$gfr, $mfr] = $mk('fr');
$sfr = $mfr->invoke($gfr, $hz([]), [], false, $comp(['has_nitrogen' => 1, 'has_sulfur' => 1]));
check($sfr['decomposition'] === 'Monoxyde de carbone, dioxyde de carbone, oxydes d\'azote, oxydes de soufre et autres gaz toxiques pouvant se dégager lors de la décomposition thermique.', 'FR N+S decomposition', $sfr['decomposition']);
$sfr2 = $mfr->invoke($gfr, $hz(['H242']), []);
check(str_starts_with($sfr2['stability'], 'Instable dans les conditions suivantes : chauffage'), 'FR stability colon spacing', $sfr2['stability']);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
