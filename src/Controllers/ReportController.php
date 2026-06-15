<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Services\FormulaCalcService;
use SDS\Services\HAPService;
use SDS\Services\SARA313Service;
use SDS\Services\ReportPDFService;
use SDS\Services\PDFService;

/**
 * ReportController — HAP/VOC reporting from CMS shipment data.
 *
 * Shipping data is stored in the local shipment_detail table, imported
 * from the CMS ShipmentDetails view via CMS Import.
 *
 * Aliases are loaded from the persistent aliases table (also synced
 * from CMS) for description lookups and SDS export naming.
 */
class ReportController
{
    /* ------------------------------------------------------------------
     *  Page: show the reporting form
     * ----------------------------------------------------------------*/

    public function index(): void
    {
        $db = Database::getInstance();

        $shipmentRow = $db->fetch("SELECT COUNT(*) AS cnt FROM shipment_detail");
        $shippingCount = (int) ($shipmentRow['cnt'] ?? 0);
        $hasShippingData = $shippingCount > 0;

        // Build unique customer lists for dropdown (from local table)
        $customers = $this->getCustomerList('ship_to_name');

        // Count aliases
        $aliasRow = $db->fetch("SELECT COUNT(*) AS cnt FROM aliases");
        $aliasCount = (int) ($aliasRow['cnt'] ?? 0);

        // Last sync time
        $lastSync = $db->fetch("SELECT MAX(imported_at) AS last_sync FROM shipment_detail");

        view('reports/index', [
            'pageTitle'       => 'HAP / VOC Reporting',
            'hasShippingData' => $hasShippingData,
            'shippingCount'   => $shippingCount,
            'aliasCount'      => $aliasCount,
            'customers'       => $customers,
            'lastSync'        => $lastSync['last_sync'] ?? null,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Generate Report (CSV)
     * ----------------------------------------------------------------*/

    public function generate(): void
    {
        CSRF::validateRequest();

        $reportData = $this->buildReportData();
        if ($reportData === null) {
            return;
        }

        $customerValue = $reportData['customer_value'];
        $dateFrom      = $reportData['date_from'];
        $dateTo        = $reportData['date_to'];
        $reportLines   = $reportData['lines'];
        $totalVocLbs   = $reportData['total_voc_lbs'];
        $totalHapLbs   = $reportData['total_hap_lbs'];
        $hapBreakdown  = $reportData['hap_breakdown'];
        $saraBreakdown = $reportData['sara_breakdown'];

        $filename = 'HAP_VOC_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $customerValue) . '_' . date('Ymd') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $output = fopen('php://output', 'w');

        fputcsv($output, ['HAP / VOC Shipping Report']);
        fputcsv($output, ['Customer:', $customerValue]);
        fputcsv($output, ['Date Range:', $dateFrom . ' to ' . $dateTo]);
        fputcsv($output, ['Generated:', date('m/d/Y H:i')]);
        fputcsv($output, []);

        fputcsv($output, [
            'Date Shipped', 'Item Name', 'Description', 'Qty Shipped (lbs)',
            'VOC by wt%', 'HAP by wt%', 'lbs of VOC', 'lbs of HAP',
        ]);

        foreach ($reportLines as $line) {
            fputcsv($output, [
                $line['date_shipped'],
                $line['item_code'],
                $line['description'],
                $line['qty_shipped'],
                $line['voc_wt_pct'] !== null ? number_format($line['voc_wt_pct'], 2) : 'N/A',
                $line['hap_wt_pct'] !== null ? number_format($line['hap_wt_pct'], 2) : 'N/A',
                $line['voc_lbs'] !== null ? number_format($line['voc_lbs'], 2) : 'N/A',
                $line['hap_lbs'] !== null ? number_format($line['hap_lbs'], 2) : 'N/A',
            ]);
        }

        fputcsv($output, []);
        fputcsv($output, ['', '', '', 'TOTALS', '', '', number_format($totalVocLbs, 2), number_format($totalHapLbs, 2)]);

        fputcsv($output, []);
        fputcsv($output, []);
        fputcsv($output, ['HAPs Breakdown']);
        fputcsv($output, ['CAS Number', 'Chemical Name', 'Total lbs']);
        if (empty($hapBreakdown)) {
            fputcsv($output, ['No HAPs found in shipped products for this period.']);
        } else {
            foreach ($hapBreakdown as $cas => $entry) {
                fputcsv($output, [$cas, $entry['name'], number_format($entry['lbs'], 2)]);
            }
        }

        fputcsv($output, []);
        fputcsv($output, []);
        fputcsv($output, ['SARA 313 Breakdown']);
        fputcsv($output, ['CAS Number', 'Chemical Name', 'Total lbs']);
        if (empty($saraBreakdown)) {
            fputcsv($output, ['No SARA 313 reportable chemicals found in shipped products for this period.']);
        } else {
            foreach ($saraBreakdown as $cas => $entry) {
                fputcsv($output, [$cas, $entry['name'], number_format($entry['lbs'], 2)]);
            }
        }

        fclose($output);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Generate Report (PDF)
     * ----------------------------------------------------------------*/

    public function generatePdf(): void
    {
        CSRF::validateRequest();

        $reportData = $this->buildReportData();
        if ($reportData === null) {
            return;
        }

        $db = Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.report_disclaimer'");
        $disclaimer = $row['value'] ?? '';

        $pdfService = new ReportPDFService();
        $pdfContent = $pdfService->generate($reportData, $disclaimer);

        $customerValue = $reportData['customer_value'];
        $filename = 'HAP_VOC_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $customerValue) . '_' . date('Ymd') . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: no-cache, no-store, must-revalidate');

        echo $pdfContent;
        exit;
    }

    /* ------------------------------------------------------------------
     *  Export SDS PDFs for shipped items as ZIP
     * ----------------------------------------------------------------*/

    public function exportShippedSds(): void
    {
        CSRF::validateRequest();

        $db = Database::getInstance();

        $customerField = $_POST['customer_field'] ?? 'ship_to_name';
        $customerValue = trim($_POST['customer_value'] ?? '');
        $dateFrom      = trim($_POST['date_from'] ?? '');
        $dateTo        = trim($_POST['date_to'] ?? '');
        $exportLang    = trim($_POST['export_language'] ?? 'all');

        $allowedLangs = ['all', 'en', 'es', 'fr', 'de'];
        if (!in_array($exportLang, $allowedLangs, true)) {
            $exportLang = 'all';
        }

        if ($customerValue === '' || $dateFrom === '' || $dateTo === '') {
            $_SESSION['_flash']['error'] = 'Customer, date from, and date to are required.';
            redirect('/reports');
        }

        // Query shipment_detail table
        $allowedFields = ['bill_to', 'ship_to', 'ship_to_name'];
        if (!in_array($customerField, $allowedFields, true)) {
            $customerField = 'ship_to_name';
        }

        $filtered = $db->fetchAll(
            "SELECT * FROM shipment_detail
             WHERE `{$customerField}` = ?
               AND date_shipped >= ? AND date_shipped <= ?
             ORDER BY date_shipped",
            [$customerValue, $dateFrom, $dateTo . ' 23:59:59']
        );

        if (empty($filtered)) {
            $_SESSION['_flash']['error'] = 'No shipping records match the selected criteria.';
            redirect('/reports');
        }

        $basePath = \SDS\Core\App::basePath();

        $productCodes = [];
        $reportItemsByProduct = [];
        $unresolvedCodes = [];
        foreach ($filtered as $row) {
            // Use item_name (alias code) if present, otherwise item_code.
            // Key by the FULL code including pack extension — that's what
            // appears on the invoice / packing slip and what the customer
            // recognizes, so the ZIP filename should match it.
            $itemName = trim(!empty($row['item_name']) ? $row['item_name'] : $row['item_code']);
            $resolved = $this->resolveToProductCode($itemName, $db);
            if ($resolved !== null) {
                $productCodes[$resolved] = true;
                $reportItemsByProduct[$resolved][$itemName] = true;
            } else {
                $unresolvedCodes[$itemName] = true;
            }
        }

        // Load aliases indexed by internal_code_base. Each pack-size variant
        // stays a distinct entry — dedupe on (internal_code_base,
        // customer_code) rather than a stripped key so e.g. R1005-2G and
        // R1005-50 can both export with their own filenames.
        $allAliases = $db->fetchAll("SELECT * FROM aliases ORDER BY customer_code");
        $aliasesByBase = [];
        $seenAliases = [];
        foreach ($allAliases as $alias) {
            $custCode  = trim((string) $alias['customer_code']);
            $dedupeKey = $alias['internal_code_base'] . '::' . $custCode;
            if (isset($seenAliases[$dedupeKey])) {
                continue;
            }
            $seenAliases[$dedupeKey] = true;
            $alias['customer_code'] = $custCode;
            $aliasesByBase[$alias['internal_code_base']][] = $alias;
        }
        unset($seenAliases);

        // Create ZIP
        $tempZip = tempnam(sys_get_temp_dir(), 'sds_shipped_') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $_SESSION['_flash']['error'] = 'Failed to create ZIP archive.';
            redirect('/reports');
        }

        $addedFiles = 0;
        $seen = [];
        $missingItems = [];
        $tempPdfs = [];

        foreach (array_keys($productCodes) as $productCode) {
            $fg = FinishedGood::findByProductCode($productCode);
            if ($fg === null) {
                $missingItems[$productCode] = true;
                continue;
            }

            $versions = $db->fetchAll(
                "SELECT sv.id, sv.version, sv.language, sv.pdf_path, sv.snapshot_json
                 FROM sds_versions sv
                 WHERE sv.finished_good_id = ?
                   AND sv.status = 'published'
                   AND sv.is_deleted = 0
                   AND sv.pdf_path IS NOT NULL
                   AND sv.pdf_path != ''
                 ORDER BY sv.version DESC, sv.language ASC",
                [(int) $fg['id']]
            );

            if (empty($versions)) {
                $missingItems[$productCode] = true;
                continue;
            }

            $aliasByCustomerCode = [];
            foreach (($aliasesByBase[$productCode] ?? []) as $a) {
                $aliasByCustomerCode[$a['customer_code']] = $a;
            }

            // Pre-load published alias PDFs for this FG so we can grab
            // them directly instead of regenerating on the fly.
            $aliasPublishedPdfs = [];
            $aliasRows = $db->fetchAll(
                "SELECT sv.alias_id, sv.language, sv.pdf_path, sv.version
                 FROM sds_versions sv
                 WHERE sv.finished_good_id = ?
                   AND sv.alias_id IS NOT NULL
                   AND sv.status = 'published'
                   AND sv.is_deleted = 0
                   AND sv.pdf_path IS NOT NULL
                   AND sv.pdf_path != ''
                 ORDER BY sv.version DESC",
                [(int) $fg['id']]
            );
            foreach ($aliasRows as $ar) {
                $key = (int) $ar['alias_id'] . '::' . $ar['language'];
                if (!isset($aliasPublishedPdfs[$key])) {
                    $aliasPublishedPdfs[$key] = $ar['pdf_path'];
                }
            }

            $reportItems = $reportItemsByProduct[$productCode] ?? [];
            foreach (array_keys($reportItems) as $itemCode) {
                $matchedAlias = $aliasByCustomerCode[$itemCode] ?? null;

                $addedLangs = [];
                foreach ($versions as $v) {
                    $lang = strtolower($v['language']);
                    if ($exportLang !== 'all' && $lang !== $exportLang) continue;
                    if (isset($addedLangs[$lang])) continue;
                    $addedLangs[$lang] = true;

                    $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $itemCode);
                    $zipName  = $safeCode . '_SDS' . ($lang !== 'en' ? '_' . strtoupper($lang) : '') . '.pdf';
                    if (isset($seen[$zipName])) continue;
                    $seen[$zipName] = true;

                    if ($matchedAlias !== null) {
                        $aliasKey = (int) $matchedAlias['id'] . '::' . $lang;
                        $prebuiltPath = $aliasPublishedPdfs[$aliasKey] ?? null;
                        if ($prebuiltPath !== null) {
                            $fullPath = $basePath . '/' . ltrim($prebuiltPath, '/');
                            if (file_exists($fullPath)) {
                                $zip->addFile($fullPath, $zipName);
                                $addedFiles++;
                                continue;
                            }
                        }
                        // Fallback: regenerate if no pre-built alias PDF
                        $aliasPdf = $this->generateAliasPdf($v, $matchedAlias, $basePath);
                        if ($aliasPdf !== null) {
                            $zip->addFile($aliasPdf, $zipName);
                            $tempPdfs[] = $aliasPdf;
                            $addedFiles++;
                        } else {
                            $missingItems[$itemCode] = 'SDS generation failed (alias rebrand error)';
                        }
                    } else {
                        $pdfFullPath = $basePath . '/' . ltrim($v['pdf_path'], '/');
                        if (!file_exists($pdfFullPath)) {
                            $missingItems[$itemCode] = 'Published PDF missing on disk: ' . basename($v['pdf_path']);
                            continue;
                        }
                        $zip->addFile($pdfFullPath, $zipName);
                        $addedFiles++;
                    }
                }
            }
        }

        foreach (array_keys($unresolvedCodes) as $code) {
            $missingItems[$code] = 'Not found in SDS system — product code unrecognized';
        }

        if (!empty($missingItems)) {
            $missingCsv = "Product Code,Status\n";
            foreach ($missingItems as $code => $reason) {
                // Legacy callers still set the value to bool(true); map to
                // the default message so the CSV is consistent.
                $msg = is_string($reason) && $reason !== ''
                    ? $reason
                    : 'Not found in SDS system — needs to be entered';
                $missingCsv .= '"' . str_replace('"', '""', (string) $code)
                             . '","' . str_replace('"', '""', $msg) . '"' . "\n";
            }
            $zip->addFromString('_MISSING_ITEMS.csv', $missingCsv);
        }

        $zip->close();

        $cleanupTempPdfs = function () use ($tempPdfs) {
            foreach ($tempPdfs as $tmpPdf) {
                @unlink($tmpPdf);
            }
        };

        if ($addedFiles === 0 && empty($missingItems)) {
            $cleanupTempPdfs();
            @unlink($tempZip);
            $langLabel = $exportLang === 'all' ? '' : ' (' . strtoupper($exportLang) . ')';
            $_SESSION['_flash']['warning'] = 'No published SDS PDFs' . $langLabel . ' found for the shipped items.';
            redirect('/reports');
        }

        $safeCustomer = preg_replace('/[^a-zA-Z0-9]/', '_', $customerValue);
        $langSuffix = $exportLang !== 'all' ? '_' . strtoupper($exportLang) : '';
        $exportName = 'SDS_Export_' . $safeCustomer . $langSuffix . '_' . date('Ymd') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $exportName . '"');
        header('Content-Length: ' . filesize($tempZip));
        header('Cache-Control: no-cache, must-revalidate');

        readfile($tempZip);
        $cleanupTempPdfs();
        @unlink($tempZip);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Build report data (shared between CSV and PDF)
     * ----------------------------------------------------------------*/

    private function buildReportData(): ?array
    {
        $db = Database::getInstance();

        $customerField = $_POST['customer_field'] ?? 'ship_to_name';
        $customerValue = trim($_POST['customer_value'] ?? '');
        $dateFrom      = trim($_POST['date_from'] ?? '');
        $dateTo        = trim($_POST['date_to'] ?? '');

        if ($customerValue === '') {
            $_SESSION['_flash']['error'] = 'Please select a customer.';
            redirect('/reports');
        }
        if ($dateFrom === '' || $dateTo === '') {
            $_SESSION['_flash']['error'] = 'Please enter both a start and end date.';
            redirect('/reports');
        }

        $allowedFields = ['bill_to', 'ship_to', 'ship_to_name'];
        if (!in_array($customerField, $allowedFields, true)) {
            $customerField = 'ship_to_name';
        }

        // Query shipment data from local table
        $filtered = $db->fetchAll(
            "SELECT * FROM shipment_detail
             WHERE `{$customerField}` = ?
               AND date_shipped >= ? AND date_shipped <= ?
             ORDER BY date_shipped",
            [$customerValue, $dateFrom, $dateTo . ' 23:59:59']
        );

        if (empty($filtered)) {
            $_SESSION['_flash']['error'] = 'No records match the selected customer and date range.';
            redirect('/reports');
        }

        // Build report lines
        $calcService = new FormulaCalcService();
        $reportLines    = [];
        $totalVocLbs    = 0.0;
        $totalHapLbs    = 0.0;
        $totalShippedLbs = 0.0;
        $calcCache = [];
        $resolvedCodes = [];
        $hapBreakdown  = [];
        $saraBreakdown = [];

        foreach ($filtered as $row) {
            // Use item_name (alias) if present, otherwise item_code
            $itemCode   = !empty($row['item_name']) ? $row['item_name'] : $row['item_code'];
            $qtyShipped = (float) $row['qty_shipped'];

            // Use the correct description: alias description if alias was used, else inventory description
            $description = '';
            if (!empty($row['item_name']) && $row['item_name'] !== $row['item_code']) {
                $description = $row['item_name_description'] ?? '';
            }
            if ($description === '') {
                $description = $row['item_description'] ?? '';
            }

            // Resolve to FG product code. resolveToProductCode handles the
            // pack-ext shape difference between aliases (stored with) and
            // finished_goods (stored without), so we pass the raw item
            // code without pre-stripping.
            if (!isset($resolvedCodes[$itemCode])) {
                $resolvedCodes[$itemCode] = $this->resolveToProductCode($itemCode, $db)
                    ?? $this->stripPackExtension($itemCode);
            }
            $productCode = $resolvedCodes[$itemCode];

            // Lookup VOC/HAP
            $vocWtPct = null;
            $hapWtPct = null;
            $vocLbs   = null;
            $hapLbs   = null;

            if (!isset($calcCache[$productCode])) {
                $calcCache[$productCode] = $this->getVocHapForProduct($productCode, $calcService);
            }

            $calcData = $calcCache[$productCode];

            if ($calcData !== null) {
                $vocWtPct = round($calcData['voc_wt_pct'], 2);
                $hapWtPct = round($calcData['hap_wt_pct'], 2);
                $vocLbs = round($qtyShipped * ($calcData['voc_wt_pct'] / 100.0), 2);
                $hapLbs = round($qtyShipped * ($calcData['hap_wt_pct'] / 100.0), 2);
                $totalVocLbs += $vocLbs;
                $totalHapLbs += $hapLbs;

                foreach ($calcData['hap_chemicals'] as $hap) {
                    $cas  = $hap['cas_number'];
                    $name = $hap['chemical_name'];
                    $lbs  = round($qtyShipped * ((float) $hap['concentration_pct'] / 100.0), 2);
                    if (!isset($hapBreakdown[$cas])) {
                        $hapBreakdown[$cas] = ['name' => $name, 'lbs' => 0.0];
                    }
                    $hapBreakdown[$cas]['lbs'] += $lbs;
                }

                foreach ($calcData['sara_reportable'] as $sara) {
                    $cas  = $sara['cas_number'];
                    $name = $sara['chemical_name'];
                    $lbs  = round($qtyShipped * ((float) $sara['concentration_pct'] / 100.0), 2);
                    if (!isset($saraBreakdown[$cas])) {
                        $saraBreakdown[$cas] = ['name' => $name, 'lbs' => 0.0];
                    }
                    $saraBreakdown[$cas]['lbs'] += $lbs;
                }
            }

            $totalShippedLbs += $qtyShipped;

            $reportLines[] = [
                'date_shipped' => $row['date_shipped'],
                'item_code'    => $itemCode,
                'description'  => $description,
                'qty_shipped'  => $qtyShipped,
                'voc_wt_pct'   => $vocWtPct,
                'hap_wt_pct'   => $hapWtPct,
                'voc_lbs'      => $vocLbs,
                'hap_lbs'      => $hapLbs,
            ];
        }

        uasort($hapBreakdown, fn($a, $b) => $b['lbs'] <=> $a['lbs']);
        uasort($saraBreakdown, fn($a, $b) => $b['lbs'] <=> $a['lbs']);

        return [
            'customer_value'    => $customerValue,
            'customer_field'    => $customerField,
            'date_from'         => $dateFrom,
            'date_to'           => $dateTo,
            'lines'             => $reportLines,
            'total_shipped_lbs' => $totalShippedLbs,
            'total_voc_lbs'     => $totalVocLbs,
            'total_hap_lbs'     => $totalHapLbs,
            'hap_breakdown'     => $hapBreakdown,
            'sara_breakdown'    => $saraBreakdown,
        ];
    }

    /* ------------------------------------------------------------------
     *  AJAX: get customers for a given field
     * ----------------------------------------------------------------*/

    public function customers(): void
    {
        $field = $_GET['field'] ?? 'ship_to_name';

        $allowed = ['bill_to', 'ship_to', 'ship_to_name'];
        if (!in_array($field, $allowed, true)) {
            $field = 'ship_to_name';
        }

        $customers = $this->getCustomerList($field);

        header('Content-Type: application/json');
        echo json_encode($customers);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Private helpers
     * ----------------------------------------------------------------*/

    private function getCustomerList(string $field = 'ship_to_name'): array
    {
        $db = Database::getInstance();

        $allowedFields = ['bill_to', 'ship_to', 'ship_to_name'];
        if (!in_array($field, $allowedFields, true)) {
            $field = 'ship_to_name';
        }

        $rows = $db->fetchAll(
            "SELECT DISTINCT `{$field}` AS val FROM shipment_detail
             WHERE `{$field}` IS NOT NULL AND `{$field}` != ''
             ORDER BY `{$field}`"
        );

        return array_column($rows, 'val');
    }

    /**
     * Generate a PDF with alias-specific product identifier.
     */
    private function generateAliasPdf(array $sdsVersion, array $alias, string $basePath): ?string
    {
        try {
            $snapshot = $sdsVersion['snapshot_json'] ?? null;
            if ($snapshot === null) {
                $db = Database::getInstance();
                $row = $db->fetch(
                    "SELECT snapshot_json FROM sds_versions WHERE id = ?",
                    [(int) $sdsVersion['id']]
                );
                $snapshot = $row['snapshot_json'] ?? null;
            }

            if ($snapshot === null) {
                return null;
            }

            $data = json_decode($snapshot, true);
            if ($data === null) {
                return null;
            }

            // Use the alias's own description
            $aliasDesc = !empty($alias['description']) ? $alias['description'] : ($data['meta']['product_name'] ?? '');

            $data['meta']['product_code'] = $alias['customer_code'];
            $data['meta']['product_name'] = $aliasDesc;

            if (isset($data['sections']['1']['product_identifier'])) {
                $data['sections']['1']['product_identifier'] = $alias['customer_code'];
            }
            if (isset($data['sections']['1']['product_name'])) {
                $data['sections']['1']['product_name'] = $aliasDesc;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'sds_alias_') . '.pdf';
            // PDFService exposes generate() (writes to a dir and returns the
            // path) and generateString() (returns bytes). It has no
            // generateToFile(). Use generateString + file_put_contents so
            // we control the filename.
            $pdfService = new PDFService();
            $bytes = $pdfService->generateString($data);
            if ($bytes === '' || file_put_contents($tempPath, $bytes) === false) {
                error_log("generateAliasPdf: failed to write {$tempPath} for alias {$alias['customer_code']}");
                return null;
            }

            return $tempPath;
        } catch (\Throwable $e) {
            error_log("generateAliasPdf: {$e->getMessage()} for alias " . ($alias['customer_code'] ?? '?'));
            return null;
        }
    }

    /**
     * Strip the pack extension (after first "-") from an item code.
     */
    private function stripPackExtension(string $code): string
    {
        $pos = strpos($code, '-');
        return $pos !== false ? substr($code, 0, $pos) : $code;
    }

    /**
     * Resolve an item code (as it appears on a shipment line) to a
     * finished_goods.product_code.
     *
     * The two tables this walks store codes differently:
     *   - finished_goods.product_code  — no pack extension (e.g. "E1043")
     *   - aliases.customer_code         — WITH pack extension (e.g. "R1005-50")
     *
     * Shipments carry the full customer-facing code including pack ext
     * ("R1005-50" or "E1043-50"). So we try in this order:
     *   1. exact alias match        (most common: "R1005-50" → E1043)
     *   2. exact FG match           (rare: some codes have no pack ext)
     *   3. stripped FG match        (internal codes: "E1043-50" → E1043)
     *   4. stripped alias match     (fallback for any legacy stripped rows)
     *
     * Previously the caller pre-stripped the code, which made step 1
     * impossible — aliases stored as "R1005-50" never matched a
     * stripped-to-"R1005" query, so every customer-aliased item was
     * wrongly flagged as "not found."
     */
    private function resolveToProductCode(string $rawCode, Database $db): ?string
    {
        $raw      = trim($rawCode);
        if ($raw === '') {
            return null;
        }
        $stripped = $this->stripPackExtension($raw);

        // 1. Exact alias match (customer_code carries pack extension)
        $alias = $db->fetch(
            "SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1",
            [$raw]
        );
        if ($alias) {
            return $alias['internal_code_base'];
        }

        // 2. Exact FG match (for codes without a pack extension)
        $fg = $db->fetch(
            "SELECT product_code FROM finished_goods WHERE product_code = ?",
            [$raw]
        );
        if ($fg) {
            return $fg['product_code'];
        }

        if ($stripped !== $raw) {
            // 3. Stripped FG match (internal codes like "E1043-50" → "E1043")
            $fg = $db->fetch(
                "SELECT product_code FROM finished_goods WHERE product_code = ?",
                [$stripped]
            );
            if ($fg) {
                return $fg['product_code'];
            }

            // 4. Stripped alias match (defensive; most aliases carry pack ext)
            $alias = $db->fetch(
                "SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1",
                [$stripped]
            );
            if ($alias) {
                return $alias['internal_code_base'];
            }
        }

        return null;
    }

    /**
     * Get VOC/HAP data for a product via FormulaCalcService.
     */
    private function getVocHapForProduct(string $productCode, FormulaCalcService $calcService): ?array
    {
        $fg = FinishedGood::findByProductCode($productCode);
        if ($fg === null) {
            return null;
        }

        try {
            $calcResult = $calcService->calculate((int) $fg['id']);
        } catch (\Throwable $e) {
            return null;
        }

        // VOCCalculator's return uses total_voc_wt_pct, not voc_weight_percent.
        $vocWtPct    = (float) ($calcResult['voc']['total_voc_wt_pct'] ?? 0);
        $composition = $calcResult['composition'] ?? [];

        // Match SDSGenerator's canonical path: walk enriched_lines (which
        // include RMs nested inside sub-FG components) to collect manual
        // HAP entries, then feed those to HAPService::analyse alongside
        // the composition. Without the second argument, products whose
        // HAPs are stored as manual RM entries (rather than CAS-detected)
        // would register 0% HAP on this report.
        $formulaLines = $calcResult['formula_props']['enriched_lines']
            ?? $calcResult['formula']['lines']
            ?? [];
        $manualHaps   = \SDS\Services\SDSGenerator::getManualHaps($formulaLines);

        $hapResult  = HAPService::analyse($composition, $manualHaps);
        $saraResult = SARA313Service::analyse($composition);

        return [
            'voc_wt_pct'      => $vocWtPct,
            'hap_wt_pct'      => (float) ($hapResult['total_hap_pct'] ?? 0),
            'hap_chemicals'   => $hapResult['hap_chemicals'] ?? [],
            'sara_reportable' => $saraResult['reportable']   ?? [],
        ];
    }
}
