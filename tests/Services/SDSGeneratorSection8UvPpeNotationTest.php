#!/usr/bin/env php
<?php
/**
 * Batch F A4 — Section 8 (DB-free).
 *
 *   A4(a) On UV rule-pack products the UV glove / eyewear sentence REPLACES the
 *         tier sentence (respiratory / clothing keep tier + UV appended); gloves
 *         are always the UV nitrile sentence, even on corrosive inks; only the
 *         goggles + face shield eyewear tier stands alone (UV_PPE_TIER_FLOOR);
 *         Section 2 mirrors Section 8; an override wins;
 *         pack off = tier sentences only.
 *   A4(b) One italic sentence naming the components whose exposure-limit Notes
 *         carry a Skin notation, from the MASKED rows (trade secret by its
 *         description), unique, row order.
 *   A4(c) No exposure-limit rows -> section8.engineering_no_limits (no reference
 *         to exposure limits), fragments unchanged; a limit row keeps the
 *         original sentence; override wins.
 *
 * Run: php tests/Services/SDSGeneratorSection8UvPpeNotationTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\HazardEngine;
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
function hz(array $h, array $limits = []): array
{
    return [
        'signal_word'     => $h === [] ? null : 'Warning',
        'pictograms'      => $h === [] ? [] : ['GHS07'],
        'hazard_classes'  => [],
        'h_statements'    => array_map(fn($c) => ['code' => $c, 'text' => ''], $h),
        'p_statements'    => [],
        'exposure_limits' => $limits,
    ];
}

$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$s2  = m($gen, 'section2');
$s8  = m($gen, 'section8');
m($gen, 'setUvState')->invoke($gen, true, true, []);
$liq = ['physical_state' => 'Liquid'];
$tr  = fn(string $k): string => $t->get('section8.' . $k);

// ---------------------------------------------------------------------
echo "1. A4(a) UV glove / eyewear sentence replaces the tier sentence\n";
$p = $s8->invoke($gen, hz(['H315', 'H317', 'H319']), [], [], $liq, true);
check($p['hand_protection'] === $tr('uv_hand_protection'), 'H315/H317/H319: hand is the UV sentence alone', $p['hand_protection']);
check(substr_count(strtolower($p['hand_protection']), 'nitrile') === 1, 'hand names nitrile once (no tier sentence)', $p['hand_protection']);
check($p['eye_protection'] === $tr('uv_eye_protection'), 'H315/H317/H319: eye is the UV sentence alone', $p['eye_protection']);
check(!str_contains(strtolower($p['eye_protection']), 'safety glasses'), 'eye no longer offers safety glasses', $p['eye_protection']);
check($p['respiratory'] === $tr('ppe.respiratory.general') . ' ' . $tr('uv_respiratory'), 'respiratory: tier + UV appended (unchanged)', $p['respiratory']);
check($p['skin_protection'] === $tr('ppe.skin_protection.clothing') . ' ' . $tr('uv_skin_protection'), 'skin: tier + UV appended (unchanged)', $p['skin_protection']);

$p = $s8->invoke($gen, hz(['H315', 'H319']), [], [], $liq, true);
check($p['hand_protection'] === $tr('uv_hand_protection'), 'H315/H319: resistant tier replaced by UV hand', $p['hand_protection']);
check($p['eye_protection'] === $tr('uv_eye_protection'), 'H315/H319: goggles tier replaced by UV eye', $p['eye_protection']);

$p = $s8->invoke($gen, hz(['H315']), [], [], $liq, true);
check($p['eye_protection'] === $tr('ppe.eye_protection.general'), 'H315 only: baseline eye tier, no H317 -> no UV', $p['eye_protection']);

$p = $s8->invoke($gen, hz([]), [], [], $liq, true);
foreach (HazardEngine::PPE_FIELDS as $f) {
    check($p[$f] === $tr("ppe.{$f}.none"), "unclassified: {$f} plain none sentence", $p[$f]);
}

// ---------------------------------------------------------------------
echo "2. Safety floor: eyewear only; UV gloves are always nitrile\n";
$p = $s8->invoke($gen, hz(['H314', 'H317']), [], [], $liq, true);
check($p['hand_protection'] === $tr('uv_hand_protection'), 'H314+H317: UV nitrile glove sentence replaces the impervious tier (owner 2026-10-12)', $p['hand_protection']);
check(!str_contains(strtolower($p['hand_protection']), 'butyl'), 'H314+H317: no butyl glove advice on a UV ink', $p['hand_protection']);
check($p['eye_protection'] === $tr('ppe.eye_protection.goggles_faceshield'), 'H314+H317: goggles + face shield alone', $p['eye_protection']);
check($p['skin_protection'] === $tr('ppe.skin_protection.suit') . ' ' . $tr('uv_skin_protection'), 'H314+H317: suit + UV skin appended', $p['skin_protection']);

$p = $s8->invoke($gen, hz(['H317', 'H318']), [], [], $liq, true);
check($p['eye_protection'] === $tr('ppe.eye_protection.goggles_faceshield'), 'H317+H318: goggles + face shield alone', $p['eye_protection']);
check($p['hand_protection'] === $tr('uv_hand_protection'), 'H317+H318: sensitizer glove tier replaced by UV hand', $p['hand_protection']);

// ---------------------------------------------------------------------
echo "3. Section 2 mirror, override, pack off, languages\n";
$hzS = hz(['H315', 'H317', 'H319']);
$p8 = $s8->invoke($gen, $hzS, [], [], $liq, true);
$p2 = $s2->invoke($gen, $hzS, [])['ppe_recommendations'];
foreach (['hand_protection', 'eye_protection', 'skin_protection'] as $f) {
    check($p2[$f] === $p8[$f], "S2 {$f} === S8 {$f}", [$p2[$f], $p8[$f]]);
}
$ov = [8 => ['eye_protection' => 'Custom eyewear']];
check(
    $s8->invoke($gen, $hzS, [], $ov, $liq, true)['eye_protection'] === 'Custom eyewear'
    && $s2->invoke($gen, $hzS, $ov)['ppe_recommendations']['eye_protection'] === 'Custom eyewear',
    'override wins in S2 and S8'
);
$off = $s8->invoke($gen, $hzS, [], [], $liq, false);
check($off['hand_protection'] === $tr('ppe.hand_protection.sensitizer'), 'pack off: sensitizer glove tier', $off['hand_protection']);
check($off['eye_protection'] === $tr('ppe.eye_protection.goggles'), 'pack off: goggles tier', $off['eye_protection']);
foreach (['es', 'fr', 'de'] as $lang) {
    $tl = new TranslationService($lang);
    $gl = new SDSGenerator($tl);
    m($gl, 'setUvState')->invoke($gl, true, true, []);
    $sl = m($gl, 'section8')->invoke($gl, $hzS, [], [], $liq, true);
    check($sl['hand_protection'] === $tl->get('section8.uv_hand_protection'), "{$lang}: hand is the translated UV sentence", $sl['hand_protection']);
    check($sl['eye_protection'] === $tl->get('section8.uv_eye_protection'), "{$lang}: eye is the translated UV sentence", $sl['eye_protection']);
}

// ---------------------------------------------------------------------
echo "4. A4(b) Skin-notation sentence\n";
$comp = [
    ['cas_number' => '111-76-2', 'chemical_name' => '2-Butoxyethanol', 'concentration_pct' => 3.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary glycol ether'],
    ['cas_number' => '67-56-1',  'chemical_name' => 'Methanol',        'concentration_pct' => 2.0],
    ['cas_number' => '64-17-5',  'chemical_name' => 'Ethanol',         'concentration_pct' => 5.0],
    ['cas_number' => '108-65-6', 'chemical_name' => 'PGMEA',           'concentration_pct' => 4.0],
];
$lim = fn(string $cas, string $name, string $type, string $notes): array => [
    'cas_number' => $cas, 'chemical_name' => $name, 'concentration_pct' => 1.0,
    'limit_type' => $type, 'value' => '1', 'units' => 'ppm', 'notes' => $notes,
];
$limits = [
    $lim('111-76-2', '2-Butoxyethanol', 'TLV-TWA', 'Skin; A3'),
    $lim('67-56-1', 'Methanol', 'TLV-TWA', 'Skin'),
    $lim('67-56-1', 'Methanol', 'REL-TWA', '[skin]'),
    $lim('64-17-5', 'Ethanol', 'PEL-TWA', ''),
    $lim('108-65-6', 'PGMEA', 'WEEL-TWA', 'skin sensitizer not expected'),
];
$s = $s8->invoke($gen, hz(['H225'], $limits), $comp, [], $liq, false);
$expect = $t->get('section8.skin_notation', ['names' => 'Proprietary glycol ether, Methanol']);
check($s['skin_notation_note'] === $expect, 'names the masked trade secret and Methanol once, in row order', $s['skin_notation_note']);
check(!str_contains($s['skin_notation_note'], 'Butoxyethanol'), 'trade-secret identity never printed', $s['skin_notation_note']);
$s = $s8->invoke($gen, hz(['H225'], [$lim('64-17-5', 'Ethanol', 'TLV-STEL', 'A4')]), $comp, [], $liq, false);
check($s['skin_notation_note'] === '', "single 'A4' row -> ''", $s['skin_notation_note']);
$s = $s8->invoke($gen, hz(['H225']), $comp, [], $liq, false);
check($s['skin_notation_note'] === '', "no limits -> ''", $s['skin_notation_note']);

$sn = m($gen, 'skinNotationNames');
foreach (['Skin', '[skin]', 'SKIN; DSEN', 'Skin; A4', 'Ca; Skin', 'Skin; SEN', 'Skin, A4, DSEN', 'Skin; Eye irr'] as $notes) {
    check($sn->invoke(null, [$lim('1-1-1', 'X', 'TLV-TWA', $notes)]) === ['X'], "regex matches '{$notes}'");
}
foreach (['A4', 'DSEN', 'skin sensitizer', 'Skin irritant', 'No skin notation', 'Skinner', '',
          'Eye & skin irr', 'URT & skin irr', 'Eye, skin, URT irr', 'skin irr', 'Skin and eye irritant', 'skin damage', 'skin burns'] as $notes) {
    check($sn->invoke(null, [$lim('1-1-1', 'X', 'TLV-TWA', $notes)]) === [], "regex does not match '{$notes}'");
}

$tf = new TranslationService('fr');
$gf = new SDSGenerator($tf);
$sf = m($gf, 'section8')->invoke($gf, hz(['H225'], $limits), $comp, [], $liq, false);
check(str_contains($sf['skin_notation_note'], 'Methanol') && str_contains($sf['skin_notation_note'], 'peau'), 'FR: translated sentence names Methanol', $sf['skin_notation_note']);

// ---------------------------------------------------------------------
echo "5. A4(c) Engineering sentence without exposure limits\n";
$eng = fn(array $h, array $fg, array $limits = [], array $ov = []): string => $s8->invoke($gen, hz($h, $limits), [], $ov, $fg, false)['engineering'];
check($eng([], $liq) === $tr('engineering_no_limits'), 'no limits -> no-limits sentence', $eng([], $liq));
check($eng(['H225'], ['physical_state' => 'Powder']) === $tr('engineering_no_limits') . ' ' . $tr('engineering_dust') . ' ' . $tr('engineering_flammable'), 'no limits + Powder + H225 -> no-limits + dust + flammable');
check($eng([], $liq, [$lim('64-17-5', 'Ethanol', 'PEL-TWA', '')]) === $tr('engineering'), 'limit row -> original sentence');
check($eng([], $liq, [], [8 => ['engineering' => 'Custom']]) === 'Custom', 'override wins');
$forbidden = ['en' => 'exposure limit', 'es' => 'límites de exposición', 'fr' => "limites d'exposition", 'de' => 'grenzwert'];
foreach ($forbidden as $lang => $needle) {
    $file = require $basePath . '/templates/translations/' . $lang . '.php';
    $nl = (string) ($file['section8']['engineering_no_limits'] ?? '');
    $sk = (string) ($file['section8']['skin_notation'] ?? '');
    check($nl !== '' && $sk !== '', "{$lang}: both new keys non-empty");
    check(!str_contains(mb_strtolower($nl), $needle), "{$lang}: engineering_no_limits does not mention exposure limits", $nl);
    check(str_contains($sk, ':names'), "{$lang}: skin_notation carries :names");
}

// ---------------------------------------------------------------------
echo "6. Renderers\n";
check(str_contains((string) file_get_contents($basePath . '/src/Services/PDFService.php'), "\$s['skin_notation_note']"), 'PDFService prints skin_notation_note');
check(substr_count((string) file_get_contents($basePath . '/src/Views/sds/preview.php'), 'skin_notation_note') >= 3, 'preview prints it and skips it in the generic loop');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
