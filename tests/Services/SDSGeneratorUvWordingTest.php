#!/usr/bin/env php
<?php
/**
 * A9 UV wording (DB-free).
 *
 *   - Section 2 Other Hazards: UV/LED families (resolved family flag, pack
 *     switch ignored) print the uncured-polymerization sentence instead of
 *     "None known."; a per-product override still wins.
 *   - Section 10 Reactivity: the hazardous-polymerization sentence follows
 *     any reactive-class fragments; alone it replaces the default.
 *   - Section 10 Stability: the inhibitor / oxygen sentence only when
 *     UVAcrylateRulePack::detectAcrylates() finds acrylates (>= 0.1 %).
 *   - Section 10 Incompatible materials: "peroxides and free-radical
 *     initiators" last; Section 7 storage repeats the same list.
 *   - Overrides replace whole fields; no temperature figure is named.
 *   - es/fr/de translated; new keys cp1252-safe.
 *
 * Run: php tests/Services/SDSGeneratorUvWordingTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

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
    if ($ok) { echo "  ok   {$label}\n"; return; }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) { echo '       actual: ' . var_export($actual, true) . "\n"; }
}

function m(object $o, string $n): ReflectionMethod
{
    $r = new ReflectionMethod($o, $n);
    $r->setAccessible(true);
    return $r;
}

/** Engine output shape (HazardEngine::classify()). */
function hz(array $h): array
{
    return [
        'signal_word'     => $h === [] ? null : 'Warning',
        'pictograms'      => [],
        'hazard_classes'  => [],
        'h_statements'    => array_map(fn($c) => ['code' => $c, 'text' => ''], $h),
        'p_statements'    => [],
        'exposure_limits' => [],
    ];
}

$acrylateComp = [
    ['cas_number' => '13048-33-4', 'chemical_name' => '1,6-Hexanediol diacrylate', 'concentration_pct' => 15.0],
    ['cas_number' => '000-00-1', 'chemical_name' => 'Urethane acrylate oligomer', 'concentration_pct' => 35.0],
];
$cationicComp = [
    ['cas_number' => '2386-87-0', 'chemical_name' => '3,4-Epoxycyclohexylmethyl 3,4-epoxycyclohexanecarboxylate', 'concentration_pct' => 60.0],
];
$traceComp = [
    ['cas_number' => '13048-33-4', 'chemical_name' => '1,6-Hexanediol diacrylate', 'concentration_pct' => 0.05],
];

$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$uv  = m($gen, 'setUvState');
$s2  = m($gen, 'section2');
$s7  = m($gen, 'section7');
$s10 = m($gen, 'section10');

$uvSentenceS2  = 'Uncured product may polymerize when exposed to UV light, elevated temperatures or contaminants.';
$uvReactivity  = 'Hazardous polymerization may occur when uncured product is exposed to UV light, elevated temperatures or contamination with peroxides or free-radical initiators.';
$inhibitor     = $t->get('section10.stability_uv_inhibitor');

echo "1. Section 2 Other Hazards\n";
$uv->invoke($gen, false, false, []);
$v = $s2->invoke($gen, hz([]), [])['other_hazards'];
check($v === 'None known.', 'not UV -> "None known."', $v);
$uv->invoke($gen, true, true, []);
$v = $s2->invoke($gen, hz([]), [])['other_hazards'];
check($v === $uvSentenceS2, 'UV family + pack on -> UV sentence (exact)', $v);
$uv->invoke($gen, false, true, []);
$v = $s2->invoke($gen, hz([]), [])['other_hazards'];
check($v === $t->get('section2.other_hazards_uv'), 'UV family, pack off -> UV sentence', $v);
$v = $s2->invoke($gen, hz([]), [2 => ['other_hazards' => 'Custom']])['other_hazards'];
check($v === 'Custom', 'override wins on a UV family', $v);
$uv->invoke($gen, false, false, []);

echo "2. Section 10\n";
$s = $s10->invoke($gen, hz(['H317']), [], false, $acrylateComp);
check($s['reactivity'] === $t->get('section10.reactivity'), 'not UV -> default reactivity', $s['reactivity']);
check($s['stability'] === $t->get('section10.stability'), 'not UV -> default stability (acrylates ignored)', $s['stability']);
check($s['incompatible'] === 'Strong oxidizing agents, strong acids, strong bases.', 'not UV -> default incompatibles', $s['incompatible']);

$s = $s10->invoke($gen, hz(['H315', 'H317', 'H319']), [], true, $acrylateComp);
check($s['reactivity'] === $uvReactivity, 'UV -> polymerization sentence replaces default (exact)', $s['reactivity']);
check($s['stability'] === 'Stable under recommended storage conditions. ' . $inhibitor, 'UV + acrylates -> stable + inhibitor', $s['stability']);
check($s['incompatible'] === 'Strong oxidizing agents, strong acids, strong bases, peroxides and free-radical initiators.', 'UV -> + peroxides / initiators last', $s['incompatible']);
check(str_contains($s['conditions_avoid'], $t->get('section10.cond_uv')), 'UV -> cond_uv still in conditions to avoid', $s['conditions_avoid']);
check(preg_match('/\d\s*°/u', $s['reactivity'] . $s['stability']) === 0, 'no temperature figure named');

$s = $s10->invoke($gen, hz(['H260']), [], true, $acrylateComp);
check($s['reactivity'] === $t->get('section10.reactivity_water_reactive') . ' ' . $t->get('section10.reactivity_uv_polymerization'),
    'H260 + UV -> class fragment then UV sentence', $s['reactivity']);
check(str_starts_with($s['stability'], 'Unstable under the following conditions: '), 'H260 + UV -> unstable branch', $s['stability']);
check(str_ends_with($s['stability'], ' ' . $inhibitor), 'H260 + UV + acrylates -> inhibitor appended', $s['stability']);

$s = $s10->invoke($gen, hz(['H315']), [], true, $cationicComp);
check($s['stability'] === 'Stable under recommended storage conditions.', 'UV cationic (no acrylate) -> no inhibitor', $s['stability']);
check($s['reactivity'] === $uvReactivity, 'UV cationic -> UV reactivity', $s['reactivity']);

$s = $s10->invoke($gen, hz(['H315']), [], true, $traceComp);
check(!str_contains($s['stability'], $inhibitor), 'UV + acrylate at 0.05 % -> no inhibitor', $s['stability']);

$s = $s10->invoke($gen, hz(['H315']), [], true, []);
check(!str_contains($s['stability'], $inhibitor), 'UV + empty composition -> no inhibitor', $s['stability']);

$s = $s10->invoke($gen, hz(['H226']), [], true, $acrylateComp);
check($s['incompatible'] === 'Strong oxidizing agents, strong acids, strong bases, halogens, peroxides and free-radical initiators.',
    'UV + H226 -> halogens then peroxides / initiators', $s['incompatible']);
check($s['reactivity'] === $uvReactivity, 'UV + H226 -> UV reactivity only (flammability is not reactive)', $s['reactivity']);

$s = $s10->invoke($gen, hz(['H315']), [10 => ['reactivity' => 'R', 'stability' => 'S', 'incompatible' => 'I.']], true, $acrylateComp);
check($s['reactivity'] === 'R', 'override reactivity verbatim', $s['reactivity']);
check($s['stability'] === 'S', 'override stability verbatim (no inhibitor)', $s['stability']);
check($s['incompatible'] === 'I.', 'override incompatible verbatim', $s['incompatible']);

echo "3. Section 7 storage repeats the incompatibles\n";
$uv->invoke($gen, false, true, []);
$storage = $s7->invoke($gen, hz([]), [])['storage'];
check(str_ends_with($storage, '(see Section 10): strong oxidizing agents, strong acids, strong bases, peroxides and free-radical initiators.'),
    'UV family (pack off) -> storage ends with the UV incompatibles', $storage);
$tail = substr($storage, strrpos($storage, '): ') + 3);
$s10inc = $s10->invoke($gen, hz([]), [], true, [])['incompatible'];
check($tail === lcfirst(rtrim($s10inc, '.')) . '.', 'S7 tail == S10 incompatibles', [$tail, $s10inc]);
$uv->invoke($gen, false, false, []);
$storage = $s7->invoke($gen, hz([]), [])['storage'];
check(!str_contains($storage, 'peroxides'), 'not UV -> no peroxides in storage', $storage);

echo "4. es / fr / de\n";
$enOther = $uvSentenceS2;
$ends = [
    'es' => 'peróxidos e iniciadores de radicales libres.',
    'fr' => 'peroxydes et amorceurs radicalaires.',
    'de' => 'Peroxide und Radikalstarter.',
];
foreach ($ends as $lang => $end) {
    $tl  = new TranslationService($lang);
    $gl  = new SDSGenerator($tl);
    m($gl, 'setUvState')->invoke($gl, true, true, []);
    $o = m($gl, 'section2')->invoke($gl, hz([]), [])['other_hazards'];
    check($o === $tl->get('section2.other_hazards_uv') && $o !== $enOther && $o !== 'section2.other_hazards_uv',
        "{$lang} other hazards translated", $o);
    $sl = m($gl, 'section10')->invoke($gl, hz([]), [], true, $acrylateComp);
    check(str_ends_with($sl['incompatible'], $end), "{$lang} incompatibles end with peroxides / initiators", $sl['incompatible']);
    check(str_ends_with($sl['stability'], ' ' . $tl->get('section10.stability_uv_inhibitor')), "{$lang} inhibitor sentence", $sl['stability']);
    check($sl['reactivity'] === $tl->get('section10.reactivity_uv_polymerization'), "{$lang} UV reactivity", $sl['reactivity']);
    if ($lang === 'de') {
        $st = m($gl, 'section7')->invoke($gl, hz([]), [])['storage'];
        check(str_ends_with($st, ': starke Oxidationsmittel, starke Säuren, starke Basen, Peroxide und Radikalstarter.'),
            'de storage ends with the UV incompatibles', $st);
    }
}

echo "5. New keys present, non-empty and cp1252-safe in all four files\n";
$keys = [
    ['section2', 'other_hazards_uv'],
    ['section10', 'reactivity_uv_polymerization'],
    ['section10', 'stability_uv_inhibitor'],
    ['section10', 'incompat_peroxides_initiators'],
];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tr = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($keys as [$sec, $key]) {
        $v = $tr[$sec][$key] ?? null;
        $ok = is_string($v) && $v !== ''
            && mb_convert_encoding(mb_convert_encoding($v, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252') === $v;
        check($ok, "{$lang} {$sec}.{$key}", $v);
    }
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
