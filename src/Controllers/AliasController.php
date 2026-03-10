<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\AuditService;

/**
 * AliasController — Manage product code aliases.
 *
 * Aliases link customer-facing codes (with pack extensions) to internal
 * finished good product codes. They are stored persistently in the database
 * and used when generating SDS exports.
 */
class AliasController
{
    /* ------------------------------------------------------------------
     *  List aliases
     * ----------------------------------------------------------------*/

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

        view('aliases/index', [
            'pageTitle' => 'Product Aliases',
            'items'     => $items,
            'total'     => $total,
            'filters'   => ['search' => $search, 'page' => $page, 'per_page' => $perPage],
            'pages'     => $pages,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Upload aliases CSV
     * ----------------------------------------------------------------*/

    public function upload(): void
    {
        if (!can_edit('aliases')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/aliases');
        }

        CSRF::validateRequest();

        if (!isset($_FILES['aliases_file']) || $_FILES['aliases_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['_flash']['error'] = 'Please select a valid CSV file to upload.';
            redirect('/aliases');
        }

        $file = $_FILES['aliases_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'], true)) {
            $_SESSION['_flash']['error'] = 'Only CSV files are supported.';
            redirect('/aliases');
        }

        $rows = $this->parseCsv($file['tmp_name']);

        if (empty($rows)) {
            $_SESSION['_flash']['error'] = 'The uploaded file is empty or could not be parsed.';
            redirect('/aliases');
        }

        // Normalize headers and find required columns
        $headers = array_map(fn($h) => strtolower(trim($h)), array_keys($rows[0]));

        $customerCodeCol = $this->findColumn($headers, ['item code', 'item_code', 'itemcode', 'customer code', 'customercode', 'customer_code', 'alias code', 'alias_code', 'alias']);
        $descCol         = $this->findColumn($headers, ['description', 'desc', 'customer description', 'customer_description']);
        $internalCodeCol = $this->findColumn($headers, ['inventory item', 'inventory_item', 'inventoryitem', 'internal code', 'internalcode', 'internal_code', 'item name', 'itemname', 'item_name']);

        if ($customerCodeCol === null || $internalCodeCol === null) {
            $_SESSION['_flash']['error'] = 'CSV must contain "Item Code" and "Inventory Item" columns.';
            redirect('/aliases');
        }

        $db = Database::getInstance();
        $inserted = 0;
        $updated  = 0;

        foreach ($rows as $row) {
            $vals         = array_values($row);
            $customerCode = trim((string) ($vals[$customerCodeCol] ?? ''));
            $description  = $descCol !== null ? trim((string) ($vals[$descCol] ?? '')) : '';
            $internalCode = trim((string) ($vals[$internalCodeCol] ?? ''));

            if ($customerCode === '' || $internalCode === '') {
                continue;
            }

            // Strip pack extension to get the base internal code
            $internalCodeBase = $this->stripPackExtension($internalCode);

            // Upsert: override if customer_code already exists
            $existing = $db->fetch("SELECT id FROM aliases WHERE customer_code = ?", [$customerCode]);

            if ($existing) {
                $db->update('aliases', [
                    'description'       => $description,
                    'internal_code'     => $internalCode,
                    'internal_code_base' => $internalCodeBase,
                ], 'id = ?', [$existing['id']]);
                $updated++;
            } else {
                $db->insert('aliases', [
                    'customer_code'     => $customerCode,
                    'description'       => $description,
                    'internal_code'     => $internalCode,
                    'internal_code_base' => $internalCodeBase,
                ]);
                $inserted++;
            }
        }

        AuditService::log('aliases', '0', 'upload', [
            'inserted' => $inserted,
            'updated'  => $updated,
        ]);

        $_SESSION['_flash']['success'] = "Aliases uploaded: {$inserted} new, {$updated} updated.";
        redirect('/aliases');
    }

    /* ------------------------------------------------------------------
     *  Delete a single alias
     * ----------------------------------------------------------------*/

    public function delete(string $id): void
    {
        if (!can_edit('aliases')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/aliases');
        }

        CSRF::validateRequest();

        $db = Database::getInstance();
        $db->delete('aliases', 'id = ?', [(int) $id]);

        $_SESSION['_flash']['success'] = 'Alias deleted.';
        redirect('/aliases');
    }

    /* ------------------------------------------------------------------
     *  Delete all aliases
     * ----------------------------------------------------------------*/

    public function deleteAll(): void
    {
        if (!can_edit('aliases')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/aliases');
        }

        CSRF::validateRequest();

        $db = Database::getInstance();
        $db->query("DELETE FROM aliases");

        AuditService::log('aliases', '0', 'delete_all');

        $_SESSION['_flash']['success'] = 'All aliases deleted.';
        redirect('/aliases');
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function parseCsv(string $filepath): array
    {
        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if ($headers === false || $headers === [null]) {
            fclose($handle);
            return [];
        }

        // Clean BOM from first header
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) < count($headers)) {
                $line = array_pad($line, count($headers), '');
            }
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $line[$i] ?? '';
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    private function findColumn(array $normalizedHeaders, array $candidates): ?int
    {
        foreach ($normalizedHeaders as $i => $header) {
            foreach ($candidates as $candidate) {
                if ($header === $candidate) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Strip the pack extension from an item code.
     * The pack extension starts with "-".
     */
    private function stripPackExtension(string $itemCode): string
    {
        $pos = strpos($itemCode, '-');
        if ($pos !== false) {
            return substr($itemCode, 0, $pos);
        }
        return $itemCode;
    }
}
