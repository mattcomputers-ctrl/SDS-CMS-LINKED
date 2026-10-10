#!/usr/bin/env php
<?php
/**
 * UV acrylate rule pack inside the section builders (DB-free).
 *
 *   #29  UV PPE sentence only on hazard-driven PPE tiers, or on every field
 *        when the mixture carries H317; Sections 2 and 8 print the same text;
 *        an override replaces the whole field.
 *   #50  generic UV text gated on the pack/family state (setUvState), never
 *        on acrylate detection; names only for acrylates >= 0.1 %, acrylic
 *        resins / copolymers / polyacrylates not matched; trade-secret
 *        acrylates never named.
 *   #65  Sections 4-7 carry their UV fragments inside the fields (state-aware
 *        wording; no repeated SCBA / drains, no "absorb" on solids); Section 7
 *        storage and Section 10 share the family-flag condition; Section 11
 *        uv_acrylate_note is editable.
 *
 * Run: php tests/Services/SDSGeneratorUvPackTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\GHSHazardClass;
use SDS\Services\HazardEngine;
use SDS\Services\SDSGenerator;
use SDS\Services\TextOverrideService;
use SDS\Services\TranslationService;
use SDS\Services\UVAcrylateRulePack;

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
function hz(array $h, array $p = [], array $classes = []): array
{
    return [
        'signal_word'     => $h === [] ? null : 'Warning',
        'pictograms'      => $h === [] ? [] : ['GHS07'],
        'hazard_classes'  => $classes,
        'h_statements'    => array_map(fn($c) => ['code' => $c, 'text' => \SDS\Services\GHSStatements::hText($c)], $h),
        'p_statements'    => array_map(fn($c) => ['code' => $c, 'text' => \SDS\Services\GHSStatements::pText($c)], $p),
        'exposure_limits' => [],
    ];
}

$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$uv  = m($gen, 'setUvState');
$s2  = m($gen, 'section2');
$s4  = m($gen, 'section4');
$s5  = m($gen, 'section5');
$s6  = m($gen, 'section6');
$s7  = m($gen, 'section7');
$s8  = m($gen, 'section8');
$s10 = m($gen, 'section10');
$sup = UVAcrylateRulePack::getPpeSupplement($t);
$liq = ['physical_state' => 'Liquid'];

$unclassified = hz([]);
$sensInk = hz(['H315', 'H317', 'H319'], ['P261', 'P264', 'P272', 'P280', 'P302+P352', 'P305+P351+P338', 'P333+P313', 'P362+P364'], [
    ['class' => 'Skin Corrosion/Irritation', 'category' => 'Category 2', 'canonical' => GHSHazardClass::SKIN_CORROSION_IRRITATION, 'category_canonical' => 'Cat 2', 'h_codes' => ['H315']],
    ['class' => 'Skin Sensitization', 'category' => 'Category 1', 'canonical' => GHSHazardClass::SKIN_SENSITIZATION, 'category_canonical' => 'Cat 1', 'h_codes' => ['H317']],
    ['class' => 'Serious Eye Damage/Eye Irritation', 'category' => 'Category 2A', 'canonical' => GHSHazardClass::EYE_DAMAGE_IRRITATION, 'category_canonical' => 'Cat 2A', 'h_codes' => ['H319']],
]);
$eyeOnly = hz(['H319'], ['P264', 'P280', 'P305+P351+P338', 'P337+P313'], [
    ['class' => 'Serious Eye Damage/Eye Irritation', 'category' => 'Category 2A', 'canonical' => GHSHazardClass::EYE_DAMAGE_IRRITATION, 'category_canonical' => 'Cat 2A', 'h_codes' => ['H319']],
]);

// ---------------------------------------------------------------------
echo "1. #29 UV PPE sentence only on hazard-driven tiers / H317; Sections 2 and 8 agree\n";
$uv->invoke($gen, true, true, []);
$p8 = $s8->invoke($gen, $unclassified, [], [], $liq, true);
foreach (HazardEngine::PPE_FIELDS as $f) {
    check($p8[$f] === $t->get("section8.ppe.{$f}.none"), "unclassified UV: {$f} plain none sentence", $p8[$f]);
}
check(!str_contains($p8['respiratory'], 'NIOSH'), 'unclassified UV: no respirator sentence');
$p8 = $s8->invoke($gen, $eyeOnly, [], [], $liq, true);
check(str_ends_with($p8['eye_protection'], ' ' . $sup['eye_protection']), 'H319: eye gets UV sentence', $p8['eye_protection']);
foreach (['respiratory', 'hand_protection', 'skin_protection'] as $f) {
    check(!str_contains($p8[$f], $sup[$f]), "H319: {$f} (baseline, no H317) no UV", $p8[$f]);
}
$p8 = $s8->invoke($gen, $sensInk, [], [], $liq, true);
foreach (HazardEngine::PPE_FIELDS as $f) {
    check(str_ends_with($p8[$f], ' ' . $sup[$f]), "H317 ink: {$f} gets UV", $p8[$f]);
}
$p2 = $s2->invoke($gen, $sensInk, [])['ppe_recommendations'];
check($p2['respiratory'] === null, 'S2 respiratory hidden (general tier)', $p2['respiratory']);
foreach (['hand_protection', 'eye_protection', 'skin_protection'] as $f) {
    check($p2[$f] === $p8[$f], "S2 {$f} === S8 {$f}", [$p2[$f], $p8[$f]]);
}
$ov = [8 => ['hand_protection' => 'Custom gloves']];
check(
    $s8->invoke($gen, $sensInk, [], $ov, $liq, true)['hand_protection'] === 'Custom gloves'
    && $s2->invoke($gen, $sensInk, $ov)['ppe_recommendations']['hand_protection'] === 'Custom gloves',
    'override replaces field in S2 and S8 (no UV append)'
);
$p2Eye = $s2->invoke($gen, $eyeOnly, [])['ppe_recommendations'];
$p8Eye = $s8->invoke($gen, $eyeOnly, [], [], $liq, true);
check($p2Eye['eye_protection'] === $p8Eye['eye_protection'], 'H319 only: S2 eye === S8 eye (UV sentence in both)', [$p2Eye['eye_protection'], $p8Eye['eye_protection']]);
$uv->invoke($gen, false, true, []);
check(!str_contains((string) $s2->invoke($gen, $sensInk, [])['ppe_recommendations']['hand_protection'], $sup['hand_protection']), 'pack off: S2 no UV');
check(!str_contains((string) $s8->invoke($gen, $sensInk, [], [], $liq, false)['hand_protection'], $sup['hand_protection']), 'pack off: S8 no UV');

// ---------------------------------------------------------------------
echo "2. #50 detection only supplies names; >= 0.1 %; acrylic polymers excluded\n";
$comp = [
    ['cas_number' => '000-00-1', 'chemical_name' => 'Urethane acrylate oligomer', 'concentration_pct' => 40.0],
    ['cas_number' => '000-00-2', 'chemical_name' => 'Acrylic resin', 'concentration_pct' => 20.0],
    ['cas_number' => '15625-89-5', 'chemical_name' => 'Trimethylolpropane triacrylate', 'concentration_pct' => 0.05],
    ['cas_number' => '000-00-3', 'chemical_name' => 'Styrene acrylate copolymer', 'concentration_pct' => 10.0],
    ['cas_number' => '000-00-4', 'chemical_name' => 'Sodium polyacrylate', 'concentration_pct' => 5.0],
    ['cas_number' => '79-10-7', 'chemical_name' => 'Acrylic acid', 'concentration_pct' => 0.5],
    ['cas_number' => '000-00-5', 'chemical_name' => 'Polyester acrylate', 'concentration_pct' => 10.0],
    ['cas_number' => '9011-14-7', 'chemical_name' => 'Poly(methyl methacrylate)', 'concentration_pct' => 3.0],
];
$found = UVAcrylateRulePack::detectAcrylates($comp);
check(array_keys($found) === ['000-00-1', '79-10-7', '000-00-5'], 'oligomer + acrylic acid + polyester acrylate only', $found);
check(UVAcrylateRulePack::detectAcrylates([['cas_number' => '15625-89-5', 'chemical_name' => 'TMPTA', 'concentration_pct' => 0.1]]) !== [], 'exactly 0.1 % is named');
$uv->invoke($gen, true, true, $found);
$skin = $s4->invoke($gen, $sensInk, [])['skin'];
check(
    str_ends_with($skin, ' ' . $t->get('section4.uv_skin_names', ['names' => 'Urethane acrylate oligomer, Acrylic acid, Polyester acrylate'])),
    'Section 4 skin ends with the names sentence',
    $skin
);
check(!str_contains($skin, 'Acrylic resin') && !str_contains($skin, 'Trimethylolpropane'), 'resin and sub-0.1 % TMPTA never named', $skin);
$uv->invoke($gen, true, true, []);
$skin = $s4->invoke($gen, $unclassified, [])['skin'];
check($skin === $t->get('section4.skin') . ' ' . $t->get('section4.uv_skin'), 'UV family, no recognised names: generic sentence still prints', $skin);
$uv->invoke($gen, false, false, $found);
$skin = $s4->invoke($gen, $sensInk, [])['skin'];
check(
    $skin === $t->get('section4.skin') . ' ' . $t->get('section4.skin_irritant') . ' ' . $t->get('section4.skin_sensitizer'),
    'non-UV / pack off: no UV sentence even with acrylates',
    $skin
);
$tsFound = UVAcrylateRulePack::detectAcrylates([['cas_number' => '13048-33-4', 'chemical_name' => 'HDDA', 'is_trade_secret' => true, 'trade_secret_description' => '', 'concentration_pct' => 5.0]]);
$uv->invoke($gen, true, true, $tsFound);
$skin = $s4->invoke($gen, $sensInk, [])['skin'];
check(str_contains($skin, $t->get('labels.trade_secret')) && !str_contains($skin, 'HDDA') && !str_contains($skin, '13048-33-4'), 'trade-secret acrylate: placeholder, never name or CAS', $skin);
check(str_contains($s4->invoke($gen, $sensInk, [4 => ['skin' => 'Custom skin']])['skin'], 'Custom skin')
    && $s4->invoke($gen, $sensInk, [4 => ['skin' => 'Custom skin']])['skin'] === 'Custom skin', 'Section 4 skin override replaces the UV sentence too');

// ---------------------------------------------------------------------
echo "3. #65 UV fragments inside Sections 4-7; same condition for Section 7 storage and Section 10\n";
$uv->invoke($gen, true, true, []);
$f5 = $s5->invoke($gen, ['formula_props' => []], $unclassified, []);
check(str_ends_with($f5['specific_hazards'], $t->get('section5.uv_specific_hazards')), 'S5 specific hazards end with the UV sentence', $f5['specific_hazards']);
check(substr_count($f5['specific_hazards'] . ' ' . $f5['firefighter_advice'], 'SCBA') === 1, 'S5: exactly one SCBA sentence', $f5);
check(!isset($f5['uv_acrylate_note']), 'S5 carries no uv_acrylate_note');
$f6 = $s6->invoke($gen, $unclassified, ['physical_state' => 'Powder'], []);
check($f6['containment'] === $t->get('section6.containment_solid') . ' ' . $t->get('section6.uv_containment'), 'S6 powder: solid containment + UV sentence', $f6['containment']);
check(stripos($f6['containment'], 'absorb') === false, 'S6 powder: no "absorb"', $f6['containment']);
check(substr_count(strtolower($f6['environmental']), 'drains') === 1, 'S6: drains sentence once', $f6['environmental']);
check(!isset($f6['uv_acrylate_note']), 'S6 carries no uv_acrylate_note');
check($s6->invoke($gen, $unclassified, $liq, [6 => ['containment' => 'Custom']])['containment'] === 'Custom', 'S6 containment override replaces the UV sentence');
$f7 = $s7->invoke($gen, $unclassified, []);
check(str_contains($f7['handling'], $t->get('section7.uv_handling')), 'S7 handling has UV sentence', $f7['handling']);
check(str_contains($f7['storage'], $t->get('section7.uv_storage')), 'S7 storage has UV storage sentence', $f7['storage']);
check(!isset($f7['uv_acrylate_note']), 'S7 carries no uv_acrylate_note');
check($s7->invoke($gen, $unclassified, [7 => ['handling' => 'Custom H']])['handling'] === 'Custom H', 'S7 handling override replaces the UV sentence');

$uv->invoke($gen, false, true, []);
$f7 = $s7->invoke($gen, $unclassified, []);
check(!str_contains($f7['handling'], $t->get('section7.uv_handling')), 'pack off: S7 handling has no UV sentence', $f7['handling']);
check(str_contains($f7['storage'], $t->get('section7.uv_storage')), 'pack off, UV family: S7 storage keeps the UV storage sentence', $f7['storage']);
check(str_contains($s10->invoke($gen, $unclassified, [], true)['conditions_avoid'], $t->get('section10.cond_uv')), 'S10 UV item under the same family condition');
check(!str_contains($s4->invoke($gen, $unclassified, [])['skin'], $t->get('section4.uv_skin')), 'pack off: S4 no UV sentence');
check(!str_contains($s5->invoke($gen, ['formula_props' => []], $unclassified, [])['specific_hazards'], $t->get('section5.uv_specific_hazards')), 'pack off: S5 no UV sentence');
check(!str_contains($s6->invoke($gen, $unclassified, $liq, [])['containment'], $t->get('section6.uv_containment')), 'pack off: S6 no UV sentence');

$uv->invoke($gen, false, false, []);
check(!str_contains($s7->invoke($gen, $unclassified, [])['storage'], $t->get('section7.uv_storage')), 'not a UV family: no UV storage sentence');
check(!str_contains($s10->invoke($gen, $unclassified, [], false)['conditions_avoid'], $t->get('section10.cond_uv')), 'not a UV family: no S10 UV item');

check(TextOverrideService::isEditable(11, 'uv_acrylate_note'), 'S11 uv_acrylate_note is editable');
check(!TextOverrideService::isEditable(4, 'uv_acrylate_note'), 'S4 uv_acrylate_note is not an editable field');
check(UVAcrylateRulePack::section11Note($t, false) === $t->get('section11.uv_acrylate_note_unclassified'), 'S11 note without H317 = unclassified variant');

$newUvKeys = [['section4', 'uv_skin'], ['section4', 'uv_skin_names'], ['section5', 'uv_specific_hazards'], ['section6', 'uv_containment'], ['section7', 'uv_handling'], ['section7', 'uv_storage']];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $file = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($newUvKeys as [$sec, $k]) {
        check(is_string($file[$sec][$k] ?? null) && trim($file[$sec][$k]) !== '', "{$lang} {$sec}.{$k}");
    }
    check(str_contains((string) ($file['section4']['uv_skin_names'] ?? ''), ':names'), "{$lang} uv_skin_names has :names");
    foreach (['section4', 'section5', 'section6', 'section7'] as $sec) {
        check(!isset($file[$sec]['uv_acrylate_note']), "{$lang} {$sec}.uv_acrylate_note absent");
    }
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
