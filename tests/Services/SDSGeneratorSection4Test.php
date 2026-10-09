#!/usr/bin/env php
<?php
/**
 * SDSGenerator::section4() unit test (audit item #10, DB-free)
 *
 * Exercises the Section 4 first-aid logic directly through Reflection:
 *   - severe fragments REPLACE the base paragraph (H330/H331, H314, H318,
 *     H304/H305, H300/H301, H310/H311);
 *   - additive fragments are APPENDED to whichever paragraph was chosen
 *     (H332/H334/H335/H336, H312/H315/H317, contact-lens sentence, H302);
 *   - the 4(b) symptoms line is built from H3xx statements only, split
 *     into acute / delayed;
 *   - the 4(c) notes fragments (H304/H305, H314/H318, H330/H331) are placed BEFORE the default sentence (audit #9);
 *   - a per-FG override replaces the whole field;
 *   - every new translation key exists in all four language files.
 *
 * Run:
 *   php tests/Services/SDSGeneratorSection4Test.php
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
$m   = new ReflectionMethod($gen, 'section4');
$m->setAccessible(true);

$tr = fn(string $key): string => $t->get('section4.' . $key);
$hz = fn(array $codes): array => [
    'h_statements' => array_map(
        fn($c) => ['code' => $c, 'text' => \SDS\Services\GHSStatements::hText($c)],
        $codes
    ),
];

// ---------------------------------------------------------------------
echo "a. No hazards -> base paragraphs, symptoms_none, base notes\n";
$s = $m->invoke($gen, $hz([]), []);
check(array_keys($s) === ['title', 'inhalation', 'skin', 'eyes', 'ingestion', 'symptoms', 'notes'], 'key order', array_keys($s));
check($s['inhalation'] === $tr('inhalation'), 'inhalation base', $s['inhalation']);
check($s['skin'] === $tr('skin'), 'skin base', $s['skin']);
check($s['eyes'] === $tr('eyes'), 'eyes base', $s['eyes']);
check($s['ingestion'] === $tr('ingestion'), 'ingestion base', $s['ingestion']);
check($s['symptoms'] === $tr('symptoms_none'), 'symptoms none', $s['symptoms']);
check($s['notes'] === $tr('notes'), 'notes base', $s['notes']);

// ---------------------------------------------------------------------
echo "b. H315 + H319 + H411 (smoke fixture)\n";
$s = $m->invoke($gen, $hz(['H315', 'H319', 'H411']), []);
check($s['skin'] === $tr('skin') . ' ' . $tr('skin_irritant'), 'skin base + irritant', $s['skin']);
check($s['eyes'] === $tr('eyes') . ' ' . $tr('eyes_contact_lenses'), 'eyes base + contact lenses', $s['eyes']);
check($s['inhalation'] === $tr('inhalation'), 'inhalation unchanged', $s['inhalation']);
check($s['ingestion'] === $tr('ingestion'), 'ingestion unchanged', $s['ingestion']);
check($s['symptoms'] === 'Acute: Causes skin irritation. Causes serious eye irritation.', 'symptoms (H411 excluded)', $s['symptoms']);
check($s['notes'] === $tr('notes'), 'notes unchanged', $s['notes']);

// ---------------------------------------------------------------------
echo "c. H302 + H317 + H332 + H335 + H336 (additive fragments)\n";
$s = $m->invoke($gen, $hz(['H302', 'H317', 'H332', 'H335', 'H336']), []);
check(
    $s['inhalation'] === $tr('inhalation') . ' ' . $tr('inhalation_harmful') . ' ' . $tr('inhalation_irritant') . ' ' . $tr('inhalation_narcotic'),
    'inhalation base + harmful + irritant + narcotic',
    $s['inhalation']
);
check($s['skin'] === $tr('skin') . ' ' . $tr('skin_sensitizer'), 'skin base + sensitizer', $s['skin']);
check($s['ingestion'] === $tr('ingestion') . ' ' . $tr('ingestion_harmful'), 'ingestion base + harmful', $s['ingestion']);
check(strncmp($s['symptoms'], 'Acute: ', 7) === 0, 'symptoms starts with Acute:', $s['symptoms']);
check(str_contains($s['symptoms'], 'Harmful if swallowed.'), 'symptoms contains H302 text', $s['symptoms']);
check(str_contains($s['symptoms'], 'May cause drowsiness or dizziness.'), 'symptoms contains H336 text', $s['symptoms']);
// Sensitisation (H317/H334) is a delayed, repeated-exposure effect (GHS Rev. 7 ch. 3.4),
// so it lands in the Delayed block, not under Acute.
check(str_contains($s['symptoms'], 'Delayed: May cause an allergic skin reaction.'), 'H317 in delayed block', $s['symptoms']);
check(!str_contains(explode('Delayed:', $s['symptoms'])[0], 'allergic skin reaction'), 'H317 not in acute block', $s['symptoms']);
$s334 = $m->invoke($gen, $hz(['H334']), []);
check($s334['symptoms'] === 'Delayed: May cause allergy or asthma symptoms or breathing difficulties if inhaled.', 'H334 alone -> delayed only', $s334['symptoms']);

// ---------------------------------------------------------------------
echo "d. H304 + H314 + H331 + H372 (severe replace, no additive, notes fragments, delayed)\n";
$s = $m->invoke($gen, $hz(['H304', 'H314', 'H331', 'H372']), []);
check($s['inhalation'] === $tr('inhalation_toxic'), 'inhalation toxic exactly', $s['inhalation']);
check($s['skin'] === $tr('skin_corrosive'), 'skin corrosive exactly', $s['skin']);
check($s['eyes'] === $tr('eyes_corrosive') . ' ' . $tr('eyes_contact_lenses'), 'eyes corrosive + contact lenses', $s['eyes']);
check($s['ingestion'] === $tr('ingestion_aspiration'), 'ingestion aspiration exactly', $s['ingestion']);
check(
    $s['notes'] === $tr('notes_aspiration') . ' ' . $tr('notes_corrosive') . ' ' . $tr('notes_inhalation_delayed') . ' ' . $tr('notes'),
    'notes aspiration + corrosive + inhalation_delayed, then base (#9: fragments lead)',
    $s['notes']
);
check(
    $s['symptoms'] === 'Acute: May be fatal if swallowed and enters airways. Causes severe skin burns and eye damage. Toxic if inhaled. Delayed: Causes damage to organs through prolonged or repeated exposure.',
    'symptoms acute + delayed',
    $s['symptoms']
);

// ---------------------------------------------------------------------
echo "e. H311 skin_toxic; combined H300+H310+H330\n";
$s = $m->invoke($gen, $hz(['H311']), []);
check($s['skin'] === $tr('skin_toxic'), 'H311 -> skin_toxic', $s['skin']);
$s = $m->invoke($gen, $hz(['H300+H310+H330']), []);
check($s['inhalation'] === $tr('inhalation_fatal'), 'combined -> inhalation_fatal', $s['inhalation']);
check($s['skin'] === $tr('skin_toxic'), 'combined -> skin_toxic', $s['skin']);
check($s['ingestion'] === $tr('ingestion_toxic'), 'combined -> ingestion_toxic', $s['ingestion']);
check($s['symptoms'] === 'Acute: Fatal if swallowed, in contact with skin or if inhaled.', 'combined symptoms', $s['symptoms']);
check($s['notes'] === $tr('notes_inhalation_delayed') . ' ' . $tr('notes'), 'combined notes: inhalation_delayed, then base', $s['notes']);

// ---------------------------------------------------------------------
echo "f. Overrides win whole-field\n";
$s = $m->invoke($gen, $hz(['H314']), [4 => ['skin' => 'Custom skin', 'symptoms' => 'Custom symptoms']]);
check($s['skin'] === 'Custom skin', 'skin override', $s['skin']);
check($s['symptoms'] === 'Custom symptoms', 'symptoms override', $s['symptoms']);
check($s['eyes'] === $tr('eyes_corrosive') . ' ' . $tr('eyes_contact_lenses'), 'eyes still derived', $s['eyes']);

// ---------------------------------------------------------------------
echo "h. Notes to physician (audit #9): fragments lead, default closes, exact EN wording\n";
// Exact decision wording (US spelling per the sheet's house style).
check($tr('notes_aspiration') === 'Do not induce vomiting; risk of aspiration into the lungs.', 'EN notes_aspiration wording', $tr('notes_aspiration'));
check($tr('notes_corrosive') === 'Do not attempt to neutralize; treat corrosive burns as thermal burns.', 'EN notes_corrosive wording', $tr('notes_corrosive'));
check($tr('notes_inhalation_delayed') === 'Keep under medical observation for at least 48 hours; delayed pulmonary edema possible.', 'EN notes_inhalation_delayed wording', $tr('notes_inhalation_delayed'));
check($tr('notes') === 'Treat symptomatically. Show this SDS to medical personnel.', 'EN default notes unchanged', $tr('notes'));

// Flammable solvent ink: H225 + H304 + H319 + H336 + H412 -> aspiration fragment only.
$s = $m->invoke($gen, $hz(['H225', 'H304', 'H319', 'H336', 'H412']), []);
check($s['notes'] === $tr('notes_aspiration') . ' ' . $tr('notes'), 'solvent ink: aspiration then base', $s['notes']);
check($s['notes'] === 'Do not induce vomiting; risk of aspiration into the lungs. Treat symptomatically. Show this SDS to medical personnel.', 'solvent ink: literal EN line', $s['notes']);

// Water-based unclassified ink: no H-codes -> default only (covered in a., re-asserted literally here).
$s = $m->invoke($gen, $hz([]), []);
check($s['notes'] === 'Treat symptomatically. Show this SDS to medical personnel.', 'unclassified ink: default only', $s['notes']);

// UV ink with a skin sensitiser: H315 + H317 + H319 + H411 -> no 4(c) fragment (H317 advice lives in the skin paragraph).
$s = $m->invoke($gen, $hz(['H315', 'H317', 'H319', 'H411']), []);
check($s['notes'] === $tr('notes'), 'UV sensitiser ink: default only', $s['notes']);
check(str_contains($s['skin'], $tr('skin_sensitizer')), 'UV sensitiser ink: H317 advice still in skin paragraph', $s['skin']);

// H318 alone triggers the corrosive-burns fragment; H319 alone does not.
$s = $m->invoke($gen, $hz(['H318']), []);
check($s['notes'] === $tr('notes_corrosive') . ' ' . $tr('notes'), 'H318 -> corrosive then base', $s['notes']);
$s = $m->invoke($gen, $hz(['H319']), []);
check($s['notes'] === $tr('notes'), 'H319 -> default only', $s['notes']);

// H314 + H318 together -> corrosive fragment printed once.
$s = $m->invoke($gen, $hz(['H314', 'H318']), []);
check($s['notes'] === $tr('notes_corrosive') . ' ' . $tr('notes'), 'H314+H318 -> single corrosive fragment', $s['notes']);
check(substr_count($s['notes'], $tr('notes_corrosive')) === 1, 'corrosive fragment not duplicated', $s['notes']);

// H330 alone and H331 alone -> delayed-effects fragment then base.
foreach (['H330', 'H331'] as $c) {
    $s = $m->invoke($gen, $hz([$c]), []);
    check($s['notes'] === $tr('notes_inhalation_delayed') . ' ' . $tr('notes'), "{$c} -> inhalation_delayed then base", $s['notes']);
}

// The default sentence always closes the field when no override is set.
foreach ([[], ['H304'], ['H314'], ['H318'], ['H330'], ['H304', 'H314', 'H331']] as $codes) {
    $s = $m->invoke($gen, $hz($codes), []);
    check(str_ends_with($s['notes'], $tr('notes')), 'default closes: ' . implode('+', $codes), $s['notes']);
}

// Per-FG override replaces the whole field, fragments included.
$s = $m->invoke($gen, $hz(['H304', 'H314']), [4 => ['notes' => 'Custom notes']]);
check($s['notes'] === 'Custom notes', 'notes override wins over fragments', $s['notes']);

// Each language: the fragments are non-empty, differ from the default and do not embed it.
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    $def = $trFile['section4']['notes'] ?? '';
    foreach (['notes_aspiration', 'notes_corrosive', 'notes_inhalation_delayed'] as $k) {
        $v = $trFile['section4'][$k] ?? '';
        check(is_string($v) && $v !== '' && $v !== $def && !str_contains($v, $def), "{$lang} section4.{$k} is a standalone fragment", $v);
    }
}

// ---------------------------------------------------------------------
echo "g. Translation completeness (en/es/fr/de)\n";
$keys = [
    'inhalation_harmful', 'inhalation_resp_sensitizer', 'inhalation_irritant', 'inhalation_narcotic',
    'skin_toxic', 'skin_harmful', 'skin_irritant', 'skin_sensitizer', 'eyes_contact_lenses',
    'ingestion_harmful', 'symptoms_none', 'symptoms_acute_prefix', 'symptoms_delayed_prefix',
    'notes_aspiration', 'notes_corrosive', 'notes_inhalation_delayed',
];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($keys as $k) {
        $v = $trFile['section4'][$k] ?? null;
        check(is_string($v) && $v !== '', "{$lang} section4.{$k}", $v);
    }
    $v = $trFile['labels']['symptoms_effects'] ?? null;
    check(is_string($v) && $v !== '', "{$lang} labels.symptoms_effects", $v);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
