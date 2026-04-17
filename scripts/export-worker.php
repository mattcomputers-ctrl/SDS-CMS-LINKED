#!/usr/bin/env php
<?php
/**
 * Bulk Export Worker — builds ZIP(s) for a single language.
 *
 * Splits into numbered parts if the language has more files than
 * MAX_FILES_PER_ZIP (keeps individual ZIPs below the ~14k open-file-handle
 * limit observed on Linux at ZipArchive::close()).
 *
 * Usage:
 *   php scripts/export-worker.php <token> <language> <progress-file> <timestamp>
 *
 * Writes progress to <progress-file> as JSON:
 *   {
 *     language: "en",
 *     progress: 1234,         // PDFs added so far
 *     total:    5400,         // total expected for this language
 *     parts:    [ { filename, size } ],   // completed ZIPs
 *     current_part: 2,        // which part is currently being built
 *     message:  "Adding ...",
 *     done:     false,
 *     error:    false
 *   }
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

new \SDS\Core\App();

use SDS\Core\Database;

const MAX_FILES_PER_ZIP   = 10000;
const EXPORT_QUERY_PAGE   = 500;

if ($argc < 5) {
    fwrite(STDERR, "Usage: php export-worker.php <token> <language> <progress-file> <timestamp>\n");
    exit(1);
}

$token        = preg_replace('/[^a-f0-9]/', '', $argv[1]);
$language     = preg_replace('/[^a-z]/', '', strtolower($argv[2]));
$progressFile = $argv[3];
$timestamp    = preg_replace('/[^0-9_]/', '', $argv[4]);

if ($token === '' || $language === '' || $timestamp === '') {
    fwrite(STDERR, "Invalid args.\n");
    exit(1);
}

$exportDir = $basePath . '/storage/exports';
$db        = Database::getInstance();

$writeProgress = function (array $state) use ($progressFile) {
    file_put_contents($progressFile, json_encode($state), LOCK_EX);
};

// Count total PDFs for this language
$countRow = $db->fetch(
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
    [$language]
);
$total = (int) ($countRow['cnt'] ?? 0);

$state = [
    'language'     => $language,
    'progress'     => 0,
    'total'        => $total,
    'parts'        => [],
    'current_part' => 1,
    'message'      => 'Starting...',
    'done'         => false,
    'error'        => false,
];
$writeProgress($state);

if ($total === 0) {
    $state['message'] = 'No PDFs for ' . strtoupper($language);
    $state['done']    = true;
    $writeProgress($state);
    exit(0);
}

// Open a new ZIP, return the archive handle + its target path
$multiPart   = $total > MAX_FILES_PER_ZIP;
$openNewZip  = function (int $partNum) use ($exportDir, $language, $timestamp, $multiPart): array {
    $suffix = $multiPart ? sprintf('_part_%02d', $partNum) : '';
    $path   = $exportDir . '/SDS_Export_' . strtoupper($language) . $suffix . '_' . $timestamp . '.zip';
    $zip    = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Failed to create ZIP: ' . $path);
    }
    return ['zip' => $zip, 'path' => $path];
};

$finalizeZip = function (\ZipArchive $zip, string $path, array &$state) use ($writeProgress) {
    $zip->close();
    if (file_exists($path) && filesize($path) > 0) {
        $state['parts'][] = [
            'filename' => basename($path),
            'size'     => (int) filesize($path),
        ];
    } else {
        @unlink($path);
    }
    $writeProgress($state);
};

try {
    $current = $openNewZip(1);
    $zip     = $current['zip'];
    $zipPath = $current['path'];
    $inZip   = 0;
    $partNum = 1;

    $lastId = 0;
    $seen   = [];
    $added  = 0;

    while (true) {
        $versions = $db->fetchAll(
            "SELECT sv.id, sv.version, sv.language, sv.pdf_path, fg.product_code
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
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
                      AND sv.is_deleted = 0
             WHERE sv.language = ? AND sv.id > ?
             ORDER BY sv.id ASC
             LIMIT " . EXPORT_QUERY_PAGE,
            [$language, $language, $lastId]
        );

        if (empty($versions)) {
            break;
        }

        foreach ($versions as $v) {
            $lastId = (int) $v['id'];
            $pdfFullPath = $basePath . '/' . ltrim($v['pdf_path'], '/');

            if (!file_exists($pdfFullPath)) {
                $state['progress']++;
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
            $inZip++;
            $added++;
            $state['progress']++;

            // Rotate when the current part hits the cap
            if ($inZip >= MAX_FILES_PER_ZIP) {
                $state['message'] = 'Finalizing part ' . $partNum . '...';
                $writeProgress($state);

                $finalizeZip($zip, $zipPath, $state);

                $partNum++;
                $state['current_part'] = $partNum;
                $next    = $openNewZip($partNum);
                $zip     = $next['zip'];
                $zipPath = $next['path'];
                $inZip   = 0;
                $seen    = [];  // part boundary — filenames reset OK because no duplicates possible within the full list
            }
        }

        $state['message'] = 'Added ' . $state['progress'] . ' / ' . $state['total'];
        $writeProgress($state);
    }

    // Finalize the last (possibly partial) ZIP
    $state['message'] = 'Finalizing...';
    $writeProgress($state);
    $finalizeZip($zip, $zipPath, $state);

    $state['message'] = 'Complete. ' . $added . ' PDFs across ' . count($state['parts']) . ' part(s).';
    $state['done']    = true;
    $writeProgress($state);

} catch (\Throwable $e) {
    $state['error']   = true;
    $state['done']    = true;
    $state['message'] = 'Failed: ' . $e->getMessage();
    $writeProgress($state);
    exit(1);
}
