<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\LabelTemplate;

/**
 * LabelPDFService — Generates GHS-compliant product labels as PDF.
 *
 * Supports two modes:
 *   1. Legacy hardcoded templates ("big"/"small") via generate()
 *   2. User-configurable templates via generateFromTemplate()
 *
 * Each label contains the six GHS-required elements:
 *   1. Product identifier
 *   2. Signal word
 *   3. Hazard statements (H-statements)
 *   4. Pictogram(s)
 *   5. Precautionary statements (P-statements)
 *   6. Supplier identification (name, address, phone)
 *
 * Plus: lot number, item code, optional net weight.
 */
class LabelPDFService
{
    // Legacy label specs (kept for backward compatibility)
    private const LABELS = [
        'big' => [
            'name'        => 'OL575WR',
            'width'       => 95.25,
            'height'      => 61.9125,
            'cols'        => 2,
            'rows'        => 4,
            'margin_left' => 11.1125,
            'margin_top'  => 11.1125,
            'h_spacing'   => 3.175,
            'v_spacing'   => 3.175,
        ],
        'small' => [
            'name'        => 'OL800WX',
            'width'       => 63.5,
            'height'      => 39.6875,
            'cols'        => 3,
            'rows'        => 6,
            'margin_left' => 9.525,
            'margin_top'  => 12.7,
            'h_spacing'   => 3.175,
            'v_spacing'   => 3.175,
        ],
    ];

    /**
     * Generate labels using a user-configurable template from the database.
     */
    public function generateFromTemplate(
        array $sdsData,
        array $fg,
        string $lotNumber,
        array $template,
        int $quantity,
        string $netWeight = '',
        bool $privateLabel = false
    ): string {
        $labelW = (float) $template['label_width'];
        $labelH = (float) $template['label_height'];
        $cols   = (int) $template['cols'];
        $rows   = (int) $template['rows'];
        $mLeft  = (float) $template['margin_left'];
        $mTop   = (float) $template['margin_top'];
        $hSpace = (float) $template['h_spacing'];
        $vSpace = (float) $template['v_spacing'];
        $defaultFont = (float) $template['default_font_size'];
        $layout = LabelTemplate::decodeLayout($template);

        $labelsPerSheet = $cols * $rows;

        // Extract GHS data
        $section1 = $sdsData['sections'][1] ?? [];
        $section2 = $sdsData['sections'][2] ?? [];
        $hazard   = $sdsData['hazard_result'] ?? [];

        $itemCode     = $fg['product_code'];
        $signalWord   = $section2['signal_word'] ?? $hazard['signal_word'] ?? null;
        $pictograms   = $section2['pictograms'] ?? $hazard['pictograms'] ?? [];
        $hStatements  = $section2['h_statements'] ?? $hazard['h_statements'] ?? [];
        $pStatements  = $section2['p_statements'] ?? $hazard['p_statements'] ?? [];

        $supplierName    = $section1['manufacturer_name'] ?? '';
        $supplierAddress = $section1['manufacturer_address'] ?? '';
        $supplierPhone   = $section1['manufacturer_phone'] ?? '';

        // Create TCPDF instance
        $pdf = new \TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('SDS System');
        $pdf->SetTitle('GHS Label - ' . $itemCode);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        $labelIndex = 0;

        for ($i = 0; $i < $quantity; $i++) {
            $posOnSheet = $labelIndex % $labelsPerSheet;

            if ($posOnSheet === 0) {
                $pdf->AddPage();
            }

            $col = $posOnSheet % $cols;
            $row = intdiv($posOnSheet, $cols);

            $x = $mLeft + $col * ($labelW + $hSpace);
            $y = $mTop + $row * ($labelH + $vSpace);

            $this->renderTemplateLabel(
                $pdf, $x, $y, $labelW, $labelH, $layout, $defaultFont,
                $itemCode, $lotNumber, $signalWord,
                $pictograms, $hStatements, $pStatements,
                $supplierName, $supplierAddress, $supplierPhone,
                $netWeight, $privateLabel
            );

            $labelIndex++;
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Render a single label using the template's field layout.
     *
     * Each field is positioned by percentage (x, y, width, height: 0-100)
     * of the label area. Content auto-shrinks font to fit the field.
     */
    private function renderTemplateLabel(
        \TCPDF $pdf,
        float $labelX, float $labelY, float $labelW, float $labelH,
        array $layout, float $defaultFont,
        string $itemCode, string $lotNumber, ?string $signalWord,
        array $pictograms, array $hStatements, array $pStatements,
        string $supplierName, string $supplierAddress, string $supplierPhone,
        string $netWeight, bool $privateLabel
    ): void {
        $pad = 1.0; // mm padding inside label

        foreach ($layout as $fieldType => $pos) {
            if (!isset($pos['x'], $pos['y'], $pos['width'], $pos['height'])) {
                continue;
            }

            // Convert percentage to mm coordinates
            $fx = $labelX + $pad + ($pos['x'] / 100) * ($labelW - 2 * $pad);
            $fy = $labelY + $pad + ($pos['y'] / 100) * ($labelH - 2 * $pad);
            $fw = ($pos['width'] / 100) * ($labelW - 2 * $pad);
            $fh = ($pos['height'] / 100) * ($labelH - 2 * $pad);

            // Skip fields with no real space
            if ($fw < 1 || $fh < 1) continue;

            // Use per-field font size override if set, otherwise fall back to template default
            $fieldFont = isset($pos['font_size']) && (float) $pos['font_size'] > 0
                ? (float) $pos['font_size']
                : $defaultFont;

            switch ($fieldType) {
                case 'lot_item_code':
                    $this->renderLotItemCode($pdf, $fx, $fy, $fw, $fh, $fieldFont, $lotNumber, $itemCode);
                    break;

                case 'net_weight':
                    if ($netWeight !== '') {
                        $this->renderTextFit($pdf, $fx, $fy, $fw, $fh, $fieldFont, $netWeight, 'B', 'C');
                    }
                    break;

                case 'pictograms':
                    $this->renderPictogramsField($pdf, $fx, $fy, $fw, $fh, $pictograms);
                    break;

                case 'signal_word':
                    if ($signalWord) {
                        $this->renderSignalWord($pdf, $fx, $fy, $fw, $fh, $fieldFont, $signalWord);
                    }
                    break;

                case 'hazard_statements':
                    $this->renderStatements($pdf, $fx, $fy, $fw, $fh, $fieldFont, $hStatements, 'Hazard Statements:');
                    break;

                case 'precautionary_statements':
                    $this->renderPStatements($pdf, $fx, $fy, $fw, $fh, $fieldFont, $pStatements);
                    break;

                case 'supplier_info':
                    if (!$privateLabel) {
                        $this->renderSupplierInfo($pdf, $fx, $fy, $fw, $fh, $fieldFont, $supplierName, $supplierAddress, $supplierPhone);
                    }
                    break;
            }
        }
    }

    // ── Template-based field renderers ────────────────────────────────────

    private function renderLotItemCode(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, string $lotNumber, string $itemCode): void
    {
        $text = 'LOT: ' . $lotNumber . $itemCode;
        $fontSize = $this->fitFontSize($pdf, $text, $w, $h, $baseFontSize, 'B');
        $pdf->SetFont('helvetica', 'B', $fontSize);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $text, 0, 0, 'C', false, '', 0, false, 'T', 'C');

        // Draw divider at bottom
        $pdf->Line($x, $y + $h, $x + $w, $y + $h);
    }

    private function renderSignalWord(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, string $signalWord): void
    {
        $sw = strtoupper($signalWord);
        $fontSize = $this->fitFontSize($pdf, $sw, $w, $h, min($baseFontSize + 2, 14), 'B');
        $pdf->SetFont('helvetica', 'B', $fontSize);

        if ($sw === 'DANGER') {
            $pdf->SetTextColor(255, 0, 0);
        } else {
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $sw, 0, 0, 'C', false, '', 0, false, 'T', 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    private function renderPictogramsField(\TCPDF $pdf, float $x, float $y, float $w, float $h, array $pictogramCodes): void
    {
        $paths = $this->getAvailablePictograms($pictogramCodes);
        if (empty($paths)) return;

        $n = count($paths);
        // Size each pictogram to fit within the field
        $maxPerRow = min($n, max(1, (int) floor($w / ($h * 0.9))));
        $pictoSize = min($h - 0.5, ($w - ($maxPerRow - 1) * 0.5) / $maxPerRow);
        $pictoSize = max(3, $pictoSize); // at least 3mm

        $totalW = $n * $pictoSize + ($n - 1) * 0.5;
        $startX = $x + max(0, ($w - $totalW) / 2);

        foreach ($paths as $path) {
            if ($path !== '') {
                $pdf->Image($path, $startX, $y + ($h - $pictoSize) / 2, $pictoSize, $pictoSize, '', '', '', true, 300, '', false, false, 0);
            }
            $startX += $pictoSize + 0.5;
        }
    }

    private function renderStatements(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, array $statements, string $header): void
    {
        if (empty($statements)) return;

        $text = $this->formatStatements($statements);
        if ($text === '') return;

        // Fit header + body into the field
        $headerSize = min($baseFontSize, $baseFontSize * 0.85);
        $bodySize = $this->fitMultilineFontSize($pdf, $text, $w, $h - ($headerSize * 0.45), $baseFontSize * 0.85);

        $headerH = $headerSize * 0.45;
        $pdf->SetFont('helvetica', 'B', $headerSize);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $headerH, $header, 0, 0, 'L');

        $pdf->SetFont('helvetica', '', $bodySize);
        $pdf->SetXY($x, $y + $headerH);
        $bodyH = $h - $headerH;
        $lineH = $bodySize * 0.42;
        $pdf->MultiCell($w, $lineH, $text, 0, 'L', false, 1, $x, $y + $headerH, true, 0, false, true, $bodyH, 'T', true);
    }

    private function renderPStatements(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, array $pStatements): void
    {
        if (empty($pStatements)) return;

        $headerSize = min($baseFontSize, $baseFontSize * 0.85);
        $headerH = $headerSize * 0.45;
        $bodyH = $h - $headerH;
        $bodyFontSize = $baseFontSize * 0.85;

        // Try fitting all P-statements
        $fullText = $this->formatStatements($pStatements);
        $testSize = $this->fitMultilineFontSize($pdf, $fullText, $w, $bodyH, $bodyFontSize);

        $seeMoreText = 'See SDS for additional precautionary statements.';

        if ($testSize >= 2.5) {
            // All fit
            $pText = $fullText;
            $truncated = false;
            $bodySize = $testSize;
        } else {
            // Not enough room — prioritize, leave room for "See SDS" note
            $seeMoreH = $this->getTextHeight($pdf, $seeMoreText, $w, min($bodyFontSize, 3.5));
            $prioritizedText = $this->buildPrioritizedPStatements($pdf, $pStatements, $w, $bodyFontSize, $bodyH - $seeMoreH - $headerH);
            $bodySize = $this->fitMultilineFontSize($pdf, $prioritizedText, $w, $bodyH - $seeMoreH, $bodyFontSize);
            $pText = $prioritizedText;
            $truncated = true;
        }

        // Header
        $pdf->SetFont('helvetica', 'B', $headerSize);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $headerH, 'Precautionary Statements:', 0, 0, 'L');

        // Body
        $pdf->SetFont('helvetica', '', $bodySize);
        $lineH = $bodySize * 0.42;
        $pdf->SetXY($x, $y + $headerH);
        $allocH = $truncated ? ($bodyH - $this->getTextHeight($pdf, $seeMoreText, $w, min($bodyFontSize, 3.5))) : $bodyH;
        $pdf->MultiCell($w, $lineH, $pText, 0, 'L', false, 1, $x, $y + $headerH, true, 0, false, true, max(0, $allocH), 'T', true);

        if ($truncated) {
            $noteY = $y + $h - $this->getTextHeight($pdf, $seeMoreText, $w, min($bodyFontSize, 3.5));
            $pdf->SetFont('helvetica', 'I', min($bodyFontSize, 3.5));
            $pdf->SetXY($x, $noteY);
            $pdf->MultiCell($w, $lineH, $seeMoreText, 0, 'L', false, 1, $x, $noteY, true, 0, false, true, 10, 'T', true);
        }
    }

    private function renderSupplierInfo(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, string $name, string $address, string $phone): void
    {
        // Draw divider at top
        $pdf->Line($x, $y, $x + $w, $y);

        $line = $name;
        if ($address !== '' && $address !== ', ,  ') {
            $line .= ' | ' . $address;
        }
        if ($phone !== '') {
            $line .= ' | ' . $phone;
        }

        $fontSize = $this->fitMultilineFontSize($pdf, $line, $w, $h, $baseFontSize * 0.8);
        $pdf->SetFont('helvetica', '', $fontSize);
        $lineH = $fontSize * 0.42;
        $pdf->SetXY($x, $y + 0.3);
        $pdf->MultiCell($w, $lineH, $line, 0, 'C', false, 1, $x, $y + 0.3, true, 0, false, true, $h - 0.3, 'T', true);
    }

    // ── Auto-fit helpers ─────────────────────────────────────────────────

    /**
     * Render single-line text auto-fitted to a box.
     */
    private function renderTextFit(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, string $text, string $style = '', string $align = 'C'): void
    {
        $fontSize = $this->fitFontSize($pdf, $text, $w, $h, $baseFontSize, $style);
        $pdf->SetFont('helvetica', $style, $fontSize);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $text, 0, 0, $align, false, '', 0, false, 'T', 'C');
    }

    /**
     * Find the largest font size (down from $maxSize) that makes single-line text fit in w x h.
     */
    private function fitFontSize(\TCPDF $pdf, string $text, float $w, float $h, float $maxSize, string $style = ''): float
    {
        $minSize = 2.0;
        $size = $maxSize;

        while ($size > $minSize) {
            $pdf->SetFont('helvetica', $style, $size);
            $textW = $pdf->GetStringWidth($text);
            $textH = $size * 0.36; // approx line height in mm
            if ($textW <= $w && $textH <= $h) {
                return $size;
            }
            $size -= 0.5;
        }

        return $minSize;
    }

    /**
     * Find the largest font size that makes multi-line text fit in w x h.
     */
    private function fitMultilineFontSize(\TCPDF $pdf, string $text, float $w, float $h, float $maxSize): float
    {
        $minSize = 2.0;
        $size = $maxSize;

        while ($size > $minSize) {
            $needed = $this->getTextHeight($pdf, $text, $w, $size);
            if ($needed <= $h) {
                return $size;
            }
            $size -= 0.25;
        }

        return $minSize;
    }

    // ── Legacy generate method (backward compatible) ─────────────────────

    /**
     * Generate a label sheet PDF using legacy hardcoded templates.
     */
    public function generate(array $sdsData, array $fg, string $lotNumber, string $size, int $quantity, string $netWeight = '', bool $privateLabel = false): string
    {
        $spec = self::LABELS[$size] ?? self::LABELS['big'];
        $labelsPerSheet = $spec['cols'] * $spec['rows'];

        $section1 = $sdsData['sections'][1] ?? [];
        $section2 = $sdsData['sections'][2] ?? [];
        $hazard   = $sdsData['hazard_result'] ?? [];

        $productName  = $fg['product_code'];
        $itemCode     = $fg['product_code'];
        $signalWord   = $section2['signal_word'] ?? $hazard['signal_word'] ?? null;
        $pictograms   = $section2['pictograms'] ?? $hazard['pictograms'] ?? [];
        $hStatements  = $section2['h_statements'] ?? $hazard['h_statements'] ?? [];
        $pStatements  = $section2['p_statements'] ?? $hazard['p_statements'] ?? [];

        $supplierName    = $section1['manufacturer_name'] ?? '';
        $supplierAddress = $section1['manufacturer_address'] ?? '';
        $supplierPhone   = $section1['manufacturer_phone'] ?? '';

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
                $isBig, $netWeight, $privateLabel
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
        bool $isBig, string $netWeight = '', bool $privateLabel = false
    ): void {
        $pad = $isBig ? 1.5 : 1.0;
        $innerW = $w - 2 * $pad;
        $innerX = $x + $pad;

        $curY = $y + $pad;

        $nameSize    = $isBig ? 9 : 7;
        $signalSize  = $isBig ? 8 : 5.5;
        $bodySize    = $isBig ? 5 : 3.5;
        $tinySize    = $isBig ? 4.5 : 3.5;
        $pictoSize   = $isBig ? 7 : 5;

        // ── Lot Number & Item Code ──
        $lineH = $isBig ? 4 : 3;
        $pdf->SetFont('helvetica', 'B', $nameSize);
        $lotLine = 'LOT: ' . $lotNumber . $itemCode;
        if ($netWeight !== '') {
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

        $pdf->Line($innerX, $curY, $innerX + $innerW, $curY);
        $curY += 0.5;

        // ── Pictograms + Signal Word ──
        $pictoRowH = $isBig ? 9 : 5.5;
        $availPictos = $this->getAvailablePictograms($pictograms);
        $numPictos = count($availPictos);

        if ($numPictos > 0 || $signalWord) {
            $pictoAreaW = $numPictos > 0 ? min($numPictos * ($pictoSize + 1), $innerW * 0.6) : 0;
            $signalAreaW = $innerW - $pictoAreaW;

            $pictoX = $innerX;
            foreach ($availPictos as $pictoPath) {
                if ($pictoPath !== '') {
                    $pdf->Image($pictoPath, $pictoX, $curY + 0.5, $pictoSize, $pictoSize, '', '', '', true, 300, '', false, false, 0);
                }
                $pictoX += $pictoSize + ($isBig ? 1 : 0.5);
            }

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

        $supplierH = $privateLabel ? 0 : ($isBig ? 6 : 5);
        $availH = $maxBottom - $curY - $supplierH - 1;

        if ($hText !== '') {
            $pdf->SetFont('helvetica', 'B', $tinySize);
            $pdf->SetXY($innerX, $curY);
            $pdf->Cell($innerW, $isBig ? 2.5 : 2, 'Hazard Statements:', 0, 0, 'L');
            $curY += $isBig ? 2.5 : 2;

            $pdf->SetFont('helvetica', '', $tinySize);
            $pdf->SetXY($innerX, $curY);

            $hNeeded = $this->getTextHeight($pdf, $hText, $innerW, $tinySize);
            $hAlloc = min($availH, $hNeeded);
            $pdf->MultiCell($innerW, $isBig ? 2.2 : 1.8, $hText, 0, 'L', false, 1, $innerX, $curY, true, 0, false, true, $hAlloc, 'T', true);
            $curY += $hAlloc;
        }

        // ── Precautionary Statements ──
        $availForP = $maxBottom - $curY - $supplierH - 1;
        if (count($pStatements) > 0 && $availForP > ($isBig ? 4 : 3)) {
            $headerH = $isBig ? 2.5 : 2;
            $seeMoreText = 'See SDS for additional precautionary statements.';
            $seeMoreH = $this->getTextHeight($pdf, $seeMoreText, $innerW, $tinySize);

            $pTextFull = $this->formatStatements($pStatements);
            $pFullNeeded = $this->getTextHeight($pdf, $pTextFull, $innerW, $tinySize);
            $pSpaceForText = $availForP - $headerH - 0.5;

            if ($pFullNeeded <= $pSpaceForText) {
                $pText = $pTextFull;
                $pTruncated = false;
            } else {
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
                $seeY = $curY;
                $pdf->SetFont('helvetica', 'I', $tinySize);
                $pdf->SetXY($innerX, $seeY);
                $pdf->MultiCell($innerW, $isBig ? 2.2 : 1.8, $seeMoreText, 0, 'L', false, 1, $innerX, $seeY, true, 0, false, true, $seeMoreH, 'T', true);
            }
        }

        // ── Supplier info ──
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

    // ── Shared helpers ───────────────────────────────────────────────────

    private function formatStatements(array $statements): string
    {
        $parts = [];
        foreach ($statements as $stmt) {
            $code = $stmt['code'] ?? '';
            $text = $stmt['text'] ?? '';
            if ($code === '' && $text === '') continue;
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

    private function buildPrioritizedPStatements(\TCPDF $pdf, array $pStatements, float $width, float $fontSize, float $maxHeight): string
    {
        $priority = [];
        $secondary = [];

        foreach ($pStatements as $stmt) {
            $code = $stmt['code'] ?? '';
            $num = (int) substr($code, 1);
            if ($num >= 200 && $num < 400) {
                $priority[] = $stmt;
            } else {
                $secondary[] = $stmt;
            }
        }

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

    private function getTextHeight(\TCPDF $pdf, string $text, float $width, float $fontSize): float
    {
        $pdf->SetFont('helvetica', '', $fontSize);
        $lines = $pdf->getNumLines($text, $width);
        return $lines * $fontSize * 0.45;
    }
}
