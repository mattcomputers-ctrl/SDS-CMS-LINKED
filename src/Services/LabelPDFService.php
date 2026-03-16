<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;

/**
 * LabelPDFService — Generates GHS-compliant product labels as PDF.
 *
 * Supports two label sizes:
 *   - "big"   (OL575WR): 3.75" x 2.4375", 8 per sheet (2 cols x 4 rows)
 *   - "small" (OL800WX): 2.5" x 1.5625", 18 per sheet (3 cols x 6 rows)
 *
 * Each label contains the six GHS-required elements:
 *   1. Product identifier
 *   2. Signal word
 *   3. Hazard statements (H-statements)
 *   4. Pictogram(s)
 *   5. Precautionary statements (P-statements)
 *   6. Supplier identification (name, address, phone)
 *
 * Plus: lot number, item code.
 */
class LabelPDFService
{
    // Label specs in mm (converted from inches, 1 inch = 25.4 mm)
    private const LABELS = [
        'big' => [
            'name'        => 'OL575WR',
            'width'       => 95.25,   // 3.75"
            'height'      => 61.9125, // 2.4375"
            'cols'        => 2,
            'rows'        => 4,
            'margin_left' => 11.1125, // 0.4375"
            'margin_top'  => 11.1125, // 0.4375"
            'h_spacing'   => 3.175,   // 0.125"
            'v_spacing'   => 3.175,   // 0.125"
        ],
        'small' => [
            'name'        => 'OL800WX',
            'width'       => 63.5,     // 2.5"
            'height'      => 39.6875,  // 1.5625"
            'cols'        => 3,
            'rows'        => 6,
            'margin_left' => 6.7818,   // 0.267" (was 0.375", shifted left 0.108")
            'margin_top'  => 12.7,     // 0.5"
            'h_spacing'   => 5.9182,   // 0.233" (was 0.125", increased 0.108")
            'v_spacing'   => 3.175,    // 0.125"
        ],
    ];

    /**
     * Generate a label sheet PDF.
     *
     * @param  array  $sdsData    Full SDS data from SDSGenerator
     * @param  array  $fg         Finished good record
     * @param  string $lotNumber  Lot number (up to 12 digits)
     * @param  string $size       'big' or 'small'
     * @param  int    $quantity   Number of labels to print
     * @param  string $netWeight     Optional net weight text
     * @param  bool   $privateLabel  If true, hide supplier info
     * @return string                Raw PDF content
     */
    public function generate(array $sdsData, array $fg, string $lotNumber, string $size, int $quantity, string $netWeight = '', bool $privateLabel = false): string
    {
        $spec = self::LABELS[$size] ?? self::LABELS['big'];
        $labelsPerSheet = $spec['cols'] * $spec['rows'];

        // Extract GHS data from SDS
        $section1 = $sdsData['sections'][1] ?? [];
        $section2 = $sdsData['sections'][2] ?? [];
        $hazard   = $sdsData['hazard_result'] ?? [];

        $productName  = $fg['product_code'];
        $itemCode     = $fg['product_code'];
        $signalWord   = $section2['signal_word'] ?? $hazard['signal_word'] ?? null;
        $pictograms   = $section2['pictograms'] ?? $hazard['pictograms'] ?? [];
        $hStatements  = $section2['h_statements'] ?? $hazard['h_statements'] ?? [];
        $pStatements  = $section2['p_statements'] ?? $hazard['p_statements'] ?? [];

        // Supplier info
        $supplierName    = $section1['manufacturer_name'] ?? '';
        $supplierAddress = $section1['manufacturer_address'] ?? '';
        $supplierPhone   = $section1['manufacturer_phone'] ?? '';

        // Create TCPDF instance - Letter size, portrait
        $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('SDS System');
        $pdf->SetTitle('GHS Label - ' . $itemCode);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        $isBig = ($size === 'big');
        $labelIndex = 0;

        for ($i = 0; $i < $quantity; $i++) {
            $posOnSheet = $labelIndex % $labelsPerSheet;

            if ($posOnSheet === 0) {
                $pdf->AddPage();
            }

            $col = $posOnSheet % $spec['cols'];
            $row = intdiv($posOnSheet, $spec['cols']);

            $x = $spec['margin_left'] + $col * ($spec['width'] + $spec['h_spacing']);
            $y = $spec['margin_top'] + $row * ($spec['height'] + $spec['v_spacing']);

            $this->renderLabel(
                $pdf, $x, $y, $spec['width'], $spec['height'],
                $productName, $itemCode, $lotNumber, $signalWord,
                $pictograms, $hStatements, $pStatements,
                $supplierName, $supplierAddress, $supplierPhone,
                $isBig, $netWeight, $privateLabel, $row
            );

            $labelIndex++;
        }

        return $pdf->Output('', 'S');
    }

    private function renderLabel(
        \TCPDF $pdf,
        float $x, float $y, float $w, float $h,
        string $productName, string $itemCode, string $lotNumber,
        ?string $signalWord, array $pictograms,
        array $hStatements, array $pStatements,
        string $supplierName, string $supplierAddress, string $supplierPhone,
        bool $isBig, string $netWeight = '', bool $privateLabel = false, int $sheetRow = 0
    ): void {
        $pad = $isBig ? 1.5 : 1.0;
        $innerW = $w - 2 * $pad;
        $innerX = $x + $pad;

        // Per-row vertical content offset for big labels to center content on sheet
        // Row 0 (top): 0.25", Row 1: 0.16", Row 2: 0.08", Row 3 (bottom): 0"
        if ($isBig) {
            $rowOffsets = [0 => 2.032, 1 => 3.302, 2 => 5.715, 3 => 7.62];
            $contentOffset = $rowOffsets[$sheetRow] ?? 0.0;
        } else {
            // Per-row vertical offset to align with physical label positions on sheet
            // Row 0: 0.067" up, Row 1: good, Row 2-5: progressively down
            $rowOffsets = [
                0 => -1.7018,  // -0.067"
                1 =>  0.0,
                2 =>  1.6002,  // +0.063"
                3 =>  3.3782,  // +0.133"
                4 =>  4.445,   // +0.175"
                5 =>  5.842,   // +0.23"
            ];
            $contentOffset = $rowOffsets[$sheetRow] ?? 0.0;
        }
        $curY = $y + $pad + $contentOffset;

        // Font sizes
        $nameSize    = $isBig ? 9 : 7;
        $signalSize  = $isBig ? 8 : 5.5;
        $bodySize    = $isBig ? 5 : 3.5;
        $tinySize    = $isBig ? 4.5 : 3.5;
        $pictoSize   = $isBig ? 7 : 5;

        // ── Lot Number & Item Code (bold, top) ──
        $lineH = $isBig ? 4 : 3;
        $pdf->SetFont('helvetica', 'B', $nameSize);
        $lotLine = 'LOT: ' . $lotNumber . $itemCode;
        if ($netWeight !== '') {
            // Lot+item on left, net weight on right — both bold
            $pdf->SetXY($innerX, $curY);
            $pdf->Cell($innerW, $lineH, $lotLine, 0, 0, 'L');
            $pdf->SetXY($innerX, $curY);
            $pdf->Cell($innerW, $lineH, $netWeight, 0, 0, 'R');
        } else {
            $pdf->SetXY($innerX, $curY);
            $displayName = $this->truncateText($pdf, $lotLine, $innerW, $nameSize);
            $pdf->Cell($innerW, $lineH, $displayName, 0, 0, 'C');
        }
        $curY += $lineH;

        // ── Thin divider ──
        $pdf->Line($innerX, $curY, $innerX + $innerW, $curY);
        $curY += 0.5;

        // ── Pictograms row + Signal Word ──
        $pictoRowH = $isBig ? 9 : 5.5;
        $availPictos = $this->getAvailablePictograms($pictograms);
        $numPictos = count($availPictos);

        if ($numPictos > 0 || $signalWord) {
            // Layout: pictograms on left, signal word on right
            $pictoAreaW = $numPictos > 0 ? min($numPictos * ($pictoSize + 1), $innerW * 0.6) : 0;
            $signalAreaW = $innerW - $pictoAreaW;

            // Draw pictograms
            $pictoX = $innerX;
            foreach ($availPictos as $pictoPath) {
                if ($pictoPath !== '') {
                    $pdf->Image($pictoPath, $pictoX, $curY + 0.5, $pictoSize, $pictoSize, '', '', '', true, 300, '', false, false, 0);
                }
                $pictoX += $pictoSize + ($isBig ? 1 : 0.5);
            }

            // Signal word
            if ($signalWord) {
                $pdf->SetFont('helvetica', 'B', $signalSize);
                if (strtoupper($signalWord) === 'DANGER') {
                    $pdf->SetTextColor(255, 0, 0);
                } else {
                    $pdf->SetTextColor(0, 0, 0);
                }
                $pdf->SetXY($innerX + $pictoAreaW, $curY + ($pictoRowH / 2 - $signalSize * 0.18));
                $pdf->Cell($signalAreaW, $isBig ? 5 : 3.5, strtoupper($signalWord), 0, 0, 'C');
                $pdf->SetTextColor(0, 0, 0);
            }

            $curY += $pictoRowH;
        }

        // ── Hazard Statements ──
        $maxBottom = $y + $h - $pad;
        $hText = $this->formatStatements($hStatements);

        // Calculate available space
        $supplierH = $privateLabel ? 0 : ($isBig ? 6 : 5);
        $availH = $maxBottom - $curY - $supplierH - 1;

        if ($hText !== '') {
            $pdf->SetFont('helvetica', 'B', $tinySize);
            $pdf->SetXY($innerX, $curY);
            $pdf->Cell($innerW, $isBig ? 2.5 : 2, 'Hazard Statements:', 0, 0, 'L');
            $curY += $isBig ? 2.5 : 2;

            $pdf->SetFont('helvetica', '', $tinySize);
            $pdf->SetXY($innerX, $curY);

            // All hazard statements must be listed — give them as much space as needed
            $hNeeded = $this->getTextHeight($pdf, $hText, $innerW, $tinySize);
            $hAlloc = min($availH, $hNeeded);
            $pdf->MultiCell($innerW, $isBig ? 2.2 : 1.8, $hText, 0, 'L', false, 1, $innerX, $curY, true, 0, false, true, $hAlloc, 'T', true);
            $curY += $hAlloc;
        }

        // ── Precautionary Statements ──
        // Prioritize physical (P200) and health (P300) statements when space is limited.
        // If not all statements fit, show prioritized ones + "See SDS" note.
        $availForP = $maxBottom - $curY - $supplierH - 1;
        if (count($pStatements) > 0 && $availForP > ($isBig ? 4 : 3)) {
            $headerH = $isBig ? 2.5 : 2;
            $seeMoreText = 'See SDS for additional precautionary statements.';
            $seeMoreH = $this->getTextHeight($pdf, $seeMoreText, $innerW, $tinySize);

            $pTextFull = $this->formatStatements($pStatements);
            $pFullNeeded = $this->getTextHeight($pdf, $pTextFull, $innerW, $tinySize);
            $pSpaceForText = $availForP - $headerH - 0.5;

            if ($pFullNeeded <= $pSpaceForText) {
                // All P-statements fit — render them all
                $pText = $pTextFull;
                $pTruncated = false;
            } else {
                // Not all fit — prioritize physical/health risk statements
                $pText = $this->buildPrioritizedPStatements($pdf, $pStatements, $innerW, $tinySize, $pSpaceForText - $seeMoreH);
                $pTruncated = true;
            }

            $pdf->SetFont('helvetica', 'B', $tinySize);
            $pdf->SetXY($innerX, $curY);
            $pdf->Cell($innerW, $headerH, 'Precautionary Statements:', 0, 0, 'L');
            $curY += $headerH;

            $pdf->SetFont('helvetica', '', $tinySize);
            $pdf->SetXY($innerX, $curY);
            $pAlloc = $maxBottom - $curY - $supplierH - 0.5;
            $pdf->MultiCell($innerW, $isBig ? 2.2 : 1.8, $pText, 0, 'L', false, 1, $innerX, $curY, true, 0, false, true, max(0, $pAlloc), 'T', true);
            $curY = min($curY + $pAlloc, $maxBottom - $supplierH - 0.5);

            if ($pTruncated) {
                // Add "See SDS" note
                $seeY = $curY;
                $pdf->SetFont('helvetica', 'I', $tinySize);
                $pdf->SetXY($innerX, $seeY);
                $pdf->MultiCell($innerW, $isBig ? 2.2 : 1.8, $seeMoreText, 0, 'L', false, 1, $innerX, $seeY, true, 0, false, true, $seeMoreH, 'T', true);
            }
        }

        // ── Supplier info (bottom of label) ──
        if (!$privateLabel) {
            $supplierY = $maxBottom - $supplierH;
            $pdf->Line($innerX, $supplierY - 0.3, $innerX + $innerW, $supplierY - 0.3);

            $pdf->SetFont('helvetica', '', $tinySize);
            $supplierLine = $supplierName;
            if ($supplierAddress !== '' && $supplierAddress !== ', ,  ') {
                $supplierLine .= ' | ' . $supplierAddress;
            }
            if ($supplierPhone !== '') {
                $supplierLine .= ' | ' . $supplierPhone;
            }

            $pdf->SetXY($innerX, $supplierY);
            $pdf->MultiCell($innerW, $isBig ? 2 : 1.6, $supplierLine, 0, 'C', false, 1, $innerX, $supplierY, true, 0, false, true, $supplierH, 'T', true);
        }
    }

    /**
     * Format H or P statements into a compact text string.
     */
    private function formatStatements(array $statements): string
    {
        $parts = [];
        foreach ($statements as $stmt) {
            $code = $stmt['code'] ?? '';
            $text = $stmt['text'] ?? '';
            if ($code === '' && $text === '') {
                continue;
            }
            if ($code !== '' && $text !== '') {
                $parts[] = $code . ': ' . $text;
            } elseif ($code !== '') {
                $parts[] = $code;
            } else {
                $parts[] = $text;
            }
        }
        return implode('. ', $parts);
    }

    /**
     * Get filesystem paths for available pictogram images.
     */
    private function getAvailablePictograms(array $pictogramCodes): array
    {
        $paths = [];
        foreach ($pictogramCodes as $code) {
            $path = PictogramHelper::getPngPath($code);
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /**
     * Truncate text to fit within a given width.
     */
    private function truncateText(\TCPDF $pdf, string $text, float $maxWidth, float $fontSize): string
    {
        $pdf->SetFont('helvetica', 'B', $fontSize);
        $textWidth = $pdf->GetStringWidth($text);
        if ($textWidth <= $maxWidth) {
            return $text;
        }
        while ($textWidth > $maxWidth && strlen($text) > 3) {
            $text = substr($text, 0, -4) . '...';
            $textWidth = $pdf->GetStringWidth($text);
        }
        return $text;
    }

    /**
     * Build prioritized P-statement text that fits within available space.
     *
     * Priority order: physical safety (P200s), health/response (P300s),
     * then general (P100s), storage (P400s), disposal (P500s).
     */
    private function buildPrioritizedPStatements(\TCPDF $pdf, array $pStatements, float $width, float $fontSize, float $maxHeight): string
    {
        // Separate statements into priority groups
        $priority = [];   // P200 (prevention/physical) and P300 (response/health)
        $secondary = [];  // P100 (general), P400 (storage), P500 (disposal)

        foreach ($pStatements as $stmt) {
            $code = $stmt['code'] ?? '';
            $num = (int) substr($code, 1);
            if ($num >= 200 && $num < 400) {
                $priority[] = $stmt;
            } else {
                $secondary[] = $stmt;
            }
        }

        // Build text incrementally, fitting as many priority statements as possible,
        // then secondary statements if space remains
        $ordered = array_merge($priority, $secondary);
        $included = [];

        foreach ($ordered as $stmt) {
            $candidate = array_merge($included, [$stmt]);
            $candidateText = $this->formatStatements($candidate);
            $needed = $this->getTextHeight($pdf, $candidateText, $width, $fontSize);
            if ($needed > $maxHeight && count($included) > 0) {
                break;
            }
            $included[] = $stmt;
        }

        return $this->formatStatements($included);
    }

    /**
     * Estimate text height for a MultiCell.
     */
    private function getTextHeight(\TCPDF $pdf, string $text, float $width, float $fontSize): float
    {
        $pdf->SetFont('helvetica', '', $fontSize);
        $lines = $pdf->getNumLines($text, $width);
        return $lines * $fontSize * 0.45;
    }
}
