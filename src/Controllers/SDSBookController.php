<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\AuditService;

/**
 * SDSBookController — Raw Material SDS Book.
 *
 * Provides a searchable directory of all supplier SDS documents uploaded
 * to raw materials, showing the newest (current) SDS for each raw material.
 * Previous SDS versions are maintained in the raw_material_sds history table
 * and can be viewed from each raw material's edit page.
 */
class SDSBookController
{
    public function index(): void
    {
        $search  = trim($_GET['q'] ?? '');
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $db = Database::getInstance();
        $results = $this->searchSupplierSDS($db, $search);

        // Sort by product name
        usort($results, fn($a, $b) => strcasecmp($a['product_name'], $b['product_name']));

        $total   = count($results);
        $pages   = (int) ceil($total / $perPage);
        $results = array_slice($results, $offset, $perPage);

        view('sds-book/index', [
            'pageTitle' => 'Raw Material SDS Book',
            'results'   => $results,
            'search'    => $search,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
        ]);
    }

    /**
     * Public RM SDS Book — accessible without login.
     * Shows only RM Code with a View SDS button.
     */
    public function publicIndex(): void
    {
        $search  = trim($_GET['q'] ?? '');
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $db = Database::getInstance();
        $results = $this->searchSupplierSDS($db, $search);

        // Sort by RM code
        usort($results, fn($a, $b) => strcasecmp($a['product_name'], $b['product_name']));

        $total   = count($results);
        $pages   = (int) ceil($total / $perPage);
        $results = array_slice($results, $offset, $perPage);

        view('sds-book/public', [
            'pageTitle' => 'RM SDS Book',
            'results'   => $results,
            'search'    => $search,
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
        if (!can_manage_users()) {
            $_SESSION['_flash']['error'] = 'Only administrators can remove SDS entries.';
            redirect('/sds-book');
        }

        CSRF::validateRequest();

        $db = Database::getInstance();
        $rm = $db->fetch("SELECT id, internal_code, supplier_sds_path FROM raw_materials WHERE id = ?", [(int) $id]);

        if ($rm && !empty($rm['supplier_sds_path'])) {
            // Clear the current pointer but DON'T delete the file — it's in history
            $db->update('raw_materials', ['supplier_sds_path' => null], 'id = ?', [(int) $id]);
            AuditService::log('sds_book', $id, 'remove_current_sds', ['code' => $rm['internal_code']]);
            $_SESSION['_flash']['success'] = 'Current SDS cleared for ' . $rm['internal_code'] . '. Historical SDSs are preserved.';
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
        if (!can_manage_users()) {
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
     * Uses the raw_material_sds history table to show the newest SDS for each raw material.
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

        // Join with raw_material_sds to get the newest SDS date and version count
        $rows = $db->fetchAll(
            "SELECT rm.id, rm.internal_code, rm.supplier, rm.supplier_product_name, rm.supplier_sds_path,
                    newest.uploaded_at AS sds_date,
                    newest.original_filename,
                    (SELECT COUNT(*) FROM raw_material_sds WHERE raw_material_id = rm.id) AS sds_count
             FROM raw_materials rm
             LEFT JOIN (
                 SELECT rms1.raw_material_id, rms1.uploaded_at, rms1.original_filename
                 FROM raw_material_sds rms1
                 INNER JOIN (
                     SELECT raw_material_id, MAX(id) AS max_id
                     FROM raw_material_sds
                     GROUP BY raw_material_id
                 ) rms2 ON rms1.id = rms2.max_id
             ) newest ON newest.raw_material_id = rm.id
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
                'view_url'     => '/raw-materials/' . (int) $row['id'] . '/sds',
                'edit_url'     => '/raw-materials/' . (int) $row['id'] . '/edit',
                'date'         => $row['sds_date'] ?? null,
                'sds_count'    => (int) ($row['sds_count'] ?? 0),
                'filename'     => $row['original_filename'] ?? basename($row['supplier_sds_path']),
            ];
        }

        return $results;
    }
}
