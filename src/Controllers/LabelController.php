<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Models\LabelTemplate;
use SDS\Services\SDSGenerator;
use SDS\Services\LabelPDFService;

class LabelController
{
    public function index(): void
    {
        $finishedGoods = FinishedGood::all([
            'per_page' => 999,
            'sort'     => 'product_code',
            'dir'      => 'asc',
            'is_active' => 1,
        ]);

        $templates = LabelTemplate::all();

        // Load net weight unit options from admin settings
        $db = Database::getInstance();
        $unitRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'label.net_weight_units'");
        $netWeightUnits = [];
        if ($unitRow && trim($unitRow['value']) !== '') {
            $netWeightUnits = array_filter(array_map('trim', explode("\n", $unitRow['value'])), 'strlen');
        }

        view('labels/index', [
            'pageTitle'      => 'GHS Labels',
            'finishedGoods'  => $finishedGoods,
            'templates'      => $templates,
            'netWeightUnits' => $netWeightUnits,
        ]);
    }

    public function generate(): void
    {
        $finishedGoodId = (int) ($_POST['finished_good_id'] ?? 0);
        $lotNumber      = trim($_POST['lot_number'] ?? '');
        $templateId     = (int) ($_POST['template_id'] ?? 0);
        $quantity        = max(1, (int) ($_POST['quantity'] ?? 1));
        $netWeightValue  = trim($_POST['net_weight_value'] ?? '');
        $netWeightUnit   = trim($_POST['net_weight_unit'] ?? '');
        $netWeight       = $netWeightValue !== '' ? $netWeightValue . ($netWeightUnit !== '' ? ' ' . $netWeightUnit : '') : '';
        $privateLabel    = !empty($_POST['private_label']);

        // Validate finished good
        $fg = FinishedGood::findById($finishedGoodId);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Please select a valid product.';
            redirect('/labels');
        }

        // Validate lot number: must be 1 to 12 digits
        if (!preg_match('/^\d{1,12}$/', $lotNumber)) {
            $_SESSION['_flash']['error'] = 'Lot number must be 1 to 12 digits.';
            redirect('/labels');
        }

        // Get template
        $template = null;
        if ($templateId > 0) {
            $template = LabelTemplate::findById($templateId);
        }
        if ($template === null) {
            $template = LabelTemplate::getDefault();
        }
        if ($template === null) {
            $_SESSION['_flash']['error'] = 'No label template found. Please create one first.';
            redirect('/labels');
        }

        try {
            // Generate SDS data to get hazard info
            $generator = new SDSGenerator();
            $sdsData   = $generator->generate($finishedGoodId, 'en');

            // Generate PDF
            $pdfService = new LabelPDFService();
            $pdfContent = $pdfService->generateFromTemplate($sdsData, $fg, $lotNumber, $template, $quantity, $netWeight, $privateLabel);

            // Output PDF
            $filename = $fg['product_code'] . '_label_' . $lotNumber . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdfContent));
            echo $pdfContent;
            exit;
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Label generation failed: ' . $e->getMessage();
            redirect('/labels');
        }
    }
}
