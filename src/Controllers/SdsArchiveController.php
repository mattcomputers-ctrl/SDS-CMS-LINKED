<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Core\CSRF;
use SDS\Services\AuditService;

class SdsArchiveController
{
    private const ARCHIVE_DIR = 'storage/exports';

    private function requireAdmin(): void
    {
        if (!can_manage_users()) {
            http_response_code(403);
            $viewFile = dirname(__DIR__) . '/Views/errors/403.php';
            if (file_exists($viewFile)) {
                include $viewFile;
            }
            exit;
        }
    }

    /**
     * GET /admin/sds-archive — show stats and controls.
     */
    public function index(): void
    {
        $this->requireAdmin();

        $db       = Database::getInstance();
        $basePath = App::basePath();

        $oldVersions    = $this->getOldVersions($db);
        $fileCount      = 0;
        $totalBytes     = 0;
        $missingCount   = 0;

        foreach ($oldVersions as $v) {
            $absPath = $basePath . '/' . $v['pdf_path'];
            if (file_exists($absPath)) {
                $fileCount++;
                $totalBytes += filesize($absPath);
            } else {
                $missingCount++;
            }
        }

        $archives = $this->findArchives($basePath);

        view('admin/sds-archive', [
            'pageTitle'    => 'SDS Archive',
            'dbRowCount'   => count($oldVersions),
            'fileCount'    => $fileCount,
            'totalBytes'   => $totalBytes,
            'missingCount' => $missingCount,
            'archives'     => $archives,
        ]);
    }

    /**
     * POST /admin/sds-archive/generate — create ZIP of old version PDFs.
     */
    public function generate(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $db       = Database::getInstance();
        $basePath = App::basePath();

        $oldVersions = $this->getOldVersions($db);
        if (empty($oldVersions)) {
            $_SESSION['_flash']['info'] = 'No old SDS versions to archive.';
            redirect('/admin/sds-archive');
            return;
        }

        $archiveDir = $basePath . '/' . self::ARCHIVE_DIR;
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        $zipName = 'sds-archive-' . date('Y-m-d_His') . '.zip';
        $zipPath = $archiveDir . '/' . $zipName;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $_SESSION['_flash']['error'] = 'Failed to create ZIP archive.';
            redirect('/admin/sds-archive');
            return;
        }

        $added   = 0;
        $skipped = 0;

        foreach ($oldVersions as $v) {
            $absPath = $basePath . '/' . $v['pdf_path'];
            if (!file_exists($absPath)) {
                $skipped++;
                continue;
            }
            $zip->addFile($absPath, basename($v['pdf_path']));
            $added++;
        }

        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);
            $_SESSION['_flash']['warning'] = "No PDF files found on disk ({$skipped} missing). Nothing to archive.";
            redirect('/admin/sds-archive');
            return;
        }

        $sizeMb = round(filesize($zipPath) / 1048576, 1);
        AuditService::log('sds_archive', null, 'generate', [
            'zip'     => $zipName,
            'added'   => $added,
            'skipped' => $skipped,
            'size_mb' => $sizeMb,
        ]);

        $_SESSION['_flash']['success'] = "Archive created: {$added} PDFs ({$sizeMb} MB). "
            . ($skipped > 0 ? "{$skipped} files were missing on disk and skipped." : '');
        redirect('/admin/sds-archive');
    }

    /**
     * GET /admin/sds-archive/download/{file} — stream a ZIP to the browser.
     */
    public function download(string $file): void
    {
        $this->requireAdmin();

        $file = basename($file);
        if (!preg_match('/^sds-archive-[\d_-]+\.zip$/', $file)) {
            $_SESSION['_flash']['error'] = 'Invalid archive filename.';
            redirect('/admin/sds-archive');
            return;
        }

        $path = App::basePath() . '/' . self::ARCHIVE_DIR . '/' . $file;
        if (!file_exists($path)) {
            $_SESSION['_flash']['error'] = 'Archive file not found.';
            redirect('/admin/sds-archive');
            return;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * POST /admin/sds-archive/purge — delete old version PDFs from disk
     * and clear their pdf_path in the database.
     */
    public function purge(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $db       = Database::getInstance();
        $basePath = App::basePath();

        $oldVersions = $this->getOldVersions($db);
        if (empty($oldVersions)) {
            $_SESSION['_flash']['info'] = 'No old SDS versions to purge.';
            redirect('/admin/sds-archive');
            return;
        }

        $deleted = 0;
        $freed   = 0;
        $ids     = [];

        foreach ($oldVersions as $v) {
            $absPath = $basePath . '/' . $v['pdf_path'];
            if (file_exists($absPath)) {
                $freed += filesize($absPath);
                @unlink($absPath);
                $deleted++;
            }
            $ids[] = (int) $v['id'];
        }

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->query(
                "UPDATE sds_versions SET pdf_path = NULL WHERE id IN ({$placeholders})",
                $ids
            );
        }

        $freedMb = round($freed / 1048576, 1);
        AuditService::log('sds_archive', null, 'purge', [
            'deleted_files' => $deleted,
            'cleared_rows'  => count($ids),
            'freed_mb'      => $freedMb,
        ]);

        $_SESSION['_flash']['success'] = "Purged {$deleted} old PDF files ({$freedMb} MB freed). "
            . count($ids) . ' database rows updated.';
        redirect('/admin/sds-archive');
    }

    /**
     * POST /admin/sds-archive/delete-zip/{file} — delete an archive ZIP.
     */
    public function deleteZip(string $file): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $file = basename($file);
        if (!preg_match('/^sds-archive-[\d_-]+\.zip$/', $file)) {
            $_SESSION['_flash']['error'] = 'Invalid archive filename.';
            redirect('/admin/sds-archive');
            return;
        }

        $path = App::basePath() . '/' . self::ARCHIVE_DIR . '/' . $file;
        if (file_exists($path)) {
            $sizeMb = round(filesize($path) / 1048576, 1);
            @unlink($path);
            AuditService::log('sds_archive', null, 'delete_zip', [
                'zip'     => $file,
                'size_mb' => $sizeMb,
            ]);
            $_SESSION['_flash']['success'] = "Archive {$file} deleted ({$sizeMb} MB freed).";
        } else {
            $_SESSION['_flash']['error'] = 'Archive file not found.';
        }

        redirect('/admin/sds-archive');
    }

    /**
     * Find all published sds_versions that are NOT the current (latest)
     * version for their product+alias+language group and have a PDF on record.
     *
     * "Current" = highest version number per (finished_good_id, raw_material_id,
     * alias_id, language) among published, non-deleted rows.
     *
     * Only returns rows from `public/generated-pdfs/` — never touches
     * supplier SDS uploads.
     */
    private function getOldVersions(Database $db): array
    {
        return $db->fetchAll(
            "SELECT sv.id, sv.pdf_path, sv.version, sv.finished_good_id,
                    sv.alias_id, sv.language
             FROM sds_versions sv
             WHERE sv.status = 'published'
               AND sv.is_deleted = 0
               AND sv.pdf_path IS NOT NULL
               AND sv.pdf_path != ''
               AND sv.pdf_path LIKE 'public/generated-pdfs/%'
               AND EXISTS (
                   SELECT 1 FROM sds_versions sv2
                   WHERE sv2.finished_good_id <=> sv.finished_good_id
                     AND sv2.raw_material_id  <=> sv.raw_material_id
                     AND sv2.alias_id         <=> sv.alias_id
                     AND sv2.language          = sv.language
                     AND sv2.status            = 'published'
                     AND sv2.is_deleted        = 0
                     AND sv2.version           > sv.version
               )"
        );
    }

    /**
     * Find existing archive ZIPs in the exports directory.
     */
    private function findArchives(string $basePath): array
    {
        $dir = $basePath . '/' . self::ARCHIVE_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/sds-archive-*.zip');
        if ($files === false) {
            return [];
        }

        $archives = [];
        foreach ($files as $f) {
            $archives[] = [
                'name'     => basename($f),
                'size'     => filesize($f),
                'modified' => filemtime($f),
            ];
        }

        usort($archives, fn($a, $b) => $b['modified'] <=> $a['modified']);
        return $archives;
    }
}
