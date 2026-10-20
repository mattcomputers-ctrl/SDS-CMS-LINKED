#!/usr/bin/env php
<?php
/**
 * Section 11 / 15 tables and the label/value layout (2026-10-19), DB-free.
 *
 *   a. SDSGenerator::section11() emits carcinogen_rows (one row per listed
 *      component, one column per agency, Section 3 band, trade secrets
 *      masked), the intro sentence and the inhalable-dust note, and only for
 *      the automatic listing (none for an operator override or the negative
 *      sentence). The 'carcinogenicity' text is unchanged.
 *   b. PDFService prints the carcinogenicity, component toxicological data
 *      and SARA 313 tables (headers in the sheet language, every exposure
 *      limit line, the dust footnote) and still prints the text form for a
 *      snapshot without rows.
 *   c. No table cell loses its last line (TCPDF drops it when the row height
 *      equals the measured text height; CELL_SLACK): Section 3 three-line
 *      names, Section 8 wrapped chemical names, the new tables.
 *   d. labelValue(): a value stays on its label's line (tab stops every
 *      25 mm from the 50 mm value column) and only drops to the next line,
 *      indented, when less than 45 mm is left.
 *   e. The new column headers exist in all four languages; the Section 16
 *      abbreviation corpus counts them only where they print.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSSectionTablesTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

define('K_TCPDF_EXTERNAL_CONFIG', true);
define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
define('PDF_IMAGE_SCALE_RATIO', 1.25);

require_once $basePath . '/vendor/autoload.php';

$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);
$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, ['paths' => ['generated_pdfs' => $basePath . '/storage/temp']]);

use SDS\Services\PDFService;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

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

// ---------------------------------------------------------------------
echo "a. Section 11 carcinogen rows\n";
$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$s11 = new ReflectionMethod($gen, 'section11');
$s11->setAccessible(true);
$hz0 = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'exposure_limits' => []];
$composition = [
    ['cas_number' => '100-41-4',   'chemical_name' => 'Ethylbenzene',     'concentration_pct' => 1.5],
    ['cas_number' => '13463-67-7', 'chemical_name' => 'Titanium dioxide', 'concentration_pct' => 20.0],
];
$carc = ['has_carcinogens' => true, 'findings' => [
    ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 1.5,
     'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => '']]],
    ['cas_number' => '13463-67-7', 'chemical_name' => 'Titanium dioxide', 'concentration_pct' => 20.0, 'inhalable_dust_only' => true,
     'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => ''],
                    ['agency' => 'NTP', 'classification' => 'Reasonably Anticipated', 'description' => '']]],
]];
$out = $s11->invoke($gen, $hz0, $composition, $carc, []);
$rows = $out['carcinogen_rows'] ?? null;
check(is_array($rows) && count($rows) === 2, 'one row per listed component', $rows);
check(($rows[0]['chemical_name'] ?? '') === 'Ethylbenzene' && ($rows[0]['cas_number'] ?? '') === '100-41-4' && ($rows[0]['iarc'] ?? '') === 'Group 2B' && ($rows[0]['ntp'] ?? 'x') === '' && ($rows[0]['osha'] ?? 'x') === '', 'agency columns: IARC filled, NTP / OSHA empty', $rows[0] ?? null);
check(($rows[0]['concentration_range'] ?? '') !== '' && !str_contains((string) ($rows[0]['concentration_range'] ?? ''), '1.5'), 'concentration is the Section 3 band, never the exact %', $rows[0]['concentration_range'] ?? null);
check(($rows[1]['ntp'] ?? '') === $t->get('section11.carcinogen_class_ntp_rahc') && ($rows[1]['inhalable_dust_only'] ?? false) === true, 'NTP classification translated; dust flag carried', $rows[1] ?? null);
check(str_starts_with((string) ($out['carcinogen_intro'] ?? ''), 'The following component(s), present at or above 0.1%'), 'intro sentence carried with the threshold', $out['carcinogen_intro'] ?? null);
check(($out['carcinogen_dust_note'] ?? '') === $t->get('section11.carcinogenicity_inhalable_dust_note'), 'dust footnote carried when a row needs it');
check(str_contains((string) $out['carcinogenicity'], 'Ethylbenzene (CAS 100-41-4, '), "'carcinogenicity' text unchanged (editor hint, overrides, old snapshots)", $out['carcinogenicity']);
$ovr = $s11->invoke($gen, $hz0, $composition, $carc, [11 => ['carcinogenicity' => 'Operator text']]);
check(($ovr['carcinogen_rows'] ?? null) === [] && $ovr['carcinogenicity'] === 'Operator text', 'operator override: no rows, the text prints');
$neg = $s11->invoke($gen, $hz0, $composition, ['has_carcinogens' => false, 'findings' => []], []);
check(($neg['carcinogen_rows'] ?? null) === [] && ($neg['carcinogen_intro'] ?? 'x') === '' && ($neg['carcinogen_dust_note'] ?? 'x') === '', 'negative sentence: no rows');
// Trade secret: masked before the row is built
$tsComp = [['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'concentration_pct' => 3.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary solvent']];
$tsCarc = ['has_carcinogens' => true, 'findings' => [['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'concentration_pct' => 3.0, 'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => '']]]]];
$ts = $s11->invoke($gen, $hz0, $tsComp, $tsCarc, []);
$tsRow = $ts['carcinogen_rows'][0] ?? [];
check(!str_contains(json_encode($tsRow), 'Toluene') && !str_contains(json_encode($tsRow), '108-88-3'), 'trade-secret row is masked (no name, no CAS)', $tsRow);

// ---------------------------------------------------------------------
echo "\nb + c. PDF tables and no dropped lines\n";
$svc = new PDFService();
$labelsFor = static fn(string $lang): array => (new TranslationService($lang))->all()['labels'];
$doc = static function (string $lang, array $s11Data, array $s15Data) use ($labelsFor): array {
    return [
        'meta' => ['finished_good_id' => 1, 'product_code' => 'T-1', 'description' => 'Table test', 'language' => $lang,
                   'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'company_logo_path' => '', 'labels' => $labelsFor($lang)],
        'sections' => [
            1 => ['title' => 'Identification', 'product_identifier' => 'T-1'],
            3 => ['title' => 'Composition', 'components' => [
                ['cas_number' => '64742-52-5', 'chemical_name' => 'Distillates (petroleum), hydrotreated heavy naphthenic, solvent dewaxed, with extra words to force a third line S3END', 'concentration_range' => '10 - 30%', 'h_codes' => ['H304']],
            ]],
            8 => ['title' => 'Exposure', 'exposure_limits' => [
                ['cas_number' => '64742-52-5', 'chemical_name' => 'Distillates (petroleum), hydrotreated heavy naphthenic S8END', 'limit_type' => 'OSHA PEL-TWA', 'value' => '5', 'units' => 'mg/m3', 'concentration_range' => '10 - 30%', 'notes' => 'as oil mist'],
            ]],
            11 => $s11Data + ['title' => 'Toxicological Information'],
            15 => $s15Data + ['title' => 'Regulatory Information'],
        ],
        'warnings' => [],
        'legal_disclaimer' => 'Disclaimer.',
    ];
};
$pdfText = static function (string $pdf): string {
    // Inflate every content stream; TCPDF writes core-font text as literals.
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $raw) {
            $inflated = @gzuncompress($raw);
            $out .= ($inflated !== false ? $inflated : $raw) . "\n";
        }
    }
    return $out;
};
$s11Rows = [
    'carcinogenicity'      => 'TEXTFORM-SHOULD-NOT-PRINT',
    'carcinogen_intro'     => 'INTRO-SENTENCE',
    'carcinogen_rows'      => [
        ['chemical_name' => 'Ethylbenzene', 'cas_number' => '100-41-4', 'concentration_range' => '1 - 5%', 'iarc' => 'Group 2B', 'ntp' => '', 'osha' => '', 'inhalable_dust_only' => false],
        ['chemical_name' => 'Titanium dioxide', 'cas_number' => '13463-67-7', 'concentration_range' => '10 - 30%', 'iarc' => 'Group 2B', 'ntp' => 'Reasonably anticipated to be a human carcinogen NTPEND', 'osha' => '', 'inhalable_dust_only' => true],
    ],
    'carcinogen_dust_note' => 'DUSTNOTE',
    'component_toxicology' => [
        ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_range' => '1 - 5%',
         'exposure_limits' => [
            ['limit_type' => 'OSHA PEL-TWA', 'value' => '100', 'units' => 'ppm'],
            ['limit_type' => 'ACGIH TLV-TWA', 'value' => '20', 'units' => 'ppm'],
            ['limit_type' => 'NIOSH REL-STEL', 'value' => '125', 'units' => 'ppm', 'notes' => 'LIMITEND'],
         ],
         'carcinogen_listings' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => 'Possibly carcinogenic to humans.']]],
    ],
];
$s15 = ['sara_313' => ['reportable' => [
    ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'sara_name' => 'Toluene', 'concentration_range' => '1 - 5%', 'threshold_pct' => 1.0, 'is_pbt' => false],
    ['cas_number' => '7439-92-1', 'chemical_name' => 'Lead', 'sara_name' => 'Lead compounds', 'concentration_range' => '0.1 - 1%', 'threshold_pct' => 0.1, 'is_pbt' => true],
]]];
foreach (['en', 'de'] as $lang) {
    $lb  = $labelsFor($lang);
    $raw = $pdfText($svc->generateString($doc($lang, $s11Rows, $s15)));
    check(str_contains($raw, 'INTRO-SENTENCE') && !str_contains($raw, 'TEXTFORM-SHOULD-NOT-PRINT'), "{$lang}: carcinogenicity prints the table, not the text");
    foreach (['carc_iarc', 'carc_ntp', 'carc_osha', 'tox_exposure_limits', 'tox_carcinogen_listings', 'sara_313_de_minimis'] as $k) {
        check(str_contains($raw, (string) $lb[$k]), "{$lang}: header '{$lb[$k]}' printed");
    }
    check(str_contains($raw, 'NTPEND') && str_contains($raw, 'LIMITEND'), "{$lang}: last line of every wrapped cell printed (new tables)");
    check(str_contains($raw, 'S3END') && str_contains($raw, 'S8END'), "{$lang}: Section 3 third line and Section 8 second line printed (CELL_SLACK)");
    check(str_contains($raw, 'DUSTNOTE'), "{$lang}: dust footnote printed");
    check(str_contains($raw, '(1%)') || str_contains($raw, '1%'), "{$lang}: SARA threshold printed");
    check(!str_contains($raw, "\xE2\x80\xA2 Toluene") , "{$lang}: SARA chemicals no longer printed as bullets");
}
// Snapshot published before the table existed: no rows -> text form
$legacy = $s11Rows;
unset($legacy['carcinogen_rows'], $legacy['carcinogen_intro'], $legacy['carcinogen_dust_note']);
$legacy['carcinogenicity'] = 'LEGACY-TEXT-FORM';
$rawL = $pdfText($svc->generateString($doc('en', $legacy, $s15)));
check(str_contains($rawL, 'LEGACY-TEXT-FORM'), 'snapshot without rows prints the carcinogenicity text');

// ---------------------------------------------------------------------
echo "\nd. labelValue layout\n";
$lv = new ReflectionMethod($svc, 'labelValue');
$lv->setAccessible(true);
$runLabel = static function (string $label, string $value) use ($svc, $lv): array {
    $pdf = new \SDS\Services\SDSTcpdf('P', 'mm', 'LETTER', true, 'UTF-8');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();
    $y0 = $pdf->GetY();
    $lv->invoke($svc, $pdf, $label, $value);
    return [round($pdf->GetY() - $y0, 2)];
};
[$dy] = $runLabel('Color', 'Yellow');
check(abs($dy - 5.0) < 0.6, 'short label + short value: one line', $dy);
[$dy] = $runLabel('Upper/Lower Flammability or Explosive Limits', 'Not determined');
check(abs($dy - 5.0) < 0.6, 'long label + short value: still one line (value tabbed over)', $dy);
[$dy] = $runLabel(str_repeat('Extremely long label ', 8), 'Not determined');
check($dy > 9.0, 'label leaving < 45 mm: value on the next line', $dy);
$src = (string) file_get_contents($basePath . '/src/Services/PDFService.php');
preg_match('/private function labelValue.*?\n    }\n/s', str_replace("\r\n", "\n", $src), $m);
check(isset($m[0]) && str_contains($m[0], '$pdf->SetX($startX + self::VALUE_COLUMN);'), 'a value moved to the next line is indented to the value column', null);
check(isset($m[0]) && !str_contains($m[0], "\$pdf->Cell(0, 5, \$labelText, 0, 1, 'L');"), 'no path prints the value flush-left under the label', null);

// ---------------------------------------------------------------------
echo "\ne. Translations and abbreviations\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $lb = $labelsFor($lang);
    foreach (['tox_exposure_limits', 'tox_carcinogen_listings', 'carc_iarc', 'carc_ntp', 'carc_osha', 'sara_313_de_minimis'] as $k) {
        check(isset($lb[$k]) && trim((string) $lb[$k]) !== '', "{$lang}: labels.{$k}");
    }
}
check($labelsFor('fr')['carc_iarc'] === 'CIRC', 'fr: IARC column reads CIRC (as the French sheet text does)');
$genLabels = new ReflectionMethod($gen, 'getLabels');
$genLabels->setAccessible(true);
$snapLabels = $genLabels->invoke($gen);
foreach (['tox_exposure_limits', 'tox_carcinogen_listings', 'carc_iarc', 'carc_ntp', 'carc_osha', 'sara_313_de_minimis'] as $k) {
    check(array_key_exists($k, $snapLabels), "getLabels() carries {$k} (stored with each snapshot)");
}
$abbr = (string) file_get_contents($basePath . '/src/Services/AbbreviationService.php');
check(str_contains($abbr, "'carc_iarc'            => !empty(\$s11['carcinogen_rows'])") && str_contains($abbr, "'sara_313_de_minimis'  => \$saraList"), 'abbreviation gates: table headers count only with their table');
check(str_contains($abbr, "self::walk(\$section['carcinogen_rows'], \$parts);"), 'abbreviation corpus reads the table rows when the table prints');

// ---------------------------------------------------------------------
echo "\nf. Section 2 \"Signal Word:\" label (2026-10-19)\n";
$expectSig = ['en' => 'Signal Word', 'es' => 'Palabra de advertencia', 'fr' => "Mention d'avertissement", 'de' => 'Signalwort'];
foreach ($expectSig as $lang => $text) {
    check(($labelsFor($lang)['signal_word'] ?? '') === $text, "{$lang}: labels.signal_word = '{$text}'");
}
check(array_key_exists('signal_word', $snapLabels), 'getLabels() carries signal_word');
$sigDoc = $doc('en', $s11Rows, $s15);
$sigDoc['sections'][2] = ['title' => 'Hazards', 'signal_word' => 'Danger', 'signal_word_en' => 'Danger', 'is_classified' => true,
                          'hazard_classes' => [['class' => 'Flammable Liquids', 'category' => 'Category 2']]];
$rawSig = $pdfText($svc->generateString($sigDoc));
check(str_contains($rawSig, 'Signal Word:') && str_contains($rawSig, 'Danger)') && !str_contains($rawSig, 'DANGER'), 'PDF prints "Signal Word:" before the signal word, as written ("Danger")');
// 2026-10-20 (owner request): the signal word in the same font, size and
// colour as every other value: 9 pt regular, black (no red / orange).
$sigPos  = strpos($rawSig, 'Danger)');
$sigLead = $sigPos === false ? '' : substr($rawSig, max(0, $sigPos - 400), 400);
preg_match_all('/([\d.]+) Tf/', $sigLead, $tfs);
check($sigPos !== false && end($tfs[1]) === '9.000000', 'signal word at 9 pt like the other values', end($tfs[1]));
preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) rg/', $sigLead, $rgs, PREG_SET_ORDER);
$lastRg = end($rgs);
check($lastRg === false || ($lastRg[1] === '0.000000' && $lastRg[2] === '0.000000' && $lastRg[3] === '0.000000'), 'signal word in black', $lastRg);
// It reads "Signal Word: Danger": the word starts right after the label, not
// in the 50 mm value column (owner request 2026-10-20).
$labelX = preg_match('/BT ([\d.]+) [\d.]+ Td \[\(Signal Word: \)\] TJ/', $rawSig, $lx) ? (float) $lx[1] : null;
$wordX  = preg_match('/BT ([\d.]+) [\d.]+ Td \[\(Danger\)\] TJ/', $rawSig, $wx) ? (float) $wx[1] : null;
$gapMm  = ($labelX !== null && $wordX !== null) ? ($wordX - $labelX) / (72 / 25.4) : null;
check($gapMm !== null && $gapMm > 15 && $gapMm < 30, 'signal word follows the label on the same line ("Signal Word: Danger"), not in the value column', $gapMm);
$warnDoc = $sigDoc;
$warnDoc['sections'][2]['signal_word'] = 'Warning';
$warnDoc['sections'][2]['signal_word_en'] = 'Warning';
$rawWarn = $pdfText($svc->generateString($warnDoc));
check(!str_contains($rawWarn, '1.000000 0.549020 0.000000 rg') && !str_contains($rawSig, '0.862745 0.000000 0.000000 rg'), 'no red (Danger) or orange (Warning) text colour');
check(!str_contains((string) file_get_contents($basePath . '/src/Services/PDFService.php'), "SetFont('helvetica', 'B', 14);\n            \$color = (\$s['signal_word_en']"), 'the 14 pt coloured signal word is gone');
$noSig = $doc('en', $s11Rows, $s15);
$noSig['sections'][2] = ['title' => 'Hazards', 'signal_word' => '', 'is_classified' => false, 'not_classified_text' => 'Not classified.'];
check(!str_contains($pdfText($svc->generateString($noSig)), 'Signal Word:'), 'no label when there is no signal word');
$pv = (string) file_get_contents($basePath . '/src/Views/sds/preview.php');
check(str_contains($pv, "<strong><?= e(\$l('signal_word')) ?>:</strong> <?= e(\$section['signal_word']) ?></p>"), 'preview prints the same label and a plain signal word');
check(!str_contains($pv, 'font-size: 1.3rem; font-weight: bold; color:'), 'preview: no large coloured signal word');
check(str_contains($abbr, "'signal_word'          => !empty(\$s2['signal_word'])"), 'abbreviation gate for the label');

// ---------------------------------------------------------------------
echo "\ng. Page breaks and footnotes (2026-10-19 review)\n";
check(PDFService::footnoteText('(The listing applies to inhalable dust only.)') === 'The listing applies to inhalable dust only.', 'footnote drops the sentence\'s own parentheses');
check(PDFService::footnoteText('Plain note.') === 'Plain note.', 'footnote without parentheses unchanged');
foreach (['renderSection3' => '$s3Header', 'renderSection8' => '$s8Header', 'renderSection12' => '$s12Header'] as $fn => $hdr) {
    preg_match('/private function ' . $fn . '\(.*?\n    }\n/s', str_replace("\r\n", "\n", $src), $mm);
    check(isset($mm[0]) && str_contains($mm[0], '$this->breakBeforeRow($pdf, $rowH, ' . $hdr . ');'), "{$fn}: a row that does not fit starts a new page and repeats the header");
}
// Render Section 3 starting near the bottom of a page: the long middle row must
// move whole to page 2 (header repeated) and print its last line.
$r3 = new ReflectionMethod($svc, 'renderSection3');
$r3->setAccessible(true);
$lbP = new ReflectionProperty($svc, 'labels');
$lbP->setAccessible(true);
$lbP->setValue($svc, $labelsFor('en'));
$long = 'Distillates (petroleum), hydrotreated heavy naphthenic, solvent dewaxed reaction products of acrylate oligomer with Phosphine oxide phenylbis trimethylbenzoyl NAMEEND';
$breakHits = 0;
foreach ([229.0, 230.0, 233.0, 240.0] as $y0) {
    $p3 = new \SDS\Services\SDSTcpdf('P', 'mm', 'LETTER', true, 'UTF-8');
    $p3->setCompression(false);
    $p3->setPrintHeader(false);
    $p3->setPrintFooter(false);
    $p3->SetMargins(15, 15, 15);
    $p3->SetAutoPageBreak(true, 20);
    $p3->AddPage();
    $p3->SetY($y0);
    $r3->invoke($svc, $p3, ['substance_or_mixture' => 'Mixture', 'mixture_notes' => false, 'components' => [
        ['cas_number' => '1-1-1', 'chemical_name' => 'Row one ROWA', 'concentration_range' => '1 - 5%', 'h_codes' => ['H315']],
        ['cas_number' => '2-2-2', 'chemical_name' => $long, 'concentration_range' => '10 - 30%', 'h_codes' => ['H304']],
        ['cas_number' => '3-3-3', 'chemical_name' => 'Row three ROWC', 'concentration_range' => '1 - 5%', 'h_codes' => ['H319']],
    ]]);
    $raw3 = $p3->Output('', 'S');
    if (str_contains($raw3, 'NAMEEND') && str_contains($raw3, 'ROWC') && substr_count($raw3, '(' . $labelsFor('en')['chemical_name'] . ')') >= ($p3->getNumPages() > 1 ? 2 : 1)) {
        $breakHits++;
    }
}
check($breakHits === 4, 'Section 3 near a page break: last line printed, header repeated on the new page', $breakHits);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
