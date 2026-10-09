<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Services\SDSGenerator;
use SDS\Services\PDFService;
use SDS\Services\AuditService;
use SDS\Services\TextOverrideService;

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

        // Main product versions (no alias)
        $versions = $db->fetchAll(
            "SELECT sv.*, u.display_name AS published_by_name, uc.display_name AS created_by_name
             FROM sds_versions sv
             LEFT JOIN users u ON u.id = sv.published_by
             LEFT JOIN users uc ON uc.id = sv.created_by
             WHERE sv.finished_good_id = ? AND sv.alias_id IS NULL AND sv.is_deleted = 0
             ORDER BY sv.version DESC, sv.language ASC",
            [(int) $finished_good_id]
        );

        // Alias-specific versions with alias details
        $aliasVersions = $db->fetchAll(
            "SELECT sv.*, a.customer_code AS alias_code, a.description AS alias_description,
                    u.display_name AS published_by_name, uc.display_name AS created_by_name
             FROM sds_versions sv
             JOIN aliases a ON a.id = sv.alias_id
             LEFT JOIN users u ON u.id = sv.published_by
             LEFT JOIN users uc ON uc.id = sv.created_by
             WHERE sv.finished_good_id = ? AND sv.alias_id IS NOT NULL AND sv.is_deleted = 0
             ORDER BY a.customer_code ASC, sv.version DESC, sv.language ASC",
            [(int) $finished_good_id]
        );

        view('sds/index', [
            'pageTitle'     => 'SDS: ' . $fg['product_code'],
            'finishedGood'  => $fg,
            'versions'      => $versions,
            'aliasVersions' => $aliasVersions,
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
        // Audit #38 — the preview is the real PDF. SDSPreviewResponse::mode():
        //   page (default)  layout chrome + <iframe> of this URL with pdf=1
        //   ?pdf=1          the PDF itself (PDFService::generateString), inline
        //   ?html=1         legacy HTML rendering, debugging only
        $mode = \SDS\Services\SDSPreviewResponse::mode($_GET);

        try {
            $generator = new SDSGenerator();
            $sdsData   = $generator->generate((int) $finished_good_id, $language);

            // Audit #2 — surface a blank company emergency phone in the Warnings box.
            $phoneError = \SDS\Services\SDSReadinessService::companyEmergencyPhoneErrorFromDb(Database::getInstance());
            if ($phoneError !== null) {
                $sdsData['warnings'][] = $phoneError;
            }

            // Audit #27 — a "Not determined" Section 14 blocks publishing; show it here.
            $transportError = \SDS\Services\SDSReadinessService::transportNotDeterminedError($sdsData);
            if ($transportError !== null) {
                $sdsData['warnings'][] = $transportError;
            }

            if ($mode === \SDS\Services\SDSPreviewResponse::MODE_PDF) {
                \SDS\Services\SDSPreviewResponse::send(
                    (new PDFService())->generateString($sdsData),
                    \SDS\Services\SDSPreviewResponse::filename($sdsData)
                );
            }

            view($mode === \SDS\Services\SDSPreviewResponse::MODE_HTML ? 'sds/preview' : 'sds/preview-pdf', [
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

    /**
     * Preview an SDS for a resale raw material (no formula — sold as-is).
     *
     * The SDS is derived 100 % from the RM's own constituents. If an
     * alias id is supplied, the preview is branded under that alias's
     * customer code + description; otherwise it renders under the RM's
     * own base code.
     */
    public function previewResale(string $rm_id): void
    {
        $rmId  = (int) $rm_id;
        $rm    = \SDS\Models\RawMaterial::findById($rmId);
        if ($rm === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/sds-review');
        }

        $language = $_GET['lang'] ?? 'en';
        $aliasId  = isset($_GET['alias_id']) ? (int) $_GET['alias_id'] : 0;
        $mode     = \SDS\Services\SDSPreviewResponse::mode($_GET); // audit #38, see preview()

        try {
            $generator = new SDSGenerator();
            $sdsData   = $generator->generateForResaleRawMaterial($rmId, $language);

            // If branded for a specific alias, swap Section 1 + meta
            // identity using the existing alias-variant helper.
            if ($aliasId > 0) {
                $alias = Database::getInstance()->fetch(
                    "SELECT customer_code, description FROM aliases WHERE id = ?",
                    [$aliasId]
                );
                if ($alias !== null) {
                    $displayCode = \SDS\Services\AliasResolver::stripPack((string) $alias['customer_code']);
                    $sdsData = SDSGenerator::createAliasVariant(
                        $sdsData,
                        $displayCode,
                        (string) $alias['description']
                    );
                }
            }

            // Reuse the existing FG preview template by shaping a fake
            // "finished good" row — the template reads product_code and
            // description, both of which are already correct in the
            // sds data's Section 1.
            $fakeFg = [
                'id'          => null,
                'product_code' => $sdsData['meta']['product_code'] ?? $rm['internal_code'],
                'description'  => $sdsData['meta']['description'] ?? $rm['supplier_product_name'],
                'family'       => null,
            ];

            if ($mode === \SDS\Services\SDSPreviewResponse::MODE_PDF) {
                \SDS\Services\SDSPreviewResponse::send(
                    (new PDFService())->generateString($sdsData),
                    \SDS\Services\SDSPreviewResponse::filename($sdsData)
                );
            }

            view($mode === \SDS\Services\SDSPreviewResponse::MODE_HTML ? 'sds/preview' : 'sds/preview-pdf', [
                'pageTitle'    => 'SDS Preview: ' . ($fakeFg['product_code'] ?? ''),
                'finishedGood' => $fakeFg,
                'sds'          => $sdsData,
                'language'     => $language,
                'isResale'     => true,
                'backUrl'      => '/sds-review',
                'backLabel'    => 'Back to SDS Creation Readiness Check',
            ]);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'SDS generation failed: ' . $e->getMessage();
            redirect('/sds-review');
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

        $language = $this->editorLanguage($_GET['lang'] ?? 'en');

        try {
            // Audit #36: the form pre-fills from STORED overrides only and shows
            // the automatic text as a hint, so generate with overrides switched
            // off — $sds['sections'][n][key] is then the computed default of
            // every field (including Section 2 PPE / signal word display).
            $generator = new SDSGenerator();
            $sdsData   = $generator->ignoreOverrides()->generate((int) $finished_good_id, $language);

            $overrides = $this->loadStoredOverrides((int) $finished_good_id, $language);

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

    /**
     * Audit #36: stored per-product overrides for one language, shaped
     * [section => [field_key => override_text]]. Shared by edit() and
     * saveEdits(); same query SDSGenerator::getOverrides() runs.
     */
    private function loadStoredOverrides(int $fgId, string $language): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT section_number, field_key, override_text
             FROM text_overrides
             WHERE finished_good_id = ? AND language = ? AND sds_version_id IS NULL
             ORDER BY section_number, field_key",
            [$fgId, $language]
        );
        $overrides = [];
        foreach ($rows as $row) {
            $overrides[(int) $row['section_number']][$row['field_key']] = $row['override_text'];
        }
        return $overrides;
    }

    /** Audit #36: the editor only works in a supported SDS language (default en). */
    private function editorLanguage(mixed $raw): string
    {
        $supported = \SDS\Core\App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $lang = is_string($raw) ? strtolower(trim($raw)) : 'en';
        return in_array($lang, $supported, true) ? $lang : 'en';
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

        $fgId     = (int) $finished_good_id;
        $language = $this->editorLanguage($_POST['language'] ?? 'en');
        $posted   = $_POST['override'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }

        // Audit #36: only operator-typed text is stored. A blank field, or text
        // equal to the automatically generated value, means "automatic" and
        // removes any stored row. The automatic values come from a generation
        // run with overrides switched off; if that fails nothing is saved.
        try {
            $sections = (new SDSGenerator())->ignoreOverrides()->generate($fgId, $language)['sections'];
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'SDS generation failed, nothing was saved: ' . $e->getMessage();
            redirect('/sds/' . $finished_good_id . '/edit?lang=' . urlencode($language));
        }

        $stored = $this->loadStoredOverrides($fgId, $language);
        $plan   = TextOverrideService::plan($posted, $sections, $stored);

        $db    = Database::getInstance();
        $where = 'finished_good_id = ? AND section_number = ? AND field_key = ? AND language = ? AND sds_version_id IS NULL';

        foreach ($plan['delete'] as $d) {
            $db->delete('text_overrides', $where, [$fgId, $d['section'], $d['key'], $language]);
        }
        foreach ($plan['upsert'] as $u) {
            if (array_key_exists($u['key'], $stored[$u['section']] ?? [])) {
                $db->update('text_overrides', ['override_text' => $u['text']], $where, [$fgId, $u['section'], $u['key'], $language]);
            } else {
                $db->insert('text_overrides', [
                    'finished_good_id' => $fgId,
                    'section_number'   => $u['section'],
                    'field_key'        => $u['key'],
                    'language'         => $language,
                    'override_text'    => $u['text'],
                ]);
            }
        }

        $c = $plan['counts'];
        AuditService::log('text_overrides', (string) $fgId, 'bulk_edit', [
            'language' => $language,
            'stored'   => array_map(static fn (array $u) => $u['section'] . '.' . $u['key'], $plan['upsert']),
            'removed'  => array_map(static fn (array $d) => $d['section'] . '.' . $d['key'], $plan['delete']),
            'counts'   => $c,
        ]);

        $_SESSION['_flash']['success'] = sprintf(
            'SDS edits saved (%s): %d override(s) stored, %d reset to automatic, %d unchanged. '
            . 'Fields left blank or matching the automatic text stay automatic. Preview your changes or publish when ready.',
            strtoupper($language),
            $c['stored'],
            $c['removed'],
            $c['unchanged']
        );
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

        // Audit #2 — the company emergency phone prints on every standard SDS.
        $phoneError = \SDS\Services\SDSReadinessService::companyEmergencyPhoneErrorFromDb($db);
        if ($phoneError !== null) {
            $_SESSION['_flash']['error'] = $phoneError;
            redirect('/sds/' . $finished_good_id);
            return;
        }

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
                    $blockError = $this->checkMissingHazardData($sdsData, $db)
                        ?? \SDS\Services\SDSReadinessService::transportNotDeterminedError($sdsData);
                    if ($blockError !== null) {
                        $_SESSION['_flash']['error'] = $blockError;
                        redirect('/sds/' . $finished_good_id);
                        return;
                    }
                }

                $langData[$lang] = $sdsData;
            }

            // Audit #29 — TSCA inventory not verified for every constituent:
            // warn in the flash, never block. Language-independent.
            $tscaWarning = \SDS\Services\SDSReadinessService::tscaWarning($langData[$languages[0]] ?? []);

            // One version number across all languages, computed BEFORE the
            // render and stamped on meta so PDFService::generate() names each
            // file {code}_v{n}[_{lang}].pdf. The number is only consumed by the
            // sds_versions inserts below (and is now recorded in the snapshot).
            // Base rows only: alias rows share finished_good_id but number
            // from their own per-alias counter (same filter as
            // SDSUpdateController::republish and BulkPublishController).
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions
                 WHERE finished_good_id = ? AND alias_id IS NULL",
                [(int) $finished_good_id]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;
            $effectiveDate = date('Y-m-d');
            foreach ($langData as &$d) {
                $d = SDSGenerator::stampPublishedVersion($d, $nextVersion, $effectiveDate);
            }
            unset($d);

            // Generate PDFs in parallel (one process per language)
            $pdfResults = $this->generatePdfsInParallel($langData);

            // All languages must have rendered before anything is inserted.
            // On a partial failure remove the languages that did render so
            // the canonical {code}_v{n}[_{lang}].pdf names are free for the
            // retry (the version number is not consumed because no
            // sds_versions row is written).
            foreach ($languages as $lang) {
                if (!$pdfResults[$lang]['ok']) {
                    self::unlinkRenderedPdfs($pdfResults);
                    throw new \RuntimeException(
                        'PDF generation failed for ' . strtoupper($lang) . ': ' . $pdfResults[$lang]['error']
                    );
                }
            }

            // Build results array
            $generated = [];
            foreach ($languages as $lang) {
                $relativePath = str_replace(\SDS\Core\App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);
                $generated[] = [
                    'language'     => $lang,
                    'sdsData'      => $langData[$lang],
                    'relativePath' => $relativePath,
                ];
            }

            // All generated successfully — insert version records
            $publishedVersions = [];
            $now = date('Y-m-d H:i:s');

            foreach ($generated as $item) {
                $lang = $item['language'];

                $versionId = $db->insert('sds_versions', [
                    'finished_good_id' => (int) $finished_good_id,
                    'language'         => $lang,
                    'version'          => $nextVersion,
                    'status'           => 'published',
                    'effective_date'   => $effectiveDate,
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
                    'engine_version' => \SDS\Services\HazardEngine::ENGINE_VERSION,
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

            // Cascade to private label items (active + auto_republish) from the
            // same already-generated base data. Base + alias rows are already
            // committed above, so a private-label failure must never fail or
            // roll back this publish — it only adds a warning flash.
            try {
                $pl = (new \SDS\Services\PrivateLabelPublisher())->publishForFinishedGood(
                    (int) $fg['id'],
                    $langData,
                    $nextVersion,
                    current_user_id(),
                    $changeSummary ?: ('Base SDS v' . $nextVersion . ' published'),
                    'fg_publish'
                );
                if ($pl['published'] > 0) {
                    $msg .= ' (+ ' . $pl['published'] . ' private label SDS)';
                }
                if (!empty($pl['failed'])) {
                    $_SESSION['_flash']['warning'] = 'Private label SDS not regenerated: ' . implode('; ', $pl['failed']);
                }
            } catch (\Throwable $plEx) {
                $_SESSION['_flash']['warning'] = 'Private label SDS not regenerated: ' . $plEx->getMessage();
            }

            if ($tscaWarning !== null) {
                // Append: the private-label cascade above may already have set a warning.
                $_SESSION['_flash']['warning'] = trim((string) ($_SESSION['_flash']['warning'] ?? '') . ' ' . $tscaWarning);
            }
            $_SESSION['_flash']['success'] = $msg;
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Publish failed: ' . $e->getMessage();
        }

        redirect('/sds/' . $finished_good_id);
    }

    /**
     * Delete the PDFs of a partially failed parallel render so the canonical
     * {code}_v{n}[_{lang}].pdf names are free for the retry. Mirrors
     * PrivateLabelPublisher::unlinkAll(); iterates every result, not only the
     * languages checked so far, since later languages may have succeeded.
     *
     * @param array<string,array> $pdfResults  Output of generatePdfsInParallel()
     */
    private static function unlinkRenderedPdfs(array $pdfResults): void
    {
        foreach ($pdfResults as $r) {
            $p = $r['pdf_path'] ?? '';
            if (($r['ok'] ?? false) && is_string($p) && $p !== '' && file_exists($p)) {
                @unlink($p);
            }
        }
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
        // LEFT JOIN finished_goods + raw_materials too so the filename
        // can fall back to either source when no alias is on the row,
        // and so formula-based FGs without aliases pick up a real code.
        $version = $db->fetch(
            "SELECT sv.*,
                    a.customer_code    AS alias_code,
                    fg.product_code    AS fg_product_code,
                    rm.internal_code   AS rm_internal_code
             FROM sds_versions sv
             LEFT JOIN aliases        a  ON a.id  = sv.alias_id
             LEFT JOIN finished_goods fg ON fg.id = sv.finished_good_id
             LEFT JOIN raw_materials  rm ON rm.id = sv.raw_material_id
             WHERE sv.id = ? AND sv.is_deleted = 0",
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

        // Prefer the alias code (what the customer sees), falling back
        // to the FG product code or the RM's internal code. Pack
        // extension is always stripped so BK1080-2G and BK1080-5G both
        // land as "SDS_BK1080_v…".
        $sourceCode = $version['alias_code']
                   ?? $version['fg_product_code']
                   ?? $version['rm_internal_code']
                   ?? '';
        $displayCode = strip_pack_extension((string) $sourceCode);
        $safeCode    = preg_replace('/[^A-Za-z0-9_\-]/', '_', $displayCode);

        $filename = 'SDS_';
        if ($safeCode !== '') {
            $filename .= $safeCode . '_';
        }
        $filename .= 'v' . $version['version'] . '_' . $version['language'] . '.pdf';

        $disposition = !empty($_COOKIE['sds_pdf_download']) ? 'attachment' : 'inline';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
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
     * Publish an SDS for a resale raw material.
     *
     * The SDS is derived 100 % from the RM's own constituents. One version
     * is stored under the RM itself (finished_good_id = NULL, raw_material_id
     * set, alias_id NULL), and one per alias that points to this RM via
     * the resale path (alias_id set).
     *
     * Route: POST /sds/resale/{rm_id}/publish
     */
    public function publishResale(string $rm_id): void
    {
        if (!can_edit('sds')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds-review?rm_id=' . $rm_id);
        }

        CSRF::validateRequest();

        $rmId = (int) $rm_id;
        $rm   = \SDS\Models\RawMaterial::findById($rmId);
        if ($rm === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/sds-review');
        }

        $changeSummary = trim($_POST['change_summary'] ?? '');
        $languages = \SDS\Core\App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $db = Database::getInstance();

        // Audit #2 — the company emergency phone prints on every resale SDS.
        $phoneError = \SDS\Services\SDSReadinessService::companyEmergencyPhoneErrorFromDb($db);
        if ($phoneError !== null) {
            $_SESSION['_flash']['error'] = $phoneError;
            redirect('/sds-review?rm_id=' . $rmId);
            return;
        }

        try {
            $generator = new SDSGenerator();
            $baseData  = $generator->computeBaseForResaleRawMaterial($rmId);

            // Generate per-language SDS data from the shared base.
            $langData = [];
            foreach ($languages as $lang) {
                $sdsData = $generator->generateFromBase($baseData, $lang);

                // Enforce missing-data threshold once; hazard data is
                // language-independent so the first language is sufficient.
                if ($lang === $languages[0]) {
                    $blockError = $this->checkMissingHazardData($sdsData, $db)
                        ?? \SDS\Services\SDSReadinessService::transportNotDeterminedError($sdsData);
                    if ($blockError !== null) {
                        $_SESSION['_flash']['error'] = $blockError;
                        redirect('/sds-review?rm_id=' . $rmId);
                        return;
                    }
                }

                $langData[$lang] = $sdsData;
            }

            // Audit #29 — TSCA inventory not verified for every constituent:
            // warn in the flash, never block. Language-independent.
            $tscaWarning = \SDS\Services\SDSReadinessService::tscaWarning($langData[$languages[0]] ?? []);

            // Version number first (one per publish, all languages) so the
            // render can name the files {code}_v{n}[_{lang}].pdf via
            // meta.sds_version; the inserts below use the same number.
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver
                 FROM sds_versions
                 WHERE raw_material_id = ? AND alias_id IS NULL",
                [$rmId]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;
            $effectiveDate = date('Y-m-d');
            foreach ($langData as &$d) {
                $d = SDSGenerator::stampPublishedVersion($d, $nextVersion, $effectiveDate);
            }
            unset($d);

            // PDFs for the base (non-alias) version.
            $pdfResults = $this->generatePdfsInParallel($langData);

            $baseCode = \SDS\Services\AliasResolver::stripPack((string) $rm['internal_code']);

            $publishedVersions = [];
            $now = date('Y-m-d H:i:s');

            // Check every language before the first insert so a partial
            // failure leaves no orphan {code}_v{n}[_{lang}].pdf behind and no
            // row pointing at a file we are about to remove.
            foreach ($languages as $lang) {
                if (!($pdfResults[$lang]['ok'] ?? false)) {
                    self::unlinkRenderedPdfs($pdfResults);
                    throw new \RuntimeException(
                        'PDF generation failed for ' . strtoupper($lang) . ': ' .
                        ($pdfResults[$lang]['error'] ?? 'unknown error')
                    );
                }
            }

            foreach ($languages as $lang) {
                $relativePath = str_replace(\SDS\Core\App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);

                $versionId = $db->insert('sds_versions', [
                    'finished_good_id' => null,
                    'raw_material_id'  => $rmId,
                    'alias_id'         => null,
                    'language'         => $lang,
                    'version'          => $nextVersion,
                    'status'           => 'published',
                    'effective_date'   => $effectiveDate,
                    'published_by'     => current_user_id(),
                    'published_at'     => $now,
                    'snapshot_json'    => json_encode($langData[$lang], JSON_UNESCAPED_UNICODE),
                    'pdf_path'         => $relativePath,
                    'change_summary'   => $changeSummary ?: ('Resale SDS for ' . $baseCode),
                    'created_by'       => current_user_id(),
                ]);

                $traceData = array_merge(
                    $langData[$lang]['hazard_result']['trace'] ?? [],
                    $langData[$lang]['voc_result']['trace'] ?? []
                );
                $db->insert('sds_generation_trace', [
                    'sds_version_id' => $versionId,
                    'engine_version' => \SDS\Services\HazardEngine::ENGINE_VERSION,
                    'trace_json'     => json_encode($traceData, JSON_UNESCAPED_UNICODE),
                ]);

                AuditService::log('sds_version', $versionId, 'publish_resale', [
                    'raw_material_id' => $rmId,
                    'base_code'       => $baseCode,
                    'language'        => $lang,
                    'version'         => $nextVersion,
                ]);

                $publishedVersions[] = strtoupper($lang);
            }

            // Publish alias-branded variants for every alias that resolves
            // to this RM via the resale path.
            $aliasCount = $this->publishResaleAliases(
                $rmId, $baseCode, $langData, $changeSummary, $now, $db
            );

            $msg = 'Resale SDS v' . $nextVersion . ' published: ' . implode(', ', $publishedVersions);
            if ($aliasCount > 0) {
                $msg .= ' (+ ' . $aliasCount . ' alias SDS' . ($aliasCount > 1 ? 'es' : '') . ')';
            }
            if ($tscaWarning !== null) {
                // Append: an earlier step may already have set a warning.
                $_SESSION['_flash']['warning'] = trim((string) ($_SESSION['_flash']['warning'] ?? '') . ' ' . $tscaWarning);
            }
            $_SESSION['_flash']['success'] = $msg;
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Publish failed: ' . $e->getMessage();
        }

        redirect('/sds-review?rm_id=' . $rmId);
    }

    /**
     * Publish alias-branded variants of a resale RM's SDS.
     *
     * An alias points at this RM via the resale path when its
     * internal_code_base matches the RM's base code AND no FG with that
     * base code exists. AliasResolver::resolveByAliasId re-verifies so a
     * half-set-up FG doesn't cause us to publish a resale version that
     * conflicts with the FG's own formula path.
     */
    private function publishResaleAliases(
        int $rmId,
        string $rmBaseCode,
        array $langData,
        string $changeSummary,
        string $now,
        Database $db
    ): int {
        // Candidates: aliases whose base points at this RM. De-dup by
        // base customer_code so pack variants share one SDS.
        $aliases = $db->fetchAll(
            "SELECT a.*
             FROM aliases a
             INNER JOIN (
                 SELECT MIN(id) AS rep_id
                 FROM aliases
                 WHERE internal_code_base = ?
                 GROUP BY SUBSTRING_INDEX(customer_code, '-', 1)
             ) rep ON rep.rep_id = a.id",
            [$rmBaseCode]
        );
        if (empty($aliases)) {
            return 0;
        }

        $count = 0;

        foreach ($aliases as $alias) {
            // Re-verify resolution — if an FG with this base code has
            // been created since the candidate query ran, the alias now
            // belongs on the formula path, not the resale path.
            $resolution = \SDS\Services\AliasResolver::resolveByAliasId((int) $alias['id']);
            if ($resolution === null || $resolution['type'] !== 'resale') {
                continue;
            }

            $displayCode = \SDS\Services\AliasResolver::stripPack((string) $alias['customer_code']);

            // Alias version first, stamped on meta so the PDFs are named
            // {alias_code}_v{n}[_{lang}].pdf; the inserts below use the same number.
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                [(int) $alias['id']]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;
            $effectiveDate = substr($now, 0, 10);

            $aliasLangData = [];
            foreach ($langData as $lang => $sdsData) {
                $aliasLangData[$lang] = SDSGenerator::createAliasVariant(
                    $sdsData,
                    $displayCode,
                    (string) $alias['description']
                );
                $aliasLangData[$lang] = SDSGenerator::stampPublishedVersion($aliasLangData[$lang], $nextVersion, $effectiveDate);
            }

            $pdfResults = $this->generatePdfsInParallel($aliasLangData);

            foreach ($aliasLangData as $lang => $aliasSds) {
                if (!($pdfResults[$lang]['ok'] ?? false)) {
                    continue;
                }

                $relativePath = str_replace(\SDS\Core\App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);

                $versionId = $db->insert('sds_versions', [
                    'finished_good_id' => null,
                    'raw_material_id'  => $rmId,
                    'alias_id'         => (int) $alias['id'],
                    'language'         => $lang,
                    'version'          => $nextVersion,
                    'status'           => 'published',
                    'effective_date'   => $effectiveDate,
                    'published_by'     => current_user_id(),
                    'published_at'     => $now,
                    'snapshot_json'    => json_encode($aliasSds, JSON_UNESCAPED_UNICODE),
                    'pdf_path'         => $relativePath,
                    'change_summary'   => $changeSummary ?: ('Resale alias of ' . $rmBaseCode),
                    'created_by'       => current_user_id(),
                ]);

                $traceData = array_merge(
                    $aliasSds['hazard_result']['trace'] ?? [],
                    $aliasSds['voc_result']['trace'] ?? []
                );
                $db->insert('sds_generation_trace', [
                    'sds_version_id' => $versionId,
                    'engine_version' => \SDS\Services\HazardEngine::ENGINE_VERSION,
                    'trace_json'     => json_encode($traceData, JSON_UNESCAPED_UNICODE),
                ]);

                AuditService::log('sds_version', $versionId, 'publish_resale_alias', [
                    'raw_material_id' => $rmId,
                    'alias_id'        => $alias['id'],
                    'alias_code'      => $alias['customer_code'],
                    'language'        => $lang,
                    'version'         => $nextVersion,
                ]);

                $count++;
            }
        }

        return $count;
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
            // Determine next version for this alias BEFORE rendering so the
            // PDFs are named {alias_code}_v{n}[_{lang}].pdf (meta.sds_version).
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                [(int) $alias['id']]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;
            $effectiveDate = substr($now, 0, 10);

            // Build alias-specific SDS data per language, then generate PDFs
            $aliasLangData = [];
            foreach ($langData as $lang => $sdsData) {
                $aliasLangData[$lang] = SDSGenerator::createAliasVariant(
                    $sdsData,
                    $alias['customer_code'],
                    $alias['description']
                );
                $aliasLangData[$lang] = SDSGenerator::stampPublishedVersion($aliasLangData[$lang], $nextVersion, $effectiveDate);
            }

            // Generate PDFs for all languages
            $pdfResults = $this->generatePdfsInParallel($aliasLangData);

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
                    'effective_date'   => $effectiveDate,
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
                    'engine_version' => \SDS\Services\HazardEngine::ENGINE_VERSION,
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
     *
     * Aliases are deduplicated by base customer code (pack extension stripped)
     * so that only one SDS is generated per base alias regardless of how many
     * pack extension variants exist (e.g., B1320-50, B1320-2G → one SDS for B1320).
     */
    private function getAliasesForFinishedGood(string $productCode, Database $db): array
    {
        $rows = $db->fetchAll(
            "SELECT id, customer_code, description, internal_code, internal_code_base
             FROM aliases
             WHERE internal_code_base = ?
             ORDER BY customer_code ASC",
            [$productCode]
        );

        return self::deduplicateAliasesByBaseCode($rows);
    }

    /**
     * Strip the pack extension from a code (everything after the first "-").
     */
    private static function stripPackExtension(string $code): string
    {
        $pos = strpos($code, '-');
        return $pos !== false ? substr($code, 0, $pos) : $code;
    }

    /**
     * Deduplicate alias rows by base customer code (pack extension stripped).
     *
     * Returns one row per unique base code, using the first occurrence's id
     * and description, with customer_code set to the base (no pack extension).
     */
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
