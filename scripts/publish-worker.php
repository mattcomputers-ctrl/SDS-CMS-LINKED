#!/usr/bin/env php
<?php
/**
 * Bulk Publish Worker — processes a batch of (finished-good, language) items.
 *
 * Usage:
 *   php scripts/publish-worker.php <batch-file> <progress-file> <user-id>
 *
 * The batch file is a JSON array of {id, product_code, language, version} objects.
 * The progress file is updated after each item is processed.
 * On completion, the progress file contains final counts.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

// Bootstrap app (DB, config, session — but no routing/dispatch)
new \SDS\Core\App();

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Services\SDSGenerator;
use SDS\Services\PDFService;
use SDS\Services\AuditService;

// ── Parse arguments ──────────────────────────────────────────────────
if ($argc < 4) {
    fwrite(STDERR, "Usage: php publish-worker.php <batch-file> <progress-file> <user-id>\n");
    exit(1);
}

$batchFile    = $argv[1];
$progressFile = $argv[2];
$userId       = (int) $argv[3];

if (!file_exists($batchFile)) {
    fwrite(STDERR, "Batch file not found: {$batchFile}\n");
    exit(1);
}

$workItems = json_decode(file_get_contents($batchFile), true);
if (!is_array($workItems) || empty($workItems)) {
    // Empty batch — write complete immediately
    writeWorkerProgress($progressFile, 0, 0, 0, 0, [], true);
    exit(0);
}

// ── Process batch ────────────────────────────────────────────────────
$generator  = new SDSGenerator();
$pdfService = new PDFService();
$db         = Database::getInstance();
$now        = date('Y-m-d H:i:s');
$today      = date('Y-m-d');

$total     = count($workItems);
$published = 0;
$failed    = 0;
$errors    = [];

// Cache computeBase() results — multiple languages for the same FG may
// land in the same worker batch, no need to recompute.
$baseDataCache = [];

writeWorkerProgress($progressFile, $total, 0, 0, 0, [], false);

foreach ($workItems as $i => $item) {
    $fgId    = (int) $item['id'];
    $code    = $item['product_code'];
    $lang    = $item['language'];
    $version = (int) $item['version'];

    // Alias support: items with alias_id get a modified section 1
    $aliasId          = isset($item['alias_id']) ? (int) $item['alias_id'] : null;
    $aliasCode        = $item['alias_code'] ?? null;
    $aliasDescription = $item['alias_description'] ?? null;

    $displayCode = $aliasCode ?? $code;

    try {
        // Compute language-independent data once per FG
        if (!isset($baseDataCache[$fgId])) {
            $baseDataCache[$fgId] = $generator->computeBase($fgId);
        }

        $sdsData = $generator->generateFromBase($baseDataCache[$fgId], $lang);

        // For alias items, replace product code/description in section 1
        if ($aliasId !== null && $aliasCode !== null) {
            $sdsData = SDSGenerator::createAliasVariant($sdsData, $aliasCode, $aliasDescription ?? '');
        }

        $pdfPath      = $pdfService->generate($sdsData);
        $relativePath = str_replace(App::basePath() . '/', '', $pdfPath);

        // Insert version record
        $versionData = [
            'finished_good_id' => $fgId,
            'language'         => $lang,
            'version'          => $version,
            'status'           => 'published',
            'effective_date'   => $today,
            'published_by'     => $userId,
            'published_at'     => $now,
            'snapshot_json'    => json_encode($sdsData, JSON_UNESCAPED_UNICODE),
            'pdf_path'         => $relativePath,
            'change_summary'   => 'Bulk publish',
            'created_by'       => $userId,
        ];

        if ($aliasId !== null) {
            $versionData['alias_id'] = $aliasId;
        }

        $versionId = $db->insert('sds_versions', $versionData);

        $traceData = array_merge(
            $sdsData['hazard_result']['trace'] ?? [],
            $sdsData['voc_result']['trace'] ?? []
        );
        $db->insert('sds_generation_trace', [
            'sds_version_id' => $versionId,
            'trace_json'     => json_encode($traceData, JSON_UNESCAPED_UNICODE),
        ]);

        $auditAction = $aliasId !== null ? 'bulk_publish_alias' : 'bulk_publish';
        $auditData = [
            'finished_good_id' => $fgId,
            'product_code'     => $displayCode,
            'language'         => $lang,
            'version'          => $version,
        ];
        if ($aliasId !== null) {
            $auditData['alias_id']   = $aliasId;
            $auditData['alias_code'] = $aliasCode;
        }

        AuditService::log('sds_version', (string) $fgId, $auditAction, $auditData);

        $published++;
    } catch (\Throwable $e) {
        $failed++;
        $errors[] = $displayCode . ' [' . $lang . ']: ' . $e->getMessage();
    }

    writeWorkerProgress($progressFile, $total, $i + 1, $published, $failed, $errors, false);
}

// Write final progress
writeWorkerProgress($progressFile, $total, $total, $published, $failed, $errors, true);

// Clean up batch file
@unlink($batchFile);

exit(0);

// ── Helper ───────────────────────────────────────────────────────────
function writeWorkerProgress(string $file, int $total, int $processed, int $published, int $failed, array $errors, bool $complete): void
{
    $data = [
        'total'     => $total,
        'processed' => $processed,
        'published' => $published,
        'failed'    => $failed,
        'errors'    => $errors,
        'complete'  => $complete,
    ];
    file_put_contents($file, json_encode($data), LOCK_EX);
}
