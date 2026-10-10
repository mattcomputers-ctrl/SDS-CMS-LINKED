#!/usr/bin/env php
<?php
/**
 * AbbreviationService Test Suite (audit #33)
 *
 * Validates the Section 16 "Abbreviations" line: the per-language master
 * table (section16.abbreviation_table) is filtered to the terms that
 * actually print on the sheet, with the same gating the renderers use.
 *
 * Run:
 *   php tests/Services/AbbreviationServiceTest.php
 *
 * Exit code:
 *   0 = all cases passed
 *   1 = one or more cases failed
 *
 * No DB or network access required. The translation files are loaded from
 * the project's templates/translations directory.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

// Bootstrap App statics without DB. new App() needs config/config.php, which
// a bare checkout / the lint container does not have, so fall back to the
// reflection pattern used by smoke_pdf.php.
try {
    new \SDS\Core\App();
} catch (\RuntimeException $e) {
    $ref = new ReflectionClass(\SDS\Core\App::class);
    $bp  = $ref->getProperty('basePath');
    $bp->setAccessible(true);
    $bp->setValue(null, $basePath);
    $cfg = $ref->getProperty('config');
    $cfg->setAccessible(true);
    $cfg->setValue(null, ['sds' => ['supported_languages' => ['en', 'es', 'fr', 'de']]]);
}

use SDS\Services\AbbreviationService;
use SDS\Services\TranslationService;

$passed = 0;
$failed = 0;
$failures = [];

function assertTrue(string $desc, bool $cond, string $extra = ''): bool
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        return true;
    }
    $failed++;
    $failures[] = $desc;
    echo "  FAIL  {$desc}\n";
    if ($extra !== '') {
        echo "        {$extra}\n";
    }
    return false;
}

function assertEquals(string $desc, $expected, $actual): bool
{
    return assertTrue(
        $desc,
        $expected === $actual,
        'Expected: ' . var_export($expected, true) . "\n        Got:      " . var_export($actual, true)
    );
}

function assertContains(string $desc, string $needle, string $haystack): bool
{
    return assertTrue($desc, strpos($haystack, $needle) !== false, "Missing: {$needle}");
}

function assertNotContains(string $desc, string $needle, string $haystack): bool
{
    return assertTrue($desc, strpos($haystack, $needle) === false, "Unexpected: {$needle}");
}

echo "=== AbbreviationService Test Suite ===\n\n";

$en       = new TranslationService('en');
$labelsEn = $en->all()['labels'];

// Fixture mirroring SDSGenerator output shape for an EN solvent ink:
// one PEL-TWA limit, no carcinogens, SARA analysed with nothing reportable,
// no SNUR, HAPs absent, Prop 65 none, not DOT-regulated.
$fixture = [
    'meta' => ['language' => 'en', 'labels' => $labelsEn],
    'sections' => [
        1  => ['title' => 'Identification', 'product_family' => 'UV Offset'],
        2  => ['title' => 'Hazards', 'is_classified' => true, 'signal_word' => 'Warning',
               'hazard_classes' => [['class' => 'Flammable Liquids', 'class_translated' => 'Flammable Liquids', 'category' => 'Cat 3', 'h_codes' => ['H226']]],
               'h_statements' => [['code' => 'H226', 'text' => 'Flammable liquid and vapour.']],
               'p_statements' => [['code' => 'P210', 'text' => 'Keep away from heat.']],
               'ppe_recommendations' => ['respiratory' => 'NIOSH-approved respirator with P100/OV cartridge.'],
               'has_other_hazards' => false, 'other_hazards' => 'None known.'],
        8  => ['title' => 'Exposure', 'exposure_limits' => [['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'limit_type' => 'PEL-TWA', 'value' => '200', 'units' => 'ppm']]],
        11 => ['title' => 'Tox', 'carcinogenicity' => 'No components present at or above 0.1% are listed as carcinogens by IARC, NTP, or OSHA.',
               'component_toxicology' => [], 'hazard_classes' => [['class' => 'STOT — Single Exposure']], 'carcinogen_result' => ['summary_text' => 'SCBA hidden']],
        14 => ['title' => 'Transport', 'un_number' => 'Not regulated'],
        15 => ['title' => 'Regulatory', 'osha_status' => 'Classified under OSHA HazCom 2012 (29 CFR 1910.1200).',
               'sara_313' => ['reportable' => [], 'listed_chemicals' => []],
               'hap' => ['has_haps' => false], 'snur' => ['has_snur' => false, 'listed_chemicals' => [['chemical_name' => 'hidden SNUR chem', 'cas_number' => '0-0-0', 'rule_citation' => 'LED hidden']]],
               'prop65' => ['requires_warning' => false, 'warning_text' => 'WARNING ... IDLH-bogus'], 'state_regs' => ''],
        16 => ['title' => 'Other Information', 'abbreviations' => ''],
    ],
    'legal_disclaimer' => 'Provided as a guide.',
];

// ──────────────────────────────────────────────────────────────────────
echo "[1] EN fixture — every printed term is defined.\n";
$line = AbbreviationService::build($fixture, $en);
foreach ([
    'CAS = Chemical Abstracts Service registry number',
    'GHS = ', 'OSHA = ', 'PEL = ', 'TWA = ', 'NIOSH = ', 'P100 = ', 'OV = ',
    'Hxxx = ', 'Pxxx = ', 'IARC = ', 'NTP = ', 'HAP = ', 'HazCom = ', 'CFR = ',
    'EPA = ', 'VOC = ', 'wt% = ', 'UN = ', 'UV = ', 'TSCA = ', 'PPE = ',
    // SARA block prints heading + "none" sentence whenever reportable is an array (audit #30)
    'SARA = ', 'TRI = ',
] as $needle) {
    assertContains("line contains '{$needle}'", $needle, $line);
}

// ──────────────────────────────────────────────────────────────────────
echo "[2] EN fixture — terms that are not printed are not defined.\n";
// #18(c): the "VOC less W&E" and "Solids (vol%)" lines are no longer printed, so W&E / vol% are not defined either.
foreach (['STEL =', 'IDLH =', 'REL =', 'TLV =', 'SNUR =', 'STOT =', 'SCBA =', 'LED =', 'n.o.s. =', 'ACGIH =', 'DOT =', 'W&E =', 'vol% ='] as $needle) {
    assertNotContains("line omits '{$needle}'", $needle, $line);
}

// ──────────────────────────────────────────────────────────────────────
echo "[3] Formatting and order.\n";
assertTrue('line ends with a period', substr($line, -1) === '.');
assertNotContains('no empty pair', '; .', $line);
assertTrue('CAS before GHS', strpos($line, 'CAS =') < strpos($line, 'GHS ='));
assertTrue('GHS before OSHA', strpos($line, 'GHS =') < strpos($line, 'OSHA ='));
assertEquals('format() of empty set is empty string', '', AbbreviationService::format([]));
assertEquals('format() joins pairs', 'A = a; B = b.', AbbreviationService::format(['A' => 'a', 'B' => 'b']));

// ──────────────────────────────────────────────────────────────────────
echo "[4] Gating follows the renderers.\n";
// SARA block absent (no 'reportable' key, e.g. pre-#30 snapshot) → no heading/none-line printed.
$noSara = $fixture;
$noSara['sections'][15]['sara_313'] = [];
$l = AbbreviationService::build($noSara, $en);
assertNotContains('no SARA block → SARA undefined', 'SARA =', $l);
assertNotContains('no SARA block → TRI undefined', 'TRI =', $l);

// Reportable SARA chemical + SNUR present → SARA/TRI/SNUR defined, SNUR citation CFR scanned.
$withReg = $fixture;
$withReg['sections'][15]['snur'] = ['has_snur' => true, 'listed_chemicals' => [['chemical_name' => 'X', 'cas_number' => '1-1-1', 'rule_citation' => '40 CFR 721.10000']]];
$withReg['sections'][15]['sara_313']['reportable'] = [['chemical_name' => 'Y', 'cas_number' => '2-2-2']];
$l = AbbreviationService::build($withReg, $en);
assertContains('SNUR listed → SNUR defined', 'SNUR = ', $l);
assertContains('SARA reportable → SARA defined', 'SARA = ', $l);
assertContains('SARA reportable → TRI defined', 'TRI = ', $l);

// Hidden SNUR chemicals (has_snur false) never leak: fixture carried 'LED hidden'.
assertNotContains('has_snur=false → listed_chemicals not scanned', 'LED =', $line);

// other_hazards prints whenever the text is non-empty, regardless of has_other_hazards.
$oh = $fixture;
$oh['sections'][2]['other_hazards'] = 'May form STOT-type effects on repeated contact (SCBA for rescue).';
$oh['sections'][2]['has_other_hazards'] = false;
$l = AbbreviationService::build($oh, $en);
assertContains('other_hazards text scanned when non-empty (STOT)', 'STOT = ', $l);
assertContains('other_hazards text scanned when non-empty (SCBA)', 'SCBA = ', $l);
$oh['sections'][2]['other_hazards'] = '';
$oh['sections'][2]['has_other_hazards'] = true;
$l = AbbreviationService::build($oh, $en);
assertNotContains('empty other_hazards not scanned', 'STOT = ', $l);

// Prop 65 warning text only when required.
$p65 = $fixture;
$p65['sections'][15]['prop65'] = ['requires_warning' => true, 'warning_text' => 'WARNING: IDLH-bogus listed chemical'];
$l = AbbreviationService::build($p65, $en);
assertContains('prop65 warning text scanned when required', 'IDLH = ', $l);
assertNotContains('prop65 warning not required → text hidden', 'IDLH = ', $line);

// Prop 65 listed-chemical lines (audit #42) print only under the warning.
$p65l = $fixture;
$p65l['sections'][15]['prop65'] = ['requires_warning' => true, 'warning_text' => 'WARNING', 'listed_lines' => ['Lead (CAS 7439-92-1) — cancer; see STEL-bogus']];
assertContains('prop65 listed lines scanned when required', 'STEL = ', AbbreviationService::build($p65l, $en));
$p65h = $fixture;
$p65h['sections'][15]['prop65'] = ['requires_warning' => false, 'warning_text' => '', 'listed_lines' => ['STEL-bogus hidden']];
assertNotContains('prop65 listed lines hidden when no warning', 'STEL = ', AbbreviationService::build($p65h, $en));
// SARA range note (audit #42) prints only under a non-empty reportable list.
$sr2 = $fixture;
$sr2['sections'][15]['sara_313']['reportable'] = [['chemical_name' => 'Toluene', 'cas_number' => '108-88-3', 'concentration_range' => '1 - 5%', 'threshold_pct' => 1.0]];
assertContains('SARA range note label scanned when listed', 'CFR = ', AbbreviationService::build($sr2, $en));

// state_regs prints whenever present, with or without a Prop 65 warning (audit #31).
$sr = $fixture;
$sr['sections'][15]['state_regs'] = 'Subject to STEL reporting in NJ.';
$l = AbbreviationService::build($sr, $en);
assertContains('state_regs scanned without Prop 65 warning', 'STEL = ', $l);
$sr['sections'][15]['prop65'] = ['requires_warning' => true, 'warning_text' => 'WARNING'];
$l = AbbreviationService::build($sr, $en);
assertContains('state_regs still scanned when Prop 65 warning prints (#31)', 'STEL = ', $l);
// Pre-#31 snapshots hold the Prop 65 warning in state_regs: not scanned twice, label gate off.
$sr['sections'][15]['prop65'] = ['requires_warning' => true, 'warning_text' => 'WARNING STEL-dup'];
$sr['sections'][15]['state_regs'] = 'WARNING STEL-dup';
$l = AbbreviationService::build($sr, $en);
assertContains('legacy duplicate still defines STEL once via the Prop 65 text', 'STEL = ', $l);
$sr['sections'][15]['prop65'] = ['requires_warning' => false, 'warning_text' => ''];
$sr['sections'][15]['state_regs'] = '   ';
$l = AbbreviationService::build($sr, $en);
assertNotContains('blank state_regs not scanned', 'STEL = ', $l);

// Section 11 hidden payloads never count (fixture carries STOT / SCBA there).
assertNotContains('section 11 hazard_classes ignored', 'STOT =', $line);
assertNotContains('section 11 carcinogen_result ignored', 'SCBA =', $line);

// Section 11 component toxicology prints agency codes + "(CAS n)".
$ct = $fixture;
$ct['sections'][11]['component_toxicology'] = [['chemical_name' => 'Z', 'cas_number' => '3-3-3', 'concentration_range' => '0.5 - 1.5%',
    'carcinogen_listings' => [['agency' => 'ACGIH', 'classification' => 'A3']],
    'exposure_limits' => [['limit_type' => 'TLV-STEL', 'value' => '1', 'units' => 'ppm']]]];
$l = AbbreviationService::build($ct, $en);
assertContains('component tox agency scanned (ACGIH)', 'ACGIH = ', $l);
assertContains('component tox limit type scanned (TLV)', 'TLV = ', $l);
assertContains('component tox limit type scanned (STEL)', 'STEL = ', $l);

// Section 8 never prints uv_acrylate_note (PPE advice is folded into the fields, audit #35).
$uv = $fixture;
$uv['sections'][8]['uv_acrylate_note'] = 'Use SCBA when curing.';
$l = AbbreviationService::build($uv, $en);
assertNotContains('section 8 uv_acrylate_note ignored', 'SCBA =', $l);

// Sections 4-7 and 11 DO print uv_acrylate_note (audit #35): terms inside it count.
$uv5 = $fixture;
$uv5['sections'][5]['uv_acrylate_note'] = 'Fire-fighters must wear SCBA.';
$l = AbbreviationService::build($uv5, $en);
assertContains('section 5 uv_acrylate_note scanned (SCBA)', 'SCBA =', $l);
$uv11 = $fixture;
$uv11['sections'][11]['uv_acrylate_note'] = 'UV/EB acrylate sensitizer note.';
$l = AbbreviationService::build($uv11, $en);
assertContains('section 11 uv_acrylate_note scanned (EB)', 'EB =', $l);
$uv4 = $fixture;
$uv4['sections'][4]['uv_acrylate_note'] = 'UV/EB curable product (TMPTA).';
$l = AbbreviationService::build($uv4, $en);
assertContains('section 4 uv_acrylate_note scanned (EB)', 'EB =', $l);
assertNotContains('EB not defined when no note prints', 'EB =', AbbreviationService::build($fixture, $en));

// Exposure-limit labels (el_*) gated on exposure_limits.
$noEl = $fixture;
$noEl['sections'][8]['exposure_limits'] = [];
$l = AbbreviationService::build($noEl, $en);
assertNotContains('no exposure limits → PEL undefined', 'PEL =', $l);
assertNotContains('no exposure limits → TWA undefined', 'TWA =', $l);

// ──────────────────────────────────────────────────────────────────────
echo "[5] UN pattern.\n";
assertEquals('UN1210 matches', ['UN' => 'x'], AbbreviationService::filter(['UN' => 'x'], 'UN1210'));
assertEquals('FUNDAMENTAL UNITS does not match', [], AbbreviationService::filter(['UN' => 'x'], 'FUNDAMENTAL UNITS'));
assertEquals('UN 1993 matches', ['UN' => 'x'], AbbreviationService::filter(['UN' => 'x'], 'UN 1993'));
assertEquals('UN Number label matches', ['UN' => 'x'], AbbreviationService::filter(['UN' => 'x'], 'UN Number'));
assertEquals('UN-Nummer (DE label) matches', ['UN' => 'x'], AbbreviationService::filter(['UN' => 'x'], 'UN-Nummer'));

// ──────────────────────────────────────────────────────────────────────
echo "[6] H/P statement code patterns.\n";
assertEquals('P100/OV → only P100', ['P100' => 'f'], AbbreviationService::filter(['Pxxx' => 'p', 'P100' => 'f'], 'P100/OV'));
assertEquals('P210+P233 → Pxxx', ['Pxxx' => 'p'], AbbreviationService::filter(['Pxxx' => 'p'], 'P210+P233'));
assertEquals('H226 → Hxxx', ['Hxxx' => 'h'], AbbreviationService::filter(['Hxxx' => 'h'], 'H226'));
assertEquals('H2260 → no Hxxx', [], AbbreviationService::filter(['Hxxx' => 'h'], 'H2260'));
assertEquals('P1000 → no Pxxx', [], AbbreviationService::filter(['Pxxx' => 'p'], 'P1000'));

// ──────────────────────────────────────────────────────────────────────
echo "[7] Plural and token boundaries.\n";
assertEquals('HAPs plural matches', ['HAP' => 'h'], AbbreviationService::filter(['HAP' => 'h'], 'EPA HAPs'));
assertEquals('RELEASE does not match REL', [], AbbreviationService::filter(['REL' => 'r'], 'RELEASE'));
assertEquals('CASCADE does not match CAS', [], AbbreviationService::filter(['CAS' => 'c'], 'CASCADE'));
assertEquals('N.O.S. matches n.o.s.', ['n.o.s.' => 'n'], AbbreviationService::filter(['n.o.s.' => 'n'], 'Paint, N.O.S.'));
assertEquals('lowercase cas does not match CAS', [], AbbreviationService::filter(['CAS' => 'c'], 'in case of fire'));
assertEquals('wt% matches', ['wt%' => 'w'], AbbreviationService::filter(['wt%' => 'w'], 'VOC (wt%)'));
assertEquals('W&E matches', ['W&E' => 'w'], AbbreviationService::filter(['W&E' => 'w'], 'VOC less W&E (lb/gal)'));
assertEquals('Gew.-% matches', ['Gew.-%' => 'g'], AbbreviationService::filter(['Gew.-%' => 'g'], 'VOC (Gew.-%)'));
assertEquals('empty definition skipped', [], AbbreviationService::filter(['CAS' => ''], 'CAS'));

// Audit #20: Section 11 ATEmix / ETAmezcla / ETAmél and the body-weight units.
assertEquals('ATEmix matches ATE', ['ATE' => 'a'], AbbreviationService::filter(['ATE' => 'a'], 'ATEmix = 1250 mg/kg bw.'));
assertEquals('bare ATE matches', ['ATE' => 'a'], AbbreviationService::filter(['ATE' => 'a'], 'ATE'));
assertEquals('CREATE does not match ATE', [], AbbreviationService::filter(['ATE' => 'a'], 'CREATE'));
assertEquals('ETAmezcla matches ETA', ['ETA' => 'e'], AbbreviationService::filter(['ETA' => 'e'], 'ETAmezcla = 1250 mg/kg pc.'));
assertEquals('ETAmél matches ETA', ['ETA' => 'e'], AbbreviationService::filter(['ETA' => 'e'], 'ETAmél = 1250 mg/kg pc.'));
assertEquals('kg does not match KG', [], AbbreviationService::filter(['KG' => 'k'], 'mg/kg'));
assertEquals('mg/kg KG matches KG', ['KG' => 'k'], AbbreviationService::filter(['KG' => 'k'], 'mg/kg KG'));

// ──────────────────────────────────────────────────────────────────────
echo "[7b] Section 14 transport terms (finding #52).\n";
$fx14 = $fixture;
$fx14['sections'][14] = ['title' => 'Transport', 'un_number' => 'UN1210', 'note' => $en->get('section14.note'),
    'transport_in_bulk' => $en->get('section14.transport_in_bulk_text'), 'environmental_hazards' => 'Marine pollutant: Yes'];
$line14 = AbbreviationService::build($fx14, $en);
foreach (['IATA = ', 'IMDG = ', 'MARPOL = ', 'IBC = '] as $needle) {
    assertContains("Section 14 line contains '{$needle}'", $needle, $line14);
}
$fx14b = $fixture;
$fx14b['sections'][14] = ['title' => 'Transport', 'environmental_hazards' => 'IMDG marine pollutant'];
assertContains('environmental_hazards is scanned', 'IMDG = ', AbbreviationService::build($fx14b, $en));
$fx14c = $fixture;
$fx14c['sections'][14] = ['title' => 'Transport', 'un_number' => 'Not regulated', 'ghs_note' => 'LED test'];
assertNotContains('ghs_note is no longer scanned in Section 14', 'LED = ', AbbreviationService::build($fx14c, $en));
$frTable14 = AbbreviationService::table(new TranslationService('fr'));
foreach (['IATA', 'IMDG', 'MARPOL', 'IBC'] as $k) {
    assertTrue("fr: has {$k}", array_key_exists($k, $frTable14));
}

// ──────────────────────────────────────────────────────────────────────
echo "[8] Per-language master tables.\n";
$sorted = static function (array $k): array { usort($k, 'strcasecmp'); return $k; };
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $table = AbbreviationService::table(new TranslationService($lang));
    assertTrue("{$lang}: table non-empty", $table !== []);
    $allStrings = true;
    foreach ($table as $term => $def) {
        if (!is_string($def) || $def === '' || (string) $term === '') {
            $allStrings = false;
        }
    }
    assertTrue("{$lang}: every entry is a non-empty string", $allStrings);
    $keys = array_map('strval', array_keys($table));
    assertEquals("{$lang}: keys alphabetical (case-insensitive)", $sorted($keys), $keys);
    assertTrue("{$lang}: no AGW entry", !array_key_exists('AGW', $table));
}
$fr = AbbreviationService::table(new TranslationService('fr'));
foreach (['SGH', 'COV', 'EPI', 'CIRC', 'ONU', 'ARA', 'E&E'] as $k) {
    assertTrue("fr: has {$k}", array_key_exists($k, $fr));
}
$es = AbbreviationService::table(new TranslationService('es'));
foreach (['EPP', 'ERA', 'COV', 'A&E', 'ONU'] as $k) {
    assertTrue("es: has {$k}", array_key_exists($k, $es));
}
$de = AbbreviationService::table(new TranslationService('de'));
foreach (['PSA', 'PA', 'Gew.-%', 'W&E'] as $k) {
    assertTrue("de: has {$k}", array_key_exists($k, $de));
}
$enTable = AbbreviationService::table($en);
foreach (['CAS', 'GHS', 'OSHA', 'PEL', 'TLV', 'REL', 'IDLH', 'VOC', 'SARA', 'TSCA', 'HAP', 'SNUR', 'IARC', 'NTP', 'STOT', 'UN', 'STEL', 'TWA', 'NIOSH', 'PPE', 'EPA', 'CFR', 'HazCom', 'TRI', 'SCBA', 'UV'] as $k) {
    assertTrue("en: has {$k}", array_key_exists($k, $enTable));
}
assertContains('en: IDLH uses NIOSH wording ("or Health")', 'Life or Health', $enTable['IDLH']);

// ──────────────────────────────────────────────────────────────────────
echo "[9] FR sheet uses the FR-printed terms.\n";
$frT = new TranslationService('fr');
$frFixture = $fixture;
$frFixture['meta']['language'] = 'fr';
$frFixture['meta']['labels']   = $frT->all()['labels'];
$frFixture['sections'][11]['carcinogenicity'] = $frT->get('section11.carcinogenicity', ['threshold' => '0.1']);
$frLine = AbbreviationService::build($frFixture, $frT);
foreach (['SGH = ', 'COV = ', 'CIRC = ', 'ONU = ', 'PEL = ', 'TWA = ', 'EPI = '] as $needle) {
    assertContains("fr line contains '{$needle}'", $needle, $frLine);
}
assertNotContains('fr line omits GHS (labels print SGH)', 'GHS = ', $frLine);
assertNotContains('fr line omits IARC (default text prints CIRC)', 'IARC = ', $frLine);
assertNotContains('fr line omits W&E (FR term is E&E; line dropped anyway)', 'W&E = ', $frLine);
assertNotContains('fr line omits E&E (VOC less W&E line no longer printed, audit #18)', 'E&E = ', $frLine);

// ──────────────────────────────────────────────────────────────────────
echo "[10] Fallback and empty input.\n";
assertEquals('unsupported language falls back to EN table', $enTable, AbbreviationService::table(new TranslationService('xx')));
assertEquals('build([]) is empty', '', AbbreviationService::build([], $en));
assertEquals('build with only labels still defines label terms',
    true, strpos(AbbreviationService::build(['meta' => ['labels' => $labelsEn]], $en), 'TSCA = ') !== false);

// ──────────────────────────────────────────────────────────────────────
echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "\nAll cases passed.\n";
echo "\nEN sample line:\n{$line}\n";
echo "\nFR sample line:\n{$frLine}\n";
exit(0);
