<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * FinishedGood Model — products for which SDS documents are authored.
 *
 * Each finished good has a product_code, description, and family (ink type).
 * The search() method powers the SDS Lookup page with latest published SDS info.
 */
class FinishedGood
{
    /* ------------------------------------------------------------------
     *  Finders
     * ----------------------------------------------------------------*/

    /**
     * Find a finished good by primary key.
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->fetch(
            "SELECT fg.*, u.display_name AS created_by_name
             FROM finished_goods fg
             LEFT JOIN users u ON u.id = fg.created_by
             WHERE fg.id = ?",
            [$id]
        );
    }

    /**
     * Find a finished good by its unique product code.
     */
    public static function findByProductCode(string $code): ?array
    {
        $db = Database::getInstance();
        return $db->fetch(
            "SELECT * FROM finished_goods WHERE product_code = ?",
            [$code]
        );
    }

    /* ------------------------------------------------------------------
     *  Listing & Pagination
     * ----------------------------------------------------------------*/

    /**
     * Return a paginated list of finished goods.
     *
     * Supported $filters:
     *   - search     (string)  partial match on product_code, description
     *   - family     (string)  exact match on family
     *   - is_active  (int)     0 or 1
     *   - page       (int)     default 1
     *   - per_page   (int)     default 25, max 100
     *   - sort       (string)  column name, default 'product_code'
     *   - dir        (string)  'asc' or 'desc', default 'asc'
     */
    public static function all(array $filters = []): array
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(fg.product_code LIKE ? OR fg.description LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        if (!empty($filters['family'])) {
            $where[]  = 'fg.family = ?';
            $params[] = $filters['family'];
        }

        if (isset($filters['is_active'])) {
            $where[]  = 'fg.is_active = ?';
            $params[] = (int) $filters['is_active'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $allowedSorts = ['id', 'product_code', 'description', 'family', 'created_at', 'updated_at'];
        $sort = in_array($filters['sort'] ?? '', $allowedSorts, true)
            ? $filters['sort']
            : 'product_code';
        $dir = strtolower($filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT fg.*, u.display_name AS created_by_name
                FROM finished_goods fg
                LEFT JOIN users u ON u.id = fg.created_by
                {$whereSQL}
                ORDER BY fg.`{$sort}` {$dir}
                LIMIT {$perPage} OFFSET {$offset}";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Count finished goods matching the given filters.
     */
    public static function count(array $filters = []): int
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(product_code LIKE ? OR description LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        if (!empty($filters['family'])) {
            $where[]  = 'family = ?';
            $params[] = $filters['family'];
        }

        if (isset($filters['is_active'])) {
            $where[]  = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $row = $db->fetch("SELECT COUNT(*) AS cnt FROM finished_goods {$whereSQL}", $params);
        return (int) ($row['cnt'] ?? 0);
    }

    /* ------------------------------------------------------------------
     *  SDS Lookup Search
     * ----------------------------------------------------------------*/

    /**
     * Search finished goods for the SDS Lookup page.
     *
     * Priority ordering:
     *   1. Exact product_code match first
     *   2. Partial product_code match (LIKE)
     *   3. Full-text description match
     *
     * Each result includes the latest published SDS info per language.
     *
     * @return array  Rows with: product_code, description, family,
     *                latest_version, latest_date, has_en, has_es, has_fr, has_de
     */
    public static function search(string $term, int $limit = 50): array
    {
        $db   = Database::getInstance();
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $like = '%' . $term . '%';

        // Build a combined query using UNION to prioritize exact match,
        // then partial code match, then fulltext description match.
        // We use a relevance score to order the results.
        $sql = "
            SELECT fg.id, fg.product_code, fg.description, fg.family, fg.is_active,
                   sv_en.version AS latest_version_en,
                   sv_en.published_at AS latest_date_en,
                   sv_es.version AS latest_version_es,
                   sv_es.published_at AS latest_date_es,
                   sv_fr.version AS latest_version_fr,
                   sv_fr.published_at AS latest_date_fr,
                   sv_de.version AS latest_version_de,
                   sv_de.published_at AS latest_date_de,
                   CASE
                       WHEN fg.product_code = ? THEN 3
                       WHEN fg.product_code LIKE ? THEN 2
                       ELSE 1
                   END AS relevance
            FROM finished_goods fg
            LEFT JOIN (
                SELECT sv1.finished_good_id, sv1.version, sv1.published_at
                FROM sds_versions sv1
                INNER JOIN (
                    SELECT finished_good_id, MAX(version) AS max_ver
                    FROM sds_versions
                    WHERE status = 'published' AND language = 'en' AND is_deleted = 0
                    GROUP BY finished_good_id
                ) sv1_max ON sv1.finished_good_id = sv1_max.finished_good_id
                         AND sv1.version = sv1_max.max_ver
                         AND sv1.language = 'en'
                         AND sv1.status = 'published'
                         AND sv1.is_deleted = 0
            ) sv_en ON sv_en.finished_good_id = fg.id
            LEFT JOIN (
                SELECT sv2.finished_good_id, sv2.version, sv2.published_at
                FROM sds_versions sv2
                INNER JOIN (
                    SELECT finished_good_id, MAX(version) AS max_ver
                    FROM sds_versions
                    WHERE status = 'published' AND language = 'es' AND is_deleted = 0
                    GROUP BY finished_good_id
                ) sv2_max ON sv2.finished_good_id = sv2_max.finished_good_id
                         AND sv2.version = sv2_max.max_ver
                         AND sv2.language = 'es'
                         AND sv2.status = 'published'
                         AND sv2.is_deleted = 0
            ) sv_es ON sv_es.finished_good_id = fg.id
            LEFT JOIN (
                SELECT sv3.finished_good_id, sv3.version, sv3.published_at
                FROM sds_versions sv3
                INNER JOIN (
                    SELECT finished_good_id, MAX(version) AS max_ver
                    FROM sds_versions
                    WHERE status = 'published' AND language = 'fr' AND is_deleted = 0
                    GROUP BY finished_good_id
                ) sv3_max ON sv3.finished_good_id = sv3_max.finished_good_id
                         AND sv3.version = sv3_max.max_ver
                         AND sv3.language = 'fr'
                         AND sv3.status = 'published'
                         AND sv3.is_deleted = 0
            ) sv_fr ON sv_fr.finished_good_id = fg.id
            LEFT JOIN (
                SELECT sv4.finished_good_id, sv4.version, sv4.published_at
                FROM sds_versions sv4
                INNER JOIN (
                    SELECT finished_good_id, MAX(version) AS max_ver
                    FROM sds_versions
                    WHERE status = 'published' AND language = 'de' AND is_deleted = 0
                    GROUP BY finished_good_id
                ) sv4_max ON sv4.finished_good_id = sv4_max.finished_good_id
                         AND sv4.version = sv4_max.max_ver
                         AND sv4.language = 'de'
                         AND sv4.status = 'published'
                         AND sv4.is_deleted = 0
            ) sv_de ON sv_de.finished_good_id = fg.id
            WHERE fg.product_code = ?
               OR fg.product_code LIKE ?
               OR MATCH(fg.description) AGAINST(? IN BOOLEAN MODE)
            ORDER BY relevance DESC, fg.product_code ASC
            LIMIT ?
        ";

        // For BOOLEAN MODE, append * for prefix matching
        $ftTerm = $term . '*';

        $rows = $db->fetchAll($sql, [$term, $like, $term, $like, $ftTerm, $limit]);

        // Post-process: add convenience booleans
        foreach ($rows as &$row) {
            $row['has_en'] = $row['latest_version_en'] !== null;
            $row['has_es'] = $row['latest_version_es'] !== null;
            $row['has_fr'] = $row['latest_version_fr'] !== null;
            $row['has_de'] = $row['latest_version_de'] !== null;
            $row['latest_version'] = $row['latest_version_en'];
            $row['latest_date']    = $row['latest_date_en'];
            unset($row['relevance']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Return paginated finished goods with their latest published SDS info per language.
     *
     * If $searchTerm is provided, filters by product_code or description.
     * Results ordered by product_code ASC.
     *
     * @return array{rows: array, total: int}
     */
    public static function lookupAll(string $searchTerm = '', int $page = 1, int $perPage = 250): array
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if ($searchTerm !== '') {
            $where[]  = '(fg.product_code LIKE ? OR fg.description LIKE ?)';
            $like     = '%' . $searchTerm . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countRow = $db->fetch(
            "SELECT COUNT(*) AS cnt FROM finished_goods fg {$whereSQL}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        // Build paginated query with latest SDS version per language
        $offset = ($page - 1) * $perPage;

        // Latest version subquery (reusable for each language)
        $latestVersionJoin = function (string $alias, string $lang) {
            return "LEFT JOIN (
                SELECT sv.finished_good_id, sv.id AS sds_id, sv.version, sv.published_at
                FROM sds_versions sv
                INNER JOIN (
                    SELECT finished_good_id, MAX(version) AS max_ver
                    FROM sds_versions
                    WHERE status = 'published' AND language = '{$lang}' AND is_deleted = 0
                    GROUP BY finished_good_id
                ) mx ON sv.finished_good_id = mx.finished_good_id
                     AND sv.version = mx.max_ver
                     AND sv.language = '{$lang}'
                     AND sv.status = 'published'
                     AND sv.is_deleted = 0
            ) {$alias} ON {$alias}.finished_good_id = fg.id";
        };

        $sql = "SELECT fg.id, fg.product_code, fg.description, fg.family, fg.is_active,
                       sv_en.version AS ver_en, sv_en.published_at AS date_en, sv_en.sds_id AS sds_id_en,
                       sv_es.version AS ver_es, sv_es.published_at AS date_es, sv_es.sds_id AS sds_id_es,
                       sv_fr.version AS ver_fr, sv_fr.published_at AS date_fr, sv_fr.sds_id AS sds_id_fr,
                       sv_de.version AS ver_de, sv_de.published_at AS date_de, sv_de.sds_id AS sds_id_de
                FROM finished_goods fg
                {$latestVersionJoin('sv_en', 'en')}
                {$latestVersionJoin('sv_es', 'es')}
                {$latestVersionJoin('sv_fr', 'fr')}
                {$latestVersionJoin('sv_de', 'de')}
                {$whereSQL}
                ORDER BY fg.product_code ASC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $db->fetchAll($sql, $params);

        // Post-process
        foreach ($rows as &$row) {
            $row['has_en'] = $row['ver_en'] !== null;
            $row['has_es'] = $row['ver_es'] !== null;
            $row['has_fr'] = $row['ver_fr'] !== null;
            $row['has_de'] = $row['ver_de'] !== null;
            // Overall latest version (English takes priority)
            $row['latest_version'] = $row['ver_en'] ?? $row['ver_es'] ?? $row['ver_fr'] ?? $row['ver_de'];
            $row['latest_date']    = $row['date_en'] ?? $row['date_es'] ?? $row['date_fr'] ?? $row['date_de'];
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /* ------------------------------------------------------------------
     *  Alias-based SDS Lookup
     * ----------------------------------------------------------------*/

    /**
     * Return paginated aliases with their latest published SDS info per language.
     *
     * Each row represents an alias (customer-facing product code) linked to
     * a finished good. The product code shown is the alias customer_code
     * and the description is the alias description.
     *
     * @return array{rows: array, total: int}
     */
    public static function lookupAllAliases(string $searchTerm = '', int $page = 1, int $perPage = 250): array
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if ($searchTerm !== '') {
            $like     = '%' . $searchTerm . '%';
            // WHERE for outer queries using alias "a"
            $where[]  = '(a.customer_code LIKE ? OR a.description LIKE ? OR SUBSTRING_INDEX(a.customer_code, \'-\', 1) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Build a matching WHERE for the representative subquery (alias "a2")
        $where2 = [];
        $params2 = [];
        if ($searchTerm !== '') {
            $where2[] = '(a2.customer_code LIKE ? OR a2.description LIKE ? OR SUBSTRING_INDEX(a2.customer_code, \'-\', 1) LIKE ?)';
            $params2[] = $like;
            $params2[] = $like;
            $params2[] = $like;
        }
        $whereSQL2 = $where2 ? 'WHERE ' . implode(' AND ', $where2) : '';

        // Count total unique base customer codes (pack extension agnostic)
        $countRow = $db->fetch(
            "SELECT COUNT(DISTINCT SUBSTRING_INDEX(a.customer_code, '-', 1)) AS cnt
             FROM aliases a
             INNER JOIN finished_goods fg ON fg.product_code = a.internal_code_base
             {$whereSQL}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $offset = ($page - 1) * $perPage;

        // Pick one representative alias per base customer code (lowest id)
        // This ensures we show one row per product regardless of pack extensions
        $representativeAlias = "INNER JOIN (
                SELECT MIN(a2.id) AS rep_id
                FROM aliases a2
                INNER JOIN finished_goods fg2 ON fg2.product_code = a2.internal_code_base
                {$whereSQL2}
                GROUP BY SUBSTRING_INDEX(a2.customer_code, '-', 1)
                ORDER BY SUBSTRING_INDEX(a2.customer_code, '-', 1) ASC
                LIMIT {$perPage} OFFSET {$offset}
            ) rep ON rep.rep_id = a.id";

        // Latest version subquery per language, scoped to alias_id
        $latestVersionJoin = function (string $alias, string $lang) {
            return "LEFT JOIN (
                SELECT sv.alias_id, sv.id AS sds_id, sv.version, sv.published_at
                FROM sds_versions sv
                INNER JOIN (
                    SELECT alias_id, MAX(version) AS max_ver
                    FROM sds_versions
                    WHERE status = 'published' AND language = '{$lang}' AND is_deleted = 0 AND alias_id IS NOT NULL
                    GROUP BY alias_id
                ) mx ON sv.alias_id = mx.alias_id
                     AND sv.version = mx.max_ver
                     AND sv.language = '{$lang}'
                     AND sv.status = 'published'
                     AND sv.is_deleted = 0
            ) {$alias} ON {$alias}.alias_id = a.id";
        };

        // The representative alias subquery already handles pagination and filtering,
        // so we don't need WHERE or LIMIT/OFFSET on the outer query
        $sql = "SELECT a.id AS alias_id, a.customer_code, a.description AS alias_description,
                       fg.id AS fg_id, fg.family, fg.is_active,
                       sv_en.version AS ver_en, sv_en.published_at AS date_en, sv_en.sds_id AS sds_id_en,
                       sv_es.version AS ver_es, sv_es.published_at AS date_es, sv_es.sds_id AS sds_id_es,
                       sv_fr.version AS ver_fr, sv_fr.published_at AS date_fr, sv_fr.sds_id AS sds_id_fr,
                       sv_de.version AS ver_de, sv_de.published_at AS date_de, sv_de.sds_id AS sds_id_de
                FROM aliases a
                INNER JOIN finished_goods fg ON fg.product_code = a.internal_code_base
                {$representativeAlias}
                {$latestVersionJoin('sv_en', 'en')}
                {$latestVersionJoin('sv_es', 'es')}
                {$latestVersionJoin('sv_fr', 'fr')}
                {$latestVersionJoin('sv_de', 'de')}
                ORDER BY a.customer_code ASC";

        // The representative subquery uses its own params (a2 alias)
        $queryParams = $params2;
        $rows = $db->fetchAll($sql, $queryParams);

        // Post-process: strip pack extension from displayed product code
        foreach ($rows as &$row) {
            $baseCode = strpos($row['customer_code'], '-') !== false
                ? substr($row['customer_code'], 0, strpos($row['customer_code'], '-'))
                : $row['customer_code'];
            $row['product_code'] = $baseCode;
            $row['description']  = $row['alias_description'];
            $row['has_en'] = $row['ver_en'] !== null;
            $row['has_es'] = $row['ver_es'] !== null;
            $row['has_fr'] = $row['ver_fr'] !== null;
            $row['has_de'] = $row['ver_de'] !== null;
            $row['latest_version'] = $row['ver_en'] ?? $row['ver_es'] ?? $row['ver_fr'] ?? $row['ver_de'];
            $row['latest_date']    = $row['date_en'] ?? $row['date_es'] ?? $row['date_fr'] ?? $row['date_de'];
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /* ------------------------------------------------------------------
     *  Create / Update
     * ----------------------------------------------------------------*/

    /**
     * Create a new finished good.
     *
     * @return int  New finished good ID.
     * @throws \InvalidArgumentException on validation failure.
     * @throws \RuntimeException on duplicate product_code.
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $code = trim($data['product_code'] ?? '');
        if ($code === '') {
            throw new \InvalidArgumentException('Product code is required.');
        }

        $existing = $db->fetch("SELECT id FROM finished_goods WHERE product_code = ?", [$code]);
        if ($existing) {
            throw new \RuntimeException("A finished good with code '{$code}' already exists.");
        }

        $strOrNull = static fn($key) => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        $insertData = [
            'product_code'       => $code,
            'description'        => trim($data['description'] ?? ''),
            'family'             => $data['family'] ?? null,
            'recommended_use'    => $strOrNull('recommended_use'),
            'restrictions_on_use' => $strOrNull('restrictions_on_use'),
            'physical_state'     => $strOrNull('physical_state'),
            'color'              => $strOrNull('color'),
            'is_active'          => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_by'         => $data['created_by'] ?? null,
        ];

        $id = $db->insert('finished_goods', $insertData);
        return (int) $id;
    }

    /**
     * Update a finished good with optimistic locking.
     *
     * If $data contains 'expected_updated_at', the update will only proceed
     * if the record's updated_at matches, preventing silent overwrites.
     *
     * @return int  Affected rows.
     * @throws \RuntimeException on concurrent modification.
     */
    public static function update(int $id, array $data): int
    {
        $db = Database::getInstance();

        // Optimistic locking: check updated_at matches
        $expectedUpdatedAt = $data['expected_updated_at'] ?? null;
        if ($expectedUpdatedAt !== null) {
            $current = $db->fetch("SELECT updated_at FROM finished_goods WHERE id = ?", [$id]);
            if (!$current) {
                throw new \RuntimeException("Finished good #{$id} not found.");
            }
            if ($current['updated_at'] !== $expectedUpdatedAt) {
                throw new \RuntimeException(
                    'This record has been modified by another user. Please reload and try again.'
                );
            }
        }

        // If renaming code, check uniqueness
        if (isset($data['product_code']) && trim($data['product_code']) !== '') {
            $dup = $db->fetch(
                "SELECT id FROM finished_goods WHERE product_code = ? AND id != ?",
                [trim($data['product_code']), $id]
            );
            if ($dup) {
                throw new \RuntimeException("Product code '{$data['product_code']}' is already in use.");
            }
        }

        $allowed = ['product_code', 'description', 'family', 'is_active', 'recommended_use', 'restrictions_on_use', 'physical_state', 'color'];
        $updateData = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $val = $data[$col];
                $updateData[$col] = is_string($val) ? trim($val) : $val;
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $db->update('finished_goods', $updateData, 'id = ?', [$id]);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Return all distinct family values currently in use.
     */
    public static function getFamilies(): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT DISTINCT family FROM finished_goods WHERE family IS NOT NULL AND family != '' ORDER BY family"
        );
        return array_column($rows, 'family');
    }
}
