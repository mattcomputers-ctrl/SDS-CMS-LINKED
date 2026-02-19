<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * AuditService — Structured audit logging for all entity changes.
 *
 * Every create, update, delete, and publish action is recorded with
 * the user, entity reference, action name, and a JSON diff of changes.
 */
class AuditService
{
    /**
     * Log an audit event.
     *
     * @param string     $entityType  e.g. 'raw_material', 'finished_good', 'sds_version'
     * @param string|int $entityId    Primary key or identifier
     * @param string     $action      e.g. 'create', 'update', 'delete', 'publish'
     * @param array|null $diff        Before/after diff or contextual data
     * @param int|null   $userId      User performing the action (null = system)
     */
    public static function log(
        string $entityType,
        string|int $entityId,
        string $action,
        ?array $diff = null,
        ?int $userId = null
    ): void {
        $db = Database::getInstance();

        $db->insert('audit_log', [
            'user_id'     => $userId ?? current_user_id(),
            'entity_type' => $entityType,
            'entity_id'   => (string) $entityId,
            'action'      => $action,
            'diff_json'   => $diff !== null ? json_encode($diff, JSON_UNESCAPED_UNICODE) : null,
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /**
     * Compute a diff between an old and new version of an entity.
     *
     * @param  array $old  Original values
     * @param  array $new  Updated values
     * @return array       ['field' => ['old' => ..., 'new' => ...], ...]
     */
    public static function diff(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $key => $newVal) {
            $oldVal = $old[$key] ?? null;
            if ((string) $oldVal !== (string) $newVal) {
                $changes[$key] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }
        return $changes;
    }

    /**
     * Retrieve audit log entries with optional filtering.
     *
     * @param  array $filters  Keys: entity_type, entity_id, user_id, action, from, to, page, per_page
     * @return array           List of audit log rows with user display name joined.
     */
    public static function getEntries(array $filters = []): array
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['entity_type'])) {
            $where[]  = 'a.entity_type = ?';
            $params[] = $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[]  = 'a.entity_id = ?';
            $params[] = (string) $filters['entity_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[]  = 'a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[]  = 'a.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'a.timestamp >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'a.timestamp <= ?';
            $params[] = $filters['to'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT a.*, u.display_name AS user_display_name, u.username
                FROM audit_log a
                LEFT JOIN users u ON u.id = a.user_id
                {$whereSQL}
                ORDER BY a.timestamp DESC
                LIMIT {$perPage} OFFSET {$offset}";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Count audit log entries matching filters.
     */
    public static function count(array $filters = []): int
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['entity_type'])) {
            $where[]  = 'entity_type = ?';
            $params[] = $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[]  = 'entity_id = ?';
            $params[] = (string) $filters['entity_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[]  = 'user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[]  = 'action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'timestamp >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'timestamp <= ?';
            $params[] = $filters['to'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $row = $db->fetch("SELECT COUNT(*) AS cnt FROM audit_log {$whereSQL}", $params);
        return (int) ($row['cnt'] ?? 0);
    }
}
