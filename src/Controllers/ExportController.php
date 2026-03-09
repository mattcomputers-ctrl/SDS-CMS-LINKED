<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\AuditService;

/**
 * ExportController — Bulk SDS export (admin only).
 *
 * Generates a ZIP file containing all published finished-good SDS PDFs
 * (most recent version only, all languages) with a progress tracking system.
 * Export files are auto-deleted after 2 hours to save disk space.
 */
class ExportController
{
    /** Directory for export files, relative to storage/ */
    private const EXPORT_DIR = '/storage/exports';

    /** Export files older than this are auto-deleted (seconds). */
    public const EXPORT_TTL_SECONDS = 7200; // 2 hours

    /**
     * GET /sds-book/export — Stream a ZIP of all finished-good SDS PDFs (legacy).
     */
    public function exportAllFgSds(): void
    {
        if (!can_read('bulk_export')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to export SDS files.';
            redirect('/sds-book');
        }

        $db = Database::getInstance();

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

        $basePath = App::basePath();

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

            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $v['product_code']);
            $zipName  = $safeCode . '-SDS' . $v['version'];

            if (strtolower($v['language']) !== 'en') {
                $zipName .= '_' . strtoupper($v['language']);
            }

            $zipName .= '.pdf';

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

        $exportName = 'SDS_Export_' . date('Y-m-d') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $exportName . '"');
        header('Content-Length: ' . filesize($tempZip));
        header('Cache-Control: no-cache, must-revalidate');

        readfile($tempZip);
        unlink($tempZip);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Admin Bulk SDS Export with progress tracking
     * ----------------------------------------------------------------*/

    /**
     * GET /admin/export — Show the export page with progress UI.
     */
    public function exportPage(): void
    {
        if (!can_read('bulk_export')) {
            http_response_code(403);
            include dirname(__DIR__) . '/Views/errors/403.php';
            exit;
        }

        $db = Database::getInstance();

        // Count available finished goods with published SDSs
        $stats = $db->fetch(
            "SELECT COUNT(DISTINCT fg.id) AS fg_count,
                    COUNT(DISTINCT sv.id) AS pdf_count
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             WHERE sv.status = 'published'
               AND sv.is_deleted = 0
               AND sv.pdf_path IS NOT NULL
               AND sv.pdf_path != ''"
        );

        // Check for existing export
        $exportDir = App::basePath() . self::EXPORT_DIR;
        $existingExport = null;
        if (is_dir($exportDir)) {
            foreach (glob($exportDir . '/SDS_Export_*.zip') as $file) {
                $age = time() - filemtime($file);
                if ($age < self::EXPORT_TTL_SECONDS) {
                    $existingExport = [
                        'filename' => basename($file),
                        'size'     => self::formatBytes((int) filesize($file)),
                        'created'  => date('m/d/Y h:i A', filemtime($file)),
                        'expires_in' => self::formatDuration(self::EXPORT_TTL_SECONDS - $age),
                    ];
                    break;
                }
            }
        }

        view('admin/export', [
            'pageTitle'      => 'Bulk SDS Export',
            'fgCount'        => (int) ($stats['fg_count'] ?? 0),
            'pdfCount'       => (int) ($stats['pdf_count'] ?? 0),
            'existingExport' => $existingExport,
        ]);
    }

    /**
     * POST /admin/export/start — Begin export, returns JSON with progress token.
     */
    public function startExport(): void
    {
        if (!can_edit('bulk_export')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }
        CSRF::validateRequest();

        $db = Database::getInstance();
        $basePath = App::basePath();

        // Get the most recent published version per finished good per language
        $versions = $db->fetchAll(
            "SELECT sv.id, sv.version, sv.language, sv.pdf_path, fg.product_code
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             INNER JOIN (
                 SELECT finished_good_id, language, MAX(version) AS max_ver
                 FROM sds_versions
                 WHERE status = 'published' AND is_deleted = 0
                   AND pdf_path IS NOT NULL AND pdf_path != ''
                 GROUP BY finished_good_id, language
             ) latest ON sv.finished_good_id = latest.finished_good_id
                      AND sv.language = latest.language
                      AND sv.version = latest.max_ver
                      AND sv.status = 'published'
                      AND sv.is_deleted = 0
             ORDER BY fg.product_code ASC, sv.language ASC"
        );

        if (empty($versions)) {
            $this->jsonResponse(['error' => 'No published SDS PDFs found to export.']);
            return;
        }

        // Create export directory
        $exportDir = $basePath . self::EXPORT_DIR;
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        // Clean up old exports
        self::cleanupExpiredExports($exportDir);

        // Create progress file
        $token = bin2hex(random_bytes(8));
        $progressFile = $exportDir . '/progress_' . $token . '.json';
        $zipFile = $exportDir . '/SDS_Export_' . date('Y-m-d_His') . '.zip';

        $totalFiles = count($versions);
        $this->writeProgress($progressFile, 0, $totalFiles, 'Starting export...');

        // Flush JSON response immediately so the browser can start polling
        $this->jsonResponse([
            'token'      => $token,
            'totalFiles' => $totalFiles,
        ]);

        // Close the connection so the browser gets the response
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Now build the ZIP in the background
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->writeProgress($progressFile, 0, $totalFiles, 'Failed to create ZIP.', true);
            return;
        }

        $addedFiles = 0;
        $seen = [];

        foreach ($versions as $i => $v) {
            $pdfFullPath = $basePath . '/' . ltrim($v['pdf_path'], '/');

            if (!file_exists($pdfFullPath)) {
                $this->writeProgress($progressFile, $i + 1, $totalFiles,
                    'Skipped: ' . $v['product_code'] . ' (' . strtoupper($v['language']) . ') — file missing');
                continue;
            }

            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $v['product_code']);
            $zipName = $safeCode . '-SDS' . $v['version'];

            if (strtolower($v['language']) !== 'en') {
                $zipName .= '_' . strtoupper($v['language']);
            }

            $zipName .= '.pdf';

            if (isset($seen[$zipName])) {
                continue;
            }
            $seen[$zipName] = true;

            $zip->addFile($pdfFullPath, $zipName);
            $addedFiles++;

            $this->writeProgress($progressFile, $i + 1, $totalFiles,
                'Added: ' . $v['product_code'] . ' (' . strtoupper($v['language']) . ')');
        }

        $zip->close();

        if ($addedFiles === 0) {
            @unlink($zipFile);
            $this->writeProgress($progressFile, $totalFiles, $totalFiles,
                'No SDS PDF files found on disk.', true);
            return;
        }

        // Write final progress with download info
        $this->writeProgress($progressFile, $totalFiles, $totalFiles,
            'Export complete! ' . $addedFiles . ' PDFs packaged.',
            false, basename($zipFile), self::formatBytes((int) filesize($zipFile)));

        AuditService::log('sds_export', '0', 'create', [
            'files' => $addedFiles,
            'size'  => filesize($zipFile),
        ]);
    }

    /**
     * GET /admin/export/progress/{token} — Return current progress as JSON.
     */
    public function exportProgress(string $token): void
    {
        if (!can_read('bulk_export')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $token = preg_replace('/[^a-f0-9]/', '', $token);
        $progressFile = App::basePath() . self::EXPORT_DIR . '/progress_' . $token . '.json';

        if (!file_exists($progressFile)) {
            $this->jsonResponse(['error' => 'Export not found.'], 404);
            return;
        }

        $data = json_decode(file_get_contents($progressFile), true);
        $this->jsonResponse($data ?: ['error' => 'Invalid progress data.']);
    }

    /**
     * GET /admin/export/download/{filename} — Download the exported ZIP.
     */
    public function downloadExport(string $filename): void
    {
        if (!can_read('bulk_export')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to download exports.';
            redirect('/bulk-export');
            return;
        }

        // Sanitize filename to prevent path traversal
        $filename = basename($filename);
        if (!preg_match('/^SDS_Export_[\w-]+\.zip$/', $filename)) {
            $_SESSION['_flash']['error'] = 'Invalid export file.';
            redirect('/bulk-export');
            return;
        }

        $filePath = App::basePath() . self::EXPORT_DIR . '/' . $filename;

        if (!file_exists($filePath)) {
            $_SESSION['_flash']['error'] = 'Export file not found or has expired.';
            redirect('/bulk-export');
            return;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');

        readfile($filePath);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function writeProgress(string $file, int $current, int $total, string $message,
                                   bool $error = false, ?string $downloadFile = null, ?string $fileSize = null): void
    {
        $data = [
            'current'  => $current,
            'total'    => $total,
            'percent'  => $total > 0 ? round(($current / $total) * 100, 1) : 0,
            'message'  => $message,
            'complete' => $current >= $total && !$error,
            'error'    => $error,
        ];

        if ($downloadFile !== null) {
            $data['downloadFile'] = $downloadFile;
            $data['fileSize'] = $fileSize;
        }

        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Remove export ZIPs and progress files older than the TTL.
     * Called during export creation and by the housekeeping cron.
     */
    public static function cleanupExpiredExports(?string $exportDir = null): int
    {
        $exportDir = $exportDir ?? App::basePath() . self::EXPORT_DIR;
        if (!is_dir($exportDir)) {
            return 0;
        }

        $count = 0;
        $cutoff = time() - self::EXPORT_TTL_SECONDS;

        foreach (glob($exportDir . '/*') as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
                $count++;
            }
        }

        return $count;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private static function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $mins  = floor(($seconds % 3600) / 60);
        if ($hours > 0) {
            return $hours . 'h ' . $mins . 'm';
        }
        return $mins . ' min';
    }
}
