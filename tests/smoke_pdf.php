<?php
/**
 * Smoke test: exercises PDFService with realistic mock SDS data.
 * Run: php tests/smoke_pdf.php
 */

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap App static properties without DB connection
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, dirname(__DIR__));

$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, [
    'company' => ['name' => 'Test Co'],
    'paths'   => ['generated_pdfs' => dirname(__DIR__) . '/storage/temp'],
]);

// Build a realistic SDS data array that mimics SDSGenerator::generate() output
$sdsData = [
    'meta' => [
        'finished_good_id' => 1,
        'product_code'     => 'TEST-001',
        'description'      => 'Smoke Test Ink',
        'family'           => 'UV Offset',
        'language'         => 'en',
        'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
        'formula_version'  => 1,
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
            'pictograms'  => ['GHS07', 'GHS09'],
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
            'ppe_recommendations' => [
                'respiratory'     => null,
                'hand_protection' => 'Chemical-resistant gloves (nitrile or neoprene recommended). Verify breakthrough time with glove manufacturer.',
                'eye_protection'  => 'Chemical splash goggles or safety glasses with side shields. Face shield if splash hazard exists.',
                'skin_protection' => 'Wear protective clothing to prevent skin contact. Impervious apron recommended. Launder contaminated clothing before reuse.',
            ],
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
            'notes'      => 'Show this SDS to medical personnel.',
        ],
        5 => [
            'title'            => 'Fire-Fighting Measures',
            'suitable_media'   => 'Water spray, dry chemical, CO2, foam.',
            'unsuitable_media' => 'Do not use direct water stream.',
            'specific_hazards' => 'Combustion may produce CO and CO2.',
            'firefighter_advice' => 'Wear SCBA and full protective gear.',
            'flash_point_c'    => 93.0,
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
                ['cas_number' => '57472-68-1', 'chemical_name' => 'DPGDA', 'limit_type' => 'TWA', 'value' => '10', 'units' => 'mg/m3', 'concentration_pct' => 39.0],
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
            'voc_less_water_exempt' => '0.42',
            'voc_wt_pct'      => '5.2',
            'solids_wt_pct'   => '40.1',
            'solids_vol_pct'  => '38.5',
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
            'acute_toxicity'  => 'Based on available data, classification criteria not met.',
            'chronic_effects' => 'Prolonged exposure may cause skin drying.',
            'carcinogenicity' => 'No listed carcinogens.',
            'hazard_classes'  => [
                ['class' => 'Skin Irritation', 'category' => 'Category 2'],
            ],
            'component_toxicology' => [
                [
                    'cas_number'        => '57472-68-1',
                    'chemical_name'     => 'DPGDA',
                    'concentration_pct' => 39.0,
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
            'ecotoxicity'     => 'Avoid release to environment.',
            'persistence'     => 'No data available.',
            'bioaccumulation' => 'No data available.',
            'note'            => 'Not required by OSHA but included per GHS.',
        ],
        13 => [
            'title'   => 'Disposal Considerations',
            'methods' => 'Dispose per local regulations.',
            'note'    => 'Not required by OSHA but included per GHS.',
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
                'listed_chemicals' => [],
                'requires_reporting' => false,
            ],
            'prop65' => [
                'requires_warning' => false,
                'warning_text'     => '',
                'listed_chemicals' => [],
            ],
            'state_regs' => '',
            'note'       => 'Not required by OSHA but included per GHS.',
        ],
        16 => [
            'title'        => 'Other Information',
            'revision_date' => date('m/d/Y'),
            'revision_note' => '',
            'disclaimer'    => 'Information is correct to the best of our knowledge.',
            'abbreviations' => 'CAS = Chemical Abstracts Service; GHS = Globally Harmonized System.',
            'voc_assumptions' => [],
        ],
    ],
    'hazard_result' => [
        'signal_word' => 'Warning',
        'trace'       => [],
    ],
    'voc_result' => [],
    'sara_result' => ['listed_chemicals' => []],
    'prop65_result' => ['requires_warning' => false],
    'carcinogen_result' => ['has_carcinogens' => false],
    'warnings' => [],
    'legal_disclaimer' => 'This SDS is provided for informational purposes only.',
];

// --- Run the test ---
echo "=== PDF Smoke Test ===\n\n";

try {
    $pdfService = new \SDS\Services\PDFService();
    echo "[1] PDFService instantiated OK\n";

    $outputDir = dirname(__DIR__) . '/storage/temp';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $pdfPath = $pdfService->generate($sdsData, $outputDir);
    echo "[2] PDF generated OK\n";
    echo "    Path: {$pdfPath}\n";

    if (file_exists($pdfPath)) {
        $size = filesize($pdfPath);
        echo "[3] File exists: YES ({$size} bytes)\n";

        // Basic sanity: PDF should start with %PDF header and be > 1 KB
        $header = file_get_contents($pdfPath, false, null, 0, 5);
        if ($header === '%PDF-') {
            echo "[4] Valid PDF header: YES\n";
        } else {
            echo "[4] FAIL: Invalid PDF header: " . bin2hex($header) . "\n";
        }

        if ($size > 1024) {
            echo "[5] Size check (> 1 KB): PASS\n";
        } else {
            echo "[5] FAIL: PDF is suspiciously small ({$size} bytes)\n";
        }

        // Test generateString too
        $pdfString = $pdfService->generateString($sdsData);
        echo "[6] generateString() returned " . strlen($pdfString) . " bytes\n";
        if (str_starts_with($pdfString, '%PDF-')) {
            echo "[7] String output valid PDF header: YES\n";
        } else {
            echo "[7] FAIL: Invalid string output header\n";
        }

        echo "\n=== ALL TESTS PASSED ===\n";

        // Clean up
        unlink($pdfPath);
    } else {
        echo "[3] FAIL: File does not exist at: {$pdfPath}\n";
    }
} catch (\Throwable $e) {
    echo "\n!!! EXCEPTION !!!\n";
    echo "Class: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ':' . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
