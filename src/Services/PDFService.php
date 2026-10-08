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
        'symptoms'             => 'symptoms_effects',
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
        'version'              => 'version',
        'effective_date'       => 'effective_date',
        'revision_date'        => 'revision_date',   // legacy snapshots only
        'revision_note'        => 'revision_note',
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
        $outputDir = $outputDir ?? App::config('paths.generated_pdfs', App::basePath() . '/public/generated-pdfs');

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $pdf = $this->buildPdf($sdsData);
        $meta = $sdsData['meta'];

        // On-disk name. The stem is the (pack-stripped) product or alias code,
        // plus the optional meta.filename_tag that distinguishes documents
        // sharing a code (private label variants carry "PL_<manufacturer>").
        // Three forms:
        //   versioned publish   {code}_v{n}[_{lang}].pdf               (meta.sds_version > 0)
        //   private label       {code}_PL_{Manufacturer}_v{n}[_{lang}].pdf (tag + sds_version)
        //   preview / ad hoc    {code}[_{tag}]_SDS_{lang}_{Ymd_His}.pdf  (no sds_version)
        // Publishers stamp meta.sds_version (SDSGenerator::stampPublishedVersion)
        // before rendering so the version number is part of the name. reserveUniquePath() is the safety net:
        // an exact-name clash (a re-render of the same version, or two
        // previews in the same second) gets _2, _3, ... instead of
        // overwriting the earlier file. No random suffixes.
        $stem = sanitize_filename(strip_pack_extension($meta['product_code']));
        $tag  = trim((string) ($meta['filename_tag'] ?? ''));
        if ($tag !== '') {
            $stem .= '_' . sanitize_filename($tag);
        }
        $version = (int) ($meta['sds_version'] ?? 0);
        if ($version > 0) {
            $base = self::versionedBaseName($stem, $version, (string) $meta['language']);
        } else {
            $base = $stem . '_SDS_' . $meta['language'] . '_' . date('Ymd_His');
        }
        $filepath = self::reserveUniquePath($outputDir, $base, '.pdf');

        // Render to memory and write with PHP (same bytes as Output('F')) so a
        // failed or short write is a catchable exception and never leaves the
        // reserved 0-byte placeholder behind.
        $bytes   = $pdf->Output('', 'S');
        $written = @file_put_contents($filepath, $bytes);
        if ($bytes === '' || $written === false || $written !== strlen($bytes)) {
            @unlink($filepath);
            throw new \RuntimeException("Unable to write PDF file: {$filepath}");
        }

        return $filepath;
    }

    /**
     * Public wrapper around reserveUniquePath() for callers that name their
     * own files (SDSAutoSendService + generateToFile()). Returns a reserved,
     * empty "{dir}/{base}{ext}" (or "{base}_2{ext}", ...) that the caller
     * must write over or unlink.
     *
     * @throws \RuntimeException if no path could be reserved
     */
    public static function uniquePath(string $dir, string $base, string $ext): string
    {
        return self::reserveUniquePath($dir, $base, $ext);
    }

    /**
     * Base name (no extension) of a published document: "{stem}_v{n}" for
     * the default language (sds.default_language, normally "en") and
     * "{stem}_v{n}_{lang}" for every other language, so UVNG009_v1.pdf is
     * the English sheet and UVNG009_v1_es.pdf the Spanish one. $stem is the
     * already-sanitised code (plus any filename tag).
     */
    public static function versionedBaseName(string $stem, int $version, string $lang): string
    {
        $default = strtolower(trim((string) App::config('sds.default_language', 'en')));
        $lang    = strtolower(trim($lang));
        $base    = $stem . '_v' . $version;
        if ($lang !== '' && $lang !== $default) {
            $base .= '_' . sanitize_filename($lang);
        }
        return $base;
    }

    /**
     * Atomically reserve an unused path in $dir: "{base}{ext}", then
     * "{base}_2{ext}", "{base}_3{ext}", ... The reservation uses fopen('x'),
     * which fails if the file already exists, so two processes can never be
     * handed the same path. The caller then writes over the empty file.
     *
     * @throws \RuntimeException if no path could be reserved
     */
    private static function reserveUniquePath(string $dir, string $base, string $ext): string
    {
        for ($n = 1; $n <= 1000; $n++) {
            $candidate = $dir . '/' . $base . ($n > 1 ? '_' . $n : '') . $ext;
            $fh = @fopen($candidate, 'x');
            if ($fh !== false) {
                fclose($fh);
                return $candidate;
            }
            if (!file_exists($candidate)) {
                // Failed for a reason other than "already exists" (permissions, bad dir)
                throw new \RuntimeException("Unable to create PDF file in {$dir}");
            }
        }
        throw new \RuntimeException("Unable to reserve a unique PDF filename for {$base}{$ext} in {$dir}");
    }

    /**
     * Generate PDF and return as string (for streaming download).
     */
    public function generateString(array $sdsData): string
    {
        $pdf = $this->buildPdf($sdsData);

        return $pdf->Output('', 'S');
    }

    /**
     * Generate a PDF and write it to an exact file path chosen by the caller.
     *
     * Unlike generate(), no filename is derived — the caller owns the name.
     * The parent directory is created if it does not exist.
     *
     * @param  array  $sdsData   Full SDS data from SDSGenerator::generate()
     * @param  string $filePath  Absolute path of the PDF file to write
     * @return string            The same $filePath, for chaining convenience
     * @throws \RuntimeException If the parent directory cannot be created
     *                           or the file cannot be (fully) written
     */
    public function generateToFile(array $sdsData, string $filePath): string
    {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create PDF output directory: {$dir}");
        }

        // Render to memory and write with PHP rather than TCPDF Output('F').
        // The bytes are identical (same buffer), but TCPDF reports an
        // unopenable path through Error(), which die()s under the bundled
        // config (K_TCPDF_THROW_EXCEPTION_ERROR=false) and so bypasses the
        // callers' try/catch blocks, and it never checks fwrite()'s return.
        $bytes = $this->buildPdf($sdsData)->Output('', 'S');
        $written = @file_put_contents($filePath, $bytes);
        if ($written === false || $written !== strlen($bytes)) {
            throw new \RuntimeException("Unable to write PDF file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Build the fully rendered TCPDF document shared by every output mode.
     *
     * Sets metadata, margins, header/footer, renders all 16 sections and
     * the legal disclaimer. Callers decide how to emit the result
     * (file path, exact file, or string).
     */
    private function buildPdf(array $sdsData): SDSTcpdf
    {
        if (!class_exists('TCPDF')) {
            throw new \RuntimeException('TCPDF library not found. Run: composer require tecnickcom/tcpdf');
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
        // Author = the supplier named in Section 1 (admin settings for a
        // standard SDS, the manufacturer record for a private label SDS),
        // never config.php.
        $author = trim((string) ($sections[1]['manufacturer_name'] ?? ''));
        $pdf->SetAuthor($author !== '' ? $author : 'SDS System');
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

        // Footer — product code, page number and the same version / effective
        // date Section 16 prints (SDSGenerator::stampPublishedVersion). The
        // full right-hand text, prefix included, is composed here.
        $pdf->setFooterInfo($meta['product_code'], $this->footerRevision($meta, $sections[16] ?? []));

        // Add first page
        $pdf->AddPage();

        // Render each section
        foreach ($sections as $num => $section) {
            $this->renderSection($pdf, $num, $section);
        }

        // Render legal disclaimer after all sections
        $this->renderLegalDisclaimer($pdf, $sdsData['legal_disclaimer'] ?? '');

        return $pdf;
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
            case 12:
                $this->renderSection12($pdf, $section);
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

        // Shared Sections 12-15 footnote (item #25); no-op when absent/empty.
        $this->renderGhsSectionNote($pdf, $section);

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
        // App. D 1(c): name, address, telephone; email/website are optional
        // supplier details; 1(d) emergency number closes the block.
        $this->labelValue($pdf, $this->label('company'), $s['manufacturer_name'] ?? '');
        $this->labelValue($pdf, $this->label('address'), $s['manufacturer_address'] ?? '');
        $this->labelValue($pdf, $this->label('phone'), $s['manufacturer_phone'] ?? '');
        // English defaults keep pre-existing snapshots (meta.labels without these keys) readable.
        $this->labelValue($pdf, $this->label('email', 'Email'), $s['manufacturer_email'] ?? '');
        $this->labelValue($pdf, $this->label('website', 'Website'), $s['manufacturer_website'] ?? '');
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

        // Not classified statement
        if (empty($s['is_classified'])) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(0, 5, $s['not_classified_text'] ?? 'Not a hazardous substance or mixture.', 0, 'L');
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

        // Hazard classes summary — grouped by hazard type, with H-code +
        // hazard-statement text inlined so each hazard is shown once
        // instead of appearing both here and in a separate Hazard
        // Statements list. Entries within each group are sorted by the
        // first H-code number so severity reads top-down (H300 before
        // H400, H315 before H319, etc.).
        if (!empty($s['hazard_classes'])) {
            // Build an H-code → statement-text map from $s['h_statements']
            // so the classification line can append the phrase text.
            $statementsByCode = [];
            foreach ($s['h_statements'] ?? [] as $stmt) {
                $code = (string) ($stmt['code'] ?? '');
                if ($code !== '') {
                    $statementsByCode[$code] = (string) ($stmt['text'] ?? '');
                }
            }

            $lookupStatement = function (array $hCodes) use ($statementsByCode): string {
                // Try the code(s) as given first (handles combined codes
                // like "H300+H310+H330" that index as a single entry),
                // then fall back to splitting on '+' and looking up the
                // component codes.
                foreach ($hCodes as $code) {
                    if (!empty($statementsByCode[$code])) {
                        return $statementsByCode[$code];
                    }
                    foreach (explode('+', (string) $code) as $part) {
                        $part = trim($part);
                        if ($part !== '' && !empty($statementsByCode[$part])) {
                            return $statementsByCode[$part];
                        }
                    }
                }
                return '';
            };

            $firstHCodeNum = function (array $hCodes): int {
                if (empty($hCodes)) return 9999;
                if (preg_match('/H(\d+)/', (string) $hCodes[0], $m)) {
                    return (int) $m[1];
                }
                return 9999;
            };

            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('ghs_classification') . ':', 0, 1);

            $grouped = HazardEngine::groupByHazardType($s['hazard_classes']);
            $groupLabels = [
                'physical'      => $this->label('physical_hazards'),
                'health'        => $this->label('health_hazards'),
                'environmental' => $this->label('environmental_hazards'),
            ];

            // Indent rendered lines by bumping the left margin for the
            // duration of each category's list. Prefixing with "  " would
            // only indent the first visual line; MultiCell wraps long
            // lines and every wrap would fall back to the page left
            // margin, producing a jagged outdent on the second line.
            $origLeftMargin = ($pdf->getMargins()['left'] ?? 10);
            $indentedMargin = $origLeftMargin + 4; // ~4mm hanging indent

            foreach ($groupLabels as $groupKey => $groupLabel) {
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell(0, 5, $groupLabel . ':', 0, 1);
                $pdf->SetFont('helvetica', '', 9);

                $pdf->SetLeftMargin($indentedMargin);
                $pdf->SetX($indentedMargin);

                if (empty($grouped[$groupKey])) {
                    $pdf->MultiCell(0, 4, 'None', 0, 'L');
                } else {
                    $sorted = $grouped[$groupKey];
                    usort($sorted, function ($a, $b) use ($firstHCodeNum) {
                        return $firstHCodeNum($a['h_codes'] ?? []) <=> $firstHCodeNum($b['h_codes'] ?? []);
                    });

                    $seen = [];
                    foreach ($sorted as $hc) {
                        $class = trim($hc['class_translated'] ?? $hc['class'] ?? '');
                        $category = trim($hc['category_translated'] ?? $hc['category'] ?? '');
                        $classLabel = ($class !== '' && $category !== '')
                            ? $class . ' (' . $category . ')'
                            : ($class !== '' ? $class : $category);
                        $hCodes   = is_array($hc['h_codes'] ?? null) ? $hc['h_codes'] : [];
                        $hcPrefix = !empty($hCodes) ? implode(', ', $hCodes) . ' — ' : '';
                        $stmtText = $lookupStatement($hCodes);

                        $line = $hcPrefix . $classLabel;
                        if ($stmtText !== '') {
                            $line .= ': ' . $stmtText;
                        }
                        if ($line !== '' && !isset($seen[$line])) {
                            $seen[$line] = true;
                            $pdf->MultiCell(0, 4, $line, 0, 'L');
                        }
                    }
                }

                $pdf->SetLeftMargin($origLeftMargin);
            }
            $pdf->Ln(1);
        }

        // Hazard statements are rendered inline with each classification
        // above (H-code + phrase on the same line under its category) —
        // no standalone list needed.

        // Precautionary statements — same 4mm hanging indent as the
        // hazard rows so long statements that wrap keep their indent on
        // every wrapped line.
        if (!empty($s['p_statements'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('precautionary_statements') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 9);

            $origLeftMargin = ($pdf->getMargins()['left'] ?? 10);
            $pdf->SetLeftMargin($origLeftMargin + 4);
            $pdf->SetX($origLeftMargin + 4);

            foreach ($s['p_statements'] as $stmt) {
                $code = $stmt['code'] ?? '';
                $text = $stmt['text'] ?? '';
                $line = $code;
                if ($text !== '') {
                    $line .= ': ' . $text;
                }
                $pdf->MultiCell(0, 4, $line, 0, 'L');
            }

            $pdf->SetLeftMargin($origLeftMargin);
        }

        // PPE derived from the H-codes — pictograms plus the same sentences
        // Section 8 prints (SDSGenerator::resolvePPE), so PDF and preview match.
        $ppe = $s['ppe_recommendations'] ?? [];
        $hasPPE = !empty($ppe['respiratory']) || !empty($ppe['hand_protection'])
               || !empty($ppe['eye_protection']) || !empty($ppe['skin_protection']);
        if ($hasPPE) {
            $pdf->Ln(1);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('ppe_recommendations') . ':', 0, 1);

            // Render PPE pictogram table (with descriptions under each pictogram)
            $this->renderPPEPictogramRow($pdf, $ppe);

            // Sentences, same order and labels as src/Views/sds/preview.php
            $ppeLines = [
                'respiratory'     => 'respiratory',
                'hand_protection' => 'hand_protection',
                'eye_protection'  => 'eye_protection',
                'skin_protection' => 'skin_body',
            ];
            foreach ($ppeLines as $field => $labelKey) {
                $this->labelValue($pdf, $this->label($labelKey), (string) ($ppe[$field] ?? ''));
            }
        }

        // Other hazards (29 CFR 1910.1200 App. D, Section 2(c)) — always
        // printed. SDSGenerator::section2() supplies the per-product override
        // when one exists, otherwise the translated default ("None known.").
        // Keyed on the text rather than has_other_hazards so stored snapshots
        // generated before the default was printed also show the line.
        $otherHazards = trim((string) ($s['other_hazards'] ?? ''));
        if ($otherHazards !== '') {
            $pdf->Ln(1);
            $this->labelValue($pdf, $this->label('other_hazards'), $otherHazards);
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

            // Table header — CAS + Chemical Name + Concentration + H-codes
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(230, 230, 230);
            $w = [25, 75, 30, 40];
            $pdf->Cell($w[0], 5, $this->label('cas_number'), 1, 0, 'C', true);
            $pdf->Cell($w[1], 5, $this->label('chemical_name'), 1, 0, 'C', true);
            $pdf->Cell($w[2], 5, $this->label('concentration'), 1, 0, 'C', true);
            $pdf->Cell($w[3], 5, 'H-Codes', 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 8);

            foreach ($s['components'] as $comp) {
                $hCodes = (!empty($comp['h_codes']) && is_array($comp['h_codes']))
                    ? implode(', ', $comp['h_codes'])
                    : '';
                $cas   = (string) ($comp['cas_number'] ?? '');
                $name  = (string) ($comp['chemical_name'] ?? '');
                $conc  = (string) ($comp['concentration_range'] ?? '');

                // Use TCPDF's getStringHeight to compute the true rendered
                // height (accounts for cellHeightRatio × font size × actual
                // wrap behaviour). Previously used `getNumLines * 4mm`
                // which underestimated at 8pt — MultiCell with $maxh too
                // small would silently truncate and the overflow would
                // bleed into adjacent columns.
                $rowH = max(
                    5,
                    $pdf->getStringHeight($w[1], $name),
                    $pdf->getStringHeight($w[3], $hCodes)
                );

                $pdf->MultiCell($w[0], $rowH, $cas,    1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[1], $rowH, $name,   1, 'L', false, 0, '', '', true, 0, false, true, $rowH, 'T');
                $pdf->MultiCell($w[2], $rowH, $conc,   1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[3], $rowH, $hCodes, 1, 'C', false, 1, '', '', true, 0, false, true, $rowH, 'M');
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
                $cas     = (string) ($el['cas_number']   ?? '');
                $name    = (string) ($el['chemical_name'] ?? '');
                $ltype   = (string) ($el['limit_type']   ?? '');
                $value   = (string) ($el['value']         ?? '');
                $units   = (string) ($el['units']         ?? '');
                // Prescribed-range band attached by SDSGenerator::section8()
                // (same band as the Section 3 row for this CAS). Never print
                // the exact percentage here; snapshots older than this change
                // carry no band and render an empty cell instead.
                $concPct = (string) ($el['concentration_range'] ?? '');
                $notes   = (string) ($el['notes']         ?? '');

                // getStringHeight gives the actual rendered height — using
                // getNumLines * 4 underestimated at 7pt, causing MultiCell
                // to silently truncate long names/notes and overflow.
                $rowH = max(
                    5,
                    $pdf->getStringHeight($w[1], $name),
                    $pdf->getStringHeight($w[6], $notes)
                );

                $pdf->MultiCell($w[0], $rowH, $cas,     1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[1], $rowH, $name,    1, 'L', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[2], $rowH, $ltype,   1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[3], $rowH, $value,   1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[4], $rowH, $units,   1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[5], $rowH, $concPct, 1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[6], $rowH, $notes,   1, 'L', false, 1, '', '', true, 0, false, true, $rowH, 'M');
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
                // Prescribed-range band only (SDS content policy); a pre-banding
                // snapshot prints no value here, never the exact percentage.
                $label = ($comp['chemical_name'] ?? '') . ' (CAS ' . ($comp['cas_number'] ?? '') . ')';
                $band  = (string) ($comp['concentration_range'] ?? '');
                if ($band !== '') {
                    $label .= ' — ' . $band;
                }
                $pdf->Cell(0, 5, $label, 0, 1);
                $pdf->SetFont('helvetica', '', 8);

                // Carcinogen listings (agency: classification — registry description)
                if (!empty($comp['carcinogen_listings'])) {
                    foreach ($comp['carcinogen_listings'] as $listing) {
                        $pdf->Cell(5, 4, '', 0, 0);
                        $listingText = ($listing['agency'] ?? '') . ': ' . ($listing['classification'] ?? '');
                        if (!empty($listing['description'])) {
                            $listingText .= ' — ' . $listing['description'];
                        }
                        $pdf->MultiCell(0, 4, $listingText, 0, 'L');
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

    /**
     * Section 12 (audit item #23): ecotoxicity text + per-component aquatic
     * hazard table (category + M-factor per CAS). The shared Sections 12-15
     * footnote ('ghs_note', item #25) is printed by renderSection(), not here.
     */
    private function renderSection12(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, $this->label('ecotoxicity'), (string) ($s['ecotoxicity'] ?? ''));

        // Same Cell/MultiCell pattern as the Section 8 exposure-limit table.
        $rows = $s['component_aquatic'] ?? [];
        if (!empty($rows) && is_array($rows)) {
            $pdf->Ln(1);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('component_ecotox_data') . ':', 0, 1);

            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetFillColor(230, 230, 230);
            $w = [68, 25, 17, 32, 33];
            $pdf->Cell($w[0], 5, $this->label('chemical_name'), 1, 0, 'C', true);
            $pdf->Cell($w[1], 5, $this->label('cas_number'), 1, 0, 'C', true);
            $pdf->Cell($w[2], 5, $this->label('el_conc_pct'), 1, 0, 'C', true);
            $pdf->Cell($w[3], 5, $this->label('aquatic_acute'), 1, 0, 'C', true);
            $pdf->Cell($w[4], 5, $this->label('aquatic_chronic'), 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 7);

            foreach ($rows as $row) {
                $name    = (string) ($row['chemical_name'] ?? '');
                $cas     = (string) ($row['cas_number'] ?? '');
                // Prescribed-range band only (SDS content policy); never the exact value.
                $concPct = (string) ($row['concentration_range'] ?? '');
                $acute   = (string) ($row['acute'] ?? '');
                $chronic = (string) ($row['chronic'] ?? '');
                if ($acute === '')   { $acute   = "\xE2\x80\x94"; }   // em dash: no classification on this route
                if ($chronic === '') { $chronic = "\xE2\x80\x94"; }

                $rowH = max(
                    5,
                    $pdf->getStringHeight($w[0], $name),
                    $pdf->getStringHeight($w[3], $acute),
                    $pdf->getStringHeight($w[4], $chronic)
                );

                $pdf->MultiCell($w[0], $rowH, $name,    1, 'L', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[1], $rowH, $cas,     1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[2], $rowH, $concPct, 1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[3], $rowH, $acute,   1, 'C', false, 0, '', '', true, 0, false, true, $rowH, 'M');
                $pdf->MultiCell($w[4], $rowH, $chronic, 1, 'C', false, 1, '', '', true, 0, false, true, $rowH, 'M');
            }
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Ln(2);
        }

        $this->labelValue($pdf, $this->label('persistence'), (string) ($s['persistence'] ?? ''));
        $this->labelValue($pdf, $this->label('bioaccumulation'), (string) ($s['bioaccumulation'] ?? ''));
    }

    private function renderSection14(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, $this->label('un_number'), $s['un_number'] ?? '');
        $this->labelValue($pdf, $this->label('proper_shipping_name'), $s['proper_shipping_name'] ?? '');
        $this->labelValue($pdf, $this->label('transport_hazard_class'), $s['hazard_class'] ?? '');
        $this->labelValue($pdf, $this->label('packing_group'), $s['packing_group'] ?? '');
        // Carrier-verification note (was preview-only before item #25)
        $this->labelValue($pdf, $this->label('note'), $s['note'] ?? '');
    }

    private function renderSection15(\TCPDF $pdf, array $s): void
    {
        $this->labelValue($pdf, $this->label('osha_status'), $s['osha_status'] ?? '');
        $this->labelValue($pdf, $this->label('tsca_status'), $s['tsca_status'] ?? '');

        // SARA 313 / TRI supplier notification (40 CFR 372.45). SARA313Service::analyse()
        // emits 'reportable' (>= applicable de minimis), 'below_threshold' and 'not_listed'
        // with 'threshold_pct' / 'is_pbt' / 'sara_name' per entry. Heading + sentence always
        // print (like HAP / Prop 65); only reportable entries are listed.
        // English defaults keep pre-existing snapshots (meta.labels without these keys) readable.
        $sara = $s['sara_313'] ?? [];
        if (isset($sara['reportable']) && is_array($sara['reportable'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('sara_313_title') . ':', 0, 1);
            $pdf->SetFont('helvetica', '', 8);
            if (!empty($sara['reportable'])) {
                $pdf->MultiCell(0, 4, $this->label('sara_313_statement', 'This product contains the following toxic chemical(s) subject to the reporting requirements of Section 313 of Title III of the Superfund Amendments and Reauthorization Act of 1986 (SARA) and 40 CFR Part 372 (supplier notification per 40 CFR 372.45):'), 0, 'L');
                foreach ($sara['reportable'] as $chem) {
                    $name      = (string) ((($chem['sara_name'] ?? '') !== '') ? $chem['sara_name'] : ($chem['chemical_name'] ?? ''));
                    $threshold = rtrim(rtrim(number_format((float) ($chem['threshold_pct'] ?? 1.0), 4), '0'), '.');
                    $text = $name . ' (CAS ' . ($chem['cas_number'] ?? '') . ') — '
                          . number_format((float) ($chem['concentration_pct'] ?? 0), 2) . '% ('
                          . $this->label('sara_313_threshold', 'de minimis threshold') . ': ' . $threshold . '%'
                          . (!empty($chem['is_pbt']) ? '; ' . $this->label('sara_313_pbt', 'PBT chemical') : '')
                          . ')';
                    $pdf->MultiCell(0, 4, "\xE2\x80\xA2 " . $text, 0, 'L');
                }
            } else {
                $pdf->MultiCell(0, 4, $this->label('sara_313_none', 'This product does not contain any toxic chemicals subject to the reporting requirements of SARA Title III Section 313 (40 CFR Part 372) at or above the applicable de minimis concentration.'), 0, 'L');
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
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->label('hap_title') . ':', 0, 1);
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
            if ($key === 'title' || $key === 'has_other_hazards' || $key === 'uv_acrylate_note' || $key === 'ghs_note') {
                continue;
            }
            if (is_string($value) && $value !== '') {
                $labelKey = self::FIELD_LABEL_MAP[$key] ?? null;
                $fallback = ucwords(str_replace('_', ' ', $key));
                $label = $labelKey !== null
                    ? $this->label($labelKey, $fallback)
                    : $fallback;
                $this->labelValue($pdf, $label, $value);
            }
        }
    }

    /**
     * Shared Sections 12-15 footnote (audit item #25): 7pt italic grey, no
     * label, printed after the section body. The generator sets 'ghs_note'
     * to '' when the admin toggle is off, so this is a no-op in that case.
     */
    private function renderGhsSectionNote(\TCPDF $pdf, array $s): void
    {
        $note = (string) ($s['ghs_note'] ?? '');
        if ($note === '') {
            return;
        }
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->MultiCell(0, 3, $note, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);
    }

    /**
     * Right-hand footer text, prefix included:
     *   "Rev. 3 — 10/08/2026"          published version (Section 16 values)
     *   "Draft (not yet published)"     unstamped preview (translated via Section 16)
     *   "Rev. 10/01/2026"               snapshot published before versions were
     *                                   stamped (legacy generation-time revision_date)
     */
    private function footerRevision(array $meta, array $s16): string
    {
        $prefix  = (string) ($this->document['revision_prefix'] ?? 'Rev.');
        $version = (int) ($meta['sds_version'] ?? 0);

        if ($version > 0) {
            $date = (string) ($s16['effective_date'] ?? '');
            if ($date === '' && !empty($meta['effective_date'])) {
                $date = format_date((string) $meta['effective_date'], 'm/d/Y');
            }
            if ($date === '' && !empty($s16['revision_date'])) {
                $date = (string) $s16['revision_date'];
            }
            return $prefix . ' ' . $version . ($date !== '' ? ' — ' . $date : '');
        }

        if (!empty($s16['revision_date'])) {
            return $prefix . ' ' . (string) $s16['revision_date'];
        }

        return (string) ($s16['version'] ?? 'Draft (not yet published)');
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
