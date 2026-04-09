<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * Customer Model — shipping locations with regulatory email and SDS send preferences.
 *
 * Keyed by Ship To code from the CMS system. Each shipping location
 * may have its own regulatory team and email address.
 */
class Customer
{
    public static function findById(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM customers WHERE id = ?",
            [$id]
        );
    }

    public static function findByShipTo(string $shipTo): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM customers WHERE ship_to = ?",
            [$shipTo]
        );
    }

    /**
     * Return all customers with an active regulatory email.
     */
    public static function getActiveWithEmail(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM customers
             WHERE is_active = 1 AND regulatory_email IS NOT NULL AND regulatory_email != ''
             ORDER BY ship_to_name"
        );
    }

    public static function all(array $filters = []): array
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(ship_to LIKE ? OR ship_to_name LIKE ? OR regulatory_email LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($filters['is_active'])) {
            $where[]  = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $sort = in_array($filters['sort'] ?? '', ['ship_to', 'ship_to_name', 'sds_send_mode', 'created_at'], true)
            ? $filters['sort'] : 'ship_to_name';
        $dir = strtolower($filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        return $db->fetchAll(
            "SELECT * FROM customers {$whereSQL}
             ORDER BY `{$sort}` {$dir}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
    }

    public static function count(array $filters = []): int
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(ship_to LIKE ? OR ship_to_name LIKE ? OR regulatory_email LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($filters['is_active'])) {
            $where[]  = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $row = $db->fetch("SELECT COUNT(*) AS cnt FROM customers {$whereSQL}", $params);
        return (int) ($row['cnt'] ?? 0);
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $shipTo = trim($data['ship_to'] ?? '');
        if ($shipTo === '') {
            throw new \InvalidArgumentException('Ship To code is required.');
        }

        $existing = $db->fetch("SELECT id FROM customers WHERE ship_to = ?", [$shipTo]);
        if ($existing) {
            throw new \RuntimeException("A customer with Ship To '{$shipTo}' already exists.");
        }

        // Build languages from checkbox array or comma-separated string
        $languages = self::normalizeLanguages($data['sds_languages'] ?? 'en');

        return (int) $db->insert('customers', [
            'ship_to'          => $shipTo,
            'ship_to_name'     => trim($data['ship_to_name'] ?? ''),
            'regulatory_email' => trim($data['regulatory_email'] ?? '') ?: null,
            'sds_send_mode'    => $data['sds_send_mode'] ?? 'osha',
            'sds_languages'    => $languages,
            'is_active'        => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);
    }

    public static function update(int $id, array $data): int
    {
        $db = Database::getInstance();

        $allowed = ['ship_to', 'ship_to_name', 'regulatory_email', 'sds_send_mode', 'sds_languages', 'is_active'];
        $updateData = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $val = is_string($data[$col]) ? trim($data[$col]) : $data[$col];
                if ($col === 'regulatory_email' && $val === '') {
                    $val = null;
                }
                if ($col === 'sds_languages') {
                    $val = self::normalizeLanguages($val);
                }
                $updateData[$col] = $val;
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $db->update('customers', $updateData, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('customers', 'id = ?', [$id]);
    }

    /**
     * Normalize language input (array of checkboxes or comma-separated string)
     * into a sorted, comma-separated string. Defaults to 'en' if empty.
     */
    public static function normalizeLanguages(mixed $input): string
    {
        $valid = ['en', 'es', 'fr', 'de'];

        if (is_array($input)) {
            $langs = $input;
        } else {
            $langs = array_map('trim', explode(',', (string) $input));
        }

        $langs = array_filter($langs, fn($l) => in_array($l, $valid, true));
        $langs = array_unique($langs);
        sort($langs);

        return !empty($langs) ? implode(',', $langs) : 'en';
    }

    /**
     * Get the language codes for a customer as an array.
     */
    public static function getLanguages(array $customer): array
    {
        return array_filter(
            array_map('trim', explode(',', $customer['sds_languages'] ?? 'en'))
        );
    }
}
