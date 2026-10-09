<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * SDSTcpdf — Thin TCPDF subclass for SDS PDF rendering.
 *
 * - Header with logo + translated document title on the first page only
 *   (pages 2+ have no header; PDFService::buildPdf() lowers the top margin
 *   after page 1 so later pages start at the normal margin — audit #41)
 * - Footer with product code, page number, and revision date on every page
 * - No "Powered by TCPDF" meta link on the last page (audit #41)
 */
class SDSTcpdf extends \TCPDF
{
    /** @var string Absolute filesystem path to the header logo image. */
    protected string $absoluteLogoPath = '';

    /** @var string Product code shown in the footer. */
    protected string $footerProductCode = '';

    /** @var string Version / effective date text shown in the footer (prefix included). */
    protected string $footerRevisionDate = '';

    /** @var array Translated document-level strings. */
    protected array $documentStrings = [];

    /**
     * Same signature as TCPDF::__construct(). TCPDF re-enables its
     * "Powered by TCPDF (www.tcpdf.org)" last-page link INSIDE its
     * constructor, so a property default cannot switch it off — it has to
     * be cleared after the parent has run (audit #41).
     */
    public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false, $pdfa = false)
    {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
        $this->tcpdflink = false;
    }

    public function setAbsoluteLogoPath(string $path): void
    {
        $this->absoluteLogoPath = $path;
    }

    public function setFooterInfo(string $productCode, string $revisionDate): void
    {
        $this->footerProductCode = $productCode;
        $this->footerRevisionDate = $revisionDate;
    }

    public function setDocumentStrings(array $strings): void
    {
        $this->documentStrings = $strings;
    }

    /**
     * Header — first page only: logo (left) + document title (right).
     *
     * Pages 2+ draw nothing here. Their top margin is NOT adjusted from this
     * method: TCPDF records tMargin in pagedim[] before Header() runs and
     * setPage() restores it from there, so a switch made here is one page
     * late on a MultiCell page break. PDFService::buildPdf() sets the
     * continuation margin once, right after the first AddPage() (audit #41).
     */
    public function Header(): void // @phpcs:ignore
    {
        // Only show header on first page
        if ($this->getPage() > 1) {
            return;
        }

        if (!$this->print_header) {
            return;
        }

        $this->setGraphicVars($this->default_graphic_vars);

        $leftMargin = $this->original_lMargin;
        $rightMargin = $this->original_rMargin;
        $pageWidth = $this->getPageWidth();
        $topY = $this->header_margin;

        // The header band height (logo + title area before the separator line)
        $headerHeight = 16; // mm — enough for a ~2" wide logo at typical aspect ratios

        // Document title — right-aligned, vertically centered in header band
        $docTitle = $this->documentStrings['title'] ?? 'SAFETY DATA SHEET';
        $this->SetFont('helvetica', 'B', 14);
        $titleCellH = 6; // approximate text height at 14pt
        $titleY = $topY + ($headerHeight - $titleCellH) / 2;
        $this->SetY($titleY);
        $this->SetX($leftMargin);
        $this->Cell($pageWidth - $leftMargin - $rightMargin, $titleCellH, $docTitle, 0, 1, 'R');

        // Logo — approximately 2" wide (≈ 51 mm), vertically centered in same header band
        $logoWidth = 51;
        if ($this->absoluteLogoPath !== '' && file_exists($this->absoluteLogoPath)) {
            $imgType = strtolower(pathinfo($this->absoluteLogoPath, PATHINFO_EXTENSION));

            // Estimate logo height (assume ~3:1 aspect for typical wide logos; TCPDF auto-scales)
            $logoHeight = 14; // approximate for centering calculation
            $logoY = $topY + ($headerHeight - $logoHeight) / 2;

            if ($imgType === 'svg') {
                $this->ImageSVG($this->absoluteLogoPath, $leftMargin, $logoY, $logoWidth, 0);
            } else {
                $this->Image($this->absoluteLogoPath, $leftMargin, $logoY, $logoWidth);
            }
        }

        // Separator line below header band
        $this->SetLineWidth(0.3);
        $this->SetDrawColor(0, 51, 102);
        $lineY = $topY + $headerHeight + 2;
        $this->Line($leftMargin, $lineY, $pageWidth - $rightMargin, $lineY);
    }

    /**
     * Footer — every page: product code (left), page number (center), version / effective date (right).
     */
    public function Footer(): void // @phpcs:ignore
    {
        // Position 15 mm from the bottom
        $this->SetY(-15);

        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);

        $leftMargin = $this->original_lMargin;
        $rightMargin = $this->original_rMargin;
        $pageWidth = $this->getPageWidth();
        $cellWidth = ($pageWidth - $leftMargin - $rightMargin) / 3;

        // Separator line above footer
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(150, 150, 150);
        $this->Line($leftMargin, $this->GetY(), $pageWidth - $rightMargin, $this->GetY());
        $this->Ln(1);

        $y = $this->GetY();

        // Product code — left
        $this->SetX($leftMargin);
        $this->Cell($cellWidth, 5, $this->footerProductCode, 0, 0, 'L');

        // Page number — center (translated)
        $pageTxt = ($this->documentStrings['page'] ?? 'Page') . ' '
                 . $this->getAliasNumPage() . ' '
                 . ($this->documentStrings['page_of'] ?? 'of') . ' '
                 . $this->getAliasNbPages();
        $this->Cell($cellWidth, 5, $pageTxt, 0, 0, 'C');

        // Version / effective date — right. PDFService::footerRevision() composes
        // the whole text (prefix included) so a draft prints without "Rev.".
        $this->Cell($cellWidth, 5, $this->footerRevisionDate, 0, 0, 'R');
    }
}
