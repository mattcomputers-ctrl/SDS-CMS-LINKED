#!/usr/bin/env php
<?php
/**
 * PDFService::generateToFile() test
 *
 * Verifies that generateToFile() writes a valid, non-empty PDF to the
 * exact path requested, creates a missing parent directory on the way,
 * and reports an unwritable target as a catchable RuntimeException.
 * Uses the same DB-free bootstrap and SDS fixture as tests/smoke_pdf.php,
 * minus the PNG-rendering fields (see the section 2 note below), so it
 * runs on a bare php:8.1-cli without GD/Imagick.
 *
 * Run:
 *   php tests/Services/PDFServiceGenerateToFileTest.php
 *
 * Exit code:
 *   0 = passed
 *   1 = failed (including a TCPDF die() or fatal error mid-test)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

// Make TCPDF throw instead of die(): the bundled vendor config hard-codes
// K_TCPDF_THROW_EXCEPTION_ERROR=false, under which TCPDF::Error() calls
// die() (exit status 0, try/catch/finally bypassed). K_TCPDF_EXTERNAL_CONFIG
// skips that file (tcpdf_autoconfig.php then supplies every other default);
// PDF_IMAGE_SCALE_RATIO is pinned to the bundled value it would otherwise
// change (1.25 -> 96/72) so rendering defaults stay identical to production.
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

// Realistic SDS data array mimicking SDSGenerator::generate() output
$sdsData = [
    'meta' => [
        'finished_good_id' => 1,
        'product_code'     => 'TEST-001',
        'description'      => 'Smoke Test Ink',
        'family'           => 'UV Offset',
        'language'         => 'en',
        'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
        'company_logo_path' => '',
    ],
    'sections' => [
        1 => [
            'title' => 'Identification',
            'product_identifier'   => 'TEST-001 — Smoke Test Ink',
            'product_family'       => 'UV Offset',
            'recommended_use'      => 'Printing ink for commercial applications.',
            'restrictions'         => 'For professional use only.',
            'manufacturer_name'    => 'Test Co',
            'manufacturer_address' => '123 Test Blvd, Testville, OH 44000',
            'manufacturer_phone'   => '(555) 000-0000',
            'emergency_phone'      => 'CHEMTREC: (800) 424-9300',
            'manufacturer_email'   => 'info@test.com',
            'manufacturer_website' => 'https://test.com',
        ],
        2 => [
            'title'       => 'Hazard(s) Identification',
            'signal_word' => 'Warning',
            'is_classified' => true,
            'signal_word_en' => 'Warning',
            // 'pictograms' and 'ppe_recommendations' are left empty on purpose:
            // PDFService renders both as alpha-channel PNGs, which TCPDF can
            // only embed with GD or Imagick loaded, and the bare php:8.1-cli
            // image used to run this test has neither. The assertions here
            // (return value, file exists, non-empty, %PDF header, nested dir
            // created, write failure is catchable) do not depend on them.
            'pictograms'  => [],
            'hazard_classes' => [
                ['class' => 'Skin Irritation', 'category' => 'Category 2'],
                ['class' => 'Eye Irritation', 'category' => 'Category 2A'],
                ['class' => 'Aquatic Toxicity', 'category' => 'Chronic Category 2'],
            ],
            'h_statements' => [
                ['code' => 'H315', 'text' => 'Causes skin irritation'],
                ['code' => 'H319', 'text' => 'Causes serious eye irritation'],
                ['code' => 'H411', 'text' => 'Toxic to aquatic life with long lasting effects'],
            ],
            'p_statements' => [
                ['code' => 'P264', 'text' => 'Wash hands thoroughly after handling'],
                ['code' => 'P280', 'text' => 'Wear protective gloves/protective clothing/eye protection/face protection'],
                ['code' => 'P302+P352', 'text' => 'IF ON SKIN: Wash with plenty of water'],
                ['code' => 'P305+P351+P338', 'text' => 'IF IN EYES: Rinse cautiously with water for several minutes. Remove contact lenses, if present and easy to do. Continue rinsing'],
                ['code' => 'P337+P313', 'text' => 'If eye irritation persists: Get medical advice/attention'],
                ['code' => 'P273', 'text' => 'Avoid release to the environment'],
                ['code' => 'P501', 'text' => 'Dispose of contents/container in accordance with local regulations'],
            ],
            'ppe_recommendations' => [],
            'other_hazards' => 'None known.',
        ],
        3 => [
            'title' => 'Composition / Information on Ingredients',
            'substance_or_mixture' => 'Mixture',
            'components' => [
                ['cas_number' => '57472-68-1', 'chemical_name' => 'Dipropylene Glycol Diacrylate (DPGDA)', 'concentration_pct' => 39.0, 'concentration_range' => '35 - 40%'],
                ['cas_number' => '15206-55-0', 'chemical_name' => 'Trimethylolpropane Triacrylate (TMPTA)', 'concentration_pct' => 28.5, 'concentration_range' => '25 - 30%'],
                ['cas_number' => '75980-60-8', 'chemical_name' => 'Diphenyl(2,4,6-trimethylbenzoyl)phosphine oxide (TPO)', 'concentration_pct' => 4.8, 'concentration_range' => '4 - 5%'],
            ],
            'trade_secret_note' => null,
        ],
        4 => [
            'title' => 'First-Aid Measures',
            'inhalation' => 'Move to fresh air.',
            'skin'       => 'Wash with soap and water.',
            'eyes'       => 'Flush with water for 15 minutes.',
            'ingestion'  => 'Do not induce vomiting. Seek medical attention.',
            'symptoms'   => 'Acute: Causes skin irritation. Causes serious eye irritation.',
            'notes'      => 'Show this SDS to medical personnel.',
        ],
        5 => [
            'title'            => 'Fire-Fighting Measures',
            'suitable_media'   => 'Water spray, dry chemical, CO2, foam.',
            'unsuitable_media' => 'Do not use direct water stream.',
            'specific_hazards' => 'Combustion may produce CO and CO2.',
            'firefighter_advice' => 'Wear SCBA and full protective gear.',
        ],
        6 => [
            'title' => 'Accidental Release Measures',
            'personal_precautions' => 'Use appropriate PPE.',
            'environmental'        => 'Prevent entry into drains.',
            'containment'          => 'Contain spill with inert absorbent.',
        ],
        7 => [
            'title'    => 'Handling and Storage',
            'handling' => 'Use in well-ventilated areas.',
            'storage'  => 'Store in a cool, dry place.',
        ],
        8 => [
            'title'           => 'Exposure Controls / Personal Protection',
            'exposure_limits' => [
                ['cas_number' => '57472-68-1', 'chemical_name' => 'DPGDA', 'limit_type' => 'TWA', 'value' => '10', 'units' => 'mg/m3', 'concentration_pct' => 39.0, 'concentration_range' => '30 - 60%'],
            ],
            'engineering'      => 'Use local exhaust ventilation.',
            'respiratory'      => 'NIOSH-approved respirator if needed.',
            'hand_protection'  => 'Chemical-resistant gloves (nitrile or neoprene).',
            'eye_protection'   => 'Safety glasses with side shields.',
            'skin_protection'  => 'Wear protective clothing.',
        ],
        9 => [
            'title'           => 'Physical and Chemical Properties',
            'appearance'      => 'Yellow viscous liquid',
            'odor'            => 'Mild acrylic',
            'ph'              => 'Not applicable',
            'boiling_point'   => 'Not determined',
            'flash_point'     => '> 200°F (93°C)',
            'specific_gravity' => '1.08',
            'voc_lb_per_gal'  => '0.45',
            'voc_wt_pct'      => '5.2',
            'solids_wt_pct'   => '40.1',
        ],
        10 => [
            'title'            => 'Stability and Reactivity',
            'reactivity'       => 'No dangerous reaction known.',
            'stability'        => 'Stable under recommended conditions.',
            'conditions_avoid' => 'Heat, sparks, open flames.',
            'incompatible'     => 'Strong oxidizers, strong acids.',
            'decomposition'    => 'CO, CO2, toxic gases on thermal decomposition.',
        ],
        11 => [
            'title'           => 'Toxicological Information',
            'acute_toxicity'  => "Acute toxicity (oral): Not classified based on available data.\nAcute toxicity (dermal): Not classified based on available data.\nAcute toxicity (inhalation): Not classified based on available data.",
            'chronic_effects' => 'Prolonged exposure may cause skin drying.',
            'carcinogenicity' => 'No listed carcinogens.',
            'hazard_classes'  => [
                ['class' => 'Skin Irritation', 'category' => 'Category 2'],
            ],
            'component_toxicology' => [
                [
                    'cas_number'        => '57472-68-1',
                    'chemical_name'     => 'DPGDA',
                    'concentration_range' => '35 - 40%',
                    'exposure_limits'   => [
                        ['limit_type' => 'TWA', 'value' => '10', 'units' => 'mg/m3'],
                    ],
                    'carcinogen_listings' => [],
                ],
            ],
            'carcinogen_result' => ['has_carcinogens' => false],
        ],
        12 => [
            'title'      => 'Ecological Information',
            'ecotoxicity'     => 'Classified as hazardous to the aquatic environment by the GHS summation method applied to the component classifications and M-factors listed below (GHS Rev. 7, Chapter 4.1): H411: Toxic to aquatic life with long lasting effects. Avoid release to the environment. Prevent entry into waterways, sewers, and soil.',
            'component_aquatic' => [
                ['cas_number' => '15206-55-0', 'chemical_name' => 'Trimethylolpropane Triacrylate (TMPTA)', 'concentration_range' => '25 - 30%', 'acute' => 'Category 1 (M = 1)', 'chronic' => 'Category 2'],
                ['cas_number' => '57472-68-1', 'chemical_name' => 'Dipropylene Glycol Diacrylate (DPGDA)',  'concentration_range' => '35 - 40%', 'acute' => '',                  'chronic' => 'Category 3'],
            ],
            'persistence'     => 'No data available.',
            'bioaccumulation' => 'No data available.',
            'mobility'        => 'No data available.',
        ],
        13 => [
            'title'   => 'Disposal Considerations',
            'methods' => 'Dispose per local regulations.',
        ],
        14 => [
            'title'                => 'Transport Information',
            'un_number'            => 'Not regulated',
            'proper_shipping_name' => 'Not regulated',
            'hazard_class'         => 'Not regulated',
            'packing_group'        => 'Not applicable',
            'note'                 => 'Verify with carrier.',
        ],
        15 => [
            'title'       => 'Regulatory Information',
            'osha_status' => 'Classified as hazardous under OSHA HazCom.',
            'tsca_status' => 'All components listed on TSCA.',
            'sara_313'    => [
                'reportable' => [],
            ],
            'prop65' => [
                'requires_warning' => false,
                'warning_text'     => '',
                'listed_chemicals' => [],
            ],
            'state_regs' => '',
            'ghs_note'   => 'Sections 12-15 are included as required by 29 CFR 1910.1200(g)(2); content not enforced by OSHA.',
        ],
        16 => [
            'title'        => 'Other Information',
            'version'      => 'Draft (not yet published)',
            'effective_date' => '',
            'revision_note' => '',
            'abbreviations' => 'CAS = Chemical Abstracts Service; GHS = Globally Harmonized System.',
        ],
    ],
    'hazard_result' => [
        'signal_word' => 'Warning',
        'trace'       => [],
    ],
    'voc_result' => [],
    'sara_result' => ['reportable' => []],
    'prop65_result' => ['requires_warning' => false],
    'carcinogen_result' => ['has_carcinogens' => false],
    'warnings' => [],
    'legal_disclaimer' => 'This SDS is provided for informational purposes only.',
];

// --- Run the test ---------------------------------------------------------

$tmpRoot = $basePath . '/tests/tmp';
$tmpDir  = $tmpRoot . '/generate_to_file_' . bin2hex(random_bytes(4));
$target  = $tmpDir . '/nested/TEST-001_SDS_en.pdf';

$cleanup = static function () use ($tmpDir): void {
    if (!is_dir($tmpDir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($tmpDir);
};

$failures = [];

// Backstop for anything that still terminates the process outright (a
// die() or fatal error inside the code under test): shutdown functions run
// after die(), and exit(1) inside one sets the process exit status, so the
// run cannot read as green and never leaves tests/tmp behind.
$done = false;
register_shutdown_function(static function () use (&$done, $cleanup, $tmpRoot): void {
    if ($done) {
        return;
    }
    $cleanup();
    if (is_dir($tmpRoot) && count(scandir($tmpRoot)) === 2) {
        rmdir($tmpRoot);
    }
    fwrite(STDERR, "FAIL: PDFService::generateToFile() aborted before completion (die() or fatal error)\n");
    exit(1);
});

try {
    if (is_dir(dirname($target))) {
        $failures[] = 'precondition: target directory unexpectedly exists: ' . dirname($target);
    }

    $pdfService = new \SDS\Services\PDFService();
    $returned = $pdfService->generateToFile($sdsData, $target);

    if ($returned !== $target) {
        $failures[] = "return value mismatch: expected {$target}, got {$returned}";
    }
    if (!is_file($target)) {
        $failures[] = "file does not exist: {$target}";
    } else {
        $size = filesize($target);
        if ($size === 0) {
            $failures[] = 'file is empty';
        }
        $header = (string) file_get_contents($target, false, null, 0, 4);
        if ($header !== '%PDF') {
            $failures[] = 'invalid PDF header: ' . bin2hex($header);
        }
        $bytes = (string) file_get_contents($target);
        $utf16 = "\xFE\xFF" . implode('', array_map(static fn (string $c): string => "\0" . $c, str_split('Test Co')));
        if (!str_contains($bytes, '/Author (' . $utf16 . ')')) {
            $failures[] = 'PDF /Author must name the Section 1 supplier (Test Co), not config.php';
        }
        if (str_contains($bytes, '/URI (http://www.tcpdf.org)')) {
            $failures[] = 'PDF must not carry the "Powered by TCPDF" link (SDSTcpdf sets $tcpdflink = false, audit #41)';
        }
    }

    // An unwritable target must surface as a catchable exception rather than
    // a TCPDF die(): ReportController::generateAliasPdf() and the auto-send
    // callers rely on their try/catch blocks seeing it. A directory sitting
    // where the file should go makes the write fail deterministically.
    $blocked = $tmpDir . '/blocked.pdf';
    mkdir($blocked, 0775, true);
    try {
        $pdfService->generateToFile($sdsData, $blocked);
        $failures[] = 'unwritable target: expected RuntimeException, none thrown';
    } catch (\RuntimeException) {
        // expected
    }
} catch (\Throwable $e) {
    $failures[] = get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
} finally {
    $cleanup();
    if (is_dir($tmpRoot) && count(scandir($tmpRoot)) === 2) {
        rmdir($tmpRoot);
    }
}

$done = true;

if ($failures) {
    echo "FAIL: PDFService::generateToFile()\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "PASS: PDFService::generateToFile() wrote a non-empty PDF to a new nested directory and threw on an unwritable target\n";
exit(0);
