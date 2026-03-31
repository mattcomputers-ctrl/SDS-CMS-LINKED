<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\AuditService;
use SDS\Services\SDSGenerator;

/**
 * SDSUpdateController — "SDS Update Required" page.
 *
 * Detects finished goods whose published SDS may be stale because
 * a raw material or constituent was updated after the SDS was last
 * published.  Users can scan for changes, review the queue, and
 * trigger republishing of standard and private-label SDS documents.
 */
class SDSUpdateController
{
    /**
     * GET /sds-updates — Main page showing the update queue.
     */
    public function index(): void
    {
        if (!can_read('sds_updates')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/');
        }

        $db = Database::getInstance();

        // Pending queue items grouped by finished good
        $pending = $db->fetchAll(
            "SELECT q.*, fg.product_code, fg.description AS fg_description,
                    u.display_name AS queued_by_name
             FROM sds_update_queue q
             JOIN finished_goods fg ON fg.id = q.finished_good_id
             LEFT JOIN users u ON u.id = q.queued_by
             WHERE q.status = 'pending'
             ORDER BY q.queued_at DESC"
        );

        // Group by finished_good_id so we show one row per FG with all reasons
        $grouped = [];
        foreach ($pending as $row) {
            $fgId = (int) $row['finished_good_id'];
            if (!isset($grouped[$fgId])) {
                $grouped[$fgId] = [
                    'finished_good_id' => $fgId,
                    'product_code'     => $row['product_code'],
                    'fg_description'   => $row['fg_description'],
                    'reasons'          => [],
                    'queue_ids'        => [],
                    'earliest_queued'  => $row['queued_at'],
                    'queued_by_name'   => $row['queued_by_name'],
                ];
            }
            $grouped[$fgId]['reasons'][] = $row['reason'];
            $grouped[$fgId]['queue_ids'][] = (int) $row['id'];
            if ($row['queued_at'] < $grouped[$fgId]['earliest_queued']) {
                $grouped[$fgId]['earliest_queued'] = $row['queued_at'];
            }
        }

        // Enrich each group with alias count and latest SDS version info
        foreach ($grouped as &$group) {
            $fgId = $group['finished_good_id'];

            // Count unique aliases (by base customer code)
            $aliasRow = $db->fetch(
                "SELECT COUNT(DISTINCT SUBSTRING_INDEX(a.customer_code, '-', 1)) AS cnt
                 FROM aliases a WHERE a.internal_code_base = ?",
                [$group['product_code']]
            );
            $group['alias_count'] = (int) ($aliasRow['cnt'] ?? 0);

            // Latest published SDS version date (standard)
            $latestSds = $db->fetch(
                "SELECT MAX(published_at) AS last_published
                 FROM sds_versions
                 WHERE finished_good_id = ? AND alias_id IS NULL AND status = 'published' AND is_deleted = 0",
                [$fgId]
            );
            $group['last_sds_published'] = $latestSds['last_published'] ?? null;

            // Count private label SDSs that would also need updating
            $plCount = $db->fetch(
                "SELECT COUNT(DISTINCT CONCAT(manufacturer_id, '-', COALESCE(alias_id, 0))) AS cnt
                 FROM private_label_sds
                 WHERE finished_good_id = ?",
                [$fgId]
            );
            $group['private_label_count'] = (int) ($plCount['cnt'] ?? 0);
        }
        unset($group);

        // Count completed items (last 30 days)
        $recentCompleted = $db->fetch(
            "SELECT COUNT(*) AS cnt FROM sds_update_queue
             WHERE status = 'completed' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        view('sds-updates/index', [
            'pageTitle'         => 'SDS Update Required',
            'queue'             => array_values($grouped),
            'pendingCount'      => count($grouped),
            'totalPendingItems' => count($pending),
            'recentCompleted'   => (int) ($recentCompleted['cnt'] ?? 0),
        ]);
    }

    /**
     * POST /sds-updates/scan — Scan for stale SDS documents.
     *
     * Compares raw_materials.updated_at and raw_material_constituents
     * timestamps against the latest published SDS date for each
     * finished good.  Queues any FG whose source data is newer.
     */
    public function scan(): void
    {
        if (!can_edit('sds_updates')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds-updates');
        }

        CSRF::validateRequest();

        $db = Database::getInstance();
        $userId = current_user_id();
        $queued = 0;

        // Find all active finished goods with a current formula and a published SDS
        $finishedGoods = $db->fetchAll(
            "SELECT DISTINCT fg.id, fg.product_code,
                    svmax.last_published
             FROM finished_goods fg
             INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
             LEFT JOIN (
                 SELECT finished_good_id, MAX(published_at) AS last_published
                 FROM sds_versions
                 WHERE status = 'published' AND is_deleted = 0 AND alias_id IS NULL
                 GROUP BY finished_good_id
             ) svmax ON svmax.finished_good_id = fg.id
             WHERE fg.is_active = 1
             ORDER BY fg.product_code ASC"
        );

        foreach ($finishedGoods as $fg) {
            $fgId = (int) $fg['id'];
            $lastPublished = $fg['last_published'];

            // Skip FGs that have never been published (they don't need "updating")
            if ($lastPublished === null) {
                continue;
            }

            // Skip if this FG already has a pending queue item
            $existing = $db->fetch(
                "SELECT id FROM sds_update_queue WHERE finished_good_id = ? AND status = 'pending'",
                [$fgId]
            );
            if ($existing) {
                continue;
            }

            // Check if any raw material in the current formula was updated
            // after the last SDS publish date
            $staleRMs = $db->fetchAll(
                "SELECT DISTINCT rm.id, rm.internal_code, rm.updated_at
                 FROM formula_lines fl
                 JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1 AND f.finished_good_id = ?
                 JOIN raw_materials rm ON rm.id = fl.raw_material_id
                 WHERE rm.updated_at > ?",
                [$fgId, $lastPublished]
            );

            foreach ($staleRMs as $rm) {
                $db->insert('sds_update_queue', [
                    'finished_good_id' => $fgId,
                    'reason'           => 'Raw material ' . $rm['internal_code'] . ' updated on ' . $rm['updated_at'],
                    'source_type'      => 'raw_material',
                    'source_id'        => (int) $rm['id'],
                    'queued_by'        => $userId,
                ]);
                $queued++;
            }

            // Also check for constituent-level changes via the raw_material_constituents table
            // (constituents don't have updated_at, so we check the RM's updated_at which
            //  is bumped when constituents are saved via saveConstituents)
            // This is already covered by the RM check above since saveConstituents
            // triggers an RM updated_at change. But let's also check for formula
            // version changes (new formula created after last publish).
            if (empty($staleRMs)) {
                $formulaChange = $db->fetch(
                    "SELECT f.id, f.version, f.created_at
                     FROM formulas f
                     WHERE f.finished_good_id = ? AND f.is_current = 1 AND f.created_at > ?",
                    [$fgId, $lastPublished]
                );

                if ($formulaChange) {
                    $db->insert('sds_update_queue', [
                        'finished_good_id' => $fgId,
                        'reason'           => 'Formula updated to v' . $formulaChange['version'] . ' on ' . $formulaChange['created_at'],
                        'source_type'      => 'raw_material',
                        'source_id'        => null,
                        'queued_by'        => $userId,
                    ]);
                    $queued++;
                }
            }
        }

        AuditService::log('sds_update_queue', '0', 'scan', [
            'items_queued' => $queued,
        ]);

        if ($queued > 0) {
            $_SESSION['_flash']['success'] = "Scan complete: {$queued} finished good(s) queued for SDS update.";
        } else {
            $_SESSION['_flash']['success'] = 'Scan complete: all published SDS documents are up to date.';
        }

        redirect('/sds-updates');
    }

    /**
     * POST /sds-updates/republish — Republish SDS for selected finished goods.
     *
     * Regenerates standard SDS (all languages) + alias SDSs for each
     * selected finished good, then marks queue items as completed.
     */
    public function republish(): void
    {
        if (!can_edit('sds_updates')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds-updates');
        }

        CSRF::validateRequest();

        $fgIds = $_POST['fg_ids'] ?? [];
        if (!is_array($fgIds) || empty($fgIds)) {
            $_SESSION['_flash']['error'] = 'No products selected.';
            redirect('/sds-updates');
        }

        $db = Database::getInstance();
        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $userId = current_user_id();
        $publishedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($fgIds as $fgId) {
            $fgId = (int) $fgId;
            $fg = \SDS\Models\FinishedGood::findById($fgId);
            if ($fg === null) {
                continue;
            }

            try {
                $generator = new SDSGenerator();
                $baseData = $generator->computeBase($fgId);

                // Generate all languages
                $langData = [];
                foreach ($languages as $lang) {
                    $langData[$lang] = $generator->generateFromBase($baseData, $lang);
                }

                // Generate PDFs in parallel
                $pdfResults = $this->generatePdfsInParallel($langData);

                // Determine next version
                $lastVersion = $db->fetch(
                    "SELECT MAX(version) AS max_ver FROM sds_versions WHERE finished_good_id = ? AND alias_id IS NULL",
                    [$fgId]
                );
                $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

                $now = date('Y-m-d H:i:s');

                foreach ($languages as $lang) {
                    if (!($pdfResults[$lang]['ok'] ?? false)) {
                        throw new \RuntimeException(
                            'PDF failed for ' . strtoupper($lang) . ': ' . ($pdfResults[$lang]['error'] ?? 'unknown')
                        );
                    }

                    $relativePath = str_replace(App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);

                    $versionId = $db->insert('sds_versions', [
                        'finished_good_id' => $fgId,
                        'language'         => $lang,
                        'version'          => $nextVersion,
                        'status'           => 'published',
                        'effective_date'   => date('Y-m-d'),
                        'published_by'     => $userId,
                        'published_at'     => $now,
                        'snapshot_json'    => json_encode($langData[$lang], JSON_UNESCAPED_UNICODE),
                        'pdf_path'         => $relativePath,
                        'change_summary'   => 'Republished via SDS Update Required',
                        'created_by'       => $userId,
                    ]);

                    $traceData = array_merge(
                        $langData[$lang]['hazard_result']['trace'] ?? [],
                        $langData[$lang]['voc_result']['trace'] ?? []
                    );
                    $db->insert('sds_generation_trace', [
                        'sds_version_id' => $versionId,
                        'trace_json'     => json_encode($traceData, JSON_UNESCAPED_UNICODE),
                    ]);
                }

                // Publish alias SDSs
                $this->publishAliasSDSs($fg, $langData, $now, $db, $userId);

                AuditService::log('sds_version', (string) $fgId, 'republish_update', [
                    'version' => $nextVersion,
                    'trigger' => 'sds_update_queue',
                ]);

                $publishedCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = $fg['product_code'] . ': ' . $e->getMessage();
            }

            // Mark queue items as completed for this FG
            $db->query(
                "UPDATE sds_update_queue SET status = 'completed', resolved_by = ?, resolved_at = NOW()
                 WHERE finished_good_id = ? AND status = 'pending'",
                [$userId, $fgId]
            );
        }

        $msg = "{$publishedCount} product(s) republished successfully.";
        if ($failedCount > 0) {
            $msg .= " {$failedCount} failed: " . implode('; ', $errors);
            $_SESSION['_flash']['error'] = $msg;
        } else {
            $_SESSION['_flash']['success'] = $msg;
        }

        redirect('/sds-updates');
    }

    /**
     * POST /sds-updates/republish-private-label — Republish private label SDSs
     * for selected finished goods.
     */
    public function republishPrivateLabel(): void
    {
        if (!can_edit('sds_updates')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds-updates');
        }

        CSRF::validateRequest();

        $fgIds = $_POST['fg_ids'] ?? [];
        if (!is_array($fgIds) || empty($fgIds)) {
            $_SESSION['_flash']['error'] = 'No products selected.';
            redirect('/sds-updates');
        }

        $db = Database::getInstance();
        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $userId = current_user_id();
        $publishedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($fgIds as $fgId) {
            $fgId = (int) $fgId;
            $fg = \SDS\Models\FinishedGood::findById($fgId);
            if ($fg === null) {
                continue;
            }

            // Find all distinct private label combinations for this FG
            $plCombinations = $db->fetchAll(
                "SELECT DISTINCT manufacturer_id, alias_id
                 FROM private_label_sds
                 WHERE finished_good_id = ?",
                [$fgId]
            );

            if (empty($plCombinations)) {
                continue;
            }

            try {
                $generator = new SDSGenerator();
                $baseData = $generator->computeBase($fgId);

                foreach ($plCombinations as $combo) {
                    $manufacturerId = (int) $combo['manufacturer_id'];
                    $aliasId = $combo['alias_id'] !== null ? (int) $combo['alias_id'] : null;

                    $manufacturer = \SDS\Models\Manufacturer::findById($manufacturerId);
                    if ($manufacturer === null) {
                        continue;
                    }

                    $mfgInfo = \SDS\Models\Manufacturer::toCompanyInfo($manufacturer);

                    $alias = null;
                    if ($aliasId !== null) {
                        $alias = $db->fetch("SELECT * FROM aliases WHERE id = ?", [$aliasId]);
                        if ($alias !== null) {
                            $alias['customer_code'] = self::stripPackExtension($alias['customer_code']);
                        }
                    }

                    $langData = [];
                    foreach ($languages as $lang) {
                        $sdsData = $generator->generateFromBase($baseData, $lang);

                        if ($alias !== null) {
                            $sdsData = SDSGenerator::createPrivateLabelVariant(
                                $sdsData,
                                $alias['customer_code'],
                                $alias['description'],
                                $mfgInfo
                            );
                        } else {
                            $sdsData = SDSGenerator::createManufacturerVariant($sdsData, $mfgInfo);
                        }

                        $langData[$lang] = $sdsData;
                    }

                    $pdfResults = $this->generatePdfsInParallel($langData);

                    // Determine next version
                    $versionWhere = 'finished_good_id = ? AND manufacturer_id = ?';
                    $versionParams = [$fgId, $manufacturerId];
                    if ($aliasId !== null) {
                        $versionWhere .= ' AND alias_id = ?';
                        $versionParams[] = $aliasId;
                    } else {
                        $versionWhere .= ' AND alias_id IS NULL';
                    }

                    $lastVersion = $db->fetch(
                        "SELECT MAX(version) AS max_ver FROM private_label_sds WHERE {$versionWhere}",
                        $versionParams
                    );
                    $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

                    $now = date('Y-m-d H:i:s');

                    foreach ($languages as $lang) {
                        if (!($pdfResults[$lang]['ok'] ?? false)) {
                            continue;
                        }

                        $relativePath = str_replace(App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);

                        $db->insert('private_label_sds', [
                            'finished_good_id' => $fgId,
                            'manufacturer_id'  => $manufacturerId,
                            'alias_id'         => $aliasId,
                            'language'         => $lang,
                            'version'          => $nextVersion,
                            'status'           => 'published',
                            'effective_date'   => date('Y-m-d'),
                            'published_by'     => $userId,
                            'published_at'     => $now,
                            'snapshot_json'    => json_encode($langData[$lang], JSON_UNESCAPED_UNICODE),
                            'pdf_path'         => $relativePath,
                            'change_summary'   => 'Republished via SDS Update Required',
                            'created_by'       => $userId,
                        ]);
                    }

                    $publishedCount++;
                }

                AuditService::log('private_label_sds', (string) $fgId, 'republish_update', [
                    'combinations' => count($plCombinations),
                    'trigger'      => 'sds_update_queue',
                ]);
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = $fg['product_code'] . ': ' . $e->getMessage();
            }
        }

        $msg = "{$publishedCount} private label combination(s) republished successfully.";
        if ($failedCount > 0) {
            $msg .= " {$failedCount} failed: " . implode('; ', $errors);
            $_SESSION['_flash']['error'] = $msg;
        } else {
            $_SESSION['_flash']['success'] = $msg;
        }

        redirect('/sds-updates');
    }

    /**
     * POST /sds-updates/dismiss — Dismiss selected queue items without republishing.
     */
    public function dismiss(): void
    {
        if (!can_edit('sds_updates')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds-updates');
        }

        CSRF::validateRequest();

        $fgIds = $_POST['fg_ids'] ?? [];
        if (!is_array($fgIds) || empty($fgIds)) {
            $_SESSION['_flash']['error'] = 'No products selected.';
            redirect('/sds-updates');
        }

        $db = Database::getInstance();
        $userId = current_user_id();
        $dismissed = 0;

        foreach ($fgIds as $fgId) {
            $fgId = (int) $fgId;
            $affected = $db->query(
                "UPDATE sds_update_queue SET status = 'dismissed', resolved_by = ?, resolved_at = NOW()
                 WHERE finished_good_id = ? AND status = 'pending'",
                [$userId, $fgId]
            );
            $dismissed++;
        }

        AuditService::log('sds_update_queue', '0', 'dismiss', [
            'dismissed_fg_count' => $dismissed,
        ]);

        $_SESSION['_flash']['success'] = "{$dismissed} product(s) dismissed from update queue.";
        redirect('/sds-updates');
    }

    /* ------------------------------------------------------------------
     *  Private helpers
     * ----------------------------------------------------------------*/

    /**
     * Publish alias SDSs for a finished good (same logic as SDSController).
     */
    private function publishAliasSDSs(
        array $fg,
        array $langData,
        string $now,
        Database $db,
        ?int $userId
    ): void {
        $aliases = self::deduplicateAliasesByBaseCode($db->fetchAll(
            "SELECT id, customer_code, description FROM aliases WHERE internal_code_base = ? ORDER BY customer_code",
            [$fg['product_code']]
        ));

        if (empty($aliases)) {
            return;
        }

        foreach ($aliases as $alias) {
            $aliasLangData = [];
            foreach ($langData as $lang => $sdsData) {
                $aliasLangData[$lang] = SDSGenerator::createAliasVariant(
                    $sdsData,
                    $alias['customer_code'],
                    $alias['description']
                );
            }

            $pdfResults = $this->generatePdfsInParallel($aliasLangData);

            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                [(int) $alias['id']]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

            foreach ($aliasLangData as $lang => $aliasSds) {
                if (!($pdfResults[$lang]['ok'] ?? false)) {
                    continue;
                }

                $relativePath = str_replace(App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);

                $versionId = $db->insert('sds_versions', [
                    'finished_good_id' => (int) $fg['id'],
                    'alias_id'         => (int) $alias['id'],
                    'language'         => $lang,
                    'version'          => $nextVersion,
                    'status'           => 'published',
                    'effective_date'   => date('Y-m-d'),
                    'published_by'     => $userId,
                    'published_at'     => $now,
                    'snapshot_json'    => json_encode($aliasSds, JSON_UNESCAPED_UNICODE),
                    'pdf_path'         => $relativePath,
                    'change_summary'   => 'Republished via SDS Update Required (alias)',
                    'created_by'       => $userId,
                ]);

                $traceData = array_merge(
                    $aliasSds['hazard_result']['trace'] ?? [],
                    $aliasSds['voc_result']['trace'] ?? []
                );
                $db->insert('sds_generation_trace', [
                    'sds_version_id' => $versionId,
                    'trace_json'     => json_encode($traceData, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }

    /**
     * Spawn parallel child processes to render PDFs via TCPDF.
     * Same pattern as SDSController / PrivateLabelController.
     */
    private function generatePdfsInParallel(array $langData): array
    {
        $basePath     = App::basePath();
        $workerScript = $basePath . '/scripts/pdf-worker.php';
        $tmpDir       = $basePath . '/storage/temp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $phpBin    = php_cli_binary();
        $processes = [];
        $tempFiles = [];

        foreach ($langData as $lang => $sdsData) {
            $inputFile  = $tmpDir . '/upd_input_' . $lang . '_' . bin2hex(random_bytes(4)) . '.json';
            $resultFile = $tmpDir . '/upd_result_' . $lang . '_' . bin2hex(random_bytes(4)) . '.json';

            file_put_contents($inputFile, json_encode($sdsData, JSON_UNESCAPED_UNICODE));

            $cmd = sprintf(
                '%s %s %s %s',
                escapeshellarg($phpBin),
                escapeshellarg($workerScript),
                escapeshellarg($inputFile),
                escapeshellarg($resultFile)
            );

            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            fclose($pipes[0]);

            $processes[$lang] = [
                'proc'       => $proc,
                'pipes'      => $pipes,
                'resultFile' => $resultFile,
            ];
            $tempFiles[] = $inputFile;
            $tempFiles[] = $resultFile;
        }

        $results = [];
        foreach ($processes as $lang => $info) {
            $stderr = stream_get_contents($info['pipes'][2]);
            fclose($info['pipes'][1]);
            fclose($info['pipes'][2]);
            $exitCode = proc_close($info['proc']);

            if (file_exists($info['resultFile'])) {
                $result = json_decode(file_get_contents($info['resultFile']), true);
                if (is_array($result)) {
                    $results[$lang] = $result;
                } else {
                    $results[$lang] = ['ok' => false, 'error' => 'Invalid result from PDF worker'];
                }
            } else {
                $errMsg = trim($stderr) ?: 'PDF worker exited with code ' . $exitCode;
                $results[$lang] = ['ok' => false, 'error' => $errMsg];
            }
        }

        foreach ($tempFiles as $f) {
            @unlink($f);
        }

        return $results;
    }

    private static function stripPackExtension(string $code): string
    {
        $pos = strpos($code, '-');
        return $pos !== false ? substr($code, 0, $pos) : $code;
    }

    private static function deduplicateAliasesByBaseCode(array $rows): array
    {
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $baseCode = self::stripPackExtension($row['customer_code']);
            if (isset($seen[$baseCode])) {
                continue;
            }
            $seen[$baseCode] = true;
            $row['customer_code'] = $baseCode;
            $result[] = $row;
        }

        return $result;
    }
}
