<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * RawMaterial Model — CRUD, constituent management, formula-usage checks.
 *
 * Each raw material has an internal_code, supplier info, physical properties,
 * and a set of CAS constituents stored in raw_material_constituents.
 */
class RawMaterial
{
    /* ------------------------------------------------------------------
     *  Finders
     * ----------------------------------------------------------------*/

    /**
     * Find a raw material by ID, including its constituents.
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();

        $rm = $db->fetch(
            "SELECT rm.*, u.display_name AS created_by_name
             FROM raw_materials rm
             LEFT JOIN users u ON u.id = rm.created_by
             WHERE rm.id = ?",
            [$id]
        );

        if (!$rm) {
            return null;
        }

        $rm['constituents'] = self::getConstituents($id);

        return $rm;
    }

    /**
     * Find by internal_code (unique).
     */
    public static function findByCode(string $code): ?array
    {
        $db = Database::getInstance();
        return $db->fetch(
            "SELECT * FROM raw_materials WHERE internal_code = ?",
            [$code]
        );
    }

    /* ------------------------------------------------------------------
     *  Listing & Pagination
     * ----------------------------------------------------------------*/

    /**
     * Return a paginated list of raw materials.
     *
     * Supported $filters:
     *   - search     (string)  partial match on internal_code, supplier, supplier_product_name
     *   - supplier   (string)  exact supplier match
     *   - page       (int)     default 1
     *   - per_page   (int)     default 25, max 100
     *   - sort       (string)  column name, default 'internal_code'
     *   - dir        (string)  'asc' or 'desc', default 'asc'
     */
    public static function all(array $filters = []): array
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(rm.internal_code LIKE ? OR rm.supplier LIKE ? OR rm.supplier_product_name LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (!empty($filters['supplier'])) {
            $where[]  = 'rm.supplier = ?';
            $params[] = $filters['supplier'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $allowedSorts = [
            'id', 'internal_code', 'supplier', 'supplier_product_name',
            'created_at', 'updated_at',
        ];
        $sort = in_array($filters['sort'] ?? '', $allowedSorts, true)
            ? $filters['sort']
            : 'internal_code';
        $dir = strtolower($filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT rm.*, u.display_name AS created_by_name
                FROM raw_materials rm
                LEFT JOIN users u ON u.id = rm.created_by
                {$whereSQL}
                ORDER BY rm.`{$sort}` {$dir}
                LIMIT {$perPage} OFFSET {$offset}";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Count raw materials matching filters.
     */
    public static function count(array $filters = []): int
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(internal_code LIKE ? OR supplier LIKE ? OR supplier_product_name LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (!empty($filters['supplier'])) {
            $where[]  = 'supplier = ?';
            $params[] = $filters['supplier'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $row = $db->fetch("SELECT COUNT(*) AS cnt FROM raw_materials {$whereSQL}", $params);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Search raw materials by internal code or supplier product name.
     * Returns a lightweight result set for autocomplete / selectors.
     */
    public static function search(string $term, int $limit = 50): array
    {
        $db   = Database::getInstance();
        $like = '%' . $term . '%';

        return $db->fetchAll(
            "SELECT id, internal_code, supplier, supplier_product_name
             FROM raw_materials
             WHERE internal_code LIKE ? OR supplier_product_name LIKE ?
             ORDER BY internal_code ASC
             LIMIT ?",
            [$like, $like, $limit]
        );
    }

    /* ------------------------------------------------------------------
     *  Create / Update / Delete
     * ----------------------------------------------------------------*/

    /**
     * Create a new raw material.
     *
     * @return int  New raw material ID.
     * @throws \InvalidArgumentException on validation failure.
     * @throws \RuntimeException on duplicate code.
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $code = trim($data['internal_code'] ?? '');
        if ($code === '') {
            throw new \InvalidArgumentException('Internal code is required.');
        }

        $existing = $db->fetch("SELECT id FROM raw_materials WHERE internal_code = ?", [$code]);
        if ($existing) {
            throw new \RuntimeException("A raw material with code '{$code}' already exists.");
        }

        // Helper: convert empty strings to null for numeric columns
        $numOrNull = static fn($key) => isset($data[$key]) && $data[$key] !== '' ? $data[$key] : null;
        $strOrNull = static fn($key) => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        $insertData = [
            'internal_code'         => $code,
            'supplier'              => trim($data['supplier'] ?? ''),
            'supplier_product_name' => trim($data['supplier_product_name'] ?? ''),
            'supplier_sds_path'     => $data['supplier_sds_path'] ?? null,
            'voc_wt'                => $numOrNull('voc_wt'),
            'exempt_voc_wt'         => $numOrNull('exempt_voc_wt'),
            'water_wt'              => $numOrNull('water_wt'),
            'specific_gravity'      => $numOrNull('specific_gravity'),
            'density'               => $numOrNull('density'),
            'density_units'         => $data['density_units'] ?? 'g/mL',
            'temp_ref_c'            => $numOrNull('temp_ref_c'),
            'solids_wt'             => $numOrNull('solids_wt'),
            'solids_vol'            => $numOrNull('solids_vol'),
            'flash_point_c'         => $numOrNull('flash_point_c'),
            'physical_state'        => $strOrNull('physical_state'),
            'appearance'            => $strOrNull('appearance'),
            'odor'                  => $strOrNull('odor'),
            'notes'                 => $strOrNull('notes'),
            'created_by'            => $data['created_by'] ?? null,
        ];

        $id = $db->insert('raw_materials', $insertData);
        return (int) $id;
    }

    /**
     * Update a raw material with optimistic locking.
     *
     * @param int   $id
     * @param array $data  Must include 'updated_at' for concurrency check.
     * @return int  Affected rows (0 means conflict or not found).
     * @throws \RuntimeException on concurrency conflict.
     */
    public static function update(int $id, array $data): int
    {
        $db = Database::getInstance();

        // Optimistic locking: check updated_at matches
        $expectedUpdatedAt = $data['expected_updated_at'] ?? ($data['updated_at'] ?? null);
        if ($expectedUpdatedAt !== null) {
            $current = $db->fetch("SELECT updated_at FROM raw_materials WHERE id = ?", [$id]);
            if (!$current) {
                throw new \RuntimeException("Raw material #{$id} not found.");
            }
            if ($current['updated_at'] !== $expectedUpdatedAt) {
                throw new \RuntimeException(
                    'This record has been modified by another user. Please reload and try again.'
                );
            }
        }

        // If renaming code, check uniqueness
        if (isset($data['internal_code']) && trim($data['internal_code']) !== '') {
            $dup = $db->fetch(
                "SELECT id FROM raw_materials WHERE internal_code = ? AND id != ?",
                [trim($data['internal_code']), $id]
            );
            if ($dup) {
                throw new \RuntimeException("Code '{$data['internal_code']}' is already in use.");
            }
        }

        $allowed = [
            'internal_code', 'supplier', 'supplier_product_name', 'supplier_sds_path',
            'voc_wt', 'exempt_voc_wt', 'water_wt', 'specific_gravity', 'density',
            'density_units', 'temp_ref_c', 'solids_wt', 'solids_vol', 'flash_point_c',
            'physical_state', 'appearance', 'odor', 'notes',
        ];

        // Numeric columns that must be null instead of empty string
        $numericCols = [
            'voc_wt', 'exempt_voc_wt', 'water_wt', 'specific_gravity', 'density',
            'temp_ref_c', 'solids_wt', 'solids_vol', 'flash_point_c',
        ];

        $updateData = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $val = is_string($data[$col]) ? trim($data[$col]) : $data[$col];
                // Convert empty strings to null for numeric columns
                if (in_array($col, $numericCols, true) && $val === '') {
                    $val = null;
                }
                $updateData[$col] = $val;
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $db->update('raw_materials', $updateData, 'id = ?', [$id]);
    }

    /**
     * Delete a raw material, but only if it is not used in any formula.
     *
     * @throws \RuntimeException if raw material is referenced by a formula line.
     */
    public static function delete(int $id): int
    {
        $db = Database::getInstance();

        $usage = self::getUsedInFormulas($id);
        if (!empty($usage)) {
            $codes = array_column($usage, 'product_code');
            throw new \RuntimeException(
                'Cannot delete: raw material is used in formula(s) for: ' . implode(', ', $codes)
            );
        }

        // Constituents are deleted via ON DELETE CASCADE
        return $db->delete('raw_materials', 'id = ?', [$id]);
    }

    /* ------------------------------------------------------------------
     *  Constituents
     * ----------------------------------------------------------------*/

    /**
     * Get all constituents for a raw material, ordered by sort_order.
     */
    public static function getConstituents(int $rmId): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT rmc.*, cm.preferred_name AS cas_preferred_name
             FROM raw_material_constituents rmc
             LEFT JOIN cas_master cm ON cm.cas_number = rmc.cas_number
             WHERE rmc.raw_material_id = ?
             ORDER BY rmc.sort_order ASC, rmc.id ASC",
            [$rmId]
        );
    }

    /**
     * Replace all constituents for a raw material (delete + insert).
     *
     * Each constituent array should contain:
     *   cas_number, chemical_name, pct_min, pct_max, pct_exact,
     *   is_trade_secret, sort_order
     *
     * Runs in a transaction to keep data consistent.
     */
    public static function saveConstituents(int $rmId, array $constituents): void
    {
        $db = Database::getInstance();

        $db->beginTransaction();
        try {
            // Delete existing
            $db->delete('raw_material_constituents', 'raw_material_id = ?', [$rmId]);

            // Insert new
            foreach ($constituents as $i => $c) {
                $db->insert('raw_material_constituents', [
                    'raw_material_id' => $rmId,
                    'cas_number'      => trim($c['cas_number'] ?? ''),
                    'chemical_name'   => trim($c['chemical_name'] ?? ''),
                    'pct_min'         => $c['pct_min'] ?? null,
                    'pct_max'         => $c['pct_max'] ?? null,
                    'pct_exact'       => $c['pct_exact'] ?? null,
                    'is_trade_secret' => (int) ($c['is_trade_secret'] ?? 0),
                    'sort_order'      => (int) ($c['sort_order'] ?? $i + 1),
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------
     *  SDS History
     * ----------------------------------------------------------------*/

    /**
     * Get all SDS uploads for a raw material, newest first.
     */
    public static function getSdsHistory(int $rmId): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT rms.*, u.display_name AS uploaded_by_name
             FROM raw_material_sds rms
             LEFT JOIN users u ON u.id = rms.uploaded_by
             WHERE rms.raw_material_id = ?
             ORDER BY rms.uploaded_at DESC",
            [$rmId]
        );
    }

    /**
     * Get the newest (current) SDS for a raw material.
     */
    public static function getCurrentSds(int $rmId): ?array
    {
        $db = Database::getInstance();
        return $db->fetch(
            "SELECT rms.*, u.display_name AS uploaded_by_name
             FROM raw_material_sds rms
             LEFT JOIN users u ON u.id = rms.uploaded_by
             WHERE rms.raw_material_id = ?
             ORDER BY rms.uploaded_at DESC
             LIMIT 1",
            [$rmId]
        );
    }

    /**
     * Add a new SDS file to the history (never overwrites previous entries).
     *
     * @return int  New raw_material_sds ID.
     */
    public static function addSds(int $rmId, string $filePath, string $originalFilename, ?int $fileSize, ?string $notes, ?int $userId): int
    {
        $db = Database::getInstance();

        $id = (int) $db->insert('raw_material_sds', [
            'raw_material_id'   => $rmId,
            'file_path'         => $filePath,
            'original_filename' => $originalFilename,
            'file_size'         => $fileSize,
            'notes'             => $notes,
            'uploaded_by'       => $userId,
        ]);

        // Also update the legacy supplier_sds_path to point to the newest SDS
        $db->update('raw_materials', ['supplier_sds_path' => $filePath], 'id = ?', [$rmId]);

        return $id;
    }

    /**
     * Look up a CAS number in cas_master and regulatory tables to find its chemical name.
     */
    public static function lookupCas(string $cas): ?array
    {
        $db = Database::getInstance();

        // Try cas_master first
        $row = $db->fetch(
            "SELECT cas_number, preferred_name AS chemical_name FROM cas_master WHERE cas_number = ?",
            [$cas]
        );
        if ($row && !empty($row['chemical_name'])) {
            return $row;
        }

        // Try prop65_list
        $row = $db->fetch(
            "SELECT cas_number, chemical_name FROM prop65_list WHERE cas_number = ?",
            [$cas]
        );
        if ($row && !empty($row['chemical_name'])) {
            return $row;
        }

        // Try sara313_list
        $row = $db->fetch(
            "SELECT cas_number, chemical_name FROM sara313_list WHERE cas_number = ?",
            [$cas]
        );
        if ($row && !empty($row['chemical_name'])) {
            return $row;
        }

        // Try carcinogen_list
        $row = $db->fetch(
            "SELECT cas_number, chemical_name FROM carcinogen_list WHERE cas_number = ? LIMIT 1",
            [$cas]
        );
        if ($row && !empty($row['chemical_name'])) {
            return $row;
        }

        // Try hap_list
        $row = $db->fetch(
            "SELECT cas_number, chemical_name FROM hap_list WHERE cas_number = ?",
            [$cas]
        );
        if ($row && !empty($row['chemical_name'])) {
            return $row;
        }

        return null;
    }

    /* ------------------------------------------------------------------
     *  Formula usage check
     * ----------------------------------------------------------------*/

    /**
     * Return formula/finished-good info for every formula that uses this raw material.
     *
     * @return array  List of rows with formula_id, finished_good_id, product_code, version.
     */
    public static function getUsedInFormulas(int $id): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT DISTINCT f.id AS formula_id, f.finished_good_id,
                    fg.product_code, f.version, f.is_current
             FROM formula_lines fl
             JOIN formulas f ON f.id = fl.formula_id
             JOIN finished_goods fg ON fg.id = f.finished_good_id
             WHERE fl.raw_material_id = ?
             ORDER BY fg.product_code, f.version DESC",
            [$id]
        );
    }
}
