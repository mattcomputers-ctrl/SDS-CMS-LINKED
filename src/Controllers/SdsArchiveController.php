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

        // Tens of thousands of PDFs: don't let max_execution_time or a
        // closed tab stop the ZIP half-written.
        set_time_limit(0);
        ignore_user_abort(true);

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
        $names   = [];

        foreach ($oldVersions as $v) {
            $absPath = $basePath . '/' . $v['pdf_path'];
            if (!file_exists($absPath)) {
                $skipped++;
                continue;
            }
            $name = self::archiveEntryName((string) $v['pdf_path'], (int) $v['version'], (string) $v['language']);
            if (isset($names[$name])) {
                $name = pathinfo($name, PATHINFO_FILENAME) . '_' . (int) $v['id'] . '.pdf';   // never two entries with one name
            }
            $names[$name] = true;
            $zip->addFile($absPath, $name);
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
        AuditService::log('sds_archive', $zipName, 'generate', [
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

        // Same as generate(): a purge stopped part-way leaves rows pointing
        // at deleted files.
        set_time_limit(0);
        ignore_user_abort(true);

        $db       = Database::getInstance();
        $basePath = App::basePath();

        $oldVersions = $this->getOldVersions($db);
        if (empty($oldVersions)) {
            $_SESSION['_flash']['info'] = 'No old SDS versions to purge.';
            redirect('/admin/sds-archive');
            return;
        }

        $freed = 0;
        $ids   = [];
        $files = [];

        foreach ($oldVersions as $v) {
            $absPath = $basePath . '/' . $v['pdf_path'];
            if (file_exists($absPath)) {
                $freed += filesize($absPath);
                $files[] = $absPath;
            }
            $ids[] = (int) $v['id'];
        }

        $freedMb = round($freed / 1048576, 1);

        // Audit before touching anything, so the record survives a purge
        // that dies part-way through the unlinks or the UPDATE.
        AuditService::log('sds_archive', 'purge', 'purge', [
            'sds_version_ids' => $ids,
            'files_on_disk'   => count($files),
            'missing_files'   => count($ids) - count($files),
            'size_mb'         => $freedMb,
        ]);

        $deleted = 0;
        foreach ($files as $absPath) {
            @unlink($absPath);
            $deleted++;
        }

        // Chunked: native prepares cap a statement at 65,535 placeholders,
        // and the files are already gone by now.
        foreach (array_chunk($ids, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $db->query(
                "UPDATE sds_versions SET pdf_path = NULL WHERE id IN ({$placeholders})",
                $chunk
            );
        }

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
            AuditService::log('sds_archive', $file, 'delete_zip', [
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
     * DB-free (2026-10-22): the ZIP entry name of an archived PDF — its
     * stored name, with the language named as new files are
     * (sds_language_suffix(): _ESP etc., nothing for English):
     *   "_v{n}_es[_2].pdf" (stored 2026-10-08 .. 10-22)  → "_v{n}_ESP[_2].pdf"
     *   "_SDS_{lang}_{Ymd_His}[_{hex}][_2].pdf" (before)  → "_v{n}[_ESP].pdf"
     */
    public static function archiveEntryName(string $pdfPath, int $version, string $lang): string
    {
        $name = basename($pdfPath);
        $lang = strtolower(trim($lang));
        if (!preg_match('/^[a-z]+$/', $lang)) {
            return $name;
        }
        if (preg_match('/^(.+)_SDS_' . $lang . '_\d{8}_\d{6}(?:_[0-9a-f]{8})?(?:_\d+)?\.pdf$/i', $name, $m)) {
            return $m[1] . '_v' . $version . sds_language_suffix($lang) . '.pdf';
        }
        if (sds_language_suffix($lang) === '') {
            return $name;
        }
        return (string) preg_replace(
            '/(_v' . $version . ')_' . $lang . '((?:_\d+)?\.pdf)$/',
            '${1}' . sds_language_suffix($lang) . '${2}',
            $name
        );
    }

    /**
     * Find all published sds_versions that are NOT the current (latest)
     * version for their product+alias+language group and have a PDF on record.
     *
     * "Current" = highest version number per (finished_good_id, raw_material_id,
     * alias_id, language) among published, non-deleted rows. PARTITION BY
     * groups NULLs together, the same as <=>. One window pass instead of a
     * correlated EXISTS per row: sds_versions grows by a full catalog on
     * every Publish All.
     *
     * Only returns PDFs inside the generated-PDF directory — never touches
     * supplier SDS uploads. pdf_path is stored relative to the base path as
     * the publisher saw it, so with the installer's config
     * (`__DIR__ . '/../public/generated-pdfs'`) it reads
     * `config/../public/generated-pdfs/...`, not `public/generated-pdfs/...`.
     */
    private function getOldVersions(Database $db): array
    {
        $rows = $db->fetchAll(
            "SELECT v.id, v.pdf_path, v.version, v.finished_good_id,
                    v.alias_id, v.language
             FROM (
                 SELECT sv.id, sv.pdf_path, sv.version, sv.finished_good_id,
                        sv.alias_id, sv.language,
                        MAX(sv.version) OVER (
                            PARTITION BY sv.finished_good_id, sv.raw_material_id,
                                         sv.alias_id, sv.language
                        ) AS latest_version
                 FROM sds_versions sv
                 WHERE sv.status = 'published'
                   AND sv.is_deleted = 0
             ) v
             WHERE v.version < v.latest_version
               AND v.pdf_path IS NOT NULL
               AND v.pdf_path LIKE '%generated-pdfs/%'"
        );

        $basePath     = App::basePath();
        $generatedDir = (string) App::config('paths.generated_pdfs', $basePath . '/public/generated-pdfs');

        return array_values(array_filter(
            $rows,
            static fn(array $r): bool => self::isInDirectory($basePath . '/' . $r['pdf_path'], $generatedDir)
        ));
    }

    /**
     * True when $path lies inside $dir once `.` / `..` segments are
     * resolved. Lexical only, so it also works for files already gone.
     */
    private static function isInDirectory(string $path, string $dir): bool
    {
        return str_starts_with(self::normalisePath($path), rtrim(self::normalisePath($dir), '/') . '/');
    }

    private static function normalisePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
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
