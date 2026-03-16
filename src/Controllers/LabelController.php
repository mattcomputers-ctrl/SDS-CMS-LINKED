<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;
use SDS\Models\FinishedGood;
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

        view('labels/index', [
            'pageTitle'     => 'GHS Labels',
            'finishedGoods' => $finishedGoods,
        ]);
    }

    public function generate(): void
    {
        $finishedGoodId = (int) ($_POST['finished_good_id'] ?? 0);
        $lotNumber      = trim($_POST['lot_number'] ?? '');
        $labelSize      = $_POST['label_size'] ?? 'big';
        $quantity        = max(1, (int) ($_POST['quantity'] ?? 1));

        // Validate finished good
        $fg = FinishedGood::findById($finishedGoodId);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Please select a valid product.';
            redirect('/labels');
        }

        // Validate lot number: must be exactly 9 digits
        if (!preg_match('/^\d{9}$/', $lotNumber)) {
            $_SESSION['_flash']['error'] = 'Lot number must be exactly 9 digits.';
            redirect('/labels');
        }

        // Validate label size
        if (!in_array($labelSize, ['big', 'small'], true)) {
            $labelSize = 'big';
        }

        try {
            // Generate SDS data to get hazard info
            $generator = new SDSGenerator();
            $sdsData   = $generator->generate($finishedGoodId, 'en');

            // Generate PDF
            $pdfService = new LabelPDFService();
            $pdfContent = $pdfService->generate($sdsData, $fg, $lotNumber, $labelSize, $quantity);

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
