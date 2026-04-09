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
            // Use item_name (alias code) if present, otherwise item_code
            $itemName = !empty($row['item_name']) ? $row['item_name'] : $row['item_code'];
            $stripped = $this->stripPackExtension($itemName);
            $resolved = $this->resolveToProductCode($stripped, $db);
            if ($resolved !== null) {
                $productCodes[$resolved] = true;
                $reportItemsByProduct[$resolved][$stripped] = true;
            } else {
                $unresolvedCodes[$stripped] = true;
            }
        }

        // Load aliases indexed by internal_code_base
        $allAliases = $db->fetchAll("SELECT * FROM aliases ORDER BY customer_code");
        $aliasesByBase = [];
        $seenAliases = [];
        foreach ($allAliases as $alias) {
            $baseCustomerCode = $this->stripPackExtension($alias['customer_code']);
            $dedupeKey = $alias['internal_code_base'] . '::' . $baseCustomerCode;
            if (isset($seenAliases[$dedupeKey])) {
                continue;
            }
            $seenAliases[$dedupeKey] = true;
            $alias['customer_code'] = $baseCustomerCode;
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
                "SELECT sv.id, sv.version, sv.language, sv.pdf_path
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

            $allAliasesForCode = $aliasesByBase[$productCode] ?? [];
            $reportItems = $reportItemsByProduct[$productCode] ?? [];
            $aliases = [];
            foreach ($allAliasesForCode as $alias) {
                if (isset($reportItems[$alias['customer_code']])) {
                    $aliases[] = $alias;
                }
            }

            if (!empty($aliases)) {
                foreach ($aliases as $alias) {
                    $addedLangs = [];
                    foreach ($versions as $v) {
                        $lang = strtolower($v['language']);
                        if ($exportLang !== 'all' && $lang !== $exportLang) continue;
                        if (isset($addedLangs[$lang])) continue;
                        $addedLangs[$lang] = true;

                        $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $alias['customer_code']);
                        $zipName  = $safeCode . '_SDS' . ($lang !== 'en' ? '_' . strtoupper($lang) : '') . '.pdf';
                        if (isset($seen[$zipName])) continue;
                        $seen[$zipName] = true;

                        $aliasPdf = $this->generateAliasPdf($v, $alias, $basePath);
                        if ($aliasPdf !== null) {
                            $zip->addFile($aliasPdf, $zipName);
                            $tempPdfs[] = $aliasPdf;
                            $addedFiles++;
                        }
                    }
                }
            } else {
                $addedLangs = [];
                foreach ($versions as $v) {
                    $lang = strtolower($v['language']);
                    if ($exportLang !== 'all' && $lang !== $exportLang) continue;
                    if (isset($addedLangs[$lang])) continue;
                    $addedLangs[$lang] = true;

                    $pdfFullPath = $basePath . '/' . ltrim($v['pdf_path'], '/');
                    if (!file_exists($pdfFullPath)) continue;

                    $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $productCode);
                    $zipName  = $safeCode . '_SDS' . ($lang !== 'en' ? '_' . strtoupper($lang) : '') . '.pdf';
                    if (isset($seen[$zipName])) continue;
                    $seen[$zipName] = true;

                    $zip->addFile($pdfFullPath, $zipName);
                    $addedFiles++;
                }
            }
        }

        foreach (array_keys($unresolvedCodes) as $code) {
            $missingItems[$code] = true;
        }

        if (!empty($missingItems)) {
            $missingCsv = "Product Code,Status\n";
            foreach (array_keys($missingItems) as $code) {
                $missingCsv .= '"' . str_replace('"', '""', $code) . '","Not found in SDS system - needs to be entered"' . "\n";
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

            // Strip pack extension and resolve to FG product code
            $strippedCode = $this->stripPackExtension($itemCode);
            if (!isset($resolvedCodes[$strippedCode])) {
                $resolvedCodes[$strippedCode] = $this->resolveToProductCode($strippedCode, $db) ?? $strippedCode;
            }
            $productCode = $resolvedCodes[$strippedCode];

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
            $pdfService = new PDFService();
            $pdfService->generateToFile($data, $tempPath);

            return $tempPath;
        } catch (\Throwable $e) {
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
     * Try to resolve a stripped item code to a finished good product code.
     * First checks finished_goods directly, then checks aliases.
     */
    private function resolveToProductCode(string $strippedCode, Database $db): ?string
    {
        $fg = $db->fetch(
            "SELECT product_code FROM finished_goods WHERE product_code = ?",
            [$strippedCode]
        );
        if ($fg) {
            return $fg['product_code'];
        }

        $alias = $db->fetch(
            "SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1",
            [$strippedCode]
        );
        if ($alias) {
            return $alias['internal_code_base'];
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

        $vocWtPct = (float) ($calcResult['voc']['voc_weight_percent'] ?? 0);
        $hapWtPct = 0.0;
        $hapChemicals = [];
        $saraReportable = [];

        foreach ($calcResult['composition'] ?? [] as $c) {
            $cas = $c['cas_number'] ?? '';
            if ($cas === '') continue;

            if (HAPService::isHAP($cas)) {
                $hapWtPct += (float) ($c['concentration_pct'] ?? 0);
                $hapChemicals[] = $c;
            }
            if (SARA313Service::isReportable($cas, (float) ($c['concentration_pct'] ?? 0))) {
                $saraReportable[] = $c;
            }
        }

        return [
            'voc_wt_pct'     => $vocWtPct,
            'hap_wt_pct'     => $hapWtPct,
            'hap_chemicals'  => $hapChemicals,
            'sara_reportable' => $saraReportable,
        ];
    }
}
