<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * SDSTcpdf — Thin TCPDF subclass that fixes absolute logo path handling.
 *
 * TCPDF's default Header() method prepends K_PATH_IMAGES to the logo path,
 * which breaks when the logo is stored at an absolute filesystem path.
 * This subclass overrides Header() to use the absolute path directly.
 */
class SDSTcpdf extends \TCPDF
{
    /** @var string Absolute filesystem path to the header logo image. */
    protected string $absoluteLogoPath = '';

    /**
     * Set an absolute filesystem path for the header logo.
     * This bypasses TCPDF's K_PATH_IMAGES prefix behavior.
     */
    public function setAbsoluteLogoPath(string $path): void
    {
        $this->absoluteLogoPath = $path;
    }

    /**
     * Custom header rendering that handles absolute logo paths.
     */
    public function Header(): void // @phpcs:ignore
    {
        if (!$this->header_on) {
            return;
        }

        $this->setGraphicVars($this->default_graphic_vars);
        $headerfont = $this->getHeaderFont();
        $headerData = $this->getHeaderData();
        $headerMargin = max($this->header_margin, $this->GetX());

        $imgWidth = 0;
        $textX = $headerMargin;

        // Render logo from absolute path
        if ($this->absoluteLogoPath !== '' && file_exists($this->absoluteLogoPath)) {
            $imgWidth = $headerData['logo_width'] ?: 18;
            $imgType = strtolower(pathinfo($this->absoluteLogoPath, PATHINFO_EXTENSION));
            if ($imgType === 'svg') {
                $this->ImageSVG($this->absoluteLogoPath, $headerMargin, $this->header_margin, $imgWidth);
            } else {
                $this->Image($this->absoluteLogoPath, $headerMargin, $this->header_margin, $imgWidth);
            }
            $textX = $headerMargin + $imgWidth + 2;
        }

        // Title
        $this->SetFont($headerfont[0], 'B', $headerfont[2] + 2);
        $this->SetX($textX);
        $this->Cell(0, 6, $headerData['title'] ?? '', 0, 1, 'L');

        // Subtitle
        $this->SetFont($headerfont[0], '', $headerfont[2]);
        $this->SetX($textX);
        $this->Cell(0, 5, $headerData['string'] ?? '', 0, 1, 'L');

        // Line separator
        $this->SetLineWidth(0.3);
        $this->SetDrawColor(0, 51, 102);
        $y = $this->GetY() + 1;
        $this->Line($headerMargin, $y, $this->getPageWidth() - $headerMargin, $y);
    }
}
