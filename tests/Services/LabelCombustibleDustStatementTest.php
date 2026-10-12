<?php
/**
 * DB-free checks for the A12(c) OSHA Combustible Dust hazard statement on
 * container labels (verification fix 2026-10-12):
 *
 *   - LabelPDFService::labelHazardStatements(): a Section 2 hazard class with
 *     no H-code and a translated 'statement' (the Combustible Dust class
 *     localised by SDSGenerator::localizeCombustibleDustClass()) is added to
 *     the label's hazard statements, first, without its trailing period;
 *     duplicates skipped; nothing added without the class.
 *   - generateFromTemplate(): a Powder product whose only hazard is
 *     Combustible Dust prints WARNING and "May form combustible dust
 *     concentrations in air"; with H372 as well, both statements print.
 *   - buildPrioritizedHStatements(): the code-less statement survives
 *     truncation on a crowded label.
 *
 * Payloads use the real engine shapes: SDSGenerator::applyCombustibleDust()
 * on a hazard result, then section2's localisation through reflection.
 * Same Reflection bootstrap as SDSGeneratorSection15StateRegsTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/LabelCombustibleDustStatementTest.php
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
$cfg->setValue(null, [
    'company' => ['name' => 'Config Placeholder Co'],
    'paths'   => ['generated_pdfs' => $basePath . '/storage/temp'],
]);

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

function pdfText(string $pdf): string
{
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $raw) {
            $dec = @gzuncompress($raw);
            $out .= ($dec === false ? $raw : $dec) . "\n";
        }
    }
    return $out;
}

use SDS\Services\LabelPDFService;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$loc = new ReflectionMethod($gen, 'localizeCombustibleDustClass');
$loc->setAccessible(true);

$stmt = $t->get('section2.combustible_dust_statement');
check($stmt === 'May form combustible dust concentrations in air.', 'EN statement literal', $stmt);
$needle = rtrim($stmt, '.');

$status = ['state' => 'Powder', 'applies' => true, 'yes' => ['RM-DUST'], 'not_assessed' => []];
$hz0 = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'trace' => []];
$h372 = [
    'signal_word' => 'Danger', 'pictograms' => ['GHS08'],
    'hazard_classes' => [['class' => 'STOT RE', 'category' => '1', 'h_codes' => ['H372'], 'cas' => 'MIXTURE']],
    'h_statements' => [['code' => 'H372', 'text' => 'Causes damage to organs through prolonged or repeated exposure']],
    'p_statements' => [['code' => 'P260', 'text' => 'Do not breathe dust']], 'trace' => [],
];

/** Section 2 payload built like SDSGenerator::section2(). */
$s2 = static function (array $hazard) use ($loc, $gen): array {
    return [
        'signal_word'    => $hazard['signal_word'],
        'pictograms'     => $hazard['pictograms'],
        'hazard_classes' => $loc->invoke($gen, $hazard['hazard_classes']),
        'h_statements'   => $hazard['h_statements'],
        'p_statements'   => $hazard['p_statements'],
    ];
};

// ---------------------------------------------------------------------
echo "a. labelHazardStatements\n";
$dust = SDSGenerator::applyCombustibleDust($hz0, $status, null);
$sec2 = $s2($dust);
check($sec2['signal_word'] === 'Warning' && $sec2['h_statements'] === [], 'dust only: Warning, no H-statements (engine shape)', $sec2);
$hs = LabelPDFService::labelHazardStatements($sec2, $dust);
check($hs === [['code' => '', 'text' => $needle]], 'dust only: the statement, no trailing period', $hs);

$both = SDSGenerator::applyCombustibleDust($h372, $status, null);
$sec2b = $s2($both);
$hsb = LabelPDFService::labelHazardStatements($sec2b, $both);
check(count($hsb) === 2 && $hsb[0] === ['code' => '', 'text' => $needle] && $hsb[1]['code'] === 'H372', 'dust + H372: dust statement first, then H372', $hsb);
check($sec2b['signal_word'] === 'Danger', 'dust + H372: signal word stays Danger', $sec2b['signal_word']);

$plain = $s2($h372);
check(LabelPDFService::labelHazardStatements($plain, $h372) === $h372['h_statements'], 'no dust class: H-statements unchanged');
check(LabelPDFService::labelHazardStatements([], ['h_statements' => $h372['h_statements']]) === $h372['h_statements'], 'falls back to hazard_result h_statements');

$dup = $sec2;
$dup['h_statements'] = [['code' => '', 'text' => $stmt]];
check(count(LabelPDFService::labelHazardStatements($dup, $dust)) === 1, 'same text already present: not added twice');

$fr = new TranslationService('fr');
$gfr = new SDSGenerator($fr);
$sec2fr = $sec2;
$sec2fr['hazard_classes'] = $loc->invoke($gfr, $dust['hazard_classes']);
$hsfr = LabelPDFService::labelHazardStatements($sec2fr, $dust);
check(($hsfr[0]['text'] ?? '') === rtrim($fr->get('section2.combustible_dust_statement'), '.') && $hsfr[0]['text'] !== $needle, 'FR label: translated statement', $hsfr);

$replace = SDSGenerator::applyCombustibleDust($hz0, $status, ['mode' => 'replace']);
check(LabelPDFService::labelHazardStatements($s2($replace), $replace) === [], "'replace' override: no dust class, nothing added");

// ---------------------------------------------------------------------
echo "b. generateFromTemplate renders the statement\n";
$template = [
    'label_width' => 200, 'label_height' => 120, 'cols' => 1, 'rows' => 1,
    'margin_left' => 5, 'margin_top' => 5, 'h_spacing' => 0, 'v_spacing' => 0,
    'default_font_size' => 8,
    'field_layout' => json_encode([
        'signal_word'       => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 15],
        'hazard_statements' => ['x' => 0, 'y' => 20, 'width' => 100, 'height' => 40],
    ]),
];
$fg = ['product_code' => 'PWD-1'];
$svc = new LabelPDFService();

$pdf = $svc->generateFromTemplate(['sections' => [1 => [], 2 => $sec2], 'hazard_result' => $dust], $fg, 'L1', $template, 1);
$txt = pdfText($pdf);
check(str_contains($txt, 'WARNING'), 'dust only: WARNING printed');
check(str_contains($txt, $needle), 'dust only: "May form combustible dust concentrations in air" printed');
check(str_contains($txt, 'Hazard Statements:'), 'dust only: hazard statement block printed');

$pdfb = $svc->generateFromTemplate(['sections' => [1 => [], 2 => $sec2b], 'hazard_result' => $both], $fg, 'L1', $template, 1);
$txtb = pdfText($pdfb);
check(str_contains($txtb, 'DANGER') && str_contains($txtb, $needle) && str_contains($txtb, 'H372'), 'dust + H372: DANGER, dust statement and H372 printed');

$pdfl = $svc->generate(['sections' => [1 => [], 2 => $sec2], 'hazard_result' => $dust], $fg, 'L1', 'big', 1);
check(str_contains(pdfText($pdfl), 'combustible dust'), 'legacy generate(): dust statement printed');

// ---------------------------------------------------------------------
echo "c. truncation keeps the code-less statement\n";
$bpH = new ReflectionMethod($svc, 'buildPrioritizedHStatements');
$bpH->setAccessible(true);
$tc = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
$tc->AddPage();
$many = [];
foreach (['H350', 'H360', 'H372', 'H373', 'H317', 'H319', 'H335', 'H336', 'H411', 'H412'] as $c) {
    $many[] = ['code' => $c, 'text' => 'Long hazard statement text that takes up a good deal of the available label space'];
}
$crowd = LabelPDFService::labelHazardStatements(['h_statements' => $many, 'hazard_classes' => $sec2['hazard_classes']], []);
$out = $bpH->invoke($svc, $tc, $crowd, 40.0, 6.0, 8.0, 'See SDS for more hazard statements.');
check(str_contains($out, $needle) && str_contains($out, 'See SDS'), 'crowded label: truncated, dust statement kept', $out);
// Code-less entry LAST behind secondary (H4xx) codes: still sorted into the priority group.
$env = [];
foreach (['H400', 'H401', 'H402', 'H410', 'H411', 'H412', 'H413', 'H400', 'H410', 'H411'] as $c) {
    $env[] = ['code' => $c, 'text' => 'Long environmental hazard statement text that takes up a good deal of the label space'];
}
$out2 = $bpH->invoke($svc, $tc, array_merge($env, [['code' => '', 'text' => $needle]]), 40.0, 6.0, 8.0, 'See SDS for more hazard statements.');
check(str_contains($out2, 'See SDS') && str_starts_with($out2, $needle), 'code-less entry sorted with the priority (H2xx/H3xx) group, ahead of H4xx', $out2);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
