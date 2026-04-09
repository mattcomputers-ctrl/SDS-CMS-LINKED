<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;

/**
 * AliasController — Read-only listing of product code aliases.
 *
 * Aliases are synced from the CMS database via the CMS Import function.
 * Manual upload and deletion have been removed.
 */
class AliasController
{
    public function index(): void
    {
        $db = Database::getInstance();

        $search = trim($_GET['search'] ?? '');
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]  = '(a.customer_code LIKE ? OR a.description LIKE ? OR a.internal_code LIKE ? OR a.internal_code_base LIKE ?)';
            $term     = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countRow = $db->fetch("SELECT COUNT(*) AS cnt FROM aliases a {$whereSQL}", $params);
        $total = (int) ($countRow['cnt'] ?? 0);

        $items = $db->fetchAll(
            "SELECT a.*,
                    fg.id AS fg_id, fg.product_code AS fg_product_code, fg.description AS fg_description
             FROM aliases a
             LEFT JOIN finished_goods fg ON fg.product_code = a.internal_code_base
             {$whereSQL}
             ORDER BY a.customer_code ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $pages = (int) ceil($total / $perPage);

        // Last sync time
        $lastSync = $db->fetch(
            "SELECT MAX(imported_at) AS last_sync FROM cms_import_log WHERE entity_type = 'finished_good'"
        );

        view('aliases/index', [
            'pageTitle' => 'Product Aliases',
            'items'     => $items,
            'total'     => $total,
            'filters'   => ['search' => $search, 'page' => $page, 'per_page' => $perPage],
            'pages'     => $pages,
            'lastSync'  => $lastSync['last_sync'] ?? null,
        ]);
    }
}
