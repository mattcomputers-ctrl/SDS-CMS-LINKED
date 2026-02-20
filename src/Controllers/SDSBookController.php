<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;

/**
 * SDSBookController — Plant SDS book replacement.
 *
 * Provides a searchable directory of all supplier SDS documents (uploaded
 * to raw materials) plus all published finished-good SDS versions, so
 * plant workers can quickly find any Safety Data Sheet in one place.
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
        $total   = 0;

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
            ];
        }

        return $results;
    }

    /**
     * Search published finished-good SDS versions.
     */
    private function searchFinishedGoodSDS(Database $db, string $search): array
    {
        $where  = ["sv.status = 'published'", 'sv.is_deleted = 0'];
        $params = [];

        if ($search !== '') {
            $where[]  = '(fg.product_code LIKE ? OR fg.product_name LIKE ?)';
            $term     = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Get only the latest version per finished good + language
        $rows = $db->fetchAll(
            "SELECT sv.id AS version_id, sv.version, sv.language, sv.effective_date, sv.published_at,
                    fg.id AS fg_id, fg.product_code, fg.product_name
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             {$whereSQL}
             AND sv.version = (
                 SELECT MAX(sv2.version) FROM sds_versions sv2
                 WHERE sv2.finished_good_id = sv.finished_good_id
                   AND sv2.language = sv.language
                   AND sv2.status = 'published'
                   AND sv2.is_deleted = 0
             )
             ORDER BY fg.product_code ASC, sv.language ASC",
            $params
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id'           => $row['version_id'],
                'product_name' => $row['product_code'] . ($row['product_name'] ? ' — ' . $row['product_name'] : ''),
                'supplier'     => '',
                'sds_type'     => 'Finished Good SDS (v' . $row['version'] . ')',
                'view_url'     => '/sds/version/' . (int) $row['version_id'] . '/download',
                'date'         => $row['effective_date'] ?? $row['published_at'] ?? null,
                'language'     => strtoupper($row['language']),
            ];
        }

        return $results;
    }
}
