#!/usr/bin/env php
<?php
/**
 * UVAcrylateRulePack unit test (audit item #35, DB-free)
 *
 *   - familyIsUv(): only the resolved family's UV/LED flag (family_is_uv)
 *     counts; the family NAME no longer matters;
 *   - detectAcrylates(): by known CAS and by name pattern;
 *   - section4SkinFragment() / section11Note(): translated Section 4 skin
 *     sentence (:names interpolated) and the Section 11 note (#65);
 *   - getPpeSupplement(): one translated sentence per HazardEngine::PPE_FIELDS;
 *   - every new key exists in all four language files.
 *
 * isEnabled() / isApplicable() read the settings table and are covered by
 * the smoke / manual checks instead.
 *
 * Run: php tests/Services/UVAcrylateRulePackTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\HazardEngine;
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

$tEn = new TranslationService('en');

echo "1. familyIsUv() — resolved family flag only\n";
check(UVAcrylateRulePack::familyIsUv([]) === false, 'empty row -> false');
check(UVAcrylateRulePack::familyIsUv(['family' => 'UV Offset']) === false, 'name "UV Offset" without flag -> false (name no longer matters)');
check(UVAcrylateRulePack::familyIsUv(['family' => 'LED Flexo', 'family_id' => 3]) === false, 'name "LED Flexo" without flag -> false');
check(UVAcrylateRulePack::familyIsUv(['family_is_uv' => 1]) === true, 'family_is_uv int 1 -> true');
check(UVAcrylateRulePack::familyIsUv(['family_is_uv' => '1']) === true, 'family_is_uv string "1" -> true');
check(UVAcrylateRulePack::familyIsUv(['family_is_uv' => true]) === true, 'family_is_uv bool true (attachFamily) -> true');
check(UVAcrylateRulePack::familyIsUv(['family_is_uv' => 0]) === false, 'family_is_uv 0 -> false');
check(UVAcrylateRulePack::familyIsUv(['family_is_uv' => false]) === false, 'family_is_uv bool false -> false');
check(UVAcrylateRulePack::familyIsUv(['family_is_uv' => null]) === false, 'family_is_uv null (no resolved family) -> false');

echo "2. detectAcrylates()\n";
$comp = [
    ['cas_number' => '15625-89-5', 'chemical_name' => 'Trimethylolpropane triacrylate'],
    ['cas_number' => '64-17-5',    'chemical_name' => 'Ethanol'],
    ['cas_number' => '999-99-9',   'chemical_name' => 'Foo Methacrylate'],
];
$found = UVAcrylateRulePack::detectAcrylates($comp);
check(isset($found['15625-89-5']) && $found['15625-89-5'] === 'Trimethylolpropane triacrylate', 'TMPTA by CAS, composition name kept', $found);
check(isset($found['999-99-9']) && $found['999-99-9'] === 'Foo Methacrylate', 'methacrylate by name pattern', $found);
check(!isset($found['64-17-5']), 'ethanol not detected');
check(count($found) === 2, 'exactly two hits', $found);
check(UVAcrylateRulePack::detectAcrylates([['cas_number' => '64-17-5', 'chemical_name' => 'Ethanol']]) === [], 'no acrylates -> []');
check(UVAcrylateRulePack::detectAcrylates([['cas_number' => '15625-89-5', 'chemical_name' => 'TMPTA', 'concentration_pct' => 0.05]]) === [], '#50 below 0.1 % -> not named');
check(UVAcrylateRulePack::detectAcrylates([['cas_number' => '1', 'chemical_name' => 'Acrylic resin', 'concentration_pct' => 20.0], ['cas_number' => '2', 'chemical_name' => 'Styrene acrylate copolymer', 'concentration_pct' => 10.0]]) === [], '#50 acrylic resins/copolymers not matched');

echo "2b. detectAcrylates() — trade-secret rows are detected but never named (29 CFR 1910.1200(i))\n";
$tsComp = [
    ['cas_number' => '57472-68-1', 'chemical_name' => 'Dipropylene glycol diacrylate', 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary Acrylate Monomer'],
    ['cas_number' => '15625-89-5', 'chemical_name' => 'Trimethylolpropane triacrylate', 'is_trade_secret' => false],
    ['cas_number' => '13048-33-4', 'chemical_name' => 'HDDA', 'is_trade_secret' => 1, 'trade_secret_description' => ''],
    ['cas_number' => '999-99-9',   'chemical_name' => 'Secret Methacrylate', 'is_trade_secret' => true, 'trade_secret_description' => null],
];
$tsFound = UVAcrylateRulePack::detectAcrylates($tsComp);
check(count($tsFound) === 4, 'all four rows detected (generic sentences still fire)', $tsFound);
check(($tsFound['57472-68-1'] ?? null) === 'Proprietary Acrylate Monomer', 'trade-secret DPGDA displays its description', $tsFound['57472-68-1'] ?? null);
check(($tsFound['13048-33-4'] ?? null) === 'Trade Secret', 'blank description -> "Trade Secret"', $tsFound['13048-33-4'] ?? null);
check(($tsFound['999-99-9'] ?? null) === 'Trade Secret', 'name-pattern trade-secret row -> "Trade Secret", never the CAS', $tsFound['999-99-9'] ?? null);
check(($tsFound['15625-89-5'] ?? null) === 'Trimethylolpropane triacrylate', 'non-secret row keeps its name');
$tsText = UVAcrylateRulePack::section4SkinFragment($tsFound, $tEn);
check(!str_contains($tsText, 'Dipropylene') && !str_contains($tsText, '57472-68-1') && !str_contains($tsText, 'HDDA') && !str_contains($tsText, '13048-33-4'), 'section 4 sentence carries no withheld identity or CAS', $tsText);
check(str_contains($tsText, 'Proprietary Acrylate Monomer') && str_contains($tsText, 'Trimethylolpropane triacrylate'), 'section 4 sentence carries the description and the disclosed name', $tsText);
check(substr_count($tsText, 'Trade Secret') === 1, '"Trade Secret" printed once for two unlabeled trade-secret rows', $tsText);
$tsWarn = UVAcrylateRulePack::getFormulatorWarnings($tsFound);
check(substr_count($tsWarn[2] ?? '', 'Trade Secret') === 1, 'formulator warning de-duplicates too', $tsWarn[2] ?? null);

echo "3. section4SkinFragment() / section11Note() — EN\n";
check(UVAcrylateRulePack::section4SkinFragment([], $tEn) === $tEn->get('section4.uv_skin'), 'no names -> generic sentence');
$one = ['15625-89-5' => 'Trimethylolpropane triacrylate'];
$en4 = UVAcrylateRulePack::section4SkinFragment($one, $tEn);
check($en4 === $tEn->get('section4.uv_skin_names', ['names' => 'Trimethylolpropane triacrylate']), 'names sentence', $en4);
check(!str_contains($en4, ':names') && str_contains($en4, 'UV/EB'), 'interpolated, mentions UV/EB');
check(str_contains(UVAcrylateRulePack::section4SkinFragment(['15625-89-5' => 'TMPTA', '13048-33-4' => 'HDDA'], $tEn), 'TMPTA, HDDA'), 'two names joined');
check(UVAcrylateRulePack::section11Note($tEn, true) === $tEn->get('section11.uv_acrylate_note') && str_contains(UVAcrylateRulePack::section11Note($tEn, true), 'known skin sensitizers'), 'S11 with H317');
check(!str_contains(UVAcrylateRulePack::section11Note($tEn, false), 'sensitiz'), 'S11 without H317: no sensitizer claim');
check(!method_exists(UVAcrylateRulePack::class, 'getSafeHandlingLanguage'), 'getSafeHandlingLanguage removed (#65)');

echo "4. ES / FR / DE\n";
foreach (['es', 'fr', 'de'] as $lang) {
    $t = new TranslationService($lang);
    $o = UVAcrylateRulePack::section4SkinFragment($one, $t);
    check($o !== $en4 && $o === $t->get('section4.uv_skin_names', ['names' => 'Trimethylolpropane triacrylate']), "{$lang} section 4 sentence translated");
    check(UVAcrylateRulePack::section11Note($t, false) !== UVAcrylateRulePack::section11Note($tEn, false), "{$lang} section 11 note translated");
}
check(!str_contains(strtolower(UVAcrylateRulePack::section11Note(new TranslationService('de'), false)), 'sensibilisator'), 'DE: no sensitizer claim without H317');

echo "5. getPpeSupplement()\n";
$ppe = UVAcrylateRulePack::getPpeSupplement($tEn);
check(array_keys($ppe) === HazardEngine::PPE_FIELDS, 'keys === HazardEngine::PPE_FIELDS', array_keys($ppe));
foreach (HazardEngine::PPE_FIELDS as $f) {
    check(($ppe[$f] ?? '') === $tEn->get('section8.uv_' . $f), "{$f} === section8.uv_{$f}");
    check(($ppe[$f] ?? '') !== '' && !str_starts_with((string) $ppe[$f], 'section8.'), "{$f} key resolved");
}
check(str_contains($ppe['respiratory'] ?? '', '1910.134'), 'respiratory cites 29 CFR 1910.134');

echo "6. Translation completeness (en/es/fr/de)\n";
$keys = [
    ['section4', 'uv_skin'], ['section4', 'uv_skin_names'], ['section5', 'uv_specific_hazards'],
    ['section6', 'uv_containment'], ['section7', 'uv_handling'], ['section7', 'uv_storage'],
    ['section8', 'uv_respiratory'], ['section8', 'uv_hand_protection'],
    ['section8', 'uv_eye_protection'], ['section8', 'uv_skin_protection'], ['section10', 'cond_uv'],
    ['section11', 'uv_acrylate_note'], ['labels', 'uv_acrylate_note'],
    ['section11', 'uv_acrylate_note_unclassified'],
];
// Audit #40 housekeeping: the old Section 10 keys section10() no longer reads are gone from every language.
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    $dead = array_intersect(['conditions_avoid', 'conditions_avoid_uv', 'conditions_avoid_water_reactive', 'conditions_avoid_pyrophoric', 'conditions_avoid_self_reactive'], array_keys($trFile['section10'] ?? []));
    check($dead === [], "{$lang}: dead section10.conditions_avoid* keys removed", $dead);
    check(!empty($trFile['labels']['conditions_avoid']), "{$lang}: labels.conditions_avoid (the heading) kept");
}
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $trFile = require $basePath . '/templates/translations/' . $lang . '.php';
    foreach ($keys as [$sec, $k]) {
        $v = $trFile[$sec][$k] ?? null;
        check(is_string($v) && trim($v) !== '', "{$lang} {$sec}.{$k}", $v);
    }
    check(str_contains((string) ($trFile['section4']['uv_skin_names'] ?? ''), ':names'), "{$lang} section4.uv_skin_names carries :names");
    foreach (['section4', 'section5', 'section6', 'section7'] as $s) {
        check(!isset($trFile[$s]['uv_acrylate_note']), "{$lang} {$s}.uv_acrylate_note removed (#65)");
    }
    foreach (['EB', 'SCBA', 'UV', 'NIOSH'] as $abbr) {
        $v = $trFile['section16']['abbreviation_table'][$abbr] ?? null;
        check(is_string($v) && $v !== '', "{$lang} abbreviation {$abbr}", $v);
    }
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
