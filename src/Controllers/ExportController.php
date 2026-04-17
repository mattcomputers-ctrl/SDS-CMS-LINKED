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

    /** Page size for paginated export queries. */
    private const EXPORT_QUERY_PAGE_SIZE = 500;

    /**
     * GET /sds-book/export — Stream a ZIP of all finished-good SDS PDFs (legacy).
     *
     * Paginates the DB query to avoid loading all rows at once, then streams
     * the completed ZIP to the client as a single download.
     */
    public function exportAllFgSds(): void
    {
        if (!can_read('bulk_export')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to export SDS files.';
            redirect('/sds-book');
        }

        $db = Database::getInstance();
        $basePath = App::basePath();

        $tempZip = tempnam(sys_get_temp_dir(), 'sds_export_') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $_SESSION['_flash']['error'] = 'Failed to create ZIP archive.';
            redirect('/sds-book');
        }

        $addedFiles = 0;
        $seen = [];
        $lastId = 0;

        // Paginate through SDS versions using keyset pagination on sv.id
        while (true) {
            $versions = $db->fetchAll(
                "SELECT sv.id, sv.version, sv.language, sv.pdf_path,
                        fg.product_code
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
                 WHERE sv.id > ?
                 ORDER BY sv.id ASC
                 LIMIT " . self::EXPORT_QUERY_PAGE_SIZE,
                [$lastId]
            );

            if (empty($versions)) {
                break;
            }

            foreach ($versions as $v) {
                $lastId = (int) $v['id'];
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

        // Stream the ZIP in 8 KB chunks instead of loading it all into memory
        $fh = fopen($tempZip, 'rb');
        while (!feof($fh)) {
            echo fread($fh, 8192);
            flush();
        }
        fclose($fh);
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
                      AND sv.is_deleted = 0"
        );

        view('admin/export', [
            'pageTitle' => 'Bulk SDS Export',
            'fgCount'   => (int) ($stats['fg_count'] ?? 0),
            'pdfCount'  => (int) ($stats['pdf_count'] ?? 0),
        ]);
    }

    /** Max files per ZIP — keeps below ~14k open-handle limit at ZipArchive::close(). */
    public const MAX_FILES_PER_ZIP = 10000;

    /**
     * POST /bulk-export/start — spawn one worker per supported language.
     * Each worker writes its own ZIP(s) and per-language progress file.
     */
    public function startExport(): void
    {
        if (!can_edit('bulk_export')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }
        CSRF::validateRequest();

        $db       = Database::getInstance();
        $basePath = App::basePath();
        $exportDir = $basePath . self::EXPORT_DIR;
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
        self::cleanupExpiredExports($exportDir);

        // Count PDFs per language so the view can render initial totals
        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $perLangTotals = [];
        $grandTotal    = 0;
        foreach ($languages as $lang) {
            $row = $db->fetch(
                "SELECT COUNT(*) AS cnt
                 FROM sds_versions sv
                 INNER JOIN (
                     SELECT finished_good_id, language, MAX(version) AS max_ver
                     FROM sds_versions
                     WHERE status = 'published' AND is_deleted = 0
                       AND pdf_path IS NOT NULL AND pdf_path != ''
                       AND language = ?
                     GROUP BY finished_good_id, language
                 ) latest ON sv.finished_good_id = latest.finished_good_id
                          AND sv.language = latest.language
                          AND sv.version = latest.max_ver
                          AND sv.status = 'published'
                          AND sv.is_deleted = 0",
                [$lang]
            );
            $perLangTotals[$lang] = (int) ($row['cnt'] ?? 0);
            $grandTotal          += $perLangTotals[$lang];
        }

        if ($grandTotal === 0) {
            $this->jsonResponse(['error' => 'No published SDS PDFs found to export.']);
            return;
        }

        $token     = bin2hex(random_bytes(8));
        $timestamp = date('Y-m-d_His');

        // Pre-seed each language's progress file so the poller sees work immediately
        $progressFiles = [];
        foreach ($languages as $lang) {
            $pf = $exportDir . '/progress_' . $token . '_' . $lang . '.json';
            file_put_contents($pf, json_encode([
                'language'     => $lang,
                'progress'     => 0,
                'total'        => $perLangTotals[$lang],
                'parts'        => [],
                'current_part' => 1,
                'message'      => $perLangTotals[$lang] > 0 ? 'Queued...' : 'No PDFs for this language',
                'done'         => $perLangTotals[$lang] === 0,
                'error'        => false,
            ]), LOCK_EX);
            $progressFiles[$lang] = $pf;
        }

        // Master manifest so the progress endpoint knows which languages belong to this token
        file_put_contents(
            $exportDir . '/manifest_' . $token . '.json',
            json_encode([
                'token'      => $token,
                'timestamp'  => $timestamp,
                'languages'  => $languages,
                'totals'     => $perLangTotals,
                'grandTotal' => $grandTotal,
            ]),
            LOCK_EX
        );

        $this->jsonResponse([
            'token'      => $token,
            'totalFiles' => $grandTotal,
            'languages'  => $languages,
            'totals'     => $perLangTotals,
        ]);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Spawn one worker per language (skip languages with 0 files)
        $workerScript = $basePath . '/scripts/export-worker.php';
        $phpBin       = php_cli_binary();

        foreach ($languages as $lang) {
            if ($perLangTotals[$lang] === 0) {
                continue;
            }
            $logFile = $exportDir . '/worker_' . $token . '_' . $lang . '.log';
            $cmd = sprintf(
                '%s %s %s %s %s %s > %s 2>&1 &',
                escapeshellarg($phpBin),
                escapeshellarg($workerScript),
                escapeshellarg($token),
                escapeshellarg($lang),
                escapeshellarg($progressFiles[$lang]),
                escapeshellarg($timestamp),
                escapeshellarg($logFile)
            );
            exec($cmd);
        }

        AuditService::log('sds_export', '0', 'start', [
            'token'      => $token,
            'grandTotal' => $grandTotal,
        ]);
    }

    /**
     * GET /bulk-export/progress/{token} — Aggregate per-language progress.
     *
     * Response shape:
     *   {
     *     token, grandTotal, totalProcessed, percent, complete,
     *     languages: { en: { progress, total, parts: [...], done, error, message }, ... }
     *   }
     */
    public function exportProgress(string $token): void
    {
        if (!can_read('bulk_export')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $token = preg_replace('/[^a-f0-9]/', '', $token);
        $dir   = App::basePath() . self::EXPORT_DIR;

        // Prefer the new manifest format; fall back to legacy single-file progress for compatibility
        $manifestFile = $dir . '/manifest_' . $token . '.json';
        if (!file_exists($manifestFile)) {
            // Legacy single-file progress (used by old callers before the rewrite)
            $legacyFile = $dir . '/progress_' . $token . '.json';
            if (file_exists($legacyFile)) {
                $data = json_decode(file_get_contents($legacyFile), true);
                $this->jsonResponse($data ?: ['error' => 'Invalid progress data.']);
                return;
            }
            $this->jsonResponse(['error' => 'Export not found.'], 404);
            return;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            $this->jsonResponse(['error' => 'Invalid manifest.'], 500);
            return;
        }

        $languages = $manifest['languages'] ?? [];
        $grandTotal = (int) ($manifest['grandTotal'] ?? 0);
        $totalProcessed = 0;
        $allDone = true;
        $anyError = false;
        $langStates = [];

        foreach ($languages as $lang) {
            $pf = $dir . '/progress_' . $token . '_' . $lang . '.json';
            if (!file_exists($pf)) {
                $langStates[$lang] = [
                    'language' => $lang,
                    'progress' => 0,
                    'total'    => $manifest['totals'][$lang] ?? 0,
                    'parts'    => [],
                    'done'     => false,
                    'error'    => false,
                    'message'  => 'Starting...',
                ];
                $allDone = false;
                continue;
            }
            $data = json_decode(file_get_contents($pf), true) ?: [];
            $langStates[$lang] = $data;
            $totalProcessed   += (int) ($data['progress'] ?? 0);
            if (empty($data['done'])) {
                $allDone = false;
            }
            if (!empty($data['error'])) {
                $anyError = true;
            }
        }

        $percent = $grandTotal > 0 ? round(($totalProcessed / $grandTotal) * 100, 1) : 0;

        $this->jsonResponse([
            'token'          => $token,
            'grandTotal'     => $grandTotal,
            'totalProcessed' => $totalProcessed,
            'percent'        => $percent,
            'complete'       => $allDone,
            'error'          => $anyError,
            'languages'      => $langStates,
        ]);
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
