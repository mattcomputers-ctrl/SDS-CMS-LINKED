#!/usr/bin/env php
<?php
/**
 * PDF Worker — Generates a single SDS PDF from serialized data.
 *
 * Usage:
 *   php scripts/pdf-worker.php <input-json> <result-file>
 *
 * The input file is a JSON-encoded SDS data array (from SDSGenerator).
 * On success, writes the PDF path to the result file.
 * On failure, writes a JSON error to the result file.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

new \SDS\Core\App();

use SDS\Services\PDFService;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php pdf-worker.php <input-json> <result-file>\n");
    exit(1);
}

$inputFile  = $argv[1];
$resultFile = $argv[2];

try {
    if (!file_exists($inputFile)) {
        throw new \RuntimeException('Input file not found: ' . $inputFile);
    }

    $sdsData = json_decode(file_get_contents($inputFile), true);
    if (!is_array($sdsData)) {
        throw new \RuntimeException('Invalid SDS data in input file.');
    }

    $pdfService = new PDFService();
    $pdfPath    = $pdfService->generate($sdsData);

    file_put_contents($resultFile, json_encode(['ok' => true, 'pdf_path' => $pdfPath]));
} catch (\Throwable $e) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'error' => $e->getMessage()]));
    exit(1);
}
