<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;

/**
 * ExportController — Bulk SDS export (admin only).
 *
 * Generates a ZIP file containing all published finished-good SDS PDFs
 * with the naming convention: ProductCode-SDSRevisionNumber.pdf
 */
class ExportController
{
    /**
     * GET /sds-book/export — Stream a ZIP of all finished-good SDS PDFs.
     */
    public function exportAllFgSds(): void
    {
        if (!is_admin()) {
            $_SESSION['_flash']['error'] = 'Only administrators can export SDS files.';
            redirect('/sds-book');
        }

        $db = Database::getInstance();

        // Get the latest published version per product + language (for the main export)
        // But also include all versions — the user said "export all finished goods SDSs"
        // We'll include the latest version of each product/language pair for the main export
        $versions = $db->fetchAll(
            "SELECT sv.id, sv.version, sv.language, sv.pdf_path,
                    fg.product_code
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             WHERE sv.status = 'published'
               AND sv.is_deleted = 0
               AND sv.pdf_path IS NOT NULL
               AND sv.pdf_path != ''
             ORDER BY fg.product_code ASC, sv.language ASC, sv.version DESC"
        );

        if (empty($versions)) {
            $_SESSION['_flash']['warning'] = 'No published SDS PDFs found to export.';
            redirect('/sds-book');
        }

        $basePath = \SDS\Core\App::basePath();

        // Create temporary ZIP file
        $tempZip = tempnam(sys_get_temp_dir(), 'sds_export_') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $_SESSION['_flash']['error'] = 'Failed to create ZIP archive.';
            redirect('/sds-book');
        }

        $addedFiles = 0;
        $seen = [];

        foreach ($versions as $v) {
            $pdfFullPath = $basePath . '/' . ltrim($v['pdf_path'], '/');

            if (!file_exists($pdfFullPath)) {
                continue;
            }

            // Naming convention: ProductCode-SDSRevisionNumber.pdf
            // For multi-language, append language code for non-English
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $v['product_code']);
            $zipName  = $safeCode . '-SDS' . $v['version'];

            if (strtolower($v['language']) !== 'en') {
                $zipName .= '_' . strtoupper($v['language']);
            }

            $zipName .= '.pdf';

            // Avoid duplicate filenames
            if (isset($seen[$zipName])) {
                continue;
            }
            $seen[$zipName] = true;

            $zip->addFile($pdfFullPath, $zipName);
            $addedFiles++;
        }

        $zip->close();

        if ($addedFiles === 0) {
            unlink($tempZip);
            $_SESSION['_flash']['warning'] = 'No SDS PDF files found on disk.';
            redirect('/sds-book');
        }

        // Stream the ZIP to the client
        $exportName = 'SDS_Export_' . date('Y-m-d') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $exportName . '"');
        header('Content-Length: ' . filesize($tempZip));
        header('Cache-Control: no-cache, must-revalidate');

        readfile($tempZip);
        unlink($tempZip);
        exit;
    }
}
