#!/usr/bin/env php
<?php
/**
 * SDSGenerator::section6() unit test (audit item #12, DB-free)
 *
 * Exercises the Section 6 accidental-release logic directly through Reflection:
 *   - personal precautions: acute tox. 1-3 / H314 replace the base text;
 *     acute tox + H314 appends the corrosive fragment (#32); the flammable
 *     fragment (H220-H226, H228) is APPENDED; H227/H250/H260/H261 add the ignition sentence instead;
 *   - environmental: drains sentence + aquatic sentence(s) + notify: acute
 *     (H400>H401>H402) and chronic (H410>H411>H412>H413) sentences chosen
 *     independently; H412/H413 have their own sentences (#24);
 *   - containment keyed on the finished good's physical_state with liquid
 *     as the fallback for blank / custom states; Gas -> containment_gas;
 *   - a per-FG override replaces the whole field;
 *   - every new translation key exists in all four language files.
 *
 * Run:
 *   php tests/Services/SDSGeneratorSection6Test.php
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
$m   = new ReflectionMethod($gen, 'section6');
$m->setAccessible(true);

$tr = fn(string $key): string => $t->get('section6.' . $key);
$hz = fn(array $codes): array => [
    'h_statements' => array_map(
        fn($c) => ['code' => $c, 'text' => \SDS\Services\GHSStatements::hText($c)],
        $codes
    ),
];
$liquid = ['physical_state' => 'Liquid'];

// ---------------------------------------------------------------------
echo "a. No hazards, Liquid -> base paragraphs\n";
$s = $m->invoke($gen, $hz([]), $liquid, []);
check(array_keys($s) === ['title', 'personal_precautions', 'environmental', 'containment'], 'key order', array_keys($s));
check($s['personal_precautions'] === $tr('personal_precautions'), 'personal_precautions base', $s['personal_precautions']);
check($s['environmental'] === $tr('environmental'), 'environmental base', $s['environmental']);
check($s['containment'] === $tr('containment_liquid'), 'containment liquid', $s['containment']);

// ---------------------------------------------------------------------
echo "b. Flammable solvent ink H225 + H319 + H336 + H411, Liquid\n";
$s = $m->invoke($gen, $hz(['H225', 'H319', 'H336', 'H411']), $liquid, []);
check(
    $s['personal_precautions'] === $tr('personal_precautions') . ' ' . $tr('precautions_flammable'),
    'base + flammable',
    $s['personal_precautions']
);
check(
    $s['environmental'] === $tr('environmental') . ' ' . $tr('environmental_aquatic_chronic') . ' ' . $tr('environmental_notify'),
    'environmental + chronic + notify',
    $s['environmental']
);
check($s['containment'] === $tr('containment_liquid'), 'containment liquid', $s['containment']);

// ---------------------------------------------------------------------
echo "c. Flammable set: H226 / H228 append flammable, H227 appends ignition (#64)\n";
foreach (['H226', 'H228'] as $code) {
    $s = $m->invoke($gen, $hz([$code]), $liquid, []);
    check(
        $s['personal_precautions'] === $tr('personal_precautions') . ' ' . $tr('precautions_flammable'),
        "{$code} appends flammable",
        $s['personal_precautions']
    );
}
$s = $m->invoke($gen, $hz(['H227']), $liquid, []);
check($s['personal_precautions'] === $tr('personal_precautions') . ' ' . $tr('precautions_ignition'), 'H227 alone -> base + ignition', $s['personal_precautions']);

// ---------------------------------------------------------------------
echo "d. Acute tox / corrosive precedence\n";
foreach (['H301', 'H311', 'H331'] as $code) {
    $s = $m->invoke($gen, $hz([$code]), $liquid, []);
    check($s['personal_precautions'] === $tr('precautions_acute_toxic'), "{$code} -> acute_toxic (cat 3)", $s['personal_precautions']);
}
$s = $m->invoke($gen, $hz(['H300+H310+H330']), $liquid, []);
check($s['personal_precautions'] === $tr('precautions_acute_toxic'), 'combined H300+H310+H330 -> acute_toxic', $s['personal_precautions']);
$s = $m->invoke($gen, $hz(['H314', 'H225']), $liquid, []);
check(
    $s['personal_precautions'] === $tr('precautions_corrosive') . ' ' . $tr('precautions_flammable'),
    'H314 + H225 -> corrosive + flammable',
    $s['personal_precautions']
);
$s = $m->invoke($gen, $hz(['H301', 'H314']), $liquid, []);
check($s['personal_precautions'] === $tr('precautions_acute_toxic') . ' ' . $tr('precautions_corrosive_addon'), 'H301 + H314 -> acute_toxic + corrosive fragment (#32)', $s['personal_precautions']);
$s = $m->invoke($gen, $hz(['H331', 'H314', 'H225']), $liquid, []);
check($s['personal_precautions'] === $tr('precautions_acute_toxic') . ' ' . $tr('precautions_corrosive_addon') . ' ' . $tr('precautions_flammable'), 'H331 + H314 + H225 -> acute + corrosive + flammable', $s['personal_precautions']);

// ---------------------------------------------------------------------
echo "e. Aquatic tiers\n";
$env = fn(array $codes): string => $m->invoke($gen, $hz($codes), $liquid, [])['environmental'];
$base = $tr('environmental');
$notify = $tr('environmental_notify');

check($env(['H400']) === $base . ' ' . $tr('environmental_aquatic_acute') . ' ' . $notify, 'H400 -> acute', $env(['H400']));
check(
    $env(['H400', 'H410']) === $base . ' ' . $tr('environmental_aquatic_acute') . ' ' . $tr('environmental_aquatic_chronic_very') . ' ' . $notify,
    'H400 + H410 -> acute + chronic_very',
    $env(['H400', 'H410'])
);
check($env(['H410']) === $base . ' ' . $tr('environmental_aquatic_chronic_very') . ' ' . $notify, 'H410 alone -> chronic_very only', $env(['H410']));
check(str_contains($env(['H410']), 'Very toxic to aquatic life with long lasting effects'), 'H410 says Very toxic (matches the Section 2 H410 statement)', $env(['H410']));
check($env(['H411']) === $base . ' ' . $tr('environmental_aquatic_chronic') . ' ' . $notify && !str_contains($env(['H411']), 'Very toxic'), 'H411 alone -> chronic (not very)', $env(['H411']));
check($env(['H410', 'H411']) === $base . ' ' . $tr('environmental_aquatic_chronic_very') . ' ' . $notify, 'H410 + H411 -> chronic_very only', $env(['H410', 'H411']));
check($env(['H401']) === $base . ' ' . $tr('environmental_aquatic_acute_toxic') . ' ' . $notify, 'H401 -> acute_toxic', $env(['H401']));
check($env(['H402']) === $base . ' ' . $tr('environmental_aquatic_harmful') . ' ' . $notify, 'H402 -> harmful', $env(['H402']));
check($env(['H412']) === $base . ' ' . $tr('environmental_aquatic_chronic_harmful') . ' ' . $notify, 'H412 -> chronic harmful (with long lasting effects)', $env(['H412']));
check($env(['H413']) === $base . ' ' . $tr('environmental_aquatic_chronic_may_harm') . ' ' . $notify && !str_contains($env(['H413']), 'Harmful to aquatic life'), 'H413 -> may cause long lasting harmful effects, never "Harmful to aquatic life"', $env(['H413']));
$mixed1 = $env(['H400', 'H412']);
check($mixed1 === $base . ' ' . $tr('environmental_aquatic_acute') . ' ' . $tr('environmental_aquatic_chronic_harmful') . ' ' . $notify, 'H400 + H412 -> acute + chronic harmful (routes independent)', $mixed1);
$mixed2 = $env(['H411', 'H402']);
check($mixed2 === $base . ' ' . $tr('environmental_aquatic_harmful') . ' ' . $tr('environmental_aquatic_chronic') . ' ' . $notify, 'H411 + H402 -> acute harmful + chronic toxic', $mixed2);
check($env(['H411', 'H413']) === $base . ' ' . $tr('environmental_aquatic_chronic') . ' ' . $notify, 'H411 + H413 -> chronic toxic only (one sentence per route)', $env(['H411', 'H413']));
$h412 = $env(['H412']);
check(!str_contains($h412, 'Toxic') && !str_contains($h412, 'toxic to aquatic'), 'H412 never says toxic', $h412);

// ---------------------------------------------------------------------
echo "f. Containment keyed on physical_state\n";
$cont = fn(array $fg): string => $m->invoke($gen, $hz([]), $fg, [])['containment'];
$cases = [
    ['Solid',   'containment_solid'],
    ['Powder',  'containment_solid'],
    ['POWDER ', 'containment_solid'],
    ['Gel',     'containment_paste'],
    ['Paste',   'containment_paste'],
    ['Gas',     'containment_gas'],
    ['',        'containment_liquid'],
    [null,      'containment_liquid'],
    ['Custom',  'containment_liquid'],
];
foreach ($cases as [$state, $key]) {
    $actual = $cont(['physical_state' => $state]);
    check($actual === $tr($key), 'state ' . var_export($state, true) . " -> {$key}", $actual);
}
$actual = $cont([]);
check($actual === $tr('containment_liquid'), 'no physical_state key -> containment_liquid', $actual);

// ---------------------------------------------------------------------
echo "g. Overrides win whole-field\n";
$s = $m->invoke($gen, $hz(['H225', 'H411']), $liquid, [6 => ['personal_precautions' => 'Custom PP', 'environmental' => 'Custom env']]);
check($s['personal_precautions'] === 'Custom PP', 'personal_precautions override', $s['personal_precautions']);
check($s['environmental'] === 'Custom env', 'environmental override', $s['environmental']);
check($s['containment'] === $tr('containment_liquid'), 'containment still derived', $s['containment']);

// ---------------------------------------------------------------------
echo "h. Translation completeness (en/es/fr/de)\n";
$keys = [
    'precautions_flammable',
    'environmental_aquatic_acute',
    'environmental_aquatic_acute_toxic',
    'environmental_aquatic_chronic_very',
    'environmental_aquatic_chronic',
    'environmental_aquatic_harmful',
    'environmental_aquatic_chronic_harmful',
    'environmental_aquatic_chronic_may_harm',
    'precautions_corrosive_addon',
    'environmental_notify',
    'containment_paste',
    'precautions_ignition',
    'containment_gas',
];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($keys as $k) {
        $v = $trFile['section6'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section6.{$k}", $v);
    }
    foreach (['personal_precautions', 'environmental_precautions', 'containment_cleanup'] as $label) {
        $v = $trFile['labels'][$label] ?? null;
        check(is_string($v) && $v !== '', "{$lang} labels.{$label}", $v);
    }
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
