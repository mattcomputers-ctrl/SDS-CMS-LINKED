#!/usr/bin/env php
<?php
/**
 * SDSGenerator::section7() / section10() incompatibles unit test
 * (audit item #13, DB-free)
 *
 * Exercises the additive Section 7 composition and the shared Section 10
 * "Incompatible materials" helper directly through Reflection:
 *   - no hazard -> base paragraphs only, no fire wording, legacy S10 default;
 *   - flammable / combustible / self-heating / pyrophoric / water-reactive /
 *     oxidizer / self-reactive each contribute their own fragments;
 *   - combined hazards keep every fragment, in flag order;
 *   - H251 is self-heating, never pyrophoric;
 *   - Section 7 storage names exactly the Section 10 incompatibles list;
 *   - overrides win whole-field, and a Section 10 override flows into
 *     Section 7 storage;
 *   - every new translation key exists in all four language files and
 *     ES/FR base paragraphs match EN/DE sentence counts;
 *   - localised composition (DE mb-safe capitalisation, ES default).
 *
 * Run:
 *   php tests/Services/SDSGeneratorSection7Test.php
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

$method = function (string $name) use ($gen): ReflectionMethod {
    $m = new ReflectionMethod($gen, $name);
    $m->setAccessible(true);
    return $m;
};
$s7  = $method('section7');
$s10 = $method('section10');

$tr = fn(string $key): string => $t->get('section7.' . $key);
$hz = fn(array $codes): array => [
    'h_statements' => array_map(fn($c) => ['code' => $c, 'text' => ''], $codes),
];

const S10_MARK = '(see Section 10): ';
$defaultList = 'strong oxidizing agents, strong acids, strong bases';
$incompatSentence = fn(string $list): string => 'Store away from incompatible materials (see Section 10): ' . $list . '.';

/** Ascending-position assertion: every needle appears, each after the previous. */
$inOrder = function (string $haystack, array $needles): bool {
    $last = -1;
    foreach ($needles as $n) {
        $p = strpos($haystack, $n);
        if ($p === false || $p <= $last) {
            return false;
        }
        $last = $p;
    }
    return true;
};

/** Case k: Section 7 storage list equals Section 10 incompatible (first letter aside). */
$agree = function (array $hazard, array $overrides, string $label) use ($s7, $s10, $gen): void {
    $storage = $s7->invoke($gen, $hazard, $overrides)['storage'];
    $inc     = $s10->invoke($gen, $hazard, $overrides)['incompatible'];
    $pos     = strpos($storage, S10_MARK);
    $tail    = $pos === false ? '' : rtrim(substr($storage, $pos + strlen(S10_MARK)), '.');
    check($pos !== false && rtrim($inc, '.') === ucfirst($tail), "k. S7/S10 agree: {$label}", [$storage, $inc]);
};

$noFire = fn(string $s): bool => !str_contains(strtolower($s), 'flame')
    && !str_contains(strtolower($s), 'spark')
    && !str_contains(strtolower($s), 'ignition');

// ---------------------------------------------------------------------
echo "a. No hazards -> base paragraphs, no fire wording, legacy S10 default\n";
$s = $s7->invoke($gen, $hz([]), []);
check(array_keys($s) === ['title', 'handling', 'storage'], 'key order', array_keys($s));
check($s['handling'] === $tr('handling_base'), 'handling === base', $s['handling']);
check($s['storage'] === $tr('storage_base') . ' ' . $incompatSentence($defaultList), 'storage === base + incompatibles', $s['storage']);
check($noFire($s['handling']), 'no fire wording in handling', $s['handling']);
check($noFire($s['storage']), 'no fire wording in storage', $s['storage']);
$inc = $s10->invoke($gen, $hz([]), [])['incompatible'];
check($inc === 'Strong oxidizing agents, strong acids, strong bases.', 'S10 default list', $inc);
check($inc === $t->get('section10.incompatible'), 'S10 byte-identical to legacy section10.incompatible', $inc);
$agree($hz([]), [], 'no hazards');

// ---------------------------------------------------------------------
echo "b. Flammable solvent ink H226 + H304 + H336 + H412\n";
$hzB = $hz(['H226', 'H304', 'H336', 'H412']);
$s = $s7->invoke($gen, $hzB, []);
check(
    $s['handling'] === $tr('handling_base') . ' ' . $tr('handling_ignition') . ' ' . $tr('handling_flammable_static'),
    'handling base + ignition + static',
    $s['handling']
);
check(
    $s['storage'] === $tr('storage_base') . ' ' . $tr('storage_ignition') . ' ' . $tr('storage_flammable_area') . ' ' . $tr('storage_locked') . ' ' . $incompatSentence($defaultList . ', halogens'),
    'storage base + ignition + flammable area + locked + incompatibles',
    $s['storage']
);
$inc = $s10->invoke($gen, $hzB, [])['incompatible'];
check($inc === 'Strong oxidizing agents, strong acids, strong bases, halogens.', 'S10 flammable list', $inc);
$agree($hzB, [], 'flammable solvent ink');

// ---------------------------------------------------------------------
echo "c. Combustible only H227\n";
$hzC = $hz(['H227']);
$s = $s7->invoke($gen, $hzC, []);
check($s['handling'] === $tr('handling_base') . ' ' . $tr('handling_ignition'), 'handling base + ignition only', $s['handling']);
check(str_contains($s['storage'], $tr('storage_ignition')), 'storage has ignition', $s['storage']);
check(!str_contains($s['storage'], $tr('storage_flammable_area')), 'storage lacks flammable area', $s['storage']);
$inc = $s10->invoke($gen, $hzC, [])['incompatible'];
check(str_contains($inc, 'halogens'), 'S10 contains halogens', $inc);
$agree($hzC, [], 'combustible');

// ---------------------------------------------------------------------
echo "d. UV ink with sensitiser H315 + H317 + H319 + H412\n";
$hzD = $hz(['H315', 'H317', 'H319', 'H412']);
$s = $s7->invoke($gen, $hzD, []);
check($s['handling'] === $tr('handling_base') . ' ' . $tr('handling_sensitizer'), 'handling base + sensitizer', $s['handling']);
check($s['storage'] === $tr('storage_base') . ' ' . $incompatSentence($defaultList), 'storage base + default incompatibles', $s['storage']);
check($noFire($s['handling']) && $noFire($s['storage']), 'no fire wording', $s);
$agree($hzD, [], 'UV sensitiser');

// ---------------------------------------------------------------------
echo "e. Flammable + corrosive both appear (H226 + H314)\n";
$hzE = $hz(['H226', 'H314']);
$s = $s7->invoke($gen, $hzE, []);
check(
    $inOrder($s['handling'], [$tr('handling_ignition'), $tr('handling_flammable_static'), $tr('handling_corrosive')]),
    'handling ignition < static < corrosive',
    $s['handling']
);
check(str_contains($s['storage'], $tr('storage_flammable_area')), 'storage has flammable area', $s['storage']);
check(str_contains($s['storage'], $tr('storage_locked')), 'storage has locked up', $s['storage']);
$agree($hzE, [], 'flammable + corrosive');

// ---------------------------------------------------------------------
echo "e2. 'Store locked up' (P405) trigger set: H318 no, H335/H336/H371 yes\n";
$s = $s7->invoke($gen, $hz(['H318', 'H315']), []);
check(!str_contains($s['storage'], $tr('storage_locked')), 'H318 + H315 (Eye Dam. 1): no locked up', $s['storage']);
check($s['storage'] === $tr('storage_base') . ' ' . $incompatSentence($defaultList), 'H318 + H315 storage = base + default incompatibles', $s['storage']);
$agree($hz(['H318', 'H315']), [], 'H318 + H315');
$s = $s7->invoke($gen, $hz(['H225', 'H319', 'H336']), []);
check(str_contains($s['storage'], $tr('storage_locked')), 'H225 + H319 + H336 (STOT SE 3): locked up', $s['storage']);
check(!str_contains($s['handling'], $tr('storage_locked')), 'locked up never leaks into handling', $s['handling']);
foreach (['H335', 'H371', 'H314', 'H304', 'H370'] as $code) {
    $s = $s7->invoke($gen, $hz([$code]), []);
    check(str_contains($s['storage'], $tr('storage_locked')), "{$code} alone: locked up", $s['storage']);
}
foreach (['H318', 'H319', 'H315'] as $code) {
    $s = $s7->invoke($gen, $hz([$code]), []);
    check(!str_contains($s['storage'], $tr('storage_locked')), "{$code} alone: no locked up", $s['storage']);
}

// ---------------------------------------------------------------------
echo "f. H251 self-heating is NOT pyrophoric\n";
$hzF = $hz(['H251']);
$s = $s7->invoke($gen, $hzF, []);
check($s['handling'] === $tr('handling_base') . ' ' . $tr('handling_self_heating'), 'handling base + self-heating', $s['handling']);
check(!str_contains($s['handling'], 'inert gas'), 'handling has no inert gas', $s['handling']);
check(str_contains($s['storage'], $tr('storage_self_heating_cool')), 'storage has self-heating cool', $s['storage']);
check(!str_contains($s['storage'], $tr('storage_inert_moisture')), 'storage lacks inert/moisture', $s['storage']);
$inc = $s10->invoke($gen, $hzF, [])['incompatible'];
check($inc === 'Strong oxidizing agents, strong acids, strong bases.', 'S10 default (no air)', $inc);
$agree($hzF, [], 'H251');

// ---------------------------------------------------------------------
echo "g. H250 pyrophoric\n";
$hzG = $hz(['H250']);
$s = $s7->invoke($gen, $hzG, []);
check(str_contains($s['handling'], $tr('handling_pyrophoric_air')), 'handling has pyrophoric air', $s['handling']);
check(str_contains($s['handling'], $tr('handling_moisture')), 'handling has moisture', $s['handling']);
check(str_contains($s['handling'], $tr('handling_ignition')), 'handling has ignition', $s['handling']);
$inc = $s10->invoke($gen, $hzG, [])['incompatible'];
check($inc === 'Water and moisture, air, strong oxidizing agents, strong acids, strong bases.', 'S10 pyrophoric list', $inc);
check(str_ends_with($s['storage'], ': water and moisture, air, strong oxidizing agents, strong acids, strong bases.'), 'storage ends with list', $s['storage']);
check(str_contains($s['storage'], $tr('storage_inert_moisture')), 'storage has inert/moisture', $s['storage']);
check(str_contains($s['storage'], $tr('storage_pyrophoric_air')), 'storage has pyrophoric air', $s['storage']);
$agree($hzG, [], 'H250');

// ---------------------------------------------------------------------
echo "h. H260 water reactive\n";
$hzH = $hz(['H260']);
$s = $s7->invoke($gen, $hzH, []);
check(str_contains($s['handling'], $tr('handling_water_reactive_water')), 'handling has water-reactive', $s['handling']);
check(str_contains($s['handling'], $tr('handling_moisture')), 'handling has moisture', $s['handling']);
check(!str_contains($s['handling'], $tr('handling_ignition')), 'handling lacks ignition', $s['handling']);
$inc = $s10->invoke($gen, $hzH, [])['incompatible'];
check($inc === 'Water and moisture, strong oxidizing agents, strong acids, strong bases.', 'S10 water-reactive list', $inc);
$agree($hzH, [], 'H260');

// ---------------------------------------------------------------------
echo "i. H272 oxidizer\n";
$hzI = $hz(['H272']);
$s = $s7->invoke($gen, $hzI, []);
$inc = $s10->invoke($gen, $hzI, [])['incompatible'];
check($inc === 'Combustible materials, reducing agents, organic materials, metals in powder form, strong acids, strong bases.', 'S10 oxidizer list', $inc);
check(!str_contains($inc, 'strong oxidizing agents'), 'S10 lacks oxidizers', $inc);
check(str_contains($s['handling'], $tr('handling_oxidizer_combustibles')), 'handling has oxidizer', $s['handling']);
check(str_contains($s['storage'], $tr('storage_oxidizer_separate')), 'storage has oxidizer separate', $s['storage']);
$agree($hzI, [], 'H272');

// ---------------------------------------------------------------------
echo "j. H241 self-reactive (symmetry)\n";
$hzJ = $hz(['H241']);
$s = $s7->invoke($gen, $hzJ, []);
check($inOrder($s['handling'], [$tr('handling_ignition'), $tr('handling_self_reactive')]), 'handling ignition + self-reactive', $s['handling']);
check($inOrder($s['storage'], [$tr('storage_ignition'), $tr('storage_self_reactive')]), 'storage ignition + self-reactive', $s['storage']);
$inc = $s10->invoke($gen, $hzJ, [])['incompatible'];
check($inc === 'Strong oxidizing agents, reducing agents, strong acids, strong bases, amines, heavy metals and metal salts.', 'S10 self-reactive list', $inc);
$agree($hzJ, [], 'H241');

// ---------------------------------------------------------------------
echo "l. Overrides\n";
$ov = [10 => ['incompatible' => 'Strong oxidizers, strong acids.']];
$inc = $s10->invoke($gen, $hzB, $ov)['incompatible'];
check($inc === 'Strong oxidizers, strong acids.', 'S10 override verbatim', $inc);
$s = $s7->invoke($gen, $hzB, $ov);
check(str_ends_with($s['storage'], '(see Section 10): Strong oxidizers, strong acids.'), 'S7 storage ends with override (single full stop)', $s['storage']);
check(!str_ends_with($s['storage'], '..'), 'no double full stop', $s['storage']);
$agree($hzB, $ov, 'S10 override');
$s = $s7->invoke($gen, $hzB, [7 => ['handling' => 'Custom H', 'storage' => 'Custom S']]);
check($s['handling'] === 'Custom H', 'handling override', $s['handling']);
check($s['storage'] === 'Custom S', 'storage override', $s['storage']);

// ---------------------------------------------------------------------
echo "m. Translation completeness (en/es/fr/de)\n";
$s7Keys = [
    'handling_base', 'handling_ignition', 'handling_flammable_static', 'handling_aerosol',
    'handling_oxidizer_combustibles', 'handling_self_reactive', 'handling_self_heating',
    'handling_pyrophoric_air', 'handling_water_reactive_water', 'handling_moisture',
    'handling_corrosive', 'handling_corrosive_metals', 'handling_sensitizer',
    'storage_base', 'storage_ignition', 'storage_flammable_area', 'storage_aerosol',
    'storage_oxidizer_separate', 'storage_self_reactive', 'storage_self_heating_cool',
    'storage_pyrophoric_air', 'storage_water_reactive_water', 'storage_inert_moisture',
    'storage_corrosive_metals', 'storage_locked', 'storage_incompatible',
];
$incompatKeys = [
    'water', 'air', 'oxidizers', 'combustibles', 'reducing_agents', 'organics',
    'metal_powders', 'acids', 'bases', 'halogens', 'amines', 'metal_salts', 'metals',
];
$files = [];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $files[$lang] = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($s7Keys as $k) {
        $v = $files[$lang]['section7'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section7.{$k}", $v);
    }
    foreach ($incompatKeys as $k) {
        $v = $files[$lang]['section10']['incompat_' . $k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section10.incompat_{$k}", $v);
    }
    check(str_contains($files[$lang]['section7']['storage_incompatible'] ?? '', ':list'), "{$lang} storage_incompatible has :list");
}
foreach (['es', 'fr', 'de'] as $lang) {
    foreach (['handling_base', 'storage_base'] as $k) {
        $enCount = substr_count($files['en']['section7'][$k], '.');
        $count   = substr_count($files[$lang]['section7'][$k], '.');
        check($count === $enCount, "{$lang} section7.{$k} sentence count === en ({$enCount})", $count);
    }
}

// ---------------------------------------------------------------------
echo "n. Localised composition (de / es)\n";
$tDe   = new \SDS\Services\TranslationService('de');
$genDe = new \SDS\Services\SDSGenerator($tDe);
$s7De  = new ReflectionMethod($genDe, 'section7');
$s7De->setAccessible(true);
$s10De = new ReflectionMethod($genDe, 'section10');
$s10De->setAccessible(true);
$inc = $s10De->invoke($genDe, $hzH, [])['incompatible'];
check($inc === 'Wasser und Feuchtigkeit, starke Oxidationsmittel, starke Säuren, starke Basen.', 'DE S10 H260 list', $inc);
$storage = $s7De->invoke($genDe, $hzH, [])['storage'];
check(str_ends_with($storage, ': Wasser und Feuchtigkeit, starke Oxidationsmittel, starke Säuren, starke Basen.'), 'DE S7 storage ends with list', $storage);
$inc = $s10De->invoke($genDe, $hz([]), [])['incompatible'];
check($inc === $tDe->get('section10.incompatible'), 'DE default byte-identical to legacy', $inc);

$tEs   = new \SDS\Services\TranslationService('es');
$genEs = new \SDS\Services\SDSGenerator($tEs);
$s10Es = new ReflectionMethod($genEs, 'section10');
$s10Es->setAccessible(true);
$inc = $s10Es->invoke($genEs, $hz([]), [])['incompatible'];
check($inc === 'Agentes oxidantes fuertes, ácidos fuertes, bases fuertes.', 'ES S10 default', $inc);
check($inc === $tEs->get('section10.incompatible'), 'ES default byte-identical to legacy', $inc);

$tFr   = new \SDS\Services\TranslationService('fr');
$genFr = new \SDS\Services\SDSGenerator($tFr);
$s10Fr = new ReflectionMethod($genFr, 'section10');
$s10Fr->setAccessible(true);
$inc = $s10Fr->invoke($genFr, $hz([]), [])['incompatible'];
check($inc === $tFr->get('section10.incompatible'), 'FR default byte-identical to legacy', $inc);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
