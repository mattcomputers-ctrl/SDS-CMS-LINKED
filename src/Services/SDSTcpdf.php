<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * SDSTcpdf — Thin TCPDF subclass for SDS PDF rendering.
 *
 * - Header with logo + "SAFETY DATA SHEET" on the first page only
 * - Footer with product code, page number, and revision date on every page
 */
class SDSTcpdf extends \TCPDF
{
    /** @var string Absolute filesystem path to the header logo image. */
    protected string $absoluteLogoPath = '';

    /** @var string Product code shown in the footer. */
    protected string $footerProductCode = '';

    /** @var string Revision date shown in the footer. */
    protected string $footerRevisionDate = '';

    public function setAbsoluteLogoPath(string $path): void
    {
        $this->absoluteLogoPath = $path;
    }

    public function setFooterInfo(string $productCode, string $revisionDate): void
    {
        $this->footerProductCode = $productCode;
        $this->footerRevisionDate = $revisionDate;
    }

    /**
     * Header — first page only: logo (left) + "SAFETY DATA SHEET" (right).
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

        // Logo — approximately 2" wide (≈ 51 mm)
        $logoWidth = 51;
        if ($this->absoluteLogoPath !== '' && file_exists($this->absoluteLogoPath)) {
            $imgType = strtolower(pathinfo($this->absoluteLogoPath, PATHINFO_EXTENSION));

            if ($imgType === 'svg') {
                $this->ImageSVG($this->absoluteLogoPath, $leftMargin, $topY, $logoWidth, 0);
            } else {
                $this->Image($this->absoluteLogoPath, $leftMargin, $topY, $logoWidth);
            }
        }

        // "SAFETY DATA SHEET" — right-aligned, vertically centered in header area
        $this->SetFont('helvetica', 'B', 14);
        $titleY = $topY + 4; // nudge down a bit for visual centering with logo
        $this->SetY($titleY);
        $this->SetX($leftMargin);
        $this->Cell($pageWidth - $leftMargin - $rightMargin, 8, 'SAFETY DATA SHEET', 0, 1, 'R');

        // Separator line below header
        $this->SetLineWidth(0.3);
        $this->SetDrawColor(0, 51, 102);
        $lineY = $topY + 20; // below the logo area
        $this->Line($leftMargin, $lineY, $pageWidth - $rightMargin, $lineY);
    }

    /**
     * Footer — every page: product code (left), page number (center), revision date (right).
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

        // Page number — center
        $this->Cell($cellWidth, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');

        // Revision date — right
        $this->Cell($cellWidth, 5, 'Rev. ' . $this->footerRevisionDate, 0, 0, 'R');
    }
}
