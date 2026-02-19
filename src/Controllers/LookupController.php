<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;
use SDS\Models\FinishedGood;

class LookupController
{
    public function index(): void
    {
        view('lookup/index', [
            'pageTitle' => 'SDS Lookup',
            'results'   => null,
            'query'     => '',
        ]);
    }

    public function search(): void
    {
        $query = trim($_GET['q'] ?? '');
        $results = [];

        if ($query !== '') {
            $results = FinishedGood::search($query, 50);
        }

        view('lookup/index', [
            'pageTitle' => 'SDS Lookup',
            'results'   => $results,
            'query'     => $query,
        ]);
    }

    public function download(string $id): void
    {
        $db = Database::getInstance();

        $version = $db->fetch(
            "SELECT sv.*, fg.product_code
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             WHERE sv.id = ? AND sv.status = 'published' AND sv.is_deleted = 0",
            [(int) $id]
        );

        if ($version === null) {
            $_SESSION['_flash']['error'] = 'SDS not found or not published.';
            redirect('/lookup');
        }

        $pdfPath = \SDS\Core\App::basePath() . '/' . $version['pdf_path'];

        if (!file_exists($pdfPath)) {
            $_SESSION['_flash']['error'] = 'PDF file not found.';
            redirect('/lookup');
        }

        // Audit the download
        $db->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'sds_version',
            'entity_id'   => (string) $id,
            'action'      => 'download',
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="SDS_' . $version['product_code'] . '_v' . $version['version'] . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        exit;
    }
}
