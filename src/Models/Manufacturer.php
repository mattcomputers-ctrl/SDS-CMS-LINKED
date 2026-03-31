<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * Manufacturer Model — companies that appear on labels and SDS documents.
 *
 * Supports private-label / multi-brand workflows where different
 * manufacturer identities are placed on products.
 */
class Manufacturer
{
    /* ------------------------------------------------------------------
     *  Finders
     * ----------------------------------------------------------------*/

    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->fetch(
            "SELECT m.*, u.display_name AS created_by_name
             FROM manufacturers m
             LEFT JOIN users u ON u.id = m.created_by
             WHERE m.id = ?",
            [$id]
        );
    }

    /* ------------------------------------------------------------------
     *  Listing
     * ----------------------------------------------------------------*/

    /**
     * Return all manufacturers ordered by name.
     */
    public static function all(array $filters = []): array
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(m.name LIKE ? OR m.city LIKE ? OR m.state LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return $db->fetchAll(
            "SELECT m.*, u.display_name AS created_by_name
             FROM manufacturers m
             LEFT JOIN users u ON u.id = m.created_by
             {$whereSQL}
             ORDER BY m.name ASC",
            $params
        );
    }

    /* ------------------------------------------------------------------
     *  Create / Update / Delete
     * ----------------------------------------------------------------*/

    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Manufacturer name is required.');
        }

        $insertData = [
            'name'            => $name,
            'address'         => trim($data['address'] ?? ''),
            'city'            => trim($data['city'] ?? ''),
            'state'           => trim($data['state'] ?? ''),
            'zip'             => trim($data['zip'] ?? ''),
            'country'         => trim($data['country'] ?? ''),
            'phone'           => trim($data['phone'] ?? ''),
            'emergency_phone' => trim($data['emergency_phone'] ?? ''),
            'email'           => trim($data['email'] ?? ''),
            'website'         => trim($data['website'] ?? ''),
            'logo_path'       => $data['logo_path'] ?? null,
            'created_by'      => $data['created_by'] ?? null,
        ];

        return (int) $db->insert('manufacturers', $insertData);
    }

    public static function update(int $id, array $data): int
    {
        $db = Database::getInstance();

        $allowed = ['name', 'address', 'city', 'state', 'zip', 'country', 'phone',
                     'emergency_phone', 'email', 'website', 'logo_path'];
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

        return $db->update('manufacturers', $updateData, 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        $db = Database::getInstance();

        // Check if manufacturer is used in any private label SDS
        $used = $db->fetch(
            "SELECT 1 FROM private_label_sds WHERE manufacturer_id = ? LIMIT 1",
            [$id]
        );
        if ($used) {
            throw new \RuntimeException('Cannot delete manufacturer: it is referenced by private label SDS documents.');
        }

        $db->query("DELETE FROM manufacturers WHERE id = ?", [$id]);
    }

    /**
     * Convert a manufacturer record to the company-info array format
     * used by SDSGenerator (matching getCompanySettings() output).
     */
    public static function toCompanyInfo(array $manufacturer): array
    {
        return [
            'name'            => $manufacturer['name'] ?? '',
            'address'         => $manufacturer['address'] ?? '',
            'city'            => $manufacturer['city'] ?? '',
            'state'           => $manufacturer['state'] ?? '',
            'zip'             => $manufacturer['zip'] ?? '',
            'country'         => $manufacturer['country'] ?? '',
            'phone'           => $manufacturer['phone'] ?? '',
            'emergency_phone' => $manufacturer['emergency_phone'] ?? '',
            'email'           => $manufacturer['email'] ?? '',
            'website'         => $manufacturer['website'] ?? '',
            'logo_path'       => $manufacturer['logo_path'] ?? '',
        ];
    }
}
