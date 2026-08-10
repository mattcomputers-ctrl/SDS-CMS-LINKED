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
     *  IL EPA Ross Calculation Report
     * ----------------------------------------------------------------*/

    /** NAPIM source-testing emission factors (lb emitted per lb). */
    private const ROSS_MIXING_FACTOR  = 0.0032;
    private const ROSS_MILLING_FACTOR = 0.0108;

    private function buildRossData(): ?array
    {
        if (!can_read('ross_report')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to run the Ross Calculation report.';
            redirect('/reports');
        }

        $db = Database::getInstance();
        $dateFrom = trim($_POST['date_from'] ?? '');
        $dateTo   = trim($_POST['date_to'] ?? '');
        if ($dateFrom === '' || $dateTo === '') {
            $_SESSION['_flash']['error'] = 'Please enter both a start and end date.';
            redirect('/reports');
        }

        $mixingOps  = max(0, min(20, (int) ($_POST['mixing_ops'] ?? 1)));
        $millingOps = max(0, min(20, (int) ($_POST['milling_ops'] ?? 2)));

        // Raw material consumption from CMS production (commingle) movements:
        // negative quantities are materials weighed into batches. Pounds
        // produced = pounds of raw materials consumed (mass balance).
        try {
            $consumed = \SDS\Services\CMSDatabase::getInstance()->fetchAll(
                "SELECT i.ItemCode, i.Description, SUM(-imd.Qty) AS lbs
                 FROM CMS.dbo.InvMovementDtl imd
                 JOIN CMS.dbo.InvMovement im ON im.InvMovement = imd.InvMovement
                 JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = im.ChangeSet
                 JOIN CMS.dbo.Item i ON i.Item = im.Item
                 WHERE im.Context = 'CMNGL' AND imd.Qty < 0
                   AND cs.ChangeDate >= ? AND cs.ChangeDate <= ?
                 GROUP BY i.ItemCode, i.Description",
                [$dateFrom, $dateTo . ' 23:59:59']
            );
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Could not query CMS production movements: ' . $e->getMessage();
            redirect('/reports');
            return null;
        }
        if (empty($consumed)) {
            $_SESSION['_flash']['error'] = 'No production consumption records in the selected date range.';
            redirect('/reports');
        }

        // Local RM data: VOC wt% and HAP wt% (sum of constituent
        // percentages whose CAS is on the EPA HAP list).
        $hapCas = array_flip(array_column($db->fetchAll("SELECT cas_number FROM hap_list"), 'cas_number'));
        $rmData = [];
        foreach ($db->fetchAll("SELECT id, internal_code, voc_wt, voc_less_than_one FROM raw_materials") as $rm) {
            // The "<1% VOC" checkbox counts as data — use 0.99% for this
            // calculation when no explicit VOC percentage was entered.
            $voc = $rm['voc_wt'];
            if ($voc === null && (int) ($rm['voc_less_than_one'] ?? 0) === 1) {
                $voc = 0.99;
            }
            $rmData[$rm['internal_code']] = ['voc' => $voc, 'hap' => 0.0, 'has_cons' => false, 'id' => (int) $rm['id']];
        }
        $consRows = $db->fetchAll(
            "SELECT rm.internal_code, rmc.cas_number, rmc.pct_exact, rmc.pct_min, rmc.pct_max
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id"
        );
        foreach ($consRows as $c) {
            $code = $c['internal_code'];
            if (!isset($rmData[$code])) {
                continue;
            }
            $rmData[$code]['has_cons'] = true;
            if (isset($hapCas[$c['cas_number']])) {
                $pct = $c['pct_exact'] !== null
                    ? (float) $c['pct_exact']
                    : (((float) ($c['pct_min'] ?? 0)) + ((float) ($c['pct_max'] ?? 0))) / 2.0;
                $rmData[$code]['hap'] += $pct;
            }
        }

        // RM id → internal code, for expanding formula lines
        $idToCode = [];
        foreach ($rmData as $c => $r) {
            $idToCode[$r['id']] = $c;
        }

        $totalLbs = 0.0;
        $missing  = [];
        $rmConsumption = []; // internal_code → lbs consumed (direct + expanded from intermediates)

        foreach ($consumed as $row) {
            $code = trim((string) $row['ItemCode']);
            $lbs  = (float) $row['lbs'];
            if ($code === '' || $lbs <= 0) {
                continue;
            }
            $totalLbs += $lbs;

            // 1. Raw material — exact code first, then pack extension stripped.
            $matched = null;
            foreach ([$code, $this->stripPackExtension($code)] as $try) {
                $rm = $rmData[$try] ?? null;
                if ($rm !== null && ($rm['voc'] !== null || $rm['has_cons'])) {
                    $matched = $try;
                    break;
                }
            }
            if ($matched !== null) {
                $rmConsumption[$matched] = ($rmConsumption[$matched] ?? 0.0) + $lbs;
                continue;
            }

            // 2. Manufactured intermediates: break down into their raw
            //    materials by expanding the formula recursively.
            $productCode = $this->resolveToProductCode($code, $db) ?? $this->stripPackExtension($code);
            $fg = FinishedGood::findByProductCode($productCode);
            if ($fg !== null && $this->expandToRawMaterials((int) $fg['id'], $lbs, $idToCode, $rmConsumption, $missing)) {
                continue;
            }

            // 3. No data anywhere — report it.
            $missing[$code] = (string) ($row['Description'] ?? '');
        }

        // Build the detail table from raw-material-level consumption.
        $vocLbs = 0.0;
        $hapLbs = 0.0;
        $detail = [];
        $rmDescRows = $db->fetchAll("SELECT internal_code, supplier_product_name FROM raw_materials");
        $rmDesc = array_column($rmDescRows, 'supplier_product_name', 'internal_code');
        foreach ($rmConsumption as $code => $lbs) {
            $rm = $rmData[$code] ?? null;
            if ($rm === null || ($rm['voc'] === null && !$rm['has_cons'])) {
                $missing[$code] = (string) ($rmDesc[$code] ?? '');
                continue;
            }
            $vocPct  = (float) ($rm['voc'] ?? 0);
            $hapPct  = (float) $rm['hap'];
            $itemVoc = $lbs * ($vocPct / 100.0);
            $itemHap = $lbs * ($hapPct / 100.0);
            $vocLbs += $itemVoc;
            $hapLbs += $itemHap;
            $detail[] = [
                'code'    => $code,
                'desc'    => (string) ($rmDesc[$code] ?? ''),
                'lbs'     => $lbs,
                'voc_pct' => $vocPct,
                'hap_pct' => $hapPct,
                'voc_lbs' => $itemVoc,
                'hap_lbs' => $itemHap,
            ];
        }
        usort($detail, fn($a, $b) => $b['lbs'] <=> $a['lbs']);

        $factor = $mixingOps * self::ROSS_MIXING_FACTOR
                + $millingOps * self::ROSS_MILLING_FACTOR;
        ksort($missing);

        return [
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'total_lbs'       => $totalLbs,
            'voc_lbs'         => $vocLbs,
            'hap_lbs'         => $hapLbs,
            'factor'          => $factor,
            'mixing_factor'   => self::ROSS_MIXING_FACTOR,
            'milling_factor'  => self::ROSS_MILLING_FACTOR,
            'mixing_ops'      => $mixingOps,
            'milling_ops'     => $millingOps,
            'voc_emissions'   => $vocLbs * $factor,
            'hap_emissions'   => $hapLbs * $factor,
            'missing'         => $missing,
            'detail'          => $detail,
        ];
    }

    /**
     * Expand a finished good's current formula into raw-material pounds,
     * recursing through sub-product components. Returns false when no
     * usable formula exists (caller reports the item as missing).
     */
    private function expandToRawMaterials(int $fgId, float $lbs, array $idToCode, array &$rmConsumption, array &$missing, int $depth = 0): bool
    {
        if ($depth > 6) {
            return false;
        }
        $formula = \SDS\Models\Formula::findCurrentByFinishedGood($fgId);
        if (!$formula || empty($formula['lines'])) {
            return false;
        }

        foreach ($formula['lines'] as $line) {
            $share = $lbs * ((float) ($line['pct'] ?? 0)) / 100.0;
            if ($share <= 0) {
                continue;
            }
            if (!empty($line['raw_material_id'])) {
                $code = $idToCode[(int) $line['raw_material_id']] ?? null;
                if ($code !== null) {
                    $rmConsumption[$code] = ($rmConsumption[$code] ?? 0.0) + $share;
                } else {
                    $missing['RM#' . $line['raw_material_id']] = 'Raw material referenced by formula not found';
                }
            } elseif (!empty($line['finished_good_component_id'])) {
                if (!$this->expandToRawMaterials((int) $line['finished_good_component_id'], $share, $idToCode, $rmConsumption, $missing, $depth + 1)) {
                    $sub = FinishedGood::findById((int) $line['finished_good_component_id']);
                    $missing[$sub['product_code'] ?? ('FG#' . $line['finished_good_component_id'])] = 'Sub-product has no formula to expand';
                }
            }
        }

        return true;
    }

    public function ross(): void
    {
        CSRF::validateRequest();
        $d = $this->buildRossData();
        if ($d === null) {
            return;
        }

        $filename = 'IL_EPA_Ross_Calculation_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $o = fopen('php://output', 'w');
        fputcsv($o, ['IL EPA Ross Calculation Report']);
        fputcsv($o, ['Date Range:', $d['date_from'] . ' to ' . $d['date_to']]);
        fputcsv($o, ['Generated:', date('m/d/Y H:i')]);
        fputcsv($o, []);
        fputcsv($o, ['Total lbs Produced (raw materials consumed)', number_format($d['total_lbs'], 2, '.', '')]);
        fputcsv($o, ['Total lbs VOC', number_format($d['voc_lbs'], 2, '.', '')]);
        fputcsv($o, ['Total lbs HAP', number_format($d['hap_lbs'], 2, '.', '')]);
        fputcsv($o, []);
        fputcsv($o, ['Emission Factors (NAPIM source testing data)']);
        fputcsv($o, ['Mixing operations', number_format($d['mixing_factor'], 4, '.', '') . ' lb/lb x ' . $d['mixing_ops'] . ' operation(s)']);
        fputcsv($o, ['Milling operations', number_format($d['milling_factor'], 4, '.', '') . ' lb/lb x ' . $d['milling_ops'] . ' operation(s)']);
        fputcsv($o, ['Combined factor', number_format($d['factor'], 4, '.', '') . ' lb/lb']);
        fputcsv($o, []);
        fputcsv($o, ['Calculated VOC Emissions (lbs)', number_format($d['voc_emissions'], 2, '.', '')]);
        fputcsv($o, ['Calculated HAP Emissions (lbs)', number_format($d['hap_emissions'], 2, '.', '')]);
        fputcsv($o, []);
        fputcsv($o, ['Consumption Detail']);
        fputcsv($o, ['Item Code', 'Description', 'Total Qty Consumed (lbs)', 'VOC % by wt', 'HAP % by wt', 'lbs VOC', 'lbs HAP']);
        foreach ($d['detail'] as $dl) {
            fputcsv($o, [
                $dl['code'],
                $dl['desc'],
                number_format($dl['lbs'], 2, '.', ''),
                number_format($dl['voc_pct'], 2, '.', ''),
                number_format($dl['hap_pct'], 2, '.', ''),
                number_format($dl['voc_lbs'], 2, '.', ''),
                number_format($dl['hap_lbs'], 2, '.', ''),
            ]);
        }
        if (!empty($d['missing'])) {
            fputcsv($o, []);
            fputcsv($o, ['Items missing raw material data (not included in VOC/HAP totals):']);
            foreach ($d['missing'] as $code => $desc) {
                fputcsv($o, [$code, $desc]);
            }
        }
        fclose($o);
        exit;
    }

    public function rossPdf(): void
    {
        CSRF::validateRequest();
        $d = $this->buildRossData();
        if ($d === null) {
            return;
        }

        $db  = Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.report_disclaimer'");
        $pdfService = new ReportPDFService();
        $pdfContent = $pdfService->generateRoss($d, $row['value'] ?? '');

        $filename = 'IL_EPA_Ross_Calculation_' . date('Ymd') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $pdfContent;
        exit;
    }

    /* ------------------------------------------------------------------
     *  Order History Report (CSV)
     * ----------------------------------------------------------------*/

    /**
     * POST /reports/order-history — items shipped to a customer in a
     * date range with PO number, unit price, and UOM, queried live from
     * CMS. Reversed invoice lines net to zero per order line and are
     * excluded.
     */
    public function orderHistory(): void
    {
        CSRF::validateRequest();

        if (!can_read('order_history_report')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to run the Order History report.';
            redirect('/reports');
        }

        $customerField = $_POST['customer_field'] ?? 'ship_to_name';
        $customerValue = trim($_POST['customer_value'] ?? '');
        $dateFrom      = trim($_POST['date_from'] ?? '');
        $dateTo        = trim($_POST['date_to'] ?? '');

        if ($customerValue === '' || $dateFrom === '' || $dateTo === '') {
            $_SESSION['_flash']['error'] = 'Please select a customer and enter both dates.';
            redirect('/reports');
        }

        $cmsFieldMap = ['bill_to' => 'BillTo', 'ship_to' => 'ShipTo', 'ship_to_name' => 'ShipToName'];
        $cmsField = $cmsFieldMap[$customerField] ?? 'ShipToName';

        try {
            $cms = \SDS\Services\CMSDatabase::getInstance();
            $rows = $cms->fetchAll(
                "SELECT sd.OrdDetail, sd.ItemCode, sd.ItemName, sd.DateShipped,
                        sd.QtyShipped, sd.PoNumber, sd.UnitPrice, sd.Unit,
                        alias_item.Description AS AliasDescription,
                        inv_item.Description AS InvDescription
                 FROM CMS.dbo.ShipmentDetails sd
                 LEFT JOIN CMS.dbo.Item alias_item ON alias_item.ItemCode = sd.ItemName
                 LEFT JOIN CMS.dbo.Item inv_item ON inv_item.ItemCode = sd.ItemCode
                 WHERE sd.{$cmsField} = ?
                   AND sd.DateShipped >= ? AND sd.DateShipped <= ?
                   AND NOT EXISTS (
                       SELECT 1 FROM CMS.dbo.Invoice iv
                       WHERE iv.TransDocument = sd.TransDocument
                         AND iv.IsReversed = 1
                   )
                 ORDER BY sd.DateShipped",
                [$customerValue, $dateFrom, $dateTo . ' 23:59:59']
            );
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Could not query CMS: ' . $e->getMessage();
            redirect('/reports');
            return;
        }

        if (empty($rows)) {
            $_SESSION['_flash']['error'] = 'No records match the selected customer and date range.';
            redirect('/reports');
        }

        // Net quantities per order line (OrdDetail) — a reversed invoice
        // produces an offsetting negative movement on the same line, so
        // reversed lines net to zero and drop out.
        $lines = [];
        foreach ($rows as $r) {
            $key = $r['OrdDetail'] !== null
                ? 'od:' . $r['OrdDetail']
                : 'x:' . ($r['ItemName'] ?: $r['ItemCode']) . '|' . ($r['PoNumber'] ?? '') . '|' . ($r['UnitPrice'] ?? '');

            if (!isset($lines[$key])) {
                $itemCode = trim((string) ($r['ItemName'] ?: $r['ItemCode']));
                $desc = trim((string) ($r['AliasDescription'] ?? ''));
                if ($desc === '') {
                    $desc = trim((string) ($r['InvDescription'] ?? ''));
                }
                $lines[$key] = [
                    'ship_date'   => '',
                    'item_code'   => $itemCode,
                    'description' => $desc,
                    'qty'         => 0.0,
                    'po_number'   => trim((string) ($r['PoNumber'] ?? '')),
                    'unit_price'  => $r['UnitPrice'] !== null ? (float) $r['UnitPrice'] : null,
                    'uom'         => trim((string) ($r['Unit'] ?? '')),
                ];
            }
            $lines[$key]['qty'] += (float) $r['QtyShipped'];
            // Latest ship date on the line wins (rows arrive date-ascending)
            if (!empty($r['DateShipped'])) {
                $lines[$key]['ship_date'] = substr((string) $r['DateShipped'], 0, 10);
            }
        }

        $lines = array_values(array_filter($lines, fn($l) => $l['qty'] > 0));
        usort($lines, fn($a, $b) => [$a['ship_date'], $a['item_code'], $a['po_number']] <=> [$b['ship_date'], $b['item_code'], $b['po_number']]);

        $filename = 'Order_History_' . preg_replace('/[^a-zA-Z0-9]/', '_', $customerValue) . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Order History Report']);
        fputcsv($output, ['Customer:', $customerValue]);
        fputcsv($output, ['Date Range:', $dateFrom . ' to ' . $dateTo]);
        fputcsv($output, ['Generated:', date('m/d/Y H:i')]);
        fputcsv($output, []);
        fputcsv($output, ['Ship Date', 'PO Number', 'Item Code', 'Description', 'Units Shipped', 'UOM', 'Sales Price/Unit']);
        foreach ($lines as $l) {
            fputcsv($output, [
                $l['ship_date'],
                $l['po_number'],
                $l['item_code'],
                $l['description'],
                rtrim(rtrim(number_format($l['qty'], 4, '.', ''), '0'), '.'),
                $l['uom'],
                $l['unit_price'] !== null ? number_format($l['unit_price'], 2, '.', '') : '',
            ]);
        }
        fclose($output);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Prop 65 Report (CSV)
     * ----------------------------------------------------------------*/

    /**
     * POST /reports/prop65 — items shipped to a customer in a date range
     * that carry a Prop 65 warning, with the triggering chemical(s) and
     * their concentration in each item.
     */
    public function prop65(): void
    {
        CSRF::validateRequest();

        $data = $this->buildProp65Data();
        if ($data === null) {
            return;
        }

        $this->outputProp65Csv($data);
    }

    /**
     * POST /reports/prop65-pdf — printable PDF version of the Prop 65 report.
     */
    public function prop65Pdf(): void
    {
        CSRF::validateRequest();

        $data = $this->buildProp65Data();
        if ($data === null) {
            return;
        }

        $db  = Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.report_disclaimer'");

        $pdfService = new ReportPDFService();
        $pdfContent = $pdfService->generateProp65($data, $row['value'] ?? '');

        $filename = 'Prop65_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $data['customer_value']) . '_' . date('Ymd') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $pdfContent;
        exit;
    }

    private function buildProp65Data(): ?array
    {
        $db = Database::getInstance();

        $customerField = $_POST['customer_field'] ?? 'ship_to_name';
        $customerValue = trim($_POST['customer_value'] ?? '');
        $dateFrom      = trim($_POST['date_from'] ?? '');
        $dateTo        = trim($_POST['date_to'] ?? '');

        if ($customerValue === '' || $dateFrom === '' || $dateTo === '') {
            $_SESSION['_flash']['error'] = 'Please select a customer and enter both dates.';
            redirect('/reports');
        }

        $allowedFields = ['bill_to', 'ship_to', 'ship_to_name'];
        if (!in_array($customerField, $allowedFields, true)) {
            $customerField = 'ship_to_name';
        }

        $shipments = $db->fetchAll(
            "SELECT * FROM shipment_detail
             WHERE `{$customerField}` = ?
               AND date_shipped >= ? AND date_shipped <= ?
             ORDER BY date_shipped",
            [$customerValue, $dateFrom, $dateTo . ' 23:59:59']
        );

        if (empty($shipments)) {
            $_SESSION['_flash']['error'] = 'No records match the selected customer and date range.';
            redirect('/reports');
        }

        // Prop 65 membership: CAS → listing name + toxicity types
        $p65Map = [];
        foreach ($db->fetchAll("SELECT cas_number, chemical_name, toxicity_type FROM prop65_list") as $r) {
            $p65Map[$r['cas_number']] = $r;
        }

        $calcService   = new FormulaCalcService();
        $resolvedCodes = [];
        $triggerCache  = [];   // product code → list of triggering chemicals (or null = could not evaluate)
        $reportRows    = [];
        $unevaluated   = [];
        $chemSummary   = [];

        // Merge shipment lines by item with the pack extension stripped —
        // pack variants (Y1011-50, Y1011-84) are the same product, so they
        // collapse into one row keyed by the base code with total quantity.
        $items = [];
        foreach ($shipments as $row) {
            $rawCode  = !empty($row['item_name']) ? $row['item_name'] : $row['item_code'];
            $itemCode = $this->stripPackExtension($rawCode);

            $description = '';
            if (!empty($row['item_name']) && $row['item_name'] !== $row['item_code']) {
                $description = $row['item_name_description'] ?? '';
            }
            if ($description === '') {
                $description = $row['item_description'] ?? '';
            }

            if (!isset($items[$itemCode])) {
                $items[$itemCode] = ['description' => $description, 'qty' => 0.0, 'raw_code' => $rawCode];
            }
            if ($items[$itemCode]['description'] === '' && $description !== '') {
                $items[$itemCode]['description'] = $description;
            }
            $items[$itemCode]['qty'] += (float) $row['qty_shipped'];
        }
        ksort($items);

        foreach ($items as $itemCode => $info) {
            $description = $info['description'];

            // Resolve from the original code — aliases store the pack
            // extension, so the unstripped form matches more reliably.
            if (!isset($resolvedCodes[$itemCode])) {
                $resolvedCodes[$itemCode] = $this->resolveToProductCode($info['raw_code'], $db)
                    ?? $itemCode;
            }
            $productCode = $resolvedCodes[$itemCode];

            if (!array_key_exists($productCode, $triggerCache)) {
                $triggerCache[$productCode] = null;
                try {
                    $fg = FinishedGood::findByProductCode($productCode);
                    if ($fg !== null) {
                        $calc = $calcService->calculate((int) $fg['id']);

                        // Airborne/unbound particles override — same rule as
                        // SDS generation: inhalation-only CAS (settings) are
                        // suppressed when the product contains any non-powder,
                        // non-solid ingredient, because the particulate is
                        // bound and no longer airborne.
                        $inhalationOnly = \SDS\Services\SDSGenerator::getInhalationOnlyCas();
                        $hasNonPowder = false;
                        foreach (($calc['formula_props']['enriched_lines'] ?? []) as $line) {
                            $state = strtolower((string) ($line['physical_state'] ?? ''));
                            if ($state !== 'powder' && $state !== 'solid') {
                                $hasNonPowder = true;
                                break;
                            }
                        }

                        $triggers = [];
                        foreach ($calc['composition'] as $c) {
                            $cas = $c['cas_number'] ?? '';
                            if ($cas !== '' && $hasNonPowder && isset($inhalationOnly[$cas])) {
                                continue;
                            }
                            if ($cas !== '' && isset($p65Map[$cas])) {
                                $triggers[] = [
                                    'cas'      => $cas,
                                    'name'     => $p65Map[$cas]['chemical_name'],
                                    'toxicity' => $p65Map[$cas]['toxicity_type'] ?? '',
                                    'pct'      => (float) ($c['concentration_pct'] ?? 0.0),
                                ];
                            }
                        }
                        usort($triggers, fn($a, $b) => $b['pct'] <=> $a['pct']);
                        $triggerCache[$productCode] = $triggers;
                    }
                } catch (\Throwable $e) {
                    // leave null — reported as "could not evaluate"
                }
            }

            $triggers = $triggerCache[$productCode];

            if ($triggers === null) {
                $unevaluated[$itemCode] = $description;
                continue;
            }
            if (empty($triggers)) {
                continue; // no Prop 65 warning for this item
            }

            foreach ($triggers as $t) {
                $reportRows[] = [
                    'item_code'    => $itemCode,
                    'description'  => $description,
                    'qty_shipped'  => $info['qty'],
                    'chem_name'    => $t['name'],
                    'cas'          => $t['cas'],
                    'toxicity'     => $t['toxicity'],
                    'pct'          => $t['pct'],
                ];
                if (!isset($chemSummary[$t['cas']])) {
                    $chemSummary[$t['cas']] = ['name' => $t['name'], 'toxicity' => $t['toxicity'], 'items' => []];
                }
                $chemSummary[$t['cas']]['items'][$itemCode] = true;
            }
        }

        $fmtPct = function (float $pct): string {
            if ($pct <= 0.0) {
                return 'trace';
            }
            if ($pct < 0.01) {
                return 'trace (<0.01%)';
            }
            return rtrim(rtrim(number_format($pct, 4, '.', ''), '0'), '.') . '%';
        };
        foreach ($reportRows as &$r) {
            $r['pct_display'] = $fmtPct($r['pct']);
        }
        unset($r);
        uasort($chemSummary, fn($a, $b) => count($b['items']) <=> count($a['items']));

        return [
            'customer_value' => $customerValue,
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'rows'           => $reportRows,
            'chem_summary'   => $chemSummary,
            'unevaluated'    => $unevaluated,
        ];
    }

    private function outputProp65Csv(array $data): void
    {
        $filename = 'Prop65_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $data['customer_value']) . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $output = fopen('php://output', 'w');

        fputcsv($output, ['California Prop 65 Shipping Report']);
        fputcsv($output, ['Customer:', $data['customer_value']]);
        fputcsv($output, ['Date Range:', $data['date_from'] . ' to ' . $data['date_to']]);
        fputcsv($output, ['Generated:', date('m/d/Y H:i')]);
        fputcsv($output, []);

        if (empty($data['rows'])) {
            fputcsv($output, ['No shipped items carry a Prop 65 warning for this period.']);
        } else {
            fputcsv($output, [
                'Item', 'Description', 'Total Qty Shipped (lbs)',
                'Prop 65 Chemical', 'CAS Number', 'Toxicity', 'Concentration',
            ]);
            foreach ($data['rows'] as $r) {
                fputcsv($output, [
                    $r['item_code'],
                    $r['description'],
                    $r['qty_shipped'],
                    $r['chem_name'],
                    $r['cas'],
                    $r['toxicity'],
                    $r['pct_display'],
                ]);
            }

            fputcsv($output, []);
            fputcsv($output, []);
            fputcsv($output, ['Prop 65 Chemical Summary']);
            fputcsv($output, ['Chemical', 'CAS Number', 'Toxicity', 'Distinct Items Shipped']);
            foreach ($data['chem_summary'] as $cas => $s) {
                fputcsv($output, [$s['name'], $cas, $s['toxicity'], count($s['items'])]);
            }
        }

        if (!empty($data['unevaluated'])) {
            fputcsv($output, []);
            fputcsv($output, ['Items that could not be evaluated (no product/formula data):']);
            foreach ($data['unevaluated'] as $code => $desc) {
                fputcsv($output, [$code, $desc]);
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
