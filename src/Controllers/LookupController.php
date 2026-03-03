<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;
use SDS\Models\FinishedGood;

class LookupController
{
    public function index(): void
    {
        $search  = trim($_GET['q'] ?? '');
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 250;

        $result = FinishedGood::lookupAll($search, $page, $perPage);

        $total = $result['total'];
        $pages = (int) ceil($total / $perPage);

        view('lookup/index', [
            'pageTitle' => 'Finished Goods SDS Lookup',
            'items'     => $result['rows'],
            'total'     => $total,
            'query'     => $search,
            'filters'   => [
                'page'     => $page,
                'per_page' => $perPage,
            ],
            'pages'     => $pages,
        ]);
    }

    public function search(): void
    {
        // Redirect search to the same page with query param
        $this->index();
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
