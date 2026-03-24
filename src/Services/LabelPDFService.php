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
    /** Minimum font size (pt) for hazard and precautionary statements per OSHA requirements. */
    private const MIN_STATEMENT_FONT_SIZE = 6.0;

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

        // Extract Prop 65 warning text (if present)
        $prop65Text = $sdsData['prop65_result']['warning_text'] ?? '';

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
                $netWeight, $privateLabel, $prop65Text
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
        string $netWeight, bool $privateLabel, string $prop65Text = ''
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
                    $fieldBold = !isset($pos['bold']) || !empty($pos['bold']);
                    $this->renderLotItemCode($pdf, $fx, $fy, $fw, $fh, $fieldFont, $lotNumber, $itemCode, $fieldBold, $labelX + $pad, $labelW - 2 * $pad);
                    break;

                case 'net_weight':
                    if ($netWeight !== '') {
                        $fieldBold = !isset($pos['bold']) || !empty($pos['bold']);
                        $this->renderNetWeight($pdf, $fx, $fy, $fw, $fh, $fieldFont, $netWeight, $fieldBold, $labelX + $pad, $labelW - 2 * $pad);
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

                case 'prop65_warning':
                    if ($prop65Text !== '') {
                        $this->renderProp65Warning($pdf, $fx, $fy, $fw, $fh, $fieldFont, $prop65Text);
                    }
                    break;

                case 'supplier_info':
                    if (!$privateLabel) {
                        $fieldAlign = isset($pos['align']) ? strtoupper((string) $pos['align']) : 'C';
                        $dividerTop = !isset($pos['divider_top']) || !empty($pos['divider_top']);
                        $this->renderSupplierInfo($pdf, $fx, $fy, $fw, $fh, $fieldFont, $supplierName, $supplierAddress, $supplierPhone, $fieldAlign, $dividerTop);
                    }
                    break;
            }
        }
    }

    // ── Template-based field renderers ────────────────────────────────────

    private function renderLotItemCode(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, string $lotNumber, string $itemCode, bool $bold = true, ?float $labelX = null, ?float $labelW = null): void
    {
        // Lot number with trailing item code
        $lotText = $lotNumber . $itemCode;
        $labelPrefix = 'LOT: ';
        $fullText = $labelPrefix . $lotText;

        $valueStyle = $bold ? 'B' : '';
        $fontSize = $this->fitFontSize($pdf, $fullText, $w, $h * 0.85, $baseFontSize, $valueStyle);

        // Draw "LOT: " prefix in normal weight, then lot+item code
        $pdf->SetFont('helvetica', '', $fontSize);
        $prefixW = $pdf->GetStringWidth($labelPrefix);
        $pdf->SetXY($x, $y);
        $pdf->Cell($prefixW, $h, $labelPrefix, 0, 0, 'L', false, '', 0, false, 'T', 'C');

        $pdf->SetFont('helvetica', $valueStyle, $fontSize);
        $pdf->SetXY($x + $prefixW, $y);
        $pdf->Cell($w - $prefixW, $h, $lotText, 0, 0, 'L', false, '', 0, false, 'T', 'C');

        // Draw a divider line spanning the full label width
        $lineX = $labelX ?? $x;
        $lineW = $labelW ?? $w;
        $pdf->SetLineWidth(0.3);
        $pdf->Line($lineX, $y + $h, $lineX + $lineW, $y + $h);
        $pdf->SetLineWidth(0.2);
    }

    private function renderNetWeight(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, string $netWeight, bool $bold = true, ?float $labelX = null, ?float $labelW = null): void
    {
        // Net weight — right-aligned for at-a-glance reading
        $labelPrefix = 'NET WT: ';
        $fullText = $labelPrefix . $netWeight;

        $valueStyle = $bold ? 'B' : '';
        $fontSize = $this->fitFontSize($pdf, $fullText, $w, $h * 0.85, $baseFontSize, $valueStyle);

        // Measure widths for right-alignment
        $pdf->SetFont('helvetica', $valueStyle, $fontSize);
        $valueW = $pdf->GetStringWidth($netWeight);
        $pdf->SetFont('helvetica', '', $fontSize);
        $prefixW = $pdf->GetStringWidth($labelPrefix);
        $totalW = $prefixW + $valueW;

        // Right-align the whole block within the field
        $startX = $x + $w - $totalW;
        $startX = max($x, $startX); // Don't overflow left

        $pdf->SetFont('helvetica', '', $fontSize);
        $pdf->SetXY($startX, $y);
        $pdf->Cell($prefixW, $h, $labelPrefix, 0, 0, 'L', false, '', 0, false, 'T', 'C');

        $pdf->SetFont('helvetica', $valueStyle, $fontSize);
        $pdf->SetXY($startX + $prefixW, $y);
        $pdf->Cell($valueW, $h, $netWeight, 0, 0, 'L', false, '', 0, false, 'T', 'C');

        // Draw divider line spanning the full label width
        $lineX = $labelX ?? $x;
        $lineW = $labelW ?? $w;
        $pdf->SetLineWidth(0.3);
        $pdf->Line($lineX, $y + $h, $lineX + $lineW, $y + $h);
        $pdf->SetLineWidth(0.2);
    }

    private function renderProp65Warning(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, string $prop65Text): void
    {
        // Draw a thin border around the Prop 65 warning area
        $pdf->SetLineWidth(0.2);
        $pdf->Rect($x, $y, $w, $h);

        $innerPad = 0.8;
        $ix = $x + $innerPad;
        $iy = $y + $innerPad;
        $iw = $w - 2 * $innerPad;
        $ih = $h - 2 * $innerPad;

        // Prop 65 warning pictogram + bold "WARNING:" header (minimum 6pt font per CA regulation)
        $headerSize = max(6.0, $baseFontSize);
        $headerH = $headerSize * 0.5;
        $pictoSize = $headerH * 1.8;

        $prop65Png = PictogramHelper::getPngPath('PROP65');
        $textOffsetX = 0.0;
        if ($prop65Png !== '') {
            $pdf->Image($prop65Png, $ix, $iy, $pictoSize, $pictoSize, '', '', '', true, 300, '', false, false, 0);
            $textOffsetX = $pictoSize + 0.5;
        }

        // Strip the leading "WARNING: " prefix — we render it inline as bold
        $bodyText = $prop65Text;
        if (str_starts_with($bodyText, 'WARNING: ')) {
            $bodyText = substr($bodyText, 9);
        }

        // Render "WARNING:" bold + body text inline to the right of the pictogram
        $bodyFontSize = max(6.0, $baseFontSize);
        $textX = $ix + $textOffsetX;
        $textW = $iw - $textOffsetX;
        $textH = $ih;

        // Use HTML so "WARNING:" stays bold inline with the regular body text
        $htmlContent = '<span style="font-weight: bold; font-size: ' . $headerSize . 'pt;">WARNING:</span> '
            . '<span style="font-size: ' . $bodyFontSize . 'pt;">' . htmlspecialchars($bodyText) . '</span>';

        $pdf->SetXY($textX, $iy);
        $pdf->writeHTMLCell($textW, $textH, $textX, $iy, $htmlContent, 0, 0, false, true, 'L', true);
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
        $gap = 0.5;

        // Size each pictogram to fit, supporting multiple rows if needed
        $maxPictoSize = min($h * 0.45, 10.0);
        $perRow = max(1, (int) floor(($w + $gap) / ($maxPictoSize + $gap)));
        $pictoSize = min($maxPictoSize, ($w - ($perRow - 1) * $gap) / $perRow);
        $pictoSize = max(3, $pictoSize);

        // Recalculate actual items per row now that size is known
        $perRow = max(1, (int) floor(($w + $gap) / ($pictoSize + $gap)));
        $rows = (int) ceil($n / $perRow);

        $curY = $y; // top-aligned
        $idx = 0;
        for ($row = 0; $row < $rows; $row++) {
            $itemsThisRow = min($perRow, $n - $idx);
            $rowW = $itemsThisRow * $pictoSize + ($itemsThisRow - 1) * $gap;
            $startX = $x + max(0, ($w - $rowW) / 2);

            for ($col = 0; $col < $itemsThisRow; $col++) {
                $path = $paths[$idx++];
                if ($path !== '') {
                    $pdf->Image($path, $startX, $curY, $pictoSize, $pictoSize, '', '', '', true, 300, '', false, false, 0);
                }
                $startX += $pictoSize + $gap;
            }
            $curY += $pictoSize + $gap;
        }
    }

    private function renderStatements(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, array $statements, string $header): void
    {
        if (empty($statements)) return;

        $minFont = self::MIN_STATEMENT_FONT_SIZE;
        $headerSize = max($minFont, $baseFontSize);
        $headerH = $headerSize * 0.45;
        $bodyH = $h - $headerH;
        $bodyFontSize = max($minFont, $baseFontSize);

        // Try fitting all statements; prioritize if they don't fit
        $fullText = $this->formatStatements($statements);
        if ($fullText === '') return;

        $testSize = $this->fitMultilineFontSize($pdf, $fullText, $w, $bodyH, $bodyFontSize);

        if ($testSize >= $minFont) {
            $text = $fullText;
            $bodySize = $testSize;
        } else {
            $seeMore = 'See SDS for more hazard statements.';
            $text = $this->buildPrioritizedHStatements($pdf, $statements, $w, $bodyFontSize, $bodyH, $seeMore);
            $bodySize = max($minFont, $this->fitMultilineFontSize($pdf, $text, $w, $bodyH, $bodyFontSize));
        }

        // Header
        $pdf->SetFont('helvetica', 'B', $headerSize);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $headerH, $header, 0, 0, 'L');

        // Body
        $pdf->SetFont('helvetica', '', $bodySize);
        $lineH = $bodySize * 0.42;
        $pdf->SetXY($x, $y + $headerH);
        $pdf->MultiCell($w, $lineH, $text, 0, 'L', false, 1, $x, $y + $headerH, true, 0, false, true, max(0, $bodyH), 'T', true);
    }

    private function renderPStatements(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, array $pStatements): void
    {
        if (empty($pStatements)) return;

        $minFont = self::MIN_STATEMENT_FONT_SIZE;
        $headerSize = max($minFont, $baseFontSize);
        $headerH = $headerSize * 0.45;
        $bodyH = $h - $headerH;
        $bodyFontSize = max($minFont, $baseFontSize);

        // Try fitting all P-statements; prioritize if they don't fit
        $fullText = $this->formatStatements($pStatements);
        $testSize = $this->fitMultilineFontSize($pdf, $fullText, $w, $bodyH, $bodyFontSize);

        if ($testSize >= $minFont) {
            $pText = $fullText;
            $bodySize = $testSize;
        } else {
            $seeMore = 'See SDS for more precautionary statements.';
            $prioritizedText = $this->buildPrioritizedPStatements($pdf, $pStatements, $w, $bodyFontSize, $bodyH, $seeMore);
            $bodySize = max($minFont, $this->fitMultilineFontSize($pdf, $prioritizedText, $w, $bodyH, $bodyFontSize));
            $pText = $prioritizedText;
        }

        // Header
        $pdf->SetFont('helvetica', 'B', $headerSize);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $headerH, 'Precautionary Statements:', 0, 0, 'L');

        // Body
        $pdf->SetFont('helvetica', '', $bodySize);
        $lineH = $bodySize * 0.42;
        $pdf->SetXY($x, $y + $headerH);
        $pdf->MultiCell($w, $lineH, $pText, 0, 'L', false, 1, $x, $y + $headerH, true, 0, false, true, max(0, $bodyH), 'T', true);
    }

    private function renderSupplierInfo(\TCPDF $pdf, float $x, float $y, float $w, float $h, float $baseFontSize, string $name, string $address, string $phone, string $align = 'C', bool $dividerTop = true): void
    {
        // Draw divider at top (skip when supplier info is placed at top of label)
        if ($dividerTop) {
            $pdf->Line($x, $y, $x + $w, $y);
        }

        $line = $name;
        if ($address !== '' && $address !== ', ,  ') {
            $line .= ' | ' . $address;
        }
        if ($phone !== '') {
            $line .= ' | ' . $phone;
        }

        $fontSize = $this->fitMultilineFontSize($pdf, $line, $w, $h, max(6.0, $baseFontSize));
        $pdf->SetFont('helvetica', '', $fontSize);
        $lineH = $fontSize * 0.42;
        $pdf->SetXY($x, $y + 0.3);
        $pdf->MultiCell($w, $lineH, $line, 0, $align, false, 1, $x, $y + 0.3, true, 0, false, true, $h - 0.3, 'T', true);
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
        $minSize = self::MIN_STATEMENT_FONT_SIZE;
        $size = max($maxSize, $minSize);

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
        $minSize = self::MIN_STATEMENT_FONT_SIZE;
        $size = max($maxSize, $minSize);

        while ($size > $minSize) {
            $needed = $this->getTextHeight($pdf, $text, $w, $size);
            if ($needed <= $h) {
                return $size;
            }
            $size -= 0.25;
        }

        // Check if text fits at minimum size; return below threshold to trigger truncation
        $needed = $this->getTextHeight($pdf, $text, $w, $minSize);
        if ($needed > $h) {
            return $minSize - 0.25;
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
        $signalSize  = $isBig ? 8 : 6;
        $bodySize    = $isBig ? 6 : 6;
        $tinySize    = max(self::MIN_STATEMENT_FONT_SIZE, $isBig ? 6 : 6);
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

        // ── Signal Word ──
        $availPictos = $this->getAvailablePictograms($pictograms);
        $numPictos = count($availPictos);

        if ($signalWord) {
            $signalH = $isBig ? 5 : 3.5;
            $pdf->SetFont('helvetica', 'B', $signalSize);
            if (strtoupper($signalWord) === 'DANGER') {
                $pdf->SetTextColor(255, 0, 0);
            } else {
                $pdf->SetTextColor(0, 0, 0);
            }
            $pdf->SetXY($innerX, $curY);
            $pdf->Cell($innerW, $signalH, strtoupper($signalWord), 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
            $curY += $signalH;
        }

        // ── Pictograms (below signal word) ──
        if ($numPictos > 0) {
            $gap = $isBig ? 1 : 0.5;
            $perRow = max(1, (int) floor(($innerW + $gap) / ($pictoSize + $gap)));
            $rows = (int) ceil($numPictos / $perRow);

            $idx = 0;
            for ($r = 0; $r < $rows; $r++) {
                $itemsThisRow = min($perRow, $numPictos - $idx);
                $rowW = $itemsThisRow * $pictoSize + ($itemsThisRow - 1) * $gap;
                $pictoX = $innerX + max(0, ($innerW - $rowW) / 2);

                for ($c = 0; $c < $itemsThisRow; $c++) {
                    $pictoPath = $availPictos[$idx++];
                    if ($pictoPath !== '') {
                        $pdf->Image($pictoPath, $pictoX, $curY, $pictoSize, $pictoSize, '', '', '', true, 300, '', false, false, 0);
                    }
                    $pictoX += $pictoSize + $gap;
                }
                $curY += $pictoSize + $gap;
            }
        }

        // ── Hazard Statements ──
        $maxBottom = $y + $h - $pad;
        $hTextFull = $this->formatStatements($hStatements);

        $supplierH = $privateLabel ? 0 : ($isBig ? 6 : 5);
        $availH = $maxBottom - $curY - $supplierH - 1;

        if ($hTextFull !== '') {
            $headerH = $isBig ? 2.5 : 2;
            $hSpaceForText = $availH - $headerH - 0.5;
            $hFullNeeded = $this->getTextHeight($pdf, $hTextFull, $innerW, $tinySize);

            if ($hFullNeeded <= $hSpaceForText) {
                $hText = $hTextFull;
            } else {
                $seeMore = 'See SDS for more hazard statements.';
                $hText = $this->buildPrioritizedHStatements($pdf, $hStatements, $innerW, $tinySize, $hSpaceForText, $seeMore);
            }

            $pdf->SetFont('helvetica', 'B', $tinySize);
            $pdf->SetXY($innerX, $curY);
            $pdf->Cell($innerW, $headerH, 'Hazard Statements:', 0, 0, 'L');
            $curY += $headerH;

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

            $pTextFull = $this->formatStatements($pStatements);
            $pFullNeeded = $this->getTextHeight($pdf, $pTextFull, $innerW, $tinySize);
            $pSpaceForText = $availForP - $headerH - 0.5;

            if ($pFullNeeded <= $pSpaceForText) {
                $pText = $pTextFull;
            } else {
                $seeMore = 'See SDS for more precautionary statements.';
                $pText = $this->buildPrioritizedPStatements($pdf, $pStatements, $innerW, $tinySize, $pSpaceForText, $seeMore);
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

    private function buildPrioritizedHStatements(\TCPDF $pdf, array $hStatements, float $width, float $fontSize, float $maxHeight, string $truncationNotice = ''): string
    {
        $priority = [];
        $secondary = [];

        foreach ($hStatements as $stmt) {
            $code = $stmt['code'] ?? '';
            $num = (int) substr($code, 1);
            // Prioritize health hazards (H300-H399) and physical hazards (H200-H299)
            if ($num >= 200 && $num < 400) {
                $priority[] = $stmt;
            } else {
                $secondary[] = $stmt;
            }
        }

        $ordered = array_merge($priority, $secondary);
        $included = [];
        $wasTruncated = false;

        foreach ($ordered as $stmt) {
            $candidate = array_merge($included, [$stmt]);
            $candidateText = $this->formatStatements($candidate);
            if ($truncationNotice !== '') {
                $candidateText .= ' ' . $truncationNotice;
            }
            $needed = $this->getTextHeight($pdf, $candidateText, $width, $fontSize);
            if ($needed > $maxHeight && count($included) > 0) {
                $wasTruncated = true;
                break;
            }
            $included[] = $stmt;
        }

        $text = $this->formatStatements($included);
        if ($wasTruncated && $truncationNotice !== '') {
            $text .= ' ' . $truncationNotice;
        }

        return $text;
    }

    private function buildPrioritizedPStatements(\TCPDF $pdf, array $pStatements, float $width, float $fontSize, float $maxHeight, string $truncationNotice = ''): string
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
        $wasTruncated = false;

        foreach ($ordered as $stmt) {
            $candidate = array_merge($included, [$stmt]);
            $candidateText = $this->formatStatements($candidate);
            if ($truncationNotice !== '') {
                $candidateText .= ' ' . $truncationNotice;
            }
            $needed = $this->getTextHeight($pdf, $candidateText, $width, $fontSize);
            if ($needed > $maxHeight && count($included) > 0) {
                $wasTruncated = true;
                break;
            }
            $included[] = $stmt;
        }

        $text = $this->formatStatements($included);
        if ($wasTruncated && $truncationNotice !== '') {
            $text .= ' ' . $truncationNotice;
        }

        return $text;
    }

    private function getTextHeight(\TCPDF $pdf, string $text, float $width, float $fontSize): float
    {
        $pdf->SetFont('helvetica', '', $fontSize);
        $lines = $pdf->getNumLines($text, $width);
        return $lines * $fontSize * 0.45;
    }
}
