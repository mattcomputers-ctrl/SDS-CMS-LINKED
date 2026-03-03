<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\SDSGenerator;
use SDS\Services\PDFService;
use SDS\Services\AuditService;

/**
 * BulkPublishController — Publish new SDS versions for all finished goods (admin only).
 *
 * Generates SDS data and PDFs for every active finished good that has a formula,
 * across all supported languages, with progress tracking.
 */
class BulkPublishController
{
    /** Directory for progress files, relative to project root */
    private const PROGRESS_DIR = '/storage/exports';

    /**
     * GET /admin/bulk-publish — Show the bulk publish page.
     */
    public function page(): void
    {
        if (!is_admin()) {
            http_response_code(403);
            include dirname(__DIR__) . '/Views/errors/403.php';
            exit;
        }

        $db = Database::getInstance();

        // Count active finished goods that have a current formula
        $stats = $db->fetch(
            "SELECT COUNT(DISTINCT fg.id) AS fg_count
             FROM finished_goods fg
             INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
             WHERE fg.is_active = 1"
        );

        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);

        view('admin/bulk-publish', [
            'pageTitle'  => 'Bulk SDS Publish',
            'fgCount'    => (int) ($stats['fg_count'] ?? 0),
            'langCount'  => count($languages),
            'languages'  => $languages,
        ]);
    }

    /**
     * POST /admin/bulk-publish/start — Begin bulk publish, returns JSON with progress token.
     */
    public function start(): void
    {
        if (!is_admin()) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }
        CSRF::validateRequest();

        $db = Database::getInstance();

        // Get all active finished goods that have a current formula
        $finishedGoods = $db->fetchAll(
            "SELECT DISTINCT fg.id, fg.product_code
             FROM finished_goods fg
             INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
             WHERE fg.is_active = 1
             ORDER BY fg.product_code ASC"
        );

        if (empty($finishedGoods)) {
            $this->jsonResponse(['error' => 'No active finished goods with formulas found.']);
            return;
        }

        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);

        // Create progress directory
        $progressDir = App::basePath() . self::PROGRESS_DIR;
        if (!is_dir($progressDir)) {
            mkdir($progressDir, 0755, true);
        }

        // Create progress file
        $token = bin2hex(random_bytes(8));
        $progressFile = $progressDir . '/publish_progress_' . $token . '.json';

        $totalItems = count($finishedGoods);
        $this->writeProgress($progressFile, 0, $totalItems, 'Starting bulk publish...');

        // Flush JSON response immediately so the browser can start polling
        $this->jsonResponse([
            'token' => $token,
            'total' => $totalItems,
        ]);

        // Close the connection so the browser gets the response
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Now process in the background
        $generator  = new SDSGenerator();
        $pdfService = new PDFService();
        $now        = date('Y-m-d H:i:s');
        $today      = date('Y-m-d');
        $userId     = current_user_id();

        $published = 0;
        $skipped   = 0;
        $failed    = 0;
        $errors    = [];

        foreach ($finishedGoods as $i => $fg) {
            $fgId   = (int) $fg['id'];
            $code   = $fg['product_code'];

            try {
                // Generate SDS data and PDFs for all languages
                $generated = [];
                foreach ($languages as $lang) {
                    $sdsData      = $generator->generate($fgId, $lang);
                    $pdfPath      = $pdfService->generate($sdsData);
                    $relativePath = str_replace(App::basePath() . '/', '', $pdfPath);

                    $generated[] = [
                        'language'     => $lang,
                        'sdsData'      => $sdsData,
                        'relativePath' => $relativePath,
                    ];
                }

                // Get next version number
                $lastVersion = $db->fetch(
                    "SELECT MAX(version) AS max_ver FROM sds_versions WHERE finished_good_id = ?",
                    [$fgId]
                );
                $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

                // Insert version records
                foreach ($generated as $item) {
                    $versionId = $db->insert('sds_versions', [
                        'finished_good_id' => $fgId,
                        'language'         => $item['language'],
                        'version'          => $nextVersion,
                        'status'           => 'published',
                        'effective_date'   => $today,
                        'published_by'     => $userId,
                        'published_at'     => $now,
                        'snapshot_json'    => json_encode($item['sdsData'], JSON_UNESCAPED_UNICODE),
                        'pdf_path'         => $item['relativePath'],
                        'change_summary'   => 'Bulk publish',
                        'created_by'       => $userId,
                    ]);

                    // Store generation trace
                    $traceData = array_merge(
                        $item['sdsData']['hazard_result']['trace'] ?? [],
                        $item['sdsData']['voc_result']['trace'] ?? []
                    );
                    $db->insert('sds_generation_trace', [
                        'sds_version_id' => $versionId,
                        'trace_json'     => json_encode($traceData, JSON_UNESCAPED_UNICODE),
                    ]);
                }

                AuditService::log('sds_version', (string) $fgId, 'bulk_publish', [
                    'finished_good_id' => $fgId,
                    'product_code'     => $code,
                    'version'          => $nextVersion,
                    'languages'        => count($languages),
                ]);

                $published++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $code . ': ' . $e->getMessage();
            }

            $this->writeProgress($progressFile, $i + 1, $totalItems,
                'Published: ' . $code . ' (' . ($i + 1) . '/' . $totalItems . ')');
        }

        // Write final progress
        $summary = $published . ' published';
        if ($failed > 0) {
            $summary .= ', ' . $failed . ' failed';
        }

        $finalData = [
            'current'   => $totalItems,
            'total'     => $totalItems,
            'percent'   => 100,
            'message'   => 'Bulk publish complete! ' . $summary . '.',
            'complete'  => true,
            'error'     => false,
            'published' => $published,
            'failed'    => $failed,
            'errors'    => $errors,
        ];
        file_put_contents($progressFile, json_encode($finalData), LOCK_EX);

        AuditService::log('sds_bulk_publish', '0', 'complete', [
            'published' => $published,
            'failed'    => $failed,
        ]);
    }

    /**
     * GET /admin/bulk-publish/progress/{token} — Return current progress as JSON.
     */
    public function progress(string $token): void
    {
        if (!is_admin()) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $token = preg_replace('/[^a-f0-9]/', '', $token);
        $progressFile = App::basePath() . self::PROGRESS_DIR . '/publish_progress_' . $token . '.json';

        if (!file_exists($progressFile)) {
            $this->jsonResponse(['error' => 'Progress not found.'], 404);
            return;
        }

        $data = json_decode(file_get_contents($progressFile), true);

        // Clean up progress file if complete
        if (!empty($data['complete'])) {
            @unlink($progressFile);
        }

        $this->jsonResponse($data ?: ['error' => 'Invalid progress data.']);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function writeProgress(string $file, int $current, int $total, string $message): void
    {
        $data = [
            'current'  => $current,
            'total'    => $total,
            'percent'  => $total > 0 ? round(($current / $total) * 100, 1) : 0,
            'message'  => $message,
            'complete' => false,
            'error'    => false,
        ];
        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
