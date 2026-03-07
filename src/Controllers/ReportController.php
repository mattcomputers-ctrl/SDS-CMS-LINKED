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

/**
 * ReportController — HAP/VOC reporting from uploaded shipping data.
 *
 * All uploaded data is stored in the PHP session and is never persisted
 * to the database or filesystem.  Data is automatically cleared on
 * logout or when the user clicks "Clear Data".
 */
class ReportController
{
    private const SESSION_KEY = '_report_data';

    /* ------------------------------------------------------------------
     *  Page: show the reporting form
     * ----------------------------------------------------------------*/

    public function index(): void
    {
        $data = $_SESSION[self::SESSION_KEY] ?? [];

        $hasItemNames    = !empty($data['item_names']);
        $hasShippingData = !empty($data['shipping_detail']);

        // Build unique customer lists for dropdown
        $customers = $this->getCustomerList($data['shipping_detail'] ?? []);

        view('reports/index', [
            'pageTitle'       => 'HAP / VOC Reporting',
            'hasItemNames'    => $hasItemNames,
            'hasShippingData' => $hasShippingData,
            'itemNameCount'   => count($data['item_names'] ?? []),
            'shippingCount'   => count($data['shipping_detail'] ?? []),
            'customers'       => $customers,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Upload: Item Names CSV
     * ----------------------------------------------------------------*/

    public function uploadItemNames(): void
    {
        CSRF::validateRequest();

        if (!isset($_FILES['item_names_file']) || $_FILES['item_names_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['_flash']['error'] = 'Please select a valid CSV file to upload.';
            redirect('/reports');
        }

        $file = $_FILES['item_names_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'], true)) {
            $_SESSION['_flash']['error'] = 'Only CSV files are supported. Please export a CSV from your ERP system.';
            redirect('/reports');
        }

        $rows = $this->parseCsv($file['tmp_name']);

        if (empty($rows)) {
            $_SESSION['_flash']['error'] = 'The uploaded file is empty or could not be parsed.';
            redirect('/reports');
        }

        // Normalize headers
        $headers = array_map(fn($h) => strtolower(trim($h)), array_keys($rows[0]));
        $itemCodeCol = $this->findColumn($headers, ['item name', 'itemname', 'item_name', 'item code', 'itemcode', 'item_code', 'code']);
        $descCol     = $this->findColumn($headers, ['description', 'desc']);

        if ($itemCodeCol === null) {
            $_SESSION['_flash']['error'] = 'Could not find an "Item Name" or "Item Code" column in the uploaded file.';
            redirect('/reports');
        }

        // Store as item_name => description map
        $itemNames = [];
        $originalHeaders = array_keys($rows[0]);
        foreach ($rows as $row) {
            $vals = array_values($row);
            $code = trim((string) ($vals[$itemCodeCol] ?? ''));
            $desc = $descCol !== null ? trim((string) ($vals[$descCol] ?? '')) : '';
            if ($code !== '') {
                $itemNames[$code] = $desc;
            }
        }

        if (empty($itemNames)) {
            $_SESSION['_flash']['error'] = 'No valid item names found in the uploaded file.';
            redirect('/reports');
        }

        $_SESSION[self::SESSION_KEY]['item_names'] = $itemNames;
        $_SESSION['_flash']['success'] = count($itemNames) . ' item name(s) loaded successfully.';
        redirect('/reports');
    }

    /* ------------------------------------------------------------------
     *  Upload: Shipping Detail CSV
     * ----------------------------------------------------------------*/

    public function uploadShippingDetail(): void
    {
        CSRF::validateRequest();

        if (!isset($_FILES['shipping_file']) || $_FILES['shipping_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['_flash']['error'] = 'Please select a valid CSV file to upload.';
            redirect('/reports');
        }

        $file = $_FILES['shipping_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'], true)) {
            $_SESSION['_flash']['error'] = 'Only CSV files are supported. Please export a CSV from your ERP system.';
            redirect('/reports');
        }

        $rows = $this->parseCsv($file['tmp_name']);

        if (empty($rows)) {
            $_SESSION['_flash']['error'] = 'The uploaded file is empty or could not be parsed.';
            redirect('/reports');
        }

        // Normalize headers and find required columns
        $headers = array_map(fn($h) => strtolower(trim($h)), array_keys($rows[0]));

        // Prefer "Item Name" (has pack extension) over "Item Code" (no pack extension)
        $itemNameCol = $this->findColumn($headers, ['item name', 'itemname', 'item_name'])
                    ?? $this->findColumn($headers, ['item code', 'itemcode', 'item_code']);

        $colMap = [
            'bill_to'      => $this->findColumn($headers, ['bill to', 'billto', 'bill_to']),
            'ship_to'      => $this->findColumn($headers, ['ship to', 'shipto', 'ship_to']),
            'ship_to_name' => $this->findColumn($headers, ['ship to name', 'shiptoname', 'ship_to_name']),
            'date_shipped' => $this->findColumn($headers, ['date shipped', 'dateshipped', 'date_shipped', 'ship date', 'shipdate']),
            'item_name'    => $itemNameCol,
            'qty_shipped'  => $this->findColumn($headers, ['qty shipped', 'qtyshipped', 'qty_shipped', 'quantity shipped', 'quantity']),
        ];

        $missing = [];
        foreach ($colMap as $name => $idx) {
            if ($idx === null) {
                $missing[] = str_replace('_', ' ', $name);
            }
        }
        if (!empty($missing)) {
            $_SESSION['_flash']['error'] = 'Could not find column(s): ' . implode(', ', $missing) . '. Please check your CSV headers.';
            redirect('/reports');
        }

        // Parse rows
        $shippingData = [];
        foreach ($rows as $row) {
            $vals = array_values($row);
            $shippingData[] = [
                'bill_to'      => trim((string) ($vals[$colMap['bill_to']] ?? '')),
                'ship_to'      => trim((string) ($vals[$colMap['ship_to']] ?? '')),
                'ship_to_name' => trim((string) ($vals[$colMap['ship_to_name']] ?? '')),
                'date_shipped' => trim((string) ($vals[$colMap['date_shipped']] ?? '')),
                'item_name'    => trim((string) ($vals[$colMap['item_name']] ?? '')),
                'qty_shipped'  => (float) ($vals[$colMap['qty_shipped']] ?? 0),
            ];
        }

        $_SESSION[self::SESSION_KEY]['shipping_detail'] = $shippingData;
        $_SESSION['_flash']['success'] = count($shippingData) . ' shipping record(s) loaded successfully.';
        redirect('/reports');
    }

    /* ------------------------------------------------------------------
     *  Generate Report (CSV)
     * ----------------------------------------------------------------*/

    public function generate(): void
    {
        CSRF::validateRequest();

        $reportData = $this->buildReportData();
        if ($reportData === null) {
            return; // redirected with flash error
        }

        $customerValue = $reportData['customer_value'];
        $dateFrom      = $reportData['date_from'];
        $dateTo        = $reportData['date_to'];
        $reportLines   = $reportData['lines'];
        $totalVocLbs   = $reportData['total_voc_lbs'];
        $totalHapLbs   = $reportData['total_hap_lbs'];
        $hapBreakdown  = $reportData['hap_breakdown'];
        $saraBreakdown = $reportData['sara_breakdown'];

        // Output CSV
        $filename = 'HAP_VOC_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $customerValue) . '_' . date('Ymd') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $output = fopen('php://output', 'w');

        // Report header info
        fputcsv($output, ['HAP / VOC Shipping Report']);
        fputcsv($output, ['Customer:', $customerValue]);
        fputcsv($output, ['Date Range:', $dateFrom . ' to ' . $dateTo]);
        fputcsv($output, ['Generated:', date('m/d/Y H:i')]);
        fputcsv($output, []);

        // Column headers
        fputcsv($output, [
            'Date Shipped',
            'Item Name',
            'Description',
            'Qty Shipped (lbs)',
            'VOC by wt%',
            'HAP by wt%',
            'lbs of VOC',
            'lbs of HAP',
        ]);

        // Data rows
        foreach ($reportLines as $line) {
            fputcsv($output, [
                $line['date_shipped'],
                $line['item_code'],
                $line['description'],
                $line['qty_shipped'],
                $line['voc_wt_pct'] !== null ? round($line['voc_wt_pct'], 4) : 'N/A',
                $line['hap_wt_pct'] !== null ? round($line['hap_wt_pct'], 4) : 'N/A',
                $line['voc_lbs'] !== null ? round($line['voc_lbs'], 4) : 'N/A',
                $line['hap_lbs'] !== null ? round($line['hap_lbs'], 4) : 'N/A',
            ]);
        }

        // Totals
        fputcsv($output, []);
        fputcsv($output, ['', '', '', 'TOTALS', '', '', round($totalVocLbs, 4), round($totalHapLbs, 4)]);

        // HAPs Breakdown
        fputcsv($output, []);
        fputcsv($output, []);
        fputcsv($output, ['HAPs Breakdown']);
        fputcsv($output, ['CAS Number', 'Chemical Name', 'Total lbs']);
        if (empty($hapBreakdown)) {
            fputcsv($output, ['No HAPs found in shipped products for this period.']);
        } else {
            foreach ($hapBreakdown as $cas => $entry) {
                fputcsv($output, [$cas, $entry['name'], round($entry['lbs'], 4)]);
            }
        }

        // SARA 313 Breakdown
        fputcsv($output, []);
        fputcsv($output, []);
        fputcsv($output, ['SARA 313 Breakdown']);
        fputcsv($output, ['CAS Number', 'Chemical Name', 'Total lbs']);
        if (empty($saraBreakdown)) {
            fputcsv($output, ['No SARA 313 reportable chemicals found in shipped products for this period.']);
        } else {
            foreach ($saraBreakdown as $cas => $entry) {
                fputcsv($output, [$cas, $entry['name'], round($entry['lbs'], 4)]);
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
            return; // redirected with flash error
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

        $data = $_SESSION[self::SESSION_KEY] ?? [];

        if (empty($data['shipping_detail'])) {
            $_SESSION['_flash']['error'] = 'Please upload shipping detail data first.';
            redirect('/reports');
        }

        $customerField = $_POST['customer_field'] ?? 'ship_to_name';
        $customerValue = trim($_POST['customer_value'] ?? '');
        $dateFrom      = trim($_POST['date_from'] ?? '');
        $dateTo        = trim($_POST['date_to'] ?? '');

        if ($customerValue === '' || $dateFrom === '' || $dateTo === '') {
            $_SESSION['_flash']['error'] = 'Customer, date from, and date to are required.';
            redirect('/reports');
        }

        // Filter shipping data
        $filtered = [];
        foreach ($data['shipping_detail'] as $row) {
            $rowCustomer = trim((string) ($row[$customerField] ?? ''));
            $rowDate     = $row['date_shipped'] ?? '';

            if ($rowCustomer === $customerValue && $rowDate >= $dateFrom && $rowDate <= $dateTo) {
                $filtered[] = $row;
            }
        }

        if (empty($filtered)) {
            $_SESSION['_flash']['error'] = 'No shipping records match the selected criteria.';
            redirect('/reports');
        }

        // Collect unique product codes
        $productCodes = [];
        foreach ($filtered as $row) {
            $code = $this->stripPackExtension($row['item_name']);
            $productCodes[$code] = true;
        }

        $db = Database::getInstance();
        $basePath = \SDS\Core\App::basePath();

        // Find the latest published SDS PDF for each product code
        $tempZip = tempnam(sys_get_temp_dir(), 'sds_shipped_') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $_SESSION['_flash']['error'] = 'Failed to create ZIP archive.';
            redirect('/reports');
        }

        $addedFiles = 0;
        $seen = [];

        foreach (array_keys($productCodes) as $productCode) {
            $fg = FinishedGood::findByProductCode($productCode);
            if ($fg === null) {
                continue;
            }

            // Get latest published SDS version(s) for this finished good
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
                continue;
            }

            // Add the most recent version per language
            $addedLangs = [];
            foreach ($versions as $v) {
                $lang = strtolower($v['language']);
                if (isset($addedLangs[$lang])) {
                    continue;
                }
                $addedLangs[$lang] = true;

                $pdfFullPath = $basePath . '/' . ltrim($v['pdf_path'], '/');
                if (!file_exists($pdfFullPath)) {
                    continue;
                }

                $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $productCode);
                $zipName  = $safeCode . '_SDS';
                if ($lang !== 'en') {
                    $zipName .= '_' . strtoupper($lang);
                }
                $zipName .= '.pdf';

                if (isset($seen[$zipName])) {
                    continue;
                }
                $seen[$zipName] = true;

                $zip->addFile($pdfFullPath, $zipName);
                $addedFiles++;
            }
        }

        $zip->close();

        if ($addedFiles === 0) {
            @unlink($tempZip);
            $_SESSION['_flash']['warning'] = 'No published SDS PDFs found for the shipped items.';
            redirect('/reports');
        }

        $safeCustomer = preg_replace('/[^a-zA-Z0-9]/', '_', $customerValue);
        $exportName = 'SDS_Export_' . $safeCustomer . '_' . date('Ymd') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $exportName . '"');
        header('Content-Length: ' . filesize($tempZip));
        header('Cache-Control: no-cache, must-revalidate');

        readfile($tempZip);
        @unlink($tempZip);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Build report data (shared between CSV and PDF)
     * ----------------------------------------------------------------*/

    private function buildReportData(): ?array
    {
        $data = $_SESSION[self::SESSION_KEY] ?? [];

        if (empty($data['shipping_detail'])) {
            $_SESSION['_flash']['error'] = 'Please upload shipping detail data first.';
            redirect('/reports');
        }

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

        $dateFromTs = strtotime($dateFrom);
        $dateToTs   = strtotime($dateTo);

        if ($dateFromTs === false || $dateToTs === false) {
            $_SESSION['_flash']['error'] = 'Invalid date format.';
            redirect('/reports');
        }

        // Make end date inclusive (end of day)
        $dateToTs = strtotime($dateTo . ' 23:59:59');

        $itemNames    = $data['item_names'] ?? [];
        $shippingData = $data['shipping_detail'] ?? [];

        // Filter shipping records
        $filtered = [];
        foreach ($shippingData as $row) {
            // Customer match
            $fieldValue = $row[$customerField] ?? '';
            if ($fieldValue !== $customerValue) {
                continue;
            }

            // Date match
            $rowDate = strtotime($row['date_shipped']);
            if ($rowDate === false || $rowDate < $dateFromTs || $rowDate > $dateToTs) {
                continue;
            }

            $filtered[] = $row;
        }

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

        // Cache calculations by product code (strip pack extension)
        $calcCache = [];

        // Aggregate HAP and SARA 313 breakdowns: CAS => ['name' => ..., 'lbs' => ...]
        $hapBreakdown  = [];
        $saraBreakdown = [];

        foreach ($filtered as $row) {
            $itemCode    = $row['item_name'];
            $description = $itemNames[$itemCode] ?? '';
            $qtyShipped  = $row['qty_shipped'];

            // Strip pack extension to get the finished good product code
            $productCode = $this->stripPackExtension($itemCode);

            // Lookup VOC/HAP from SDS system
            $vocWtPct = null;
            $hapWtPct = null;
            $vocLbs   = null;
            $hapLbs   = null;

            if (!isset($calcCache[$productCode])) {
                $calcCache[$productCode] = $this->getVocHapForProduct($productCode, $calcService);
            }

            $calcData = $calcCache[$productCode];

            if ($calcData !== null) {
                $vocWtPct = $calcData['voc_wt_pct'];
                $hapWtPct = $calcData['hap_wt_pct'];

                // lbs of VOC = qty_shipped * (voc_wt_pct / 100)
                $vocLbs = $qtyShipped * ($vocWtPct / 100.0);
                $hapLbs = $qtyShipped * ($hapWtPct / 100.0);

                $totalVocLbs += $vocLbs;
                $totalHapLbs += $hapLbs;

                // Aggregate individual HAP chemicals
                foreach ($calcData['hap_chemicals'] as $hap) {
                    $cas  = $hap['cas_number'];
                    $name = $hap['chemical_name'];
                    $lbs  = $qtyShipped * ((float) $hap['concentration_pct'] / 100.0);
                    if (!isset($hapBreakdown[$cas])) {
                        $hapBreakdown[$cas] = ['name' => $name, 'lbs' => 0.0];
                    }
                    $hapBreakdown[$cas]['lbs'] += $lbs;
                }

                // Aggregate individual SARA 313 reportable chemicals
                foreach ($calcData['sara_reportable'] as $sara) {
                    $cas  = $sara['cas_number'];
                    $name = $sara['chemical_name'];
                    $lbs  = $qtyShipped * ((float) $sara['concentration_pct'] / 100.0);
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

        // Sort breakdowns by lbs descending
        uasort($hapBreakdown, fn($a, $b) => $b['lbs'] <=> $a['lbs']);
        uasort($saraBreakdown, fn($a, $b) => $b['lbs'] <=> $a['lbs']);

        return [
            'customer_value' => $customerValue,
            'customer_field' => $customerField,
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'lines'            => $reportLines,
            'total_shipped_lbs' => $totalShippedLbs,
            'total_voc_lbs'    => $totalVocLbs,
            'total_hap_lbs'    => $totalHapLbs,
            'hap_breakdown'  => $hapBreakdown,
            'sara_breakdown' => $saraBreakdown,
        ];
    }

    /* ------------------------------------------------------------------
     *  Clear all report data from session
     * ----------------------------------------------------------------*/

    public function clear(): void
    {
        CSRF::validateRequest();

        unset($_SESSION[self::SESSION_KEY]);
        $_SESSION['_flash']['success'] = 'All report data has been cleared.';
        redirect('/reports');
    }

    /* ------------------------------------------------------------------
     *  AJAX: get customers for a given field
     * ----------------------------------------------------------------*/

    public function customers(): void
    {
        $data  = $_SESSION[self::SESSION_KEY] ?? [];
        $field = $_GET['field'] ?? 'ship_to_name';

        $allowed = ['bill_to', 'ship_to', 'ship_to_name'];
        if (!in_array($field, $allowed, true)) {
            $field = 'ship_to_name';
        }

        $customers = $this->getCustomerList($data['shipping_detail'] ?? [], $field);

        header('Content-Type: application/json');
        echo json_encode($customers);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Private helpers
     * ----------------------------------------------------------------*/

    private function parseCsv(string $filepath): array
    {
        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return [];
        }

        // Read header row
        $headers = fgetcsv($handle);
        if ($headers === false || $headers === [null]) {
            fclose($handle);
            return [];
        }

        // Clean BOM from first header
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) < count($headers)) {
                $line = array_pad($line, count($headers), '');
            }
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $line[$i] ?? '';
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    private function findColumn(array $normalizedHeaders, array $candidates): ?int
    {
        foreach ($normalizedHeaders as $i => $header) {
            foreach ($candidates as $candidate) {
                if ($header === $candidate) {
                    return $i;
                }
            }
        }
        return null;
    }

    private function getCustomerList(array $shippingData, string $field = 'ship_to_name'): array
    {
        $customers = [];
        foreach ($shippingData as $row) {
            $val = trim((string) ($row[$field] ?? ''));
            if ($val !== '' && !in_array($val, $customers, true)) {
                $customers[] = $val;
            }
        }
        sort($customers);
        return $customers;
    }

    /**
     * Strip the pack extension from a customer-facing item code.
     *
     * The pack extension always starts with a "-". Examples:
     *   "ABC123-50"  => "ABC123"
     *   "XY9000-1G"  => "XY9000"
     *   "PROD-55M"   => "PROD"
     *
     * The product code itself never contains a "-".
     */
    private function stripPackExtension(string $itemCode): string
    {
        $pos = strpos($itemCode, '-');
        if ($pos !== false) {
            return substr($itemCode, 0, $pos);
        }
        return $itemCode;
    }

    /**
     * Get VOC wt%, HAP wt%, HAP chemical details, and SARA 313 details
     * for a finished good product code.
     *
     * Returns null if the product is not found or has no formula.
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

        $vocWtPct = (float) ($calcResult['voc']['total_voc_wt_pct'] ?? 0);

        // HAP analysis
        $hapResult = HAPService::analyse($calcResult['composition']);
        $hapWtPct  = (float) ($hapResult['total_hap_pct'] ?? 0);

        // SARA 313 analysis
        $saraResult = SARA313Service::analyse($calcResult['composition']);

        return [
            'voc_wt_pct'      => $vocWtPct,
            'hap_wt_pct'      => $hapWtPct,
            'hap_chemicals'   => $hapResult['hap_chemicals'] ?? [],
            'sara_reportable' => $saraResult['reportable'] ?? [],
        ];
    }
}
