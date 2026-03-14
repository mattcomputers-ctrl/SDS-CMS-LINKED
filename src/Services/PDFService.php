<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;

/**
 * PDFService — Generates SDS PDF documents using TCPDF.
 *
 * Takes the structured SDS data array from SDSGenerator and renders
 * a multi-page PDF following the standard 16-section GHS format.
 */
class PDFService
{
    /** Standard page margins in mm. */
    private const MARGIN_LEFT   = 15;
    private const MARGIN_TOP    = 30;  // accommodates first-page header with ~2" logo
    private const MARGIN_RIGHT  = 15;
    private const MARGIN_BOTTOM = 20;

    /**
     * Map data-array field keys to label keys for the generic section renderer.
     * Keys not listed here fall back to ucwords(str_replace('_', ' ', $key)).
     */
    private const FIELD_LABEL_MAP = [
        // Section 4
        'inhalation'           => 'inhalation',
        'skin'                 => 'skin_contact',
        'eyes'                 => 'eye_contact',
        'ingestion'            => 'ingestion',
        'notes'                => 'notes_to_physician',
        // Section 5
        'suitable_media'       => 'suitable_media',
        'unsuitable_media'     => 'unsuitable_media',
        'specific_hazards'     => 'specific_hazards',
        'firefighter_advice'   => 'firefighter_advice',
        // Section 6
        'personal_precautions' => 'personal_precautions',
        'environmental'        => 'environmental_precautions',
        'containment'          => 'containment_cleanup',
        // Section 7
        'handling'             => 'handling',
        'storage'              => 'storage',
        // Section 10
        'reactivity'           => 'reactivity',
        'stability'            => 'chemical_stability',
        'conditions_avoid'     => 'conditions_avoid',
        'incompatible'         => 'incompatible_materials',
        'decomposition'        => 'decomposition_products',
        // Section 12
        'ecotoxicity'          => 'ecotoxicity',
        'persistence'          => 'persistence',
        'bioaccumulation'      => 'bioaccumulation',
        'note'                 => 'note',
        // Section 13
        'methods'              => 'disposal_methods',
        // Section 16
        'revision_date'        => 'revision_date',
        'abbreviations'        => 'abbreviations',
        'disclaimer'           => 'disclaimer',
    ];

    /** @var array Translated labels for PDF field names */
    private array $labels = [];

    /** @var array Translated document-level strings */
    private array $document = [];

    /** @var string Language code for GHS translations */
    private string $language = 'en';

    /**
     * Generate a PDF from SDS data and return the file path.
     *
     * @param  array  $sdsData     Full SDS data from SDSGenerator::generate()
     * @param  string $outputDir   Directory to save PDF (defaults to config)
     * @return string              Absolute path to generated PDF file
     */
    public function generate(array $sdsData, ?string $outputDir = null): string
    {
        if (!class_exists('TCPDF')) {
            throw new \RuntimeException('TCPDF library not found. Run: composer require tecnickcom/tcpdf');
        }

        $outputDir = $outputDir ?? App::config('paths.generated_pdfs', App::basePath() . '/public/generated-pdfs');

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $meta = $sdsData['meta'];
        $sections = $sdsData['sections'];
        $this->labels = $meta['labels'] ?? [];
        $this->language = $meta['language'] ?? 'en';
        $this->document = $meta['document'] ?? [];

        // Create PDF using custom subclass that handles absolute logo paths
        $pdf = new SDSTcpdf('P', 'mm', 'LETTER', true, 'UTF-8');

        // Document metadata
        $pdf->SetCreator('SDS System');
        $pdf->SetAuthor(App::config('company.name', 'SDS System'));
        $pdf->SetTitle('SDS - ' . $meta['product_code']);
        $pdf->SetSubject('Safety Data Sheet');

        // Page settings
        $pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $pdf->SetAutoPageBreak(true, self::MARGIN_BOTTOM);
        $pdf->setHeaderFont(['helvetica', '', 8]);
        $pdf->setFooterFont(['helvetica', '', 8]);

        // Header — logo + translated document title on first page only
        $logoFile = $this->resolveLogoPath($meta['company_logo_path'] ?? '');
        $pdf->setAbsoluteLogoPath($logoFile);
        $pdf->SetHeaderMargin(5);
        $pdf->setDocumentStrings($this->document);

        // Footer — product code, page number, revision date on every page
        $revisionDate = $sections[16]['revision_date'] ?? date('m/d/Y');
        $pdf->setFooterInfo($meta['product_code'], $revisionDate);

        // Add first page
        $pdf->AddPage();

        // Render each section
        foreach ($sections as $num => $section) {
            $this->renderSection($pdf, $num, $section);
        }

        // Render legal disclaimer after all sections
        $this->renderLegalDisclaimer($pdf, $sdsData['legal_disclaimer'] ?? '');

        // Save to file
        $filename = sanitize_filename(strip_pack_extension($meta['product_code'])) . '_SDS_' . $meta['language'] . '_' . date('Ymd_His') . '.pdf';
        $filepath = $outputDir . '/' . $filename;

        $pdf->Output($filepath, 'F');

        return $filepath;
    }

    /**
     * Generate PDF and return as string (for streaming download).
     */
    public function generateString(array $sdsData): string
    {
        if (!class_exists('TCPDF')) {
            throw new \RuntimeException('TCPDF library not found.');
        }

        $meta = $sdsData['meta'];
        $sections = $sdsData['sections'];
        $this->labels = $meta['labels'] ?? [];
        $this->language = $meta['language'] ?? 'en';
        $this->document = $meta['document'] ?? [];

        $pdf = new SDSTcpdf('P', 'mm', 'LETTER', true, 'UTF-8');
        $pdf->SetCreator('SDS System');
        $pdf->SetAuthor(App::config('company.name', 'SDS System'));
        $pdf->SetTitle('SDS - ' . $meta['product_code']);
        $pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $pdf->SetAutoPageBreak(true, self::MARGIN_BOTTOM);

        // Header — logo + translated document title on first page only
        $logoFile = $this->resolveLogoPath($meta['company_logo_path'] ?? '');
        $pdf->setAbsoluteLogoPath($logoFile);
        $pdf->SetHeaderMargin(5);
        $pdf->setDocumentStrings($this->document);

        // Footer — product code, page number, revision date on every page
        $revisionDate = $sections[16]['revision_date'] ?? date('m/d/Y');
        $pdf->setFooterInfo($meta['product_code'], $revisionDate);

        $pdf->AddPage();

        foreach ($sections as $num => $section) {
            $this->renderSection($pdf, $num, $section);
        }

        $this->renderLegalDisclaimer($pdf, $sdsData['legal_disclaimer'] ?? '');

        return $pdf->Output('', 'S');
    }

    /**
     * Render a single SDS section to the PDF.
     */
    private function renderSection(\TCPDF $pdf, int $sectionNum, array $section): void
    {
        $title = $section['title'] ?? "Section {$sectionNum}";
        $sectionPrefix = $this->document['section_prefix'] ?? 'SECTION';

        // Section header
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(0, 51, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 7, strtoupper($sectionPrefix) . " {$sectionNum}: " . strtoupper($title), 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', '', 9);

        switch ($sectionNum) {
            case 1:
                $this->renderSection1($pdf, $section);
                break;
            case 2:
                $this->renderSection2($pdf, $section);
                break;
            case 3:
                $this->renderSection3($pdf, $section);
                break;
            case 8:
                $this->renderSection8($pdf, $section);
                break;
            case 9:
                $this->renderSection9($pdf, $section);
                break;
            case 11:
                $this->renderSection11($pdf, $section);
                break;
            case 14:
                $this->renderSection14($pdf, $section);
                break;
            case 15:
                $this->renderSection15($pdf, $section);
                break;
            default:
                $this->renderGenericSection($pdf, $section);
                break;
        }

        $pdf->Ln(4);
    }

    private function renderSection1(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, $this->label('product_identifier'), $s['product_identifier'] ?? '');
        $this->labelValue($pdf, $this->label('product_family'), $s['product_family'] ?? '');
        $this->labelValue($pdf, $this->label('recommended_use'), $s['recommended_use'] ?? '');
        $this->labelValue($pdf, $this->label('restrictions'), $s['restrictions'] ?? '');
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, $this->label('manufacturer_info'), 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $this->labelValue($pdf, $this->label('company'), $s['manufacturer_name'] ?? '');
        $this->labelValue($pdf, $this->label('address'), $s['manufacturer_address'] ?? '');
        $this->labelValue($pdf, $this->label('phone'), $s['manufacturer_phone'] ?? '');
        $this->labelValue($pdf, $this->label('emergency'), $s['emergency_phone'] ?? '');
    }

    private function renderSection2(\TCPDF $pdf, array $s): void
    {
        // Section 2 can be very tall (signal word, pictograms, hazard
        // classes, H/P statements, PPE).  If less than ~30 mm remain on
        // the current page the content would split awkwardly — start a
        // fresh page instead.
        $pageH   = $pdf->getPageHeight();
        $bMargin = $pdf->getBreakMargin();
        if (($pageH - $bMargin - $pdf->GetY()) < 30) {
            $pdf->AddPage();
        }

        // Signal word
        if (!empty($s['signal_word'])) {
            $pdf->SetFont('helvetica', 'B', 14);
            $color = ($s['signal_word_en'] ?? $s['signal_word']) === 'Danger' ? [220, 0, 0] : [255, 140, 0];
            $pdf->SetTextColor(...$color);
            $pdf->Cell(0, 7, strtoupper($s['signal_word']), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 9);
        }

        // Pictograms — render as images if available, else text codes with names
        if (!empty($s['pictograms'])) {
            $this->renderPictogramRow($pdf, $s['pictograms']);
            $pdf->Ln(2);
        }

        // Hazard classes summary
        if (!empty($s['hazard_classes'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('ghs_classification') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $seen = [];
            foreach ($s['hazard_classes'] as $hc) {
                $class = trim($hc['class_translated'] ?? $hc['class'] ?? '');
                $category = trim($hc['category_translated'] ?? $hc['category'] ?? '');
                if ($class !== '' && $category !== '') {
                    $label = $class . ' (' . $category . ')';
                } elseif ($class !== '') {
                    $label = $class;
                } else {
                    $label = $category;
                }
                if ($label !== '' && !isset($seen[$label])) {
                    $seen[$label] = true;
                    $pdf->MultiCell(0, 4, "\xE2\x80\xA2 " . $label, 0, 'L');
                }
            }
            $pdf->Ln(1);
        }

        // Hazard statements
        if (!empty($s['h_statements'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('hazard_statements') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            foreach ($s['h_statements'] as $stmt) {
                $code = $stmt['code'] ?? '';
                $text = $stmt['text'] ?? '';
                $line = $code;
                if ($text !== '') {
                    $line .= ': ' . $text;
                }
                $pdf->MultiCell(0, 4, $line, 0, 'L');
            }
            $pdf->Ln(1);
        }

        // Precautionary statements
        if (!empty($s['p_statements'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('precautionary_statements') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            foreach ($s['p_statements'] as $stmt) {
                $code = $stmt['code'] ?? '';
                $text = $stmt['text'] ?? '';
                $line = $code;
                if ($text !== '') {
                    $line .= ': ' . $text;
                }
                $pdf->MultiCell(0, 4, $line, 0, 'L');
            }
        }

        // PPE Recommendations derived from H/P codes — with pictograms
        $ppe = $s['ppe_recommendations'] ?? [];
        $hasPPE = !empty($ppe['respiratory']) || !empty($ppe['hand_protection'])
               || !empty($ppe['eye_protection']) || !empty($ppe['skin_protection']);
        if ($hasPPE) {
            $pdf->Ln(1);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('ppe_recommendations') . ':', 0, 1);

            // Render PPE pictogram table (with descriptions under each pictogram)
            $this->renderPPEPictogramRow($pdf, $ppe);
        }

        // Other hazards — only show if a custom override was provided
        if (!empty($s['has_other_hazards'])) {
            $pdf->Ln(1);
            $this->labelValue($pdf, $this->label('other_hazards'), $s['other_hazards'] ?? '');
        }
    }

    /**
     * Render a row of GHS pictogram images in the PDF.
     * Uses PNG images generated by PictogramHelper for reliable TCPDF rendering.
     */
    private function renderPictogramRow(\TCPDF $pdf, array $pictogramCodes): void
    {
        $pictoSize = 14; // mm
        $colWidth  = 22; // mm per column
        $neededHeight = $pictoSize + 5 + 4; // image + label + padding

        // Ensure enough space on the current page for the pictogram row;
        // Image() with absolute coords bypasses auto page break, so images
        // placed near the bottom of a page would be clipped or land in the
        // footer area while subsequent text jumps to the next page.
        $pageH   = $pdf->getPageHeight();
        $bMargin = $pdf->getBreakMargin();
        $availableY = $pageH - $bMargin - $pdf->GetY();
        if ($availableY < $neededHeight) {
            $pdf->AddPage();
        }

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, $this->label('pictograms') . ':', 0, 1, 'L');

        $startX    = $pdf->GetX();
        $startY    = $pdf->GetY();

        // Filter to codes that have valid PNGs
        $validCodes = [];
        foreach ($pictogramCodes as $code) {
            if (PictogramHelper::getPngPath($code) !== '') {
                $validCodes[] = $code;
            }
        }

        if (empty($validCodes)) {
            $pdf->SetFont('helvetica', '', 9);
            $labels = [];
            foreach ($pictogramCodes as $code) {
                $labels[] = $code . ' (' . GHSStatements::pictogramName($code, $this->language) . ')';
            }
            $pdf->MultiCell(0, 5, implode(', ', $labels), 0, 'L');
            return;
        }

        // Row 1: render pictogram images centered within each column
        $imgOffset = ($colWidth - $pictoSize) / 2;
        $x = $startX;
        foreach ($validCodes as $code) {
            $pngPath = PictogramHelper::getPngPath($code);
            $pdf->Image($pngPath, $x + $imgOffset, $startY, $pictoSize, $pictoSize, 'PNG');
            $x += $colWidth;
        }

        // Row 2: render descriptions centered under each pictogram
        $labelY = $startY + $pictoSize + 1;
        $pdf->SetFont('helvetica', '', 6);
        $x = $startX;
        foreach ($validCodes as $code) {
            $name = GHSStatements::pictogramName($code, $this->language);
            $pdf->SetXY($x, $labelY);
            $pdf->Cell($colWidth, 3, $name, 0, 0, 'C');
            $x += $colWidth;
        }

        $pdf->SetY($labelY + 4);
        $pdf->SetFont('helvetica', '', 9);
    }

    /**
     * Render PPE pictograms (blue circles) for the PPE types that are present.
     */
    private function renderPPEPictogramRow(\TCPDF $pdf, array $ppe): void
    {
        $ppeMap = [
            'eye_protection'  => ['code' => 'PPE-eye',         'labelKey' => 'ppe_wear_eye'],
            'hand_protection' => ['code' => 'PPE-hand',        'labelKey' => 'ppe_wear_gloves'],
            'respiratory'     => ['code' => 'PPE-respiratory',  'labelKey' => 'ppe_wear_respiratory'],
            'skin_protection' => ['code' => 'PPE-skin',        'labelKey' => 'ppe_wear_skin'],
        ];

        $pictoSize = 12; // mm
        $colWidth  = 28; // mm per column (wider for longer PPE labels)

        // Collect active PPE items
        $active = [];
        foreach ($ppeMap as $field => $info) {
            if (empty($ppe[$field])) {
                continue;
            }
            $pngPath = PictogramHelper::getPngPath($info['code']);
            if ($pngPath !== '') {
                $active[] = ['png' => $pngPath, 'label' => $this->label($info['labelKey'])];
            }
        }

        if (empty($active)) {
            return;
        }

        // Ensure enough space for PPE images + labels before rendering;
        // Image() with absolute coords bypasses auto page break.
        $neededHeight = $pictoSize + 5 + 4;
        $pageH   = $pdf->getPageHeight();
        $bMargin = $pdf->getBreakMargin();
        $availableY = $pageH - $bMargin - $pdf->GetY();
        if ($availableY < $neededHeight) {
            $pdf->AddPage();
        }

        $startX = $pdf->GetX();
        $startY = $pdf->GetY();

        // Row 1: render pictogram images centered within each column
        $imgOffset = ($colWidth - $pictoSize) / 2;
        $x = $startX;
        foreach ($active as $item) {
            $pdf->Image($item['png'], $x + $imgOffset, $startY, $pictoSize, $pictoSize, 'PNG');
            $x += $colWidth;
        }

        // Row 2: render descriptions centered under each pictogram
        $labelY = $startY + $pictoSize + 1;
        $pdf->SetFont('helvetica', '', 6);
        $x = $startX;
        foreach ($active as $item) {
            $pdf->SetXY($x, $labelY);
            $pdf->Cell($colWidth, 3, $item['label'], 0, 0, 'C');
            $x += $colWidth;
        }

        $pdf->SetY($labelY + 4);
        $pdf->SetFont('helvetica', '', 9);
    }

    private function renderSection3(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, $this->label('type'), $s['substance_or_mixture'] ?? $this->label('mixture'));

        if (!empty($s['components'])) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->MultiCell(0, 4, $this->label('hazardous_only_note'), 0, 'L');
            $pdf->Ln(1);

            // Table header
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(230, 230, 230);
            $w = [30, 95, 45];
            $pdf->Cell($w[0], 5, $this->label('cas_number'), 1, 0, 'C', true);
            $pdf->Cell($w[1], 5, $this->label('chemical_name'), 1, 0, 'C', true);
            $pdf->Cell($w[2], 5, $this->label('concentration'), 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 8);

            foreach ($s['components'] as $comp) {
                $pdf->Cell($w[0], 5, $comp['cas_number'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[1], 5, $comp['chemical_name'] ?? '', 1, 0, 'L');
                $pdf->Cell($w[2], 5, $comp['concentration_range'] ?? '', 1, 1, 'C');
            }
        } else {
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->MultiCell(0, 4, $this->label('no_hazardous_note'), 0, 'L');
        }

        // Trade secret / concentration withheld statement
        if (!empty($s['trade_secret_note'])) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->MultiCell(0, 4, $s['trade_secret_note'], 0, 'L');
        }
    }

    private function renderSection8(\TCPDF $pdf, array $s): void
    {
        if (!empty($s['exposure_limits'])) {
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetFillColor(230, 230, 230);
            $w = [22, 42, 22, 20, 17, 15, 37];
            $pdf->Cell($w[0], 5, $this->label('el_cas'), 1, 0, 'C', true);
            $pdf->Cell($w[1], 5, $this->label('el_chemical'), 1, 0, 'C', true);
            $pdf->Cell($w[2], 5, $this->label('el_type'), 1, 0, 'C', true);
            $pdf->Cell($w[3], 5, $this->label('el_value'), 1, 0, 'C', true);
            $pdf->Cell($w[4], 5, $this->label('el_units'), 1, 0, 'C', true);
            $pdf->Cell($w[5], 5, $this->label('el_conc_pct'), 1, 0, 'C', true);
            $pdf->Cell($w[6], 5, $this->label('el_notes'), 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 7);

            foreach ($s['exposure_limits'] as $el) {
                $pdf->Cell($w[0], 5, $el['cas_number'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[1], 5, substr($el['chemical_name'] ?? '', 0, 28), 1, 0, 'L');
                $pdf->Cell($w[2], 5, $el['limit_type'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[3], 5, $el['value'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[4], 5, $el['units'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[5], 5, round((float) ($el['concentration_pct'] ?? 0), 2), 1, 0, 'C');
                $pdf->Cell($w[6], 5, substr($el['notes'] ?? '', 0, 25), 1, 1, 'L');
            }
            $pdf->Ln(2);
        }

        $this->labelValue($pdf, $this->label('engineering_controls'), $s['engineering'] ?? '');
        $this->labelValue($pdf, $this->label('respiratory_protection'), $s['respiratory'] ?? '');
        $this->labelValue($pdf, $this->label('hand_protection'), $s['hand_protection'] ?? '');
        $this->labelValue($pdf, $this->label('eye_protection'), $s['eye_protection'] ?? '');
        $this->labelValue($pdf, $this->label('skin_protection'), $s['skin_protection'] ?? '');
    }

    private function renderSection9(\TCPDF $pdf, array $s): void
    {
        $props = [
            'physical_state'    => $s['physical_state'] ?? '',
            'color'             => $s['color'] ?? '',
            'appearance'        => $s['appearance'] ?? '',
            'odor'              => $s['odor'] ?? '',
            'boiling_point'     => $s['boiling_point'] ?? '',
            'flash_point'       => $s['flash_point'] ?? '',
            'solubility'        => $s['solubility'] ?? '',
            'specific_gravity'  => $s['specific_gravity'] ?? '',
            'voc_lb_gal'        => $s['voc_lb_per_gal'] ?? '',
            'voc_less_we'       => $s['voc_less_water_exempt'] ?? '',
            'voc_wt_pct'        => $s['voc_wt_pct'] ?? '',
            'solids_wt_pct'     => $s['solids_wt_pct'] ?? '',
            'solids_vol_pct'    => $s['solids_vol_pct'] ?? '',
        ];

        foreach ($props as $labelKey => $value) {
            $this->labelValue($pdf, $this->label($labelKey), (string) $value);
        }
    }

    private function renderSection11(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, $this->label('acute_toxicity'), $s['acute_toxicity'] ?? '');
        $this->labelValue($pdf, $this->label('chronic_effects'), $s['chronic_effects'] ?? '');

        // Carcinogenicity
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, $this->label('carcinogenicity') . ':', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $carcinogenText = $s['carcinogenicity'] ?? '';
        if ($carcinogenText !== '') {
            $pdf->MultiCell(0, 4, $carcinogenText, 0, 'L');
        }

        // Component-level toxicology table
        $componentTox = $s['component_toxicology'] ?? [];
        if (!empty($componentTox)) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('component_tox_data') . ':', 0, 1);

            foreach ($componentTox as $comp) {
                $pdf->SetFont('helvetica', 'B', 8);
                $label = ($comp['chemical_name'] ?? '') . ' (CAS ' . ($comp['cas_number'] ?? '') . ') — '
                       . round((float) ($comp['concentration_pct'] ?? 0), 2) . '%';
                $pdf->Cell(0, 5, $label, 0, 1);
                $pdf->SetFont('helvetica', '', 8);

                // Carcinogen listings
                if (!empty($comp['carcinogen_listings'])) {
                    foreach ($comp['carcinogen_listings'] as $listing) {
                        $pdf->Cell(5, 4, '', 0, 0);
                        $pdf->MultiCell(0, 4, $listing['agency'] . ': ' . $listing['classification'], 0, 'L');
                    }
                }

                // Exposure limits for this component
                if (!empty($comp['exposure_limits'])) {
                    foreach ($comp['exposure_limits'] as $el) {
                        $pdf->Cell(5, 4, '', 0, 0);
                        $limitText = ($el['limit_type'] ?? '') . ': ' . ($el['value'] ?? '') . ' ' . ($el['units'] ?? '');
                        if (!empty($el['notes'])) {
                            $limitText .= ' (' . $el['notes'] . ')';
                        }
                        $pdf->MultiCell(0, 4, $limitText, 0, 'L');
                    }
                }
            }
        }

        // Pictograms are intentionally NOT shown in Section 11;
        // they appear in Section 2 (Hazard Identification) only.
    }

    private function renderSection14(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, $this->label('un_number'), $s['un_number'] ?? '');
        $this->labelValue($pdf, $this->label('proper_shipping_name'), $s['proper_shipping_name'] ?? '');
        $this->labelValue($pdf, $this->label('transport_hazard_class'), $s['hazard_class'] ?? '');
        $this->labelValue($pdf, $this->label('packing_group'), $s['packing_group'] ?? '');
    }

    private function renderSection15(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, $this->label('osha_status'), $s['osha_status'] ?? '');
        $this->labelValue($pdf, $this->label('tsca_status'), $s['tsca_status'] ?? '');

        // SARA 313 data
        $sara = $s['sara_313'] ?? [];
        if (!empty($sara['listed_chemicals'] ?? [])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('sara_313_title') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 8);
            foreach ($sara['listed_chemicals'] as $chem) {
                $text = ($chem['chemical_name'] ?? '') . ' (CAS ' . ($chem['cas_number'] ?? '') . ') — '
                      . round((float) ($chem['concentration_pct'] ?? 0), 2) . '% (de minimis: '
                      . ($chem['deminimis_pct'] ?? '1.0') . '%)';
                $pdf->MultiCell(0, 4, "\xE2\x80\xA2 " . $text, 0, 'L');
            }
            $pdf->Ln(2);
        }

        // Hazardous Air Pollutants (HAPs)
        $hap = $s['hap'] ?? [];
        if (!empty($hap['has_haps'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('hap_title') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 8);

            // Table header
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetFillColor(230, 230, 230);
            $wHap = [100, 40];
            $pdf->Cell($wHap[0], 5, $this->label('hap_triggering'), 1, 0, 'C', true);
            $pdf->Cell($wHap[1], 5, $this->label('hap_wt_pct'), 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 7);

            foreach ($hap['hap_chemicals'] as $chem) {
                $hapName = $chem['hap_name'] ?? $chem['chemical_name'] ?? '';
                $concPct = number_format((float) ($chem['concentration_pct'] ?? 0), 2);
                $pdf->Cell($wHap[0], 5, substr($hapName, 0, 65), 1, 0, 'L');
                $pdf->Cell($wHap[1], 5, $concPct . '%', 1, 1, 'C');
            }

            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell($wHap[0], 5, $this->label('hap_total') . ':', 1, 0, 'R');
            $pdf->Cell($wHap[1], 5, number_format((float) ($hap['total_hap_pct'] ?? 0), 2) . '%', 1, 1, 'C');
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Ln(2);
        } elseif (isset($hap['has_haps'])) {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->MultiCell(0, 4, $this->label('hap_none'), 0, 'L');
            $pdf->Ln(2);
        }

        // SNUR (Significant New Use Rules)
        $snur = $s['snur'] ?? [];
        if (!empty($snur['has_snur'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('snur_title') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 8);
            foreach ($snur['listed_chemicals'] as $chem) {
                $text = ($chem['chemical_name'] ?? '') . ' (CAS ' . ($chem['cas_number'] ?? '') . ')';
                if (!empty($chem['rule_citation'])) {
                    $text .= ' — ' . $chem['rule_citation'];
                }
                $pdf->MultiCell(0, 4, "\xE2\x80\xA2 " . $text, 0, 'L');
                if (!empty($chem['description'])) {
                    $pdf->SetFont('helvetica', 'I', 7);
                    $pdf->Cell(5, 4, '', 0, 0);
                    $pdf->MultiCell(0, 3, $chem['description'], 0, 'L');
                    $pdf->SetFont('helvetica', '', 8);
                }
            }
            $pdf->Ln(2);
        }

        // California Prop 65
        $prop65 = $s['prop65'] ?? [];
        if (!empty($prop65['requires_warning'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('prop65_title') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 8);

            // Warning pictogram + warning text
            $prop65Png = PictogramHelper::getPngPath('PROP65');
            // Ensure enough space for the pictogram + warning text
            $pageH   = $pdf->getPageHeight();
            $bMargin = $pdf->getBreakMargin();
            if (($pageH - $bMargin - $pdf->GetY()) < 15) {
                $pdf->AddPage();
            }
            $imgStartY = $pdf->GetY();
            if ($prop65Png !== '') {
                $pdf->Image($prop65Png, $pdf->GetX(), $imgStartY, 10, 10, 'PNG');
                $pdf->SetX($pdf->GetX() + 12);
            }

            $pdf->MultiCell(0, 4, $prop65['warning_text'] ?? '', 0, 'L');

            // Ensure cursor is below the pictogram image (10mm) + padding
            $minY = $imgStartY + 12;
            if ($pdf->GetY() < $minY) {
                $pdf->SetY($minY);
            }
            $pdf->Ln(1);
        } else {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('prop65_title') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->MultiCell(0, 4, $this->label('prop65_none'), 0, 'L');
            $pdf->Ln(1);
        }

        // State regulations override text
        if (!empty($s['state_regs']) && empty($prop65['requires_warning'])) {
            $this->labelValue($pdf, $this->label('state_regulations'), $s['state_regs']);
        }

        // Note
        if (!empty($s['note'])) {
            $pdf->SetFont('helvetica', 'I', 7);
            $pdf->MultiCell(0, 3, $s['note'], 0, 'L');
        }
    }

    private function renderGenericSection(\TCPDF $pdf, array $section): void
    {
        foreach ($section as $key => $value) {
            if ($key === 'title' || $key === 'has_other_hazards' || $key === 'uv_acrylate_note') {
                continue;
            }
            if (is_string($value) && $value !== '') {
                $labelKey = self::FIELD_LABEL_MAP[$key] ?? null;
                $label = $labelKey !== null
                    ? $this->label($labelKey)
                    : ucwords(str_replace('_', ' ', $key));
                $this->labelValue($pdf, $label, $value);
            }
        }
    }

    /**
     * Get a translated label, falling back to the key itself.
     */
    private function label(string $key, string $default = ''): string
    {
        return $this->labels[$key] ?? ($default ?: $key);
    }

    /**
     * Render a bold label + normal value line.
     */
    private function labelValue(\TCPDF $pdf, string $label, string $value): void
    {
        if ($value === '') {
            return;
        }
        $pdf->SetFont('helvetica', 'B', 9);
        $labelText = $label . ':';
        $defaultWidth = 50;
        $neededWidth = $pdf->GetStringWidth($labelText) + 2;

        if ($neededWidth > $defaultWidth) {
            // Label too long for inline layout — place value on next line
            $pdf->Cell(0, 5, $labelText, 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(0, 5, $value, 0, 'L');
        } else {
            $pdf->Cell($defaultWidth, 5, $labelText, 0, 0, 'L', false, '', 0, false, 'T', 'T');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(0, 5, $value, 0, 'L');
        }
    }

    /**
     * Resolve a web-relative logo path to an absolute filesystem path.
     * Returns empty string if the file doesn't exist.
     */
    private function resolveLogoPath(string $webPath): string
    {
        if ($webPath === '') {
            return '';
        }
        $absPath = App::basePath() . '/public' . $webPath;
        return file_exists($absPath) ? $absPath : '';
    }

    /**
     * Render the legal disclaimer block after the last section.
     */
    private function renderLegalDisclaimer(\TCPDF $pdf, string $disclaimer): void
    {
        if ($disclaimer === '') {
            return;
        }

        $pdf->Ln(6);

        // Header bar
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(0, 51, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 7, $this->label('disclaimer'), 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);

        // Body text
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell(0, 4, $disclaimer, 0, 'L');
    }
}
