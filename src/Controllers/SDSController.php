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
             ORDER BY sv.language ASC, sv.version DESC",
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

    public function publish(string $finished_good_id): void
    {
        if (!is_editor()) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds/' . $finished_good_id);
        }

        CSRF::validateRequest();

        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $language = $_POST['language'] ?? 'en';
        $changeSummary = trim($_POST['change_summary'] ?? '');

        $db = Database::getInstance();

        try {
            // Generate SDS data
            $generator = new SDSGenerator();
            $sdsData   = $generator->generate((int) $finished_good_id, $language);

            // Generate PDF
            $pdfService = new PDFService();
            $pdfPath    = $pdfService->generate($sdsData);
            $relativePath = str_replace(\SDS\Core\App::basePath() . '/', '', $pdfPath);

            // Determine next version number for this language
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions
                 WHERE finished_good_id = ? AND language = ?",
                [(int) $finished_good_id, $language]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

            // Create the published SDS version record
            $versionId = $db->insert('sds_versions', [
                'finished_good_id' => (int) $finished_good_id,
                'language'         => $language,
                'version'          => $nextVersion,
                'status'           => 'published',
                'effective_date'   => date('Y-m-d'),
                'published_by'     => current_user_id(),
                'published_at'     => date('Y-m-d H:i:s'),
                'snapshot_json'    => json_encode($sdsData, JSON_UNESCAPED_UNICODE),
                'pdf_path'         => $relativePath,
                'change_summary'   => $changeSummary ?: null,
                'created_by'       => current_user_id(),
            ]);

            // Store generation trace
            $traceData = array_merge(
                $sdsData['hazard_result']['trace'] ?? [],
                $sdsData['voc_result']['trace'] ?? []
            );
            $db->insert('sds_generation_trace', [
                'sds_version_id' => $versionId,
                'trace_json'     => json_encode($traceData, JSON_UNESCAPED_UNICODE),
            ]);

            AuditService::log('sds_version', $versionId, 'publish', [
                'finished_good_id' => $finished_good_id,
                'language'         => $language,
                'version'          => $nextVersion,
            ]);

            $_SESSION['_flash']['success'] = "SDS v{$nextVersion} ({$language}) published successfully.";
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Publish failed: ' . $e->getMessage();
        }

        redirect('/sds/' . $finished_good_id);
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
}
