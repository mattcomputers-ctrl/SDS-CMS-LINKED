<?php
/**
 * DB-free checks for audit item #31 — Section 15 "State Regulations":
 *
 *   - SDSGenerator::section15(): the operator's per-product note is the only
 *     source of state_regs (the old Prop 65 fallback is gone), it is trimmed,
 *     and the Prop 65 block passes through untouched.
 *   - PDFService::renderSection15(): the note prints whenever present, with or
 *     without the Prop 65 warning; it is absent when blank; a pre-#31 snapshot
 *     whose state_regs equals the Prop 65 warning text is not printed twice.
 *
 * Same Reflection bootstrap as SDSGeneratorSections2_11_12Test.php plus the
 * TCPDF defines from PDFServiceGenerateToFileTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorSection15StateRegsTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

// Make TCPDF throw instead of die() (see PDFServiceGenerateToFileTest.php).
define('K_TCPDF_EXTERNAL_CONFIG', true);
define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
define('PDF_IMAGE_SCALE_RATIO', 1.25);

require_once $basePath . '/vendor/autoload.php';

// Bootstrap App static properties without a DB connection
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

/**
 * Concatenate the (inflated) content streams of a TCPDF output. Core-font
 * text is emitted as plain literal strings, so needles without parentheses
 * can be matched directly.
 */
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

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);

// section15() calls ghsSectionNote(), which reads settings via Database::getInstance() unless its static cache is pre-set.
$p = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'showGhsSectionNote');
$p->setAccessible(true);
$p->setValue(null, false);

$s15m = new ReflectionMethod($gen, 'section15');
$s15m->setAccessible(true);
$lblm = new ReflectionMethod($gen, 'getLabels');
$lblm->setAccessible(true);
$labels = $lblm->invoke($gen);

$hz0  = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'exposure_limits' => []];
$sara = [];
$hap  = [];
$calc = ['composition' => []];
$note = 'New Jersey Right-to-Know: Toluene, CAS 108-88-3.';
$p65On  = ['requires_warning' => true, 'warning_text' => 'WARNING: This product can expose you to chemicals including Toluene, which is known to the State of California to cause birth defects or other reproductive harm. For more information go to www.P65Warnings.ca.gov.', 'listed_chemicals' => []];
$p65Off = ['requires_warning' => false, 'warning_text' => '', 'listed_chemicals' => []];

// ---------------------------------------------------------------------
echo "a. SDSGenerator::section15() state_regs source\n";
$r = $s15m->invoke($gen, $hz0, $sara, $p65On, $hap, $calc, []);
check($r['state_regs'] === '', 'no override + Prop 65 fires -> state_regs empty (fallback removed)', $r['state_regs']);
// Audit #42 adds listed_lines / drops listed_chemicals on the Section 15 copy; the warning itself is untouched.
check(($r['prop65']['requires_warning'] ?? null) === true && ($r['prop65']['warning_text'] ?? null) === $p65On['warning_text'], 'prop65 warning passed through untouched', $r['prop65']);

$r = $s15m->invoke($gen, $hz0, $sara, $p65On, $hap, $calc, [15 => ['state_regs' => $note]]);
check($r['state_regs'] === $note, 'override kept while Prop 65 fires', $r['state_regs']);

$r = $s15m->invoke($gen, $hz0, $sara, $p65Off, $hap, $calc, [15 => ['state_regs' => $note]]);
check($r['state_regs'] === $note, 'override kept without Prop 65', $r['state_regs']);

$r = $s15m->invoke($gen, $hz0, $sara, $p65Off, $hap, $calc, [15 => ['state_regs' => '   ']]);
check($r['state_regs'] === '', 'whitespace-only override -> empty', $r['state_regs']);

$r = $s15m->invoke($gen, $hz0, $sara, $p65On, $hap, $calc, [15 => ['state_regs' => "  {$note}\n"]]);
check($r['state_regs'] === $note, 'override is trimmed', $r['state_regs']);

$p->setValue(null, null);

// ---------------------------------------------------------------------
echo "b. PDFService::renderSection15() State Regulations line\n";

$fixture = static function (array $prop65, string $stateRegs) use ($labels): array {
    return [
        'meta' => [
            'product_code'      => 'TEST-031',
            'description'       => 'State Regs Test Ink',
            'language'          => 'en',
            'generated_at'      => gmdate('Y-m-d\TH:i:s\Z'),
            'company_logo_path' => '',
            'labels'            => $labels,
        ],
        'sections' => [
            1 => [
                'title'                => 'Identification',
                'product_identifier'   => 'TEST-031 — State Regs Test Ink',
                'manufacturer_name'    => 'Test Co',
                'manufacturer_address' => '123 Test Blvd, Testville, OH 44000',
                'manufacturer_phone'   => '(555) 000-0000',
                'emergency_phone'      => 'CHEMTREC: (800) 424-9300',
            ],
            15 => [
                'title'       => 'Regulatory Information',
                'osha_status' => 'Classified as hazardous under OSHA HazCom.',
                'tsca_status' => 'All components listed on TSCA.',
                'sara_313'    => ['reportable' => [], 'below_threshold' => [], 'not_listed' => []],
                'hap'         => ['has_haps' => false],
                'snur'        => ['has_snur' => false, 'listed_chemicals' => []],
                'prop65'      => $prop65,
                'state_regs'  => $stateRegs,
                'ghs_note'    => '',
            ],
        ],
        'legal_disclaimer' => 'Guide only.',
    ];
};

$svc  = new \SDS\Services\PDFService();

$pdf  = $svc->generateString($fixture($p65On, $note));
$text = pdfText($pdf);
check(str_starts_with($pdf, '%PDF-'), 'generateString returns a PDF');
check(str_contains($text, 'State Regulations:') && str_contains($text, 'New Jersey Right-to-Know: Toluene'), 'note prints WITH Prop 65 warning (#31)');
check(str_contains($text, 'State of California'), 'Prop 65 block still prints');

$text = pdfText($svc->generateString($fixture($p65On, '')));
check(!str_contains($text, 'State Regulations:'), 'no note -> no State Regulations line');

$text = pdfText($svc->generateString($fixture($p65On, $p65On['warning_text'])));
check(!str_contains($text, 'State Regulations:'), 'legacy snapshot (state_regs == Prop 65 warning) -> line suppressed');
check(substr_count($text, 'State of California') === 1, 'legacy snapshot -> Prop 65 warning printed exactly once', substr_count($text, 'State of California'));

$text = pdfText($svc->generateString($fixture($p65Off, $note)));
check(str_contains($text, 'State Regulations:') && str_contains($text, 'New Jersey Right-to-Know: Toluene'), 'note prints without Prop 65');
check(!str_contains($text, 'State of California'), 'no Prop 65 warning text when not required');
check(str_contains($text, 'not known to contain'), 'prop65_none sentence prints when not required');

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
