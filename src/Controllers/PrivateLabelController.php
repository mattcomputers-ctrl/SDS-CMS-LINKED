<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Models\Manufacturer;
use SDS\Services\SDSGenerator;
use SDS\Services\PDFService;
use SDS\Services\AuditService;

/**
 * PrivateLabelController — Create and manage private-label SDS documents.
 *
 * Private label SDSs are stored in a separate table (private_label_sds)
 * and are NOT exported by any other utility in the system (bulk export,
 * SDS lookup, SDS book, etc.). They are only accessible from this section.
 */
class PrivateLabelController
{
    public function index(): void
    {
        if (!can_read('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/');
        }

        $db = Database::getInstance();
        $search = trim($_GET['search'] ?? '');
        $manufacturerFilter = (int) ($_GET['manufacturer_id'] ?? 0);

        $where  = [];
        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[]  = '(fg.product_code LIKE ? OR fg.description LIKE ? OR a.customer_code LIKE ? OR m.name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($manufacturerFilter > 0) {
            $where[]  = 'pl.manufacturer_id = ?';
            $params[] = $manufacturerFilter;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get all private label SDS grouped by product + manufacturer
        $items = $db->fetchAll(
            "SELECT pl.*, fg.product_code, fg.description AS fg_description,
                    m.name AS manufacturer_name,
                    a.customer_code AS alias_code, a.description AS alias_description,
                    u.display_name AS published_by_name
             FROM private_label_sds pl
             JOIN finished_goods fg ON fg.id = pl.finished_good_id
             JOIN manufacturers m ON m.id = pl.manufacturer_id
             LEFT JOIN aliases a ON a.id = pl.alias_id
             LEFT JOIN users u ON u.id = pl.published_by
             {$whereSQL}
             ORDER BY m.name ASC, COALESCE(a.customer_code, fg.product_code) ASC, pl.version DESC, pl.language ASC",
            $params
        );

        $manufacturers = Manufacturer::all();

        view('private-label/index', [
            'pageTitle'          => 'Private Label SDS',
            'items'              => $items,
            'manufacturers'      => $manufacturers,
            'search'             => $search,
            'manufacturerFilter' => $manufacturerFilter,
        ]);
    }

    /**
     * Live preview — generates fresh SDS data with manufacturer swap.
     *
     * Uses the exact same SDSGenerator::generate() path as the standard
     * SDS preview, then applies the manufacturer (and optional alias)
     * override so the output is identical except for Section 1 identity.
     */
    public function livePreview(): void
    {
        if (!can_read('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/');
        }

        $finishedGoodId = (int) ($_GET['finished_good_id'] ?? 0);
        $manufacturerId = (int) ($_GET['manufacturer_id'] ?? 0);
        $aliasId        = (int) ($_GET['alias_id'] ?? 0) ?: null;
        $language       = $_GET['lang'] ?? 'en';

        $fg = FinishedGood::findById($finishedGoodId);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Please select a valid product.';
            redirect('/private-label/create');
        }

        $manufacturer = Manufacturer::findById($manufacturerId);
        if ($manufacturer === null) {
            $_SESSION['_flash']['error'] = 'Please select a manufacturer.';
            redirect('/private-label/create');
        }

        $mfgInfo = Manufacturer::toCompanyInfo($manufacturer);

        // Look up alias if provided
        $alias = null;
        if ($aliasId !== null) {
            $db = Database::getInstance();
            $alias = $db->fetch("SELECT * FROM aliases WHERE id = ?", [$aliasId]);
            if ($alias !== null) {
                $alias['customer_code'] = self::stripPackExtension($alias['customer_code']);
            }
        }

        try {
            // Use the exact same generate() call as SDSController::preview()
            $generator = new SDSGenerator();
            $sdsData   = $generator->generate($finishedGoodId, $language);

            // Apply manufacturer and optional alias overrides
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

            $productCode = $alias ? $alias['customer_code'] : $fg['product_code'];

            view('sds/preview', [
                'pageTitle'     => 'Private Label SDS Preview: ' . $productCode . ' / ' . $manufacturer['name'],
                'finishedGood'  => ['product_code' => $productCode],
                'sds'           => $sdsData,
                'language'      => $language,
                'privateLabelId' => 0,
            ]);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'SDS preview failed: ' . $e->getMessage();
            redirect('/private-label/create');
        }
    }

    public function create(): void
    {
        if (!can_edit('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/private-label');
        }

        $finishedGoods = FinishedGood::all([
            'per_page' => 999,
            'sort'     => 'product_code',
            'dir'      => 'asc',
            'is_active' => 1,
        ]);

        $manufacturers = Manufacturer::all();

        // Load aliases, deduplicated by base customer code (pack extension stripped)
        $db = Database::getInstance();
        $aliasRows = $db->fetchAll(
            "SELECT a.id, a.customer_code, a.description, a.internal_code_base
             FROM aliases a
             ORDER BY a.customer_code ASC"
        );
        $aliases = self::deduplicateAliasesByBaseCode($aliasRows);

        view('private-label/create', [
            'pageTitle'     => 'Create Private Label SDS',
            'finishedGoods' => $finishedGoods,
            'manufacturers' => $manufacturers,
            'aliases'       => $aliases,
        ]);
    }

    public function generate(): void
    {
        if (!can_edit('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/private-label');
        }

        CSRF::validateRequest();

        $finishedGoodId = (int) ($_POST['finished_good_id'] ?? 0);
        $manufacturerId = (int) ($_POST['manufacturer_id'] ?? 0);
        $aliasId        = (int) ($_POST['alias_id'] ?? 0) ?: null;
        $changeSummary  = trim($_POST['change_summary'] ?? '');
        $useAlias       = !empty($_POST['use_alias']);

        // Validate product
        $fg = FinishedGood::findById($finishedGoodId);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Please select a valid product.';
            redirect('/private-label/create');
        }

        // Validate manufacturer
        $manufacturer = Manufacturer::findById($manufacturerId);
        if ($manufacturer === null) {
            $_SESSION['_flash']['error'] = 'Please select a manufacturer.';
            redirect('/private-label/create');
        }

        // If using alias, validate it and strip pack extension from customer code
        $alias = null;
        if ($useAlias && $aliasId !== null) {
            $db = Database::getInstance();
            $alias = $db->fetch("SELECT * FROM aliases WHERE id = ?", [$aliasId]);
            if ($alias === null) {
                $_SESSION['_flash']['error'] = 'Selected alias not found.';
                redirect('/private-label/create');
            }
            $alias['customer_code'] = self::stripPackExtension($alias['customer_code']);
        } else {
            $aliasId = null;
        }

        $db = Database::getInstance();
        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $mfgInfo = Manufacturer::toCompanyInfo($manufacturer);

        try {
            $generator = new SDSGenerator();
            $baseData  = $generator->computeBase($finishedGoodId);

            $langData = [];
            foreach ($languages as $lang) {
                $sdsData = $generator->generateFromBase($baseData, $lang);

                // Enforce missing-data threshold (same check as standard SDS)
                if ($lang === $languages[0]) {
                    $blockError = $this->checkMissingHazardData($sdsData, $db);
                    if ($blockError !== null) {
                        $_SESSION['_flash']['error'] = $blockError;
                        redirect('/private-label/create');
                        return;
                    }
                }

                // Apply manufacturer and optional alias overrides
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

            // Generate PDFs using the same parallel worker pattern as SDSController
            $pdfResults = $this->generatePdfsInParallel($langData);

            // Determine next version
            $versionWhere = 'finished_good_id = ? AND manufacturer_id = ?';
            $versionParams = [$finishedGoodId, $manufacturerId];
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
            $publishedVersions = [];

            foreach ($languages as $lang) {
                if (!($pdfResults[$lang]['ok'] ?? false)) {
                    throw new \RuntimeException(
                        'PDF generation failed for ' . strtoupper($lang) . ': ' . ($pdfResults[$lang]['error'] ?? 'unknown error')
                    );
                }

                $relativePath = str_replace(App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);

                $db->insert('private_label_sds', [
                    'finished_good_id' => $finishedGoodId,
                    'manufacturer_id'  => $manufacturerId,
                    'alias_id'         => $aliasId,
                    'language'         => $lang,
                    'version'          => $nextVersion,
                    'status'           => 'published',
                    'effective_date'   => date('Y-m-d'),
                    'published_by'     => current_user_id(),
                    'published_at'     => $now,
                    'snapshot_json'    => json_encode($langData[$lang], JSON_UNESCAPED_UNICODE),
                    'pdf_path'         => $relativePath,
                    'change_summary'   => $changeSummary ?: null,
                    'created_by'       => current_user_id(),
                ]);

                $publishedVersions[] = strtoupper($lang);
            }

            AuditService::log('private_label_sds', (string) $finishedGoodId, 'publish', [
                'manufacturer_id'  => $manufacturerId,
                'manufacturer'     => $manufacturer['name'],
                'alias_id'         => $aliasId,
                'version'          => $nextVersion,
                'languages'        => $publishedVersions,
            ]);

            $productLabel = $alias ? $alias['customer_code'] : $fg['product_code'];
            $_SESSION['_flash']['success'] = "Private label SDS v{$nextVersion} published for {$productLabel} / {$manufacturer['name']}: " . implode(', ', $publishedVersions);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Private label SDS generation failed: ' . $e->getMessage();
        }

        redirect('/private-label');
    }

    public function download(string $id): void
    {
        if (!can_read('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/');
        }

        $db = Database::getInstance();
        $version = $db->fetch(
            "SELECT pl.*, fg.product_code, m.name AS manufacturer_name,
                    a.customer_code AS alias_code
             FROM private_label_sds pl
             JOIN finished_goods fg ON fg.id = pl.finished_good_id
             JOIN manufacturers m ON m.id = pl.manufacturer_id
             LEFT JOIN aliases a ON a.id = pl.alias_id
             WHERE pl.id = ?",
            [(int) $id]
        );

        if ($version === null) {
            $_SESSION['_flash']['error'] = 'Private label SDS not found.';
            redirect('/private-label');
        }

        $pdfPath = App::basePath() . '/' . $version['pdf_path'];

        if (!file_exists($pdfPath)) {
            $_SESSION['_flash']['error'] = 'PDF file not found on disk.';
            redirect('/private-label');
        }

        $productCode = !empty($version['alias_code']) ? strip_pack_extension($version['alias_code']) : $version['product_code'];
        $safeCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $productCode);
        $safeMfg  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $version['manufacturer_name']);
        $filename = 'PL_SDS_' . $safeCode . '_' . $safeMfg . '_v' . $version['version'] . '_' . $version['language'] . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        exit;
    }

    public function preview(string $id): void
    {
        if (!can_read('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/');
        }

        $db = Database::getInstance();
        $version = $db->fetch(
            "SELECT pl.*, fg.product_code, m.name AS manufacturer_name,
                    a.customer_code AS alias_code, a.description AS alias_description
             FROM private_label_sds pl
             JOIN finished_goods fg ON fg.id = pl.finished_good_id
             JOIN manufacturers m ON m.id = pl.manufacturer_id
             LEFT JOIN aliases a ON a.id = pl.alias_id
             WHERE pl.id = ?",
            [(int) $id]
        );

        if ($version === null) {
            $_SESSION['_flash']['error'] = 'Private label SDS not found.';
            redirect('/private-label');
        }

        $productCode = !empty($version['alias_code']) ? strip_pack_extension($version['alias_code']) : $version['product_code'];

        // Regenerate fresh SDS data (same approach as standard SDS preview)
        // to ensure GHS classification data is always current.
        try {
            $generator = new SDSGenerator();
            $sdsData = $generator->generate(
                (int) $version['finished_good_id'],
                $version['language']
            );

            $manufacturer = Manufacturer::findById((int) $version['manufacturer_id']);
            $mfgInfo = $manufacturer ? Manufacturer::toCompanyInfo($manufacturer) : [];

            if (!empty($version['alias_code'])) {
                $aliasCode = strip_pack_extension($version['alias_code']);
                $aliasDesc = $version['alias_description'] ?? '';
                $sdsData = SDSGenerator::createPrivateLabelVariant(
                    $sdsData, $aliasCode, $aliasDesc, $mfgInfo
                );
            } elseif (!empty($mfgInfo)) {
                $sdsData = SDSGenerator::createManufacturerVariant($sdsData, $mfgInfo);
            }
        } catch (\Throwable $e) {
            // Fall back to stored snapshot if regeneration fails
            if ($version['snapshot_json'] !== null) {
                $sdsData = json_decode($version['snapshot_json'], true);
            } else {
                $_SESSION['_flash']['error'] = 'SDS preview failed: ' . $e->getMessage();
                redirect('/private-label');
                return;
            }
        }

        view('sds/preview', [
            'pageTitle'     => 'Private Label SDS Preview: ' . $productCode . ' / ' . $version['manufacturer_name'],
            'finishedGood'  => ['product_code' => $productCode],
            'sds'           => $sdsData,
            'language'      => $version['language'],
            'privateLabelId' => (int) $id,
        ]);
    }

    /**
     * Republish — regenerate PDF and snapshot for an existing private label SDS.
     *
     * Creates a new version with fresh hazard classification data,
     * ensuring GHS data reflects current federal hazard records.
     */
    public function republish(string $id): void
    {
        if (!can_edit('private_label')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/private-label');
        }

        CSRF::validateRequest();

        $db = Database::getInstance();
        $version = $db->fetch(
            "SELECT pl.*, fg.product_code, m.name AS manufacturer_name,
                    a.customer_code AS alias_code, a.description AS alias_description
             FROM private_label_sds pl
             JOIN finished_goods fg ON fg.id = pl.finished_good_id
             JOIN manufacturers m ON m.id = pl.manufacturer_id
             LEFT JOIN aliases a ON a.id = pl.alias_id
             WHERE pl.id = ?",
            [(int) $id]
        );

        if ($version === null) {
            $_SESSION['_flash']['error'] = 'Private label SDS not found.';
            redirect('/private-label');
            return;
        }

        $finishedGoodId = (int) $version['finished_good_id'];
        $manufacturerId = (int) $version['manufacturer_id'];
        $aliasId        = $version['alias_id'] !== null ? (int) $version['alias_id'] : null;

        $manufacturer = Manufacturer::findById($manufacturerId);
        if ($manufacturer === null) {
            $_SESSION['_flash']['error'] = 'Manufacturer not found.';
            redirect('/private-label');
            return;
        }

        $mfgInfo = Manufacturer::toCompanyInfo($manufacturer);

        $alias = null;
        if ($aliasId !== null) {
            $alias = $db->fetch("SELECT * FROM aliases WHERE id = ?", [$aliasId]);
            if ($alias !== null) {
                $alias['customer_code'] = self::stripPackExtension($alias['customer_code']);
            }
        }

        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);

        try {
            $generator = new SDSGenerator();
            $baseData  = $generator->computeBase($finishedGoodId);

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
            $versionParams = [$finishedGoodId, $manufacturerId];
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
            $publishedVersions = [];

            foreach ($languages as $lang) {
                if (!($pdfResults[$lang]['ok'] ?? false)) {
                    throw new \RuntimeException(
                        'PDF generation failed for ' . strtoupper($lang) . ': ' . ($pdfResults[$lang]['error'] ?? 'unknown error')
                    );
                }

                $relativePath = str_replace(App::basePath() . '/', '', $pdfResults[$lang]['pdf_path']);

                $db->insert('private_label_sds', [
                    'finished_good_id' => $finishedGoodId,
                    'manufacturer_id'  => $manufacturerId,
                    'alias_id'         => $aliasId,
                    'language'         => $lang,
                    'version'          => $nextVersion,
                    'status'           => 'published',
                    'effective_date'   => date('Y-m-d'),
                    'published_by'     => current_user_id(),
                    'published_at'     => $now,
                    'snapshot_json'    => json_encode($langData[$lang], JSON_UNESCAPED_UNICODE),
                    'pdf_path'         => $relativePath,
                    'change_summary'   => 'Republished with current hazard data',
                    'created_by'       => current_user_id(),
                ]);

                $publishedVersions[] = strtoupper($lang);
            }

            $productLabel = $alias ? $alias['customer_code'] : $version['product_code'];

            AuditService::log('private_label_sds', (string) $finishedGoodId, 'republish', [
                'manufacturer_id' => $manufacturerId,
                'manufacturer'    => $manufacturer['name'],
                'alias_id'        => $aliasId,
                'version'         => $nextVersion,
                'languages'       => $publishedVersions,
            ]);

            $_SESSION['_flash']['success'] = "Private label SDS v{$nextVersion} republished for {$productLabel} / {$manufacturer['name']}: " . implode(', ', $publishedVersions);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Republish failed: ' . $e->getMessage();
        }

        redirect('/private-label');
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
     * Spawn parallel child processes to render PDFs.
     * Reuses the same pattern as SDSController.
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
            $inputFile  = $tmpDir . '/plpdf_input_' . $lang . '_' . bin2hex(random_bytes(4)) . '.json';
            $resultFile = $tmpDir . '/plpdf_result_' . $lang . '_' . bin2hex(random_bytes(4)) . '.json';

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

    /**
     * Check for missing federal hazard data above the configured threshold.
     *
     * Same logic as SDSController::checkMissingHazardData() — ensures
     * private label SDS documents are not published when critical hazard
     * data is missing.
     */
    private function checkMissingHazardData(array $sdsData, Database $db): ?string
    {
        $blockSetting = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.block_publish_missing'");
        $blockEnabled = $blockSetting ? ($blockSetting['value'] !== '0') : App::config('sds.block_publish_missing', true);

        if (!$blockEnabled) {
            return null;
        }

        $thresholdRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.missing_threshold_pct'");
        $threshold = $thresholdRow ? (float) $thresholdRow['value'] : App::config('sds.missing_threshold_pct', 1.0);

        $missingCas = [];
        foreach ($sdsData['hazard_result']['trace'] ?? [] as $step) {
            if (($step['step'] ?? '') === 'no_data') {
                $cas = $step['data']['cas'] ?? null;
                $conc = $step['data']['concentration_pct'] ?? 0;
                if ($cas !== null && (float) $conc >= $threshold) {
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
