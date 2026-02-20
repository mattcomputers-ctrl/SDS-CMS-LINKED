<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\AuditService;

/**
 * SDSBookController — Plant SDS book replacement.
 *
 * Provides a searchable directory of all supplier SDS documents (uploaded
 * to raw materials) plus ALL published finished-good SDS versions (every
 * revision is retained), so plant workers can quickly find any Safety
 * Data Sheet in one place.
 */
class SDSBookController
{
    public function index(): void
    {
        $search   = trim($_GET['q'] ?? '');
        $type     = $_GET['type'] ?? 'all'; // all, supplier, finished
        $page     = max(1, (int) ($_GET['page'] ?? 1));
        $perPage  = 25;
        $offset   = ($page - 1) * $perPage;

        $db = Database::getInstance();
        $results = [];

        if ($type === 'all' || $type === 'supplier') {
            $supplierResults = $this->searchSupplierSDS($db, $search);
            foreach ($supplierResults as &$r) {
                $r['source'] = 'supplier';
            }
            unset($r);
            $results = array_merge($results, $supplierResults);
        }

        if ($type === 'all' || $type === 'finished') {
            $fgResults = $this->searchFinishedGoodSDS($db, $search);
            foreach ($fgResults as &$r) {
                $r['source'] = 'finished_good';
            }
            unset($r);
            $results = array_merge($results, $fgResults);
        }

        // Sort by product name
        usort($results, fn($a, $b) => strcasecmp($a['product_name'], $b['product_name']));

        $total   = count($results);
        $pages   = (int) ceil($total / $perPage);
        $results = array_slice($results, $offset, $perPage);

        view('sds-book/index', [
            'pageTitle' => 'SDS Book',
            'results'   => $results,
            'search'    => $search,
            'type'      => $type,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
        ]);
    }

    /**
     * Admin-only: soft-delete a supplier SDS entry (remove the file reference).
     */
    public function deleteSupplierSds(string $id): void
    {
        if (!is_admin()) {
            $_SESSION['_flash']['error'] = 'Only administrators can remove SDS entries.';
            redirect('/sds-book');
        }

        CSRF::validateRequest();

        $db = Database::getInstance();
        $rm = $db->fetch("SELECT id, internal_code, supplier_sds_path FROM raw_materials WHERE id = ?", [(int) $id]);

        if ($rm && !empty($rm['supplier_sds_path'])) {
            $fullPath = \SDS\Core\App::basePath() . '/public/uploads/' . $rm['supplier_sds_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            $db->update('raw_materials', ['supplier_sds_path' => null], 'id = ?', [(int) $id]);
            AuditService::log('sds_book', $id, 'delete_supplier_sds', ['code' => $rm['internal_code']]);
            $_SESSION['_flash']['success'] = 'Supplier SDS removed for ' . $rm['internal_code'] . '.';
        } else {
            $_SESSION['_flash']['error'] = 'SDS entry not found.';
        }

        redirect('/sds-book');
    }

    /**
     * Admin-only: soft-delete a finished good SDS version.
     */
    public function deleteFgSds(string $id): void
    {
        if (!is_admin()) {
            $_SESSION['_flash']['error'] = 'Only administrators can remove SDS entries.';
            redirect('/sds-book');
        }

        CSRF::validateRequest();

        $db = Database::getInstance();
        $db->update('sds_versions', [
            'is_deleted' => 1,
            'deleted_by' => current_user_id(),
            'deleted_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $id]);

        AuditService::log('sds_book', $id, 'soft_delete_fg_sds');
        $_SESSION['_flash']['success'] = 'SDS version removed from the book.';
        redirect('/sds-book');
    }

    /**
     * Search raw materials that have a supplier SDS uploaded.
     */
    private function searchSupplierSDS(Database $db, string $search): array
    {
        $where  = ['rm.supplier_sds_path IS NOT NULL', "rm.supplier_sds_path != ''"];
        $params = [];

        if ($search !== '') {
            $where[]  = '(rm.internal_code LIKE ? OR rm.supplier LIKE ? OR rm.supplier_product_name LIKE ?)';
            $term     = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $rows = $db->fetchAll(
            "SELECT rm.id, rm.internal_code, rm.supplier, rm.supplier_product_name, rm.supplier_sds_path
             FROM raw_materials rm
             {$whereSQL}
             ORDER BY rm.internal_code ASC",
            $params
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id'           => $row['id'],
                'product_name' => $row['internal_code'] . ($row['supplier_product_name'] ? ' — ' . $row['supplier_product_name'] : ''),
                'supplier'     => $row['supplier'] ?? '',
                'sds_type'     => 'Supplier SDS',
                'view_url'     => '/raw-materials/' . (int) $row['id'] . '/sds',
                'date'         => null,
                'language'     => '',
                'version'      => null,
            ];
        }

        return $results;
    }

    /**
     * Search ALL published finished-good SDS versions (every revision is kept).
     */
    private function searchFinishedGoodSDS(Database $db, string $search): array
    {
        $where  = ["sv.status = 'published'", 'sv.is_deleted = 0'];
        $params = [];

        if ($search !== '') {
            $where[]  = '(fg.product_code LIKE ? OR fg.description LIKE ?)';
            $term     = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Return ALL published versions, not just the latest
        $rows = $db->fetchAll(
            "SELECT sv.id AS version_id, sv.version, sv.language, sv.effective_date, sv.published_at,
                    fg.id AS fg_id, fg.product_code, fg.description
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             {$whereSQL}
             ORDER BY fg.product_code ASC, sv.version DESC, sv.language ASC",
            $params
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id'           => $row['version_id'],
                'product_name' => $row['product_code'] . ($row['description'] ? ' — ' . $row['description'] : ''),
                'supplier'     => '',
                'sds_type'     => 'Finished Good SDS',
                'view_url'     => '/sds/version/' . (int) $row['version_id'] . '/download',
                'date'         => $row['effective_date'] ?? $row['published_at'] ?? null,
                'language'     => strtoupper($row['language']),
                'version'      => (int) $row['version'],
            ];
        }

        return $results;
    }
}
