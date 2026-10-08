#!/usr/bin/env php
<?php
/**
 * Bulk Publish Worker — processes a batch of (finished-good, language) items.
 *
 * Usage:
 *   php scripts/publish-worker.php <batch-file> <progress-file> <user-id>
 *
 * The batch file is a JSON array of {id, product_code, language, version} objects.
 * Items may carry type "resale" (rm_id instead of id) or type "private_label"
 * (pl_item_id, manufacturer_id, pl_code, pl_description, source_fg_version —
 * written to private_label_sds instead of sds_versions).
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
// Cron dispatches the workers with user id "0" because there's no
// HTTP user. published_by / created_by on sds_versions are FK'd to
// users.id so a literal 0 fails the constraint; translate to null
// here so system-initiated publishes just leave the user columns
// blank.
$userId = (int) $argv[3];
if ($userId <= 0) {
    $userId = null;
}

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
// $now is captured per-insert inside the loop below — freezing it at
// worker startup caused every row in a multi-hour run to carry the
// timestamp from second 1, which broke the bulk-publish eligibility
// freshness check (any RM updated during the run ended up looking
// newer than "its own" SDS).
//
// $today is UTC so it matches MySQL's CURRENT_TIMESTAMP-based
// updated_at columns. PHP's default timezone is user-local
// (America/New_York), but every MySQL-written timestamp on this DB is
// UTC. Using date() here would store effective_date 5 hours behind
// MySQL's today on late-evening runs and break freshness comparisons.
$today      = gmdate('Y-m-d');

$total     = count($workItems);
$published = 0;
$failed    = 0;
$errors    = [];

// Cache computeBase() results — multiple languages for the same FG may
// land in the same worker batch, no need to recompute. Separate cache
// keys for FG vs resale so a resale RM id can't collide with an FG id.
$baseDataCache       = [];
$resaleBaseDataCache = [];
// Manufacturer rows for private label items, keyed by manufacturer_id.
$mfgCache            = [];

writeWorkerProgress($progressFile, $total, 0, 0, 0, [], false);

// Stop-flag path: derived from the progress file name so every worker
// polls the same marker regardless of which token/batch it is processing.
//   .../publish_worker_{token}_{idx}.json  →  .../publish_stop_{token}.flag
$stopFlagFile = null;
if (preg_match('/publish_worker_([a-f0-9]+)_\d+\.json$/', $progressFile, $m)) {
    $stopFlagFile = dirname($progressFile) . '/publish_stop_' . $m[1] . '.flag';
}

foreach ($workItems as $i => $item) {
    // Poll the stop-flag file at the top of every work-item iteration.
    // When the controller's stop() action creates this file, every worker
    // observes it and exits cleanly without writing further PDFs — the
    // already-written ones stay (they're complete) and the progress file
    // is finalised so the UI can detect the stop and stop polling.
    if ($stopFlagFile !== null && file_exists($stopFlagFile)) {
        $errors[] = 'Worker stopped by user request after ' . ($published + $failed) . ' item(s).';
        writeWorkerProgress($progressFile, $total, $published + $failed, $published, $failed, $errors, true);
        exit(0);
    }

    $isResale = (($item['type'] ?? null) === 'resale');
    $isPrivateLabel = (($item['type'] ?? null) === 'private_label');
    $fgId     = $isResale ? null : (int) $item['id'];
    $rmId     = $isResale ? (int) $item['rm_id'] : null;
    $code     = $item['product_code'];
    $lang     = $item['language'];
    $version  = (int) $item['version'];

    // Alias support: items with alias_id get a modified section 1
    // alias_code is already the base code (pack extension stripped by BulkPublishController)
    $aliasId          = isset($item['alias_id']) ? (int) $item['alias_id'] : null;
    $aliasCode        = $item['alias_code'] ?? null;
    $aliasDescription = $item['alias_description'] ?? null;

    // Private label items carry their resolved code so error strings and
    // audit rows show the code actually printed, not the base FG code.
    $displayCode = $item['pl_code'] ?? $aliasCode ?? $code;

    try {
        // A private label item BulkPublishController::buildWorkItems already
        // knows cannot be published (shared alias re-pointed by the CMS to a
        // different product) fails here, before any base data is computed,
        // so the reason lands in errors[] under the PL display code.
        if ($isPrivateLabel && !empty($item['pl_error'])) {
            throw new \RuntimeException((string) $item['pl_error']);
        }

        // Compute language-independent data once per source — formula
        // path caches by fg_id, resale path caches by rm_id, so the two
        // can't collide.
        if ($isResale) {
            if (!isset($resaleBaseDataCache[$rmId])) {
                $resaleBaseDataCache[$rmId] = $generator->computeBaseForResaleRawMaterial($rmId);
            }
            $sdsData = $generator->generateFromBase($resaleBaseDataCache[$rmId], $lang);
        } else {
            if (!isset($baseDataCache[$fgId])) {
                $baseDataCache[$fgId] = $generator->computeBase($fgId);
            }
            $sdsData = $generator->generateFromBase($baseDataCache[$fgId], $lang);
        }

        // For alias items, replace product code/description in section 1
        if ($aliasId !== null && $aliasCode !== null) {
            $sdsData = SDSGenerator::createAliasVariant($sdsData, $aliasCode, $aliasDescription ?? '');
        }

        // Private label items: brand with the identity already resolved by
        // BulkPublishController::buildWorkItems (code, description) and the
        // manufacturer's company info / logo. One code path for all three
        // identity sources (custom / shared alias / base).
        if ($isPrivateLabel) {
            $mfgId = (int) $item['manufacturer_id'];
            if (!array_key_exists($mfgId, $mfgCache)) {
                $mfgCache[$mfgId] = \SDS\Models\Manufacturer::findById($mfgId);
            }
            $mfgRow = $mfgCache[$mfgId];
            if ($mfgRow === null) {
                throw new \RuntimeException('Manufacturer not found for private label item');
            }
            $sdsData = SDSGenerator::createPrivateLabelVariant(
                $sdsData,
                (string) $item['pl_code'],
                (string) ($item['pl_description'] ?? ''),
                \SDS\Models\Manufacturer::toCompanyInfo($mfgRow)
            );
        }

        // The version was assigned per work item by BulkPublishController;
        // stamp it on meta (after the alias / private label branches so the
        // PL filename_tag is already present) so PDFService::generate() names
        // the file {code}[_PL_{Mfg}]_v{n}_{lang}.pdf.
        $sdsData['meta']['sds_version'] = $version;

        $pdfPath      = $pdfService->generate($sdsData);
        $relativePath = str_replace(App::basePath() . '/', '', $pdfPath);

        // published_at reflects when THIS PDF was actually written,
        // not when the worker started. In long-running bulk publishes
        // workers can run for hours; stamping every row with the
        // worker-start time makes freshness comparisons incorrect.
        //
        // gmdate (UTC) matches MySQL's CURRENT_TIMESTAMP-written
        // columns like raw_materials.updated_at. date() would drift
        // by the app-local timezone offset (e.g. 5h on UTC-5), making
        // every freshly-published SDS look 5 hours older than the RMs
        // it was derived from and leaving the FG permanently
        // "eligible for publish" even right after it's republished.
        $now = gmdate('Y-m-d H:i:s');

        if ($isPrivateLabel) {
            // Private label rows live in private_label_sds — no sds_versions
            // row and no generation trace (its FK is to sds_versions). The
            // printed identity and the base FG version are frozen on the row.
            //
            // private_label_sds is stamped app-local, not UTC: there is no
            // raw_materials.updated_at freshness comparison on this table,
            // PrivateLabelPublisher::publishOne (R13) and every legacy PL row
            // use date(), and created_at is written by MySQL under the session
            // time_zone App sets from date('P'), so published_at and created_at
            // on one row must agree. The gmdate() $today/$now above stay for
            // the sds_versions branch only.
            $plToday = date('Y-m-d');
            $plNow   = date('Y-m-d H:i:s');

            $db->insert('private_label_sds', [
                'item_id'             => (int) $item['pl_item_id'],
                'finished_good_id'    => $fgId,
                'manufacturer_id'     => (int) $item['manufacturer_id'],
                'alias_id'            => $aliasId,
                'language'            => $lang,
                'product_code'        => (string) $item['pl_code'],
                'product_description' => (string) ($item['pl_description'] ?? ''),
                'version'             => $version,
                'source_fg_version'   => (int) $item['source_fg_version'],
                'status'              => 'published',
                'effective_date'      => $plToday,
                'published_by'        => $userId,
                'published_at'        => $plNow,
                'snapshot_json'       => json_encode($sdsData, JSON_UNESCAPED_UNICODE),
                'pdf_path'            => $relativePath,
                'change_summary'      => 'Bulk publish (private label)',
                'created_by'          => $userId,
            ]);

            AuditService::log('private_label_sds', (string) $item['pl_item_id'], 'bulk_publish_private_label', [
                'finished_good_id'  => $fgId,
                'manufacturer_id'   => (int) $item['manufacturer_id'],
                'alias_id'          => $aliasId,
                'product_code'      => $displayCode,
                'source'            => $item['pl_source'] ?? null,
                'language'          => $lang,
                'version'           => $version,
                'source_fg_version' => (int) $item['source_fg_version'],
            ]);
        } else {
            // Insert version record. For resale items finished_good_id is
            // NULL and raw_material_id carries the source; for FG items
            // it's the other way round.
            $versionData = [
                'finished_good_id' => $fgId,
                'raw_material_id'  => $rmId,
                'language'         => $lang,
                'version'          => $version,
                'status'           => 'published',
                'effective_date'   => $today,
                'published_by'     => $userId,
                'published_at'     => $now,
                'snapshot_json'    => json_encode($sdsData, JSON_UNESCAPED_UNICODE),
                'pdf_path'         => $relativePath,
                'change_summary'   => $isResale ? 'Bulk publish (resale)' : 'Bulk publish',
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

            if ($isResale) {
                $auditAction = $aliasId !== null ? 'bulk_publish_resale_alias' : 'bulk_publish_resale';
            } else {
                $auditAction = $aliasId !== null ? 'bulk_publish_alias' : 'bulk_publish';
            }
            $auditData = [
                'finished_good_id' => $fgId,
                'raw_material_id'  => $rmId,
                'product_code'     => $displayCode,
                'language'         => $lang,
                'version'          => $version,
            ];
            if ($aliasId !== null) {
                $auditData['alias_id']   = $aliasId;
                $auditData['alias_code'] = $aliasCode;
            }

            $auditKey = $isResale ? (string) $rmId : (string) $fgId;
            AuditService::log('sds_version', $auditKey, $auditAction, $auditData);
        }

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
