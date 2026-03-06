<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;

/**
 * ReportPDFService — Generates professional HAP/VOC shipping report PDFs.
 *
 * Uses TCPDF to produce a clean, branded report with shipping detail,
 * totals, HAPs breakdown, and SARA 313 breakdown sections.
 */
class ReportPDFService
{
    private const MARGIN_LEFT   = 15;
    private const MARGIN_TOP    = 20;
    private const MARGIN_RIGHT  = 15;
    private const MARGIN_BOTTOM = 20;

    /** Navy blue used for headers and accents. */
    private const COLOR_NAVY = [0, 51, 102];

    /** Light grey for table header backgrounds. */
    private const COLOR_LIGHT_GREY = [235, 235, 235];

    /** Alternate row shading. */
    private const COLOR_ZEBRA = [245, 247, 250];

    /**
     * Generate the PDF and return its content as a string.
     *
     * @param  array $reportData  Output from ReportController::buildReportData()
     * @return string             Raw PDF bytes
     */
    public function generate(array $reportData): string
    {
        $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8');

        $pdf->SetCreator('SDS System');
        $pdf->SetAuthor(App::config('company.name', 'SDS System'));
        $pdf->SetTitle('HAP / VOC Shipping Report');
        $pdf->SetSubject('HAP / VOC Shipping Report — ' . $reportData['customer_value']);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $pdf->SetAutoPageBreak(true, self::MARGIN_BOTTOM);

        // Custom footer
        $pdf->setFooterData(self::COLOR_NAVY, [150, 150, 150]);

        $pdf->AddPage();

        $this->renderTitleBlock($pdf, $reportData);
        $this->renderShippingTable($pdf, $reportData);
        $this->renderHAPBreakdown($pdf, $reportData['hap_breakdown']);
        $this->renderSARABreakdown($pdf, $reportData['sara_breakdown']);
        $this->renderFooterNote($pdf);

        return $pdf->Output('', 'S');
    }

    /* ------------------------------------------------------------------
     *  Title Block
     * ----------------------------------------------------------------*/

    private function renderTitleBlock(\TCPDF $pdf, array $data): void
    {
        // Company logo if available
        $logoPath = $this->resolveLogoPath();
        if ($logoPath !== '') {
            $imgType = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            if ($imgType === 'svg') {
                $pdf->ImageSVG($logoPath, self::MARGIN_LEFT, $pdf->GetY(), 45, 0);
            } else {
                $pdf->Image($logoPath, self::MARGIN_LEFT, $pdf->GetY(), 45);
            }
            $pdf->Ln(16);
        }

        // Title bar
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetFillColor(...self::COLOR_NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 10, 'HAP / VOC Shipping Report', 0, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);

        // Report metadata in a clean 2-column layout
        $pdf->SetFont('helvetica', '', 10);
        $labelW = 35;

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($labelW, 6, 'Customer:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $data['customer_value'], 0, 1);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($labelW, 6, 'Date Range:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $data['date_from'] . '  to  ' . $data['date_to'], 0, 1);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($labelW, 6, 'Generated:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, date('m/d/Y h:i A'), 0, 1);

        $pdf->Ln(4);

        // Thin accent line
        $pdf->SetDrawColor(...self::COLOR_NAVY);
        $pdf->SetLineWidth(0.4);
        $pageW = $pdf->getPageWidth();
        $pdf->Line(self::MARGIN_LEFT, $pdf->GetY(), $pageW - self::MARGIN_RIGHT, $pdf->GetY());
        $pdf->Ln(4);
    }

    /* ------------------------------------------------------------------
     *  Shipping Detail Table
     * ----------------------------------------------------------------*/

    private function renderShippingTable(\TCPDF $pdf, array $data): void
    {
        $this->sectionHeading($pdf, 'Shipping Detail');

        // Column widths — must sum to usable page width (216 - 15 - 15 = 186 for letter)
        $w = [22, 26, 40, 22, 18, 18, 20, 20];

        // Table header
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(...self::COLOR_LIGHT_GREY);
        $pdf->SetDrawColor(180, 180, 180);

        $headers = ['Date Shipped', 'Item Name', 'Description', 'Qty (lbs)', 'VOC wt%', 'HAP wt%', 'lbs VOC', 'lbs HAP'];
        foreach ($headers as $i => $h) {
            $pdf->Cell($w[$i], 6, $h, 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Data rows with zebra striping
        $pdf->SetFont('helvetica', '', 7);
        $rowIdx = 0;
        foreach ($data['lines'] as $line) {
            $fill = ($rowIdx % 2 === 1);
            if ($fill) {
                $pdf->SetFillColor(...self::COLOR_ZEBRA);
            }

            // Check if we need a page break
            if ($pdf->GetY() + 5 > $pdf->getPageHeight() - self::MARGIN_BOTTOM) {
                $pdf->AddPage();
                // Re-render table header
                $pdf->SetFont('helvetica', 'B', 7);
                $pdf->SetFillColor(...self::COLOR_LIGHT_GREY);
                foreach ($headers as $i => $h) {
                    $pdf->Cell($w[$i], 6, $h, 1, 0, 'C', true);
                }
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 7);
            }

            $pdf->Cell($w[0], 5, $line['date_shipped'], 1, 0, 'C', $fill);
            $pdf->Cell($w[1], 5, $this->truncate($line['item_code'], 16), 1, 0, 'L', $fill);
            $pdf->Cell($w[2], 5, $this->truncate($line['description'], 28), 1, 0, 'L', $fill);
            $pdf->Cell($w[3], 5, number_format($line['qty_shipped'], 2), 1, 0, 'R', $fill);
            $pdf->Cell($w[4], 5, $line['voc_wt_pct'] !== null ? round($line['voc_wt_pct'], 2) : 'N/A', 1, 0, 'C', $fill);
            $pdf->Cell($w[5], 5, $line['hap_wt_pct'] !== null ? round($line['hap_wt_pct'], 2) : 'N/A', 1, 0, 'C', $fill);
            $pdf->Cell($w[6], 5, $line['voc_lbs'] !== null ? number_format($line['voc_lbs'], 2) : 'N/A', 1, 0, 'R', $fill);
            $pdf->Cell($w[7], 5, $line['hap_lbs'] !== null ? number_format($line['hap_lbs'], 2) : 'N/A', 1, 0, 'R', $fill);
            $pdf->Ln();
            $rowIdx++;
        }

        // Totals row
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(...self::COLOR_NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $preW = $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5];
        $pdf->Cell($preW, 6, 'TOTALS', 1, 0, 'R', true);
        $pdf->Cell($w[6], 6, number_format($data['total_voc_lbs'], 2), 1, 0, 'R', true);
        $pdf->Cell($w[7], 6, number_format($data['total_hap_lbs'], 2), 1, 0, 'R', true);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Ln(6);
    }

    /* ------------------------------------------------------------------
     *  HAPs Breakdown
     * ----------------------------------------------------------------*/

    private function renderHAPBreakdown(\TCPDF $pdf, array $hapBreakdown): void
    {
        $this->sectionHeading($pdf, 'HAPs Breakdown');

        if (empty($hapBreakdown)) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 6, 'No Hazardous Air Pollutants (HAPs) were found in shipped products for this period.', 0, 'L');
            $pdf->Ln(4);
            return;
        }

        // Column widths
        $w = [35, 111, 40];

        // Table header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(...self::COLOR_LIGHT_GREY);
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Cell($w[0], 6, 'CAS Number', 1, 0, 'C', true);
        $pdf->Cell($w[1], 6, 'Chemical Name', 1, 0, 'C', true);
        $pdf->Cell($w[2], 6, 'Total lbs', 1, 0, 'C', true);
        $pdf->Ln();

        // Data rows
        $pdf->SetFont('helvetica', '', 8);
        $totalLbs = 0.0;
        $rowIdx = 0;
        foreach ($hapBreakdown as $cas => $entry) {
            $fill = ($rowIdx % 2 === 1);
            if ($fill) {
                $pdf->SetFillColor(...self::COLOR_ZEBRA);
            }

            if ($pdf->GetY() + 5 > $pdf->getPageHeight() - self::MARGIN_BOTTOM) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetFillColor(...self::COLOR_LIGHT_GREY);
                $pdf->Cell($w[0], 6, 'CAS Number', 1, 0, 'C', true);
                $pdf->Cell($w[1], 6, 'Chemical Name', 1, 0, 'C', true);
                $pdf->Cell($w[2], 6, 'Total lbs', 1, 0, 'C', true);
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 8);
            }

            $pdf->Cell($w[0], 5, $cas, 1, 0, 'C', $fill);
            $pdf->Cell($w[1], 5, $this->truncate($entry['name'], 72), 1, 0, 'L', $fill);
            $pdf->Cell($w[2], 5, number_format($entry['lbs'], 4), 1, 0, 'R', $fill);
            $pdf->Ln();
            $totalLbs += $entry['lbs'];
            $rowIdx++;
        }

        // Total row
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(...self::COLOR_NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($w[0] + $w[1], 6, 'TOTAL HAPs', 1, 0, 'R', true);
        $pdf->Cell($w[2], 6, number_format($totalLbs, 4), 1, 0, 'R', true);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Ln(6);
    }

    /* ------------------------------------------------------------------
     *  SARA 313 Breakdown
     * ----------------------------------------------------------------*/

    private function renderSARABreakdown(\TCPDF $pdf, array $saraBreakdown): void
    {
        $this->sectionHeading($pdf, 'SARA 313 Breakdown');

        if (empty($saraBreakdown)) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 6, 'No SARA 313 reportable chemicals were found in shipped products for this period.', 0, 'L');
            $pdf->Ln(4);
            return;
        }

        // Column widths
        $w = [35, 111, 40];

        // Table header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(...self::COLOR_LIGHT_GREY);
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Cell($w[0], 6, 'CAS Number', 1, 0, 'C', true);
        $pdf->Cell($w[1], 6, 'Chemical Name', 1, 0, 'C', true);
        $pdf->Cell($w[2], 6, 'Total lbs', 1, 0, 'C', true);
        $pdf->Ln();

        // Data rows
        $pdf->SetFont('helvetica', '', 8);
        $totalLbs = 0.0;
        $rowIdx = 0;
        foreach ($saraBreakdown as $cas => $entry) {
            $fill = ($rowIdx % 2 === 1);
            if ($fill) {
                $pdf->SetFillColor(...self::COLOR_ZEBRA);
            }

            if ($pdf->GetY() + 5 > $pdf->getPageHeight() - self::MARGIN_BOTTOM) {
                $pdf->AddPage();
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetFillColor(...self::COLOR_LIGHT_GREY);
                $pdf->Cell($w[0], 6, 'CAS Number', 1, 0, 'C', true);
                $pdf->Cell($w[1], 6, 'Chemical Name', 1, 0, 'C', true);
                $pdf->Cell($w[2], 6, 'Total lbs', 1, 0, 'C', true);
                $pdf->Ln();
                $pdf->SetFont('helvetica', '', 8);
            }

            $pdf->Cell($w[0], 5, $cas, 1, 0, 'C', $fill);
            $pdf->Cell($w[1], 5, $this->truncate($entry['name'], 72), 1, 0, 'L', $fill);
            $pdf->Cell($w[2], 5, number_format($entry['lbs'], 4), 1, 0, 'R', $fill);
            $pdf->Ln();
            $totalLbs += $entry['lbs'];
            $rowIdx++;
        }

        // Total row
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(...self::COLOR_NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($w[0] + $w[1], 6, 'TOTAL SARA 313', 1, 0, 'R', true);
        $pdf->Cell($w[2], 6, number_format($totalLbs, 4), 1, 0, 'R', true);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Ln(6);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function sectionHeading(\TCPDF $pdf, string $title): void
    {
        // Ensure there's room for heading + at least a few rows
        if ($pdf->GetY() + 20 > $pdf->getPageHeight() - self::MARGIN_BOTTOM) {
            $pdf->AddPage();
        }

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(...self::COLOR_NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 7, $title, 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }

    private function renderFooterNote(\TCPDF $pdf): void
    {
        $pdf->Ln(4);
        $pdf->SetDrawColor(...self::COLOR_NAVY);
        $pdf->SetLineWidth(0.4);
        $pageW = $pdf->getPageWidth();
        $pdf->Line(self::MARGIN_LEFT, $pdf->GetY(), $pageW - self::MARGIN_RIGHT, $pdf->GetY());
        $pdf->Ln(3);

        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->MultiCell(0, 4,
            'This report was generated by the SDS System. HAP and SARA 313 data is derived from '
            . 'current product formulations on file. Actual emissions may vary based on application '
            . 'conditions. This report is intended for internal environmental compliance use only.',
            0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    private function truncate(string $text, int $maxLen): string
    {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }
        return mb_substr($text, 0, $maxLen - 1) . "\xE2\x80\xA6"; // ellipsis
    }

    private function resolveLogoPath(): string
    {
        $webPath = App::config('company.logo', '');
        if ($webPath === '') {
            return '';
        }
        $absPath = App::basePath() . '/public' . $webPath;
        return file_exists($absPath) ? $absPath : '';
    }
}
