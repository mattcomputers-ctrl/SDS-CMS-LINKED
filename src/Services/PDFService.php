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
    private const MARGIN_TOP    = 20;
    private const MARGIN_RIGHT  = 15;
    private const MARGIN_BOTTOM = 20;

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

        // Create PDF
        $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8');

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

        // Custom header — include logo if available
        $logoFile = $this->resolveLogoPath($meta['company_logo_path'] ?? '');
        if ($logoFile !== '') {
            $pdf->SetHeaderData($logoFile, 18, 'SAFETY DATA SHEET', $meta['product_code'] . ' — ' . ($meta['description'] ?? ''));
        } else {
            $pdf->SetHeaderData('', 0, 'SAFETY DATA SHEET', $meta['product_code'] . ' — ' . ($meta['description'] ?? ''));
        }
        $pdf->setFooterData([0, 0, 0], [0, 0, 0]);

        // Add first page
        $pdf->AddPage();

        // Render each section
        foreach ($sections as $num => $section) {
            $this->renderSection($pdf, $num, $section);
        }

        // Render legal disclaimer after all sections
        $this->renderLegalDisclaimer($pdf, $sdsData['legal_disclaimer'] ?? '');

        // Save to file
        $filename = sanitize_filename($meta['product_code']) . '_SDS_' . $meta['language'] . '_' . date('Ymd_His') . '.pdf';
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

        $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8');
        $pdf->SetCreator('SDS System');
        $pdf->SetAuthor(App::config('company.name', 'SDS System'));
        $pdf->SetTitle('SDS - ' . $meta['product_code']);
        $pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $pdf->SetAutoPageBreak(true, self::MARGIN_BOTTOM);

        $logoFile = $this->resolveLogoPath($meta['company_logo_path'] ?? '');
        if ($logoFile !== '') {
            $pdf->SetHeaderData($logoFile, 18, 'SAFETY DATA SHEET', $meta['product_code']);
        } else {
            $pdf->SetHeaderData('', 0, 'SAFETY DATA SHEET', $meta['product_code']);
        }

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

        // Section header
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(0, 51, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 7, "SECTION {$sectionNum}: " . strtoupper($title), 0, 1, 'L', true);
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
            case 14:
                $this->renderSection14($pdf, $section);
                break;
            default:
                $this->renderGenericSection($pdf, $section);
                break;
        }

        $pdf->Ln(4);
    }

    private function renderSection1(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, 'Product Identifier', $s['product_identifier'] ?? '');
        $this->labelValue($pdf, 'Product Family', $s['product_family'] ?? '');
        $this->labelValue($pdf, 'Recommended Use', $s['recommended_use'] ?? '');
        $this->labelValue($pdf, 'Restrictions on Use', $s['restrictions'] ?? '');
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'Manufacturer / Supplier Information', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $this->labelValue($pdf, 'Company', $s['manufacturer_name'] ?? '');
        $this->labelValue($pdf, 'Address', $s['manufacturer_address'] ?? '');
        $this->labelValue($pdf, 'Phone', $s['manufacturer_phone'] ?? '');
        $this->labelValue($pdf, 'Emergency', $s['emergency_phone'] ?? '');
    }

    private function renderSection2(\TCPDF $pdf, array $s): void
    {
        if (!empty($s['signal_word'])) {
            $pdf->SetFont('helvetica', 'B', 12);
            $color = $s['signal_word'] === 'Danger' ? [220, 0, 0] : [255, 140, 0];
            $pdf->SetTextColor(...$color);
            $pdf->Cell(0, 6, strtoupper($s['signal_word']), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 9);
        }

        if (!empty($s['pictograms'])) {
            $this->labelValue($pdf, 'Pictograms', implode(', ', $s['pictograms']));
        }

        if (!empty($s['h_statements'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Hazard Statements:', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            foreach ($s['h_statements'] as $stmt) {
                $text = ($stmt['code'] ?? '') . ': ' . ($stmt['text'] ?? '');
                $pdf->MultiCell(0, 4, $text, 0, 'L');
            }
        }

        if (!empty($s['p_statements'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Precautionary Statements:', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            foreach ($s['p_statements'] as $stmt) {
                $text = ($stmt['code'] ?? '') . ': ' . ($stmt['text'] ?? '');
                $pdf->MultiCell(0, 4, $text, 0, 'L');
            }
        }
    }

    private function renderSection3(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, 'Type', $s['substance_or_mixture'] ?? 'Mixture');

        if (!empty($s['components'])) {
            $pdf->Ln(2);
            // Table header
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(230, 230, 230);
            $w = [30, 80, 30, 30];
            $pdf->Cell($w[0], 5, 'CAS Number', 1, 0, 'C', true);
            $pdf->Cell($w[1], 5, 'Chemical Name', 1, 0, 'C', true);
            $pdf->Cell($w[2], 5, 'Concentration', 1, 0, 'C', true);
            $pdf->Cell($w[3], 5, 'Range', 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 8);

            foreach ($s['components'] as $comp) {
                $pdf->Cell($w[0], 5, $comp['cas_number'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[1], 5, $comp['chemical_name'] ?? '', 1, 0, 'L');
                $pdf->Cell($w[2], 5, round((float) ($comp['concentration_pct'] ?? 0), 2) . '%', 1, 0, 'C');
                $pdf->Cell($w[3], 5, $comp['concentration_range'] ?? '', 1, 1, 'C');
            }
        }

        if (!empty($s['trade_secret_note'])) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->MultiCell(0, 4, $s['trade_secret_note'], 0, 'L');
        }
    }

    private function renderSection8(\TCPDF $pdf, array $s): void
    {
        if (!empty($s['exposure_limits'])) {
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(230, 230, 230);
            $w = [25, 60, 25, 25, 20, 20];
            $pdf->Cell($w[0], 5, 'CAS', 1, 0, 'C', true);
            $pdf->Cell($w[1], 5, 'Chemical', 1, 0, 'C', true);
            $pdf->Cell($w[2], 5, 'Type', 1, 0, 'C', true);
            $pdf->Cell($w[3], 5, 'Value', 1, 0, 'C', true);
            $pdf->Cell($w[4], 5, 'Units', 1, 0, 'C', true);
            $pdf->Cell($w[5], 5, 'Conc%', 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 8);

            foreach ($s['exposure_limits'] as $el) {
                $pdf->Cell($w[0], 5, $el['cas_number'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[1], 5, substr($el['chemical_name'] ?? '', 0, 35), 1, 0, 'L');
                $pdf->Cell($w[2], 5, $el['limit_type'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[3], 5, $el['value'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[4], 5, $el['units'] ?? '', 1, 0, 'C');
                $pdf->Cell($w[5], 5, round((float) ($el['concentration_pct'] ?? 0), 2), 1, 1, 'C');
            }
            $pdf->Ln(2);
        }

        $this->labelValue($pdf, 'Engineering Controls', $s['engineering'] ?? '');
        $this->labelValue($pdf, 'Respiratory Protection', $s['respiratory'] ?? '');
        $this->labelValue($pdf, 'Hand Protection', $s['hand_protection'] ?? '');
        $this->labelValue($pdf, 'Eye Protection', $s['eye_protection'] ?? '');
        $this->labelValue($pdf, 'Skin Protection', $s['skin_protection'] ?? '');
    }

    private function renderSection9(\TCPDF $pdf, array $s): void
    {
        $props = [
            'Appearance'        => $s['appearance'] ?? '',
            'Odor'              => $s['odor'] ?? '',
            'pH'                => $s['ph'] ?? '',
            'Boiling Point'     => $s['boiling_point'] ?? '',
            'Flash Point'       => $s['flash_point'] ?? '',
            'Specific Gravity'  => $s['specific_gravity'] ?? '',
            'VOC (lb/gal)'      => $s['voc_lb_per_gal'] ?? '',
            'VOC less W&E (lb/gal)' => $s['voc_less_water_exempt'] ?? '',
            'VOC (wt%)'         => $s['voc_wt_pct'] ?? '',
            'Solids (wt%)'      => $s['solids_wt_pct'] ?? '',
            'Solids (vol%)'     => $s['solids_vol_pct'] ?? '',
        ];

        foreach ($props as $label => $value) {
            $this->labelValue($pdf, $label, (string) $value);
        }
    }

    private function renderSection14(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, 'UN Number', $s['un_number'] ?? '');
        $this->labelValue($pdf, 'Proper Shipping Name', $s['proper_shipping_name'] ?? '');
        $this->labelValue($pdf, 'Hazard Class', $s['hazard_class'] ?? '');
        $this->labelValue($pdf, 'Packing Group', $s['packing_group'] ?? '');
    }

    private function renderGenericSection(\TCPDF $pdf, array $section): void
    {
        foreach ($section as $key => $value) {
            if ($key === 'title') {
                continue;
            }
            if (is_string($value) && $value !== '') {
                $label = ucwords(str_replace('_', ' ', $key));
                $this->labelValue($pdf, $label, $value);
            }
        }
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
        $labelWidth = 50;
        $pdf->Cell($labelWidth, 5, $label . ':', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 5, $value, 0, 'L');
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
        $pdf->Cell(0, 7, 'DISCLAIMER', 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);

        // Body text
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell(0, 4, $disclaimer, 0, 'L');
    }
}
