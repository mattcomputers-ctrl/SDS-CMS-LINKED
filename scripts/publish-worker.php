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
 * (pl_item_id, manufacturer_id, pl_code, source_fg_version, pl_error). A private
 * label item is ONE work item covering every language and is published through
 * PrivateLabelPublisher::publishItemFromBase() (audit #54).
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
// Audit #59 — one clock. published_at is UTC (PublishClock::nowUtc(), taken per
// item when its PDF is written), like every MySQL-written timestamp (App sets
// the session time_zone to UTC) and the UTC_TIMESTAMP() staleness bumps. The
// printed effective date is today in the admin time zone (PublishClock::
// todayLocal()), also per item, so an evening US run never prints tomorrow's
// date and base, alias and private-label copies agree.
$languages   = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
$plPublisher = new \SDS\Services\PrivateLabelPublisher();

$total     = count($workItems);
$published = 0;
$failed    = 0;
$errors    = [];

// Cache computeBase() results — multiple languages for the same FG may
// land in the same worker batch, no need to recompute. Separate cache
// keys for FG vs resale so a resale RM id can't collide with an FG id.
$baseDataCache       = [];
$resaleBaseDataCache = [];
// Publish-gate result per source ('fg{id}' / 'rm{id}'). Hazard data and the
// transport status are language-independent, so the gates run once per source.
$gateCache           = [];

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

    // Audit #54 — a private label item is published through the same code
    // path as every other private-label publish (PrivateLabelPublisher): the
    // version is read and re-checked inside a transaction, every language is
    // rendered first and all rows are inserted together or not at all. A
    // concurrent manual publish therefore cannot duplicate or split a version.
    if ($isPrivateLabel) {
        try {
            if (!empty($item['pl_error'])) {
                throw new \RuntimeException((string) $item['pl_error']);
            }
            $plItem = \SDS\Models\PrivateLabelItem::findById((int) $item['pl_item_id']);
            if ($plItem === null) {
                throw new \RuntimeException('Private label item #' . (int) $item['pl_item_id'] . ' not found');
            }
            // Retired or frozen since the work list was built: skip quietly.
            if ((int) ($plItem['is_active'] ?? 0) !== 1 || (int) ($plItem['auto_republish'] ?? 0) !== 1) {
                writeWorkerProgress($progressFile, $total, $i + 1, $published, $failed, $errors, false);
                continue;
            }
            if (!isset($baseDataCache[$fgId])) {
                $baseDataCache[$fgId] = $generator->computeBase($fgId);
            }
            $plLangData = [];
            foreach ($languages as $plLang) {
                $plLangData[$plLang] = $generator->generateFromBase($baseDataCache[$fgId], $plLang);
            }
            // Same gates as the FG items: Q4 trade-secret Prop 65, Q11/#28
            // missing data, #27 transport. Own cache key: the FG items' cached
            // chain does not include the trade-secret gate (checked separately).
            $gateKey = 'plfg' . $fgId;
            if (!array_key_exists($gateKey, $gateCache)) {
                $gateCache[$gateKey] = \SDS\Services\SDSReadinessService::tradeSecretProp65Error($plLangData[$languages[0]]['prop65_result'] ?? [])
                    ?? \SDS\Services\SDSReadinessService::missingHazardDataError($plLangData[$languages[0]], $db);
            }
            if ($gateCache[$gateKey] !== null) {
                throw new \RuntimeException($gateCache[$gateKey]);
            }
            // Finding #6: Section 14 overrides are stored per language → every language.
            foreach ($plLangData as $plLangSds) {
                $transportError = \SDS\Services\SDSReadinessService::transportNotDeterminedError($plLangSds);
                if ($transportError !== null) {
                    throw new \RuntimeException($transportError);
                }
            }
            $r = $plPublisher->publishItemFromBase(
                $plItem,
                $plLangData,
                (int) $item['source_fg_version'],
                $userId,
                'Bulk publish (private label)',
                'bulk_publish'
            );
            if (!$r['ok']) {
                throw new \RuntimeException((string) ($r['error'] ?? 'Private label publish failed'));
            }
            $published++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = $displayCode . ' [' . implode(',', $languages) . ']: ' . $e->getMessage();
        }
        writeWorkerProgress($progressFile, $total, $i + 1, $published, $failed, $errors, false);
        continue;
    }

    try {
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

        // Audit #13 / decision Q4: a trade-secret constituent on the Prop 65 list blocks publishing.
        $tsProp65Error = \SDS\Services\SDSReadinessService::tradeSecretProp65Error($sdsData['prop65_result'] ?? []);
        if ($tsProp65Error !== null) {
            throw new \RuntimeException($tsProp65Error);
        }

        // Publish gates, same rules and order as SDSController::publish (the
        // exception lands in errors[] below): Q11/#28 missing federal hazard data
        // at or above the threshold (language-independent: cached per source),
        // then #27 Section 14 transport across EVERY configured language
        // (finding #6: Section 14 overrides are stored per language). A source
        // that fails in any language fails all its items (base and alias, every
        // language), so no version is written for only some languages — the
        // all-language staleness check would otherwise treat it as current.
        // Cached per source; each worker computes the same result, so items
        // of one source split across workers agree.
        $gateKey = $isResale ? 'rm' . $rmId : 'fg' . $fgId;
        if (!array_key_exists($gateKey, $gateCache)) {
            $gateCache[$gateKey] = \SDS\Services\SDSReadinessService::missingHazardDataError($sdsData, $db);
        }
        if ($gateCache[$gateKey] !== null) {
            throw new \RuntimeException($gateCache[$gateKey]);
        }
        $txKey = 'tx' . $gateKey;
        if (!array_key_exists($txKey, $gateCache)) {
            $gateCache[$txKey] = null;
            $txBase = $isResale ? $resaleBaseDataCache[$rmId] : $baseDataCache[$fgId];
            foreach ($languages as $txLang) {
                $txSds = $txLang === $lang ? $sdsData : $generator->generateFromBase($txBase, $txLang);
                $txError = \SDS\Services\SDSReadinessService::transportNotDeterminedError($txSds);
                if ($txError !== null) {
                    $gateCache[$txKey] = $txError;
                    break;
                }
            }
            unset($txBase, $txSds);
        }
        if ($gateCache[$txKey] !== null) {
            throw new \RuntimeException($gateCache[$txKey]);
        }

        // For alias items, replace product code/description in section 1
        if ($aliasId !== null && $aliasCode !== null) {
            $sdsData = SDSGenerator::createAliasVariant($sdsData, $aliasCode, $aliasDescription ?? '');
        }

        // The version was assigned per work item by BulkPublishController;
        // stamp it on meta (after the alias branch) so PDFService::generate()
        // names the file {code}_v{n}[_{lang}].pdf. The same call writes the
        // Section 16 "Version" / "Effective Date" lines (and the footer) from
        // the version number and the effective date written to the row below.
        // Audit #59: today in the admin time zone, taken per item.
        $effectiveDate = \SDS\Services\PublishClock::todayLocal();   // matches the row written below
        $sdsData = SDSGenerator::stampPublishedVersion($sdsData, $version, $effectiveDate);

        $pdfPath      = $pdfService->generate($sdsData);
        $relativePath = str_replace(App::basePath() . '/', '', $pdfPath);

        // published_at = when THIS PDF was written (not when the worker
        // started), in UTC like every staleness timestamp (audit #59).
        $now = \SDS\Services\PublishClock::nowUtc();

        // Insert version record. For resale items finished_good_id is
        // NULL and raw_material_id carries the source; for FG items
        // it's the other way round.
        $versionData = [
            'finished_good_id' => $fgId,
            'raw_material_id'  => $rmId,
            'language'         => $lang,
            'version'          => $version,
            'status'           => 'published',
            'effective_date'   => $effectiveDate,
            'published_by'     => $userId,
            'published_at'     => $now,
            'snapshot_json'    => \SDS\Services\SDSGenerator::snapshotJson($sdsData),   // finding #70
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
