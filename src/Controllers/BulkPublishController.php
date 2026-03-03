<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\AuditService;

/**
 * BulkPublishController — Publish new SDS versions for all finished goods (admin only).
 *
 * Spawns parallel PHP CLI worker processes (one per CPU core) to generate
 * SDS data and PDFs across all supported languages with progress tracking.
 */
class BulkPublishController
{
    /** Directory for progress/batch files, relative to project root */
    private const PROGRESS_DIR = '/storage/exports';

    /** Number of parallel worker processes */
    private const WORKER_COUNT = 4;

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
     * POST /admin/bulk-publish/start — Begin bulk publish with parallel workers.
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

        // Create progress directory
        $basePath    = App::basePath();
        $progressDir = $basePath . self::PROGRESS_DIR;
        if (!is_dir($progressDir)) {
            mkdir($progressDir, 0755, true);
        }

        $token       = bin2hex(random_bytes(8));
        $totalItems  = count($finishedGoods);
        $workerCount = min(self::WORKER_COUNT, $totalItems);
        $userId      = current_user_id();

        // Write the master progress file (used by progress endpoint)
        $masterFile = $progressDir . '/publish_progress_' . $token . '.json';
        file_put_contents($masterFile, json_encode([
            'current' => 0, 'total' => $totalItems, 'percent' => 0,
            'message' => 'Starting bulk publish...', 'complete' => false,
            'error' => false, 'workers' => $workerCount,
        ]), LOCK_EX);

        // Split FGs into batches and write batch files
        $batches = array_chunk($finishedGoods, (int) ceil($totalItems / $workerCount));
        $workerProgressFiles = [];
        $batchFiles = [];

        for ($w = 0; $w < count($batches); $w++) {
            $batchFile    = $progressDir . '/publish_batch_' . $token . '_' . $w . '.json';
            $workerFile   = $progressDir . '/publish_worker_' . $token . '_' . $w . '.json';

            file_put_contents($batchFile, json_encode($batches[$w]));
            file_put_contents($workerFile, json_encode([
                'total' => count($batches[$w]), 'processed' => 0,
                'published' => 0, 'failed' => 0, 'errors' => [], 'complete' => false,
            ]), LOCK_EX);

            $batchFiles[] = $batchFile;
            $workerProgressFiles[] = $workerFile;
        }

        // Write manifest so progress endpoint knows the worker files
        $manifestFile = $progressDir . '/publish_manifest_' . $token . '.json';
        file_put_contents($manifestFile, json_encode([
            'total'          => $totalItems,
            'worker_files'   => $workerProgressFiles,
            'master_file'    => $masterFile,
        ]), LOCK_EX);

        // Flush JSON response to browser
        $this->jsonResponse([
            'token' => $token,
            'total' => $totalItems,
        ]);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Spawn parallel worker processes
        $workerScript = $basePath . '/scripts/publish-worker.php';
        $phpBin       = PHP_BINARY;
        $processes     = [];

        for ($w = 0; $w < count($batches); $w++) {
            $cmd = sprintf(
                '%s %s %s %s %s > /dev/null 2>&1 &',
                escapeshellarg($phpBin),
                escapeshellarg($workerScript),
                escapeshellarg($batchFiles[$w]),
                escapeshellarg($workerProgressFiles[$w]),
                escapeshellarg((string) $userId)
            );
            exec($cmd);
        }
    }

    /**
     * GET /admin/bulk-publish/progress/{token} — Aggregate worker progress.
     */
    public function progress(string $token): void
    {
        if (!is_admin()) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $token       = preg_replace('/[^a-f0-9]/', '', $token);
        $progressDir = App::basePath() . self::PROGRESS_DIR;
        $manifestFile = $progressDir . '/publish_manifest_' . $token . '.json';

        if (!file_exists($manifestFile)) {
            // Fall back to master file for initial poll before manifest is written
            $masterFile = $progressDir . '/publish_progress_' . $token . '.json';
            if (file_exists($masterFile)) {
                $data = json_decode(file_get_contents($masterFile), true);
                $this->jsonResponse($data ?: ['error' => 'Invalid progress data.']);
                return;
            }
            $this->jsonResponse(['error' => 'Progress not found.'], 404);
            return;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!$manifest) {
            $this->jsonResponse(['error' => 'Invalid manifest.']);
            return;
        }

        $totalItems    = (int) $manifest['total'];
        $totalProcessed = 0;
        $totalPublished = 0;
        $totalFailed    = 0;
        $allErrors      = [];
        $allComplete    = true;
        $workerFiles    = $manifest['worker_files'] ?? [];

        foreach ($workerFiles as $workerFile) {
            if (!file_exists($workerFile)) {
                $allComplete = false;
                continue;
            }
            $wData = json_decode(file_get_contents($workerFile), true);
            if (!$wData) {
                $allComplete = false;
                continue;
            }

            $totalProcessed += (int) ($wData['processed'] ?? 0);
            $totalPublished += (int) ($wData['published'] ?? 0);
            $totalFailed    += (int) ($wData['failed'] ?? 0);
            $allErrors       = array_merge($allErrors, $wData['errors'] ?? []);

            if (empty($wData['complete'])) {
                $allComplete = false;
            }
        }

        $percent = $totalItems > 0 ? round(($totalProcessed / $totalItems) * 100, 1) : 0;

        if ($allComplete) {
            $summary = $totalPublished . ' published';
            if ($totalFailed > 0) {
                $summary .= ', ' . $totalFailed . ' failed';
            }

            $response = [
                'current'   => $totalItems,
                'total'     => $totalItems,
                'percent'   => 100,
                'message'   => 'Bulk publish complete! ' . $summary . '.',
                'complete'  => true,
                'error'     => false,
                'published' => $totalPublished,
                'failed'    => $totalFailed,
                'errors'    => $allErrors,
            ];

            // Clean up all worker/manifest/master files
            foreach ($workerFiles as $wf) {
                @unlink($wf);
            }
            @unlink($manifestFile);
            @unlink($manifest['master_file'] ?? '');

            AuditService::log('sds_bulk_publish', '0', 'complete', [
                'published' => $totalPublished,
                'failed'    => $totalFailed,
            ]);

            $this->jsonResponse($response);
            return;
        }

        $this->jsonResponse([
            'current'  => $totalProcessed,
            'total'    => $totalItems,
            'percent'  => $percent,
            'message'  => 'Publishing... ' . $totalProcessed . '/' . $totalItems
                . ' (' . count($workerFiles) . ' workers)',
            'complete' => false,
            'error'    => false,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
