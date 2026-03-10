<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Services\SDSGenerator;
use SDS\Services\PDFService;
use SDS\Services\AuditService;

class SDSController
{
    public function index(string $finished_good_id): void
    {
        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $db = Database::getInstance();
        $versions = $db->fetchAll(
            "SELECT sv.*, u.display_name AS published_by_name, uc.display_name AS created_by_name
             FROM sds_versions sv
             LEFT JOIN users u ON u.id = sv.published_by
             LEFT JOIN users uc ON uc.id = sv.created_by
             WHERE sv.finished_good_id = ? AND sv.is_deleted = 0
             ORDER BY sv.version DESC, sv.language ASC",
            [(int) $finished_good_id]
        );

        view('sds/index', [
            'pageTitle'    => 'SDS: ' . $fg['product_code'],
            'finishedGood' => $fg,
            'versions'     => $versions,
        ]);
    }

    public function preview(string $finished_good_id): void
    {
        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $language = $_GET['lang'] ?? 'en';

        try {
            $generator = new SDSGenerator();
            $sdsData   = $generator->generate((int) $finished_good_id, $language);

            view('sds/preview', [
                'pageTitle'    => 'SDS Preview: ' . $fg['product_code'],
                'finishedGood' => $fg,
                'sds'          => $sdsData,
                'language'     => $language,
            ]);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'SDS generation failed: ' . $e->getMessage();
            redirect('/sds/' . $finished_good_id);
        }
    }

    public function edit(string $finished_good_id): void
    {
        if (!can_edit('sds')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds/' . $finished_good_id);
        }

        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $language = $_GET['lang'] ?? 'en';

        try {
            $generator = new SDSGenerator();
            $sdsData   = $generator->generate((int) $finished_good_id, $language);

            // Load existing overrides for the form
            $db = Database::getInstance();
            $overrideRows = $db->fetchAll(
                "SELECT section_number, field_key, override_text
                 FROM text_overrides
                 WHERE finished_good_id = ? AND language = ? AND sds_version_id IS NULL
                 ORDER BY section_number, field_key",
                [(int) $finished_good_id, $language]
            );
            $overrides = [];
            foreach ($overrideRows as $row) {
                $overrides[(int) $row['section_number']][$row['field_key']] = $row['override_text'];
            }

            view('sds/edit', [
                'pageTitle'    => 'Edit SDS: ' . $fg['product_code'],
                'finishedGood' => $fg,
                'sds'          => $sdsData,
                'overrides'    => $overrides,
                'language'     => $language,
            ]);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'SDS generation failed: ' . $e->getMessage();
            redirect('/sds/' . $finished_good_id);
        }
    }

    public function saveEdits(string $finished_good_id): void
    {
        if (!can_edit('sds')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds/' . $finished_good_id);
        }

        CSRF::validateRequest();

        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $language  = $_POST['language'] ?? 'en';
        $overrides = $_POST['override'] ?? [];
        $db = Database::getInstance();
        $savedCount = 0;

        foreach ($overrides as $sectionNum => $fields) {
            $sectionNum = (int) $sectionNum;
            if ($sectionNum < 1 || $sectionNum > 16 || !is_array($fields)) {
                continue;
            }

            foreach ($fields as $fieldKey => $value) {
                $fieldKey = preg_replace('/[^a-zA-Z0-9_]/', '', $fieldKey);
                $value    = trim((string) $value);

                // Check for existing override
                $existing = $db->fetch(
                    "SELECT id FROM text_overrides
                     WHERE finished_good_id = ? AND section_number = ? AND field_key = ? AND language = ? AND sds_version_id IS NULL",
                    [(int) $finished_good_id, $sectionNum, $fieldKey, $language]
                );

                if ($value === '') {
                    // If empty and override exists, remove it to fall back to defaults
                    if ($existing) {
                        $db->query(
                            "DELETE FROM text_overrides WHERE id = ?",
                            [$existing['id']]
                        );
                    }
                    continue;
                }

                if ($existing) {
                    $db->update('text_overrides', [
                        'override_text' => $value,
                    ], 'id = ?', [$existing['id']]);
                } else {
                    $db->insert('text_overrides', [
                        'finished_good_id' => (int) $finished_good_id,
                        'section_number'   => $sectionNum,
                        'field_key'        => $fieldKey,
                        'language'         => $language,
                        'override_text'    => $value,
                    ]);
                }
                $savedCount++;
            }
        }

        AuditService::log('text_overrides', (string) $finished_good_id, 'bulk_edit', [
            'language'    => $language,
            'fields_saved' => $savedCount,
        ]);

        $_SESSION['_flash']['success'] = "SDS edits saved ({$savedCount} fields). Preview your changes or publish when ready.";
        redirect('/sds/' . $finished_good_id);
    }

    public function publish(string $finished_good_id): void
    {
        if (!can_edit('sds')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds/' . $finished_good_id);
        }

        CSRF::validateRequest();

        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $changeSummary = trim($_POST['change_summary'] ?? '');
        $languages = \SDS\Core\App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);

        $db = Database::getInstance();

        try {
            $generator = new SDSGenerator();

            // Compute language-independent base data once
            $baseData = $generator->computeBase((int) $finished_good_id);

            // Generate language-specific SDS data (fast — mostly translation)
            $langData = [];
            foreach ($languages as $lang) {
                $sdsData = $generator->generateFromBase($baseData, $lang);

                // Enforce missing-data threshold once (hazard data is language-independent)
                if ($lang === $languages[0]) {
                    $blockError = $this->checkMissingHazardData($sdsData, $db);
                    if ($blockError !== null) {
                        $_SESSION['_flash']['error'] = $blockError;
                        redirect('/sds/' . $finished_good_id);
                        return;
                    }
                }

                $langData[$lang] = $sdsData;
            }

            // Generate PDFs in parallel (one process per language)
            $pdfResults = $this->generatePdfsInParallel($langData);

            // Build results array
            $generated = [];
            foreach ($languages as $lang) {
                if (!$pdfResults[$lang]['ok']) {
                    throw new \RuntimeException(
                        'PDF generation failed for ' . strtoupper($lang) . ': ' . $pdfResults[$lang]['error']
                    );
                }
                $relativePath = str_replace(\SDS\Core\App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);
                $generated[] = [
                    'language'     => $lang,
                    'sdsData'      => $langData[$lang],
                    'relativePath' => $relativePath,
                ];
            }

            // All generated successfully — insert version records
            // Use a single version number across all languages
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions
                 WHERE finished_good_id = ?",
                [(int) $finished_good_id]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

            $publishedVersions = [];
            $now = date('Y-m-d H:i:s');

            foreach ($generated as $item) {
                $lang = $item['language'];

                $versionId = $db->insert('sds_versions', [
                    'finished_good_id' => (int) $finished_good_id,
                    'language'         => $lang,
                    'version'          => $nextVersion,
                    'status'           => 'published',
                    'effective_date'   => date('Y-m-d'),
                    'published_by'     => current_user_id(),
                    'published_at'     => $now,
                    'snapshot_json'    => json_encode($item['sdsData'], JSON_UNESCAPED_UNICODE),
                    'pdf_path'         => $item['relativePath'],
                    'change_summary'   => $changeSummary ?: null,
                    'created_by'       => current_user_id(),
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

                AuditService::log('sds_version', $versionId, 'publish', [
                    'finished_good_id' => $finished_good_id,
                    'language'         => $lang,
                    'version'          => $nextVersion,
                ]);

                $publishedVersions[] = strtoupper($lang);
            }

            // Publish alias SDS documents
            $aliasCount = $this->publishAliases(
                $fg, $langData, $changeSummary, $now, $db
            );

            $msg = 'SDS v' . $nextVersion . ' published successfully: ' . implode(', ', $publishedVersions);
            if ($aliasCount > 0) {
                $msg .= ' (+ ' . $aliasCount . ' alias SDS' . ($aliasCount > 1 ? 'es' : '') . ')';
            }
            $_SESSION['_flash']['success'] = $msg;
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Publish failed: ' . $e->getMessage();
        }

        redirect('/sds/' . $finished_good_id);
    }

    /**
     * Spawn parallel child processes to render PDFs via TCPDF.
     *
     * Each language's SDS data is serialized to a temp file, a worker
     * process generates the PDF, and writes the result path back.
     *
     * @param  array<string,array> $langData  Language code => SDS data array
     * @return array<string,array>            Language code => ['ok' => bool, 'pdf_path' => string] or ['ok' => false, 'error' => string]
     */
    private function generatePdfsInParallel(array $langData): array
    {
        $basePath     = \SDS\Core\App::basePath();
        $workerScript = $basePath . '/scripts/pdf-worker.php';
        $tmpDir       = $basePath . '/storage/temp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $phpBin    = php_cli_binary();
        $processes = [];
        $tempFiles = [];

        // Launch one worker per language
        foreach ($langData as $lang => $sdsData) {
            $inputFile  = $tmpDir . '/pdf_input_' . $lang . '_' . bin2hex(random_bytes(4)) . '.json';
            $resultFile = $tmpDir . '/pdf_result_' . $lang . '_' . bin2hex(random_bytes(4)) . '.json';

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

            // Close stdin immediately
            fclose($pipes[0]);

            $processes[$lang] = [
                'proc'       => $proc,
                'pipes'      => $pipes,
                'resultFile' => $resultFile,
            ];
            $tempFiles[] = $inputFile;
            $tempFiles[] = $resultFile;
        }

        // Wait for all workers and collect results
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

        // Clean up temp files
        foreach ($tempFiles as $f) {
            @unlink($f);
        }

        return $results;
    }

    public function download(string $id): void
    {
        $db = Database::getInstance();
        $version = $db->fetch(
            "SELECT * FROM sds_versions WHERE id = ? AND is_deleted = 0",
            [(int) $id]
        );

        if ($version === null) {
            $_SESSION['_flash']['error'] = 'SDS version not found.';
            redirect('/');
        }

        $pdfPath = \SDS\Core\App::basePath() . '/' . $version['pdf_path'];

        if (!file_exists($pdfPath)) {
            $_SESSION['_flash']['error'] = 'PDF file not found on disk.';
            redirect('/sds/' . $version['finished_good_id']);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="SDS_v' . $version['version'] . '_' . $version['language'] . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        exit;
    }

    public function trace(string $id): void
    {
        $db = Database::getInstance();
        $version = $db->fetch(
            "SELECT sv.*, fg.product_code
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             WHERE sv.id = ?",
            [(int) $id]
        );

        if ($version === null) {
            $_SESSION['_flash']['error'] = 'SDS version not found.';
            redirect('/');
        }

        $trace = $db->fetch(
            "SELECT trace_json FROM sds_generation_trace WHERE sds_version_id = ?",
            [(int) $id]
        );

        $traceData = $trace ? json_decode($trace['trace_json'], true) : [];

        view('sds/trace', [
            'pageTitle' => 'Audit Trace: ' . $version['product_code'] . ' v' . $version['version'],
            'version'   => $version,
            'trace'     => $traceData,
        ]);
    }

    /**
     * Publish SDS documents for all aliases of a finished good.
     *
     * Each alias gets its own SDS per language, identical to the parent
     * except for product code and description in section 1.
     *
     * @return int Number of alias SDS documents published.
     */
    private function publishAliases(
        array $fg,
        array $langData,
        string $changeSummary,
        string $now,
        Database $db
    ): int {
        $aliases = $this->getAliasesForFinishedGood($fg['product_code'], $db);
        if (empty($aliases)) {
            return 0;
        }

        $count = 0;

        foreach ($aliases as $alias) {
            // Build alias-specific SDS data per language, then generate PDFs
            $aliasLangData = [];
            foreach ($langData as $lang => $sdsData) {
                $aliasLangData[$lang] = SDSGenerator::createAliasVariant(
                    $sdsData,
                    $alias['customer_code'],
                    $alias['description']
                );
            }

            // Generate PDFs for all languages
            $pdfResults = $this->generatePdfsInParallel($aliasLangData);

            // Determine next version for this alias
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                [(int) $alias['id']]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

            foreach ($aliasLangData as $lang => $aliasSds) {
                if (!($pdfResults[$lang]['ok'] ?? false)) {
                    continue;
                }

                $relativePath = str_replace(\SDS\Core\App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);

                $versionId = $db->insert('sds_versions', [
                    'finished_good_id' => (int) $fg['id'],
                    'alias_id'         => (int) $alias['id'],
                    'language'         => $lang,
                    'version'          => $nextVersion,
                    'status'           => 'published',
                    'effective_date'   => date('Y-m-d'),
                    'published_by'     => current_user_id(),
                    'published_at'     => $now,
                    'snapshot_json'    => json_encode($aliasSds, JSON_UNESCAPED_UNICODE),
                    'pdf_path'         => $relativePath,
                    'change_summary'   => $changeSummary ?: ('Alias of ' . $fg['product_code']),
                    'created_by'       => current_user_id(),
                ]);

                $traceData = array_merge(
                    $aliasSds['hazard_result']['trace'] ?? [],
                    $aliasSds['voc_result']['trace'] ?? []
                );
                $db->insert('sds_generation_trace', [
                    'sds_version_id' => $versionId,
                    'trace_json'     => json_encode($traceData, JSON_UNESCAPED_UNICODE),
                ]);

                AuditService::log('sds_version', $versionId, 'publish_alias', [
                    'finished_good_id' => $fg['id'],
                    'alias_id'         => $alias['id'],
                    'alias_code'       => $alias['customer_code'],
                    'language'         => $lang,
                    'version'          => $nextVersion,
                ]);

                $count++;
            }
        }

        return $count;
    }

    /**
     * Find all aliases whose internal_code_base matches the finished good's product code.
     */
    private function getAliasesForFinishedGood(string $productCode, Database $db): array
    {
        return $db->fetchAll(
            "SELECT id, customer_code, description, internal_code, internal_code_base
             FROM aliases
             WHERE internal_code_base = ?
             ORDER BY customer_code ASC",
            [$productCode]
        );
    }

    /**
     * Check if required hazard data is missing for CAS numbers above the
     * configured threshold. If blocking is enabled and data is missing,
     * returns an error message; otherwise returns null.
     *
     * A CAS number is "covered" if it has federal hazard source records OR
     * an active competent person determination.
     */
    private function checkMissingHazardData(array $sdsData, Database $db): ?string
    {
        // Read threshold settings
        $blockSetting = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.block_publish_missing'");
        $blockEnabled = $blockSetting ? ($blockSetting['value'] !== '0') : \SDS\Core\App::config('sds.block_publish_missing', true);

        if (!$blockEnabled) {
            return null;
        }

        $thresholdRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.missing_threshold_pct'");
        $threshold = $thresholdRow ? (float) $thresholdRow['value'] : \SDS\Core\App::config('sds.missing_threshold_pct', 1.0);

        // Get composition from the section 3 data or hazard result
        $composition = [];
        foreach ($sdsData['sections'][3]['components'] ?? [] as $comp) {
            $composition[$comp['cas_number']] = (float) ($comp['concentration_pct'] ?? 0);
        }

        // Also check hazard_result trace for CAS numbers with no data
        $missingCas = [];
        foreach ($sdsData['hazard_result']['trace'] ?? [] as $step) {
            if (($step['step'] ?? '') === 'no_data') {
                $cas = $step['data']['cas'] ?? null;
                $conc = $step['data']['concentration_pct'] ?? 0;
                if ($cas !== null && (float) $conc >= $threshold) {
                    // Check if a competent person determination covers it
                    $cpd = $db->fetch(
                        "SELECT id FROM competent_person_determinations WHERE cas_number = ? AND is_active = 1 LIMIT 1",
                        [$cas]
                    );
                    if (!$cpd) {
                        $missingCas[] = $cas . ' (' . round((float) $conc, 2) . '%)';
                    }
                }
            }
        }

        if (!empty($missingCas)) {
            return 'Publishing blocked: missing federal hazard data for CAS numbers at or above '
                . $threshold . '% threshold: ' . implode(', ', $missingCas)
                . '. Create a Competent Person Determination for these CAS numbers or disable the threshold in Admin Settings.';
        }

        return null;
    }
}
