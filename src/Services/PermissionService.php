<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * PermissionService — Group-based, per-page permission checks.
 *
 * Access levels (ordered):
 *   none — cannot see or access the page
 *   read — view only
 *   full — full access (view, edit, delete)
 *
 * Users in an admin group (is_admin=1) have full access to everything.
 */
class PermissionService
{
    /**
     * All page keys that the permission system manages.
     * key => human label
     */
    public const PAGE_KEYS = [
        'dashboard'        => 'Dashboard',
        'raw_materials'    => 'Raw Materials',
        'finished_goods'   => 'Finished Goods',
        'fg_sds_lookup'    => 'FG SDS Lookup',
        'rm_sds_book'      => 'RM SDS Book',
        'reports'          => 'Reports',
        'aliases'          => 'Product Aliases',
        'formulas'         => 'Formulas',
        'sds'                => 'SDS Generation',
        'rm_mass_replace'    => 'RM Mass Replacement',
        'cas_determinations' => 'CAS Determinations',
        'bulk_publish'       => 'Bulk SDS Publish',
        'bulk_export'        => 'Bulk SDS Export',
        'exempt_vocs'        => 'Exempt VOC Library',
    ];

    /**
     * Access level labels for display.
     */
    public const ACCESS_LEVELS = [
        'none' => 'None',
        'read' => 'Read Only',
        'full' => 'Full Access',
    ];

    /**
     * Get the effective access level for a user on a given page.
     * Returns the highest access level across all the user's groups.
     * Users in an admin group always get 'full'.
     */
    public static function getAccess(?int $userId, string $pageKey): string
    {
        if ($userId === null) {
            return 'none';
        }

        try {
            $db = Database::getInstance();

            // Check if user belongs to any admin group
            $adminGroup = $db->fetch(
                "SELECT 1 FROM user_group_members ugm
                 JOIN permission_groups pg ON pg.id = ugm.group_id
                 WHERE ugm.user_id = ? AND pg.is_admin = 1
                 LIMIT 1",
                [$userId]
            );
            if ($adminGroup) {
                return 'full';
            }

            // Check if user has ANY group memberships
            $hasMembership = $db->fetch(
                "SELECT 1 FROM user_group_members WHERE user_id = ? LIMIT 1",
                [$userId]
            );

            // If user has no group memberships, no access
            if (!$hasMembership) {
                return 'none';
            }

            // Get the highest permission across all user's groups for this page
            $row = $db->fetch(
                "SELECT gp.access_level
                 FROM user_group_members ugm
                 JOIN group_permissions gp ON gp.group_id = ugm.group_id
                 WHERE ugm.user_id = ? AND gp.page_key = ?
                 ORDER BY FIELD(gp.access_level, 'none', 'read', 'full') DESC
                 LIMIT 1",
                [$userId, $pageKey]
            );

            return $row['access_level'] ?? 'none';
        } catch (\Throwable $e) {
            return 'none';
        }
    }

    /**
     * Check if user can view a page.
     */
    public static function canRead(?int $userId, string $pageKey): bool
    {
        return in_array(self::getAccess($userId, $pageKey), ['read', 'full'], true);
    }

    /**
     * Check if user has full access on a page (edit, delete, etc.).
     */
    public static function canEdit(?int $userId, string $pageKey): bool
    {
        return self::getAccess($userId, $pageKey) === 'full';
    }

    /**
     * Check if user has full access on a page.
     */
    public static function canDelete(?int $userId, string $pageKey): bool
    {
        return self::getAccess($userId, $pageKey) === 'full';
    }

    /**
     * Get all page permissions for a user (for nav rendering).
     * Returns ['page_key' => 'access_level', ...]
     */
    public static function getAllAccess(?int $userId): array
    {
        $result = [];
        foreach (array_keys(self::PAGE_KEYS) as $key) {
            $result[$key] = self::getAccess($userId, $key);
        }
        return $result;
    }

    /**
     * Check if the current user can manage users/groups (admin group only).
     */
    public static function canManageUsersAndGroups(): bool
    {
        $user = $_SESSION['_user'] ?? null;
        if (!$user) {
            return false;
        }

        try {
            $db = Database::getInstance();
            $row = $db->fetch(
                "SELECT 1 FROM user_group_members ugm
                 JOIN permission_groups pg ON pg.id = ugm.group_id
                 WHERE ugm.user_id = ? AND pg.is_admin = 1
                 LIMIT 1",
                [(int) $user['id']]
            );

            return $row !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ------------------------------------------------------------------
     *  Group CRUD
     * ----------------------------------------------------------------*/

    /**
     * List all groups.
     */
    public static function allGroups(): array
    {
        try {
            $db = Database::getInstance();
            return $db->fetchAll(
                "SELECT pg.*, COUNT(ugm.id) AS member_count
                 FROM permission_groups pg
                 LEFT JOIN user_group_members ugm ON ugm.group_id = pg.id
                 GROUP BY pg.id
                 ORDER BY pg.name"
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Find a group by ID with its permissions.
     */
    public static function findGroup(int $id): ?array
    {
        $db = Database::getInstance();
        $group = $db->fetch("SELECT * FROM permission_groups WHERE id = ?", [$id]);
        if (!$group) {
            return null;
        }

        // Load permissions
        $perms = $db->fetchAll(
            "SELECT page_key, access_level FROM group_permissions WHERE group_id = ?",
            [$id]
        );
        $group['permissions'] = [];
        foreach ($perms as $p) {
            $group['permissions'][$p['page_key']] = $p['access_level'];
        }

        // Load members
        $group['members'] = $db->fetchAll(
            "SELECT u.id, u.username, u.display_name
             FROM user_group_members ugm
             JOIN users u ON u.id = ugm.user_id
             WHERE ugm.group_id = ?
             ORDER BY u.username",
            [$id]
        );

        return $group;
    }

    /**
     * Create a new group with permissions.
     */
    public static function createGroup(string $name, string $description, bool $isAdmin, array $permissions): int
    {
        $db = Database::getInstance();

        $id = (int) $db->insert('permission_groups', [
            'name'        => $name,
            'description' => $description,
            'is_admin'    => $isAdmin ? 1 : 0,
        ]);

        self::saveGroupPermissions($id, $permissions);

        return $id;
    }

    /**
     * Update an existing group.
     */
    public static function updateGroup(int $id, string $name, string $description, bool $isAdmin, array $permissions): void
    {
        $db = Database::getInstance();

        $db->update('permission_groups', [
            'name'        => $name,
            'description' => $description,
            'is_admin'    => $isAdmin ? 1 : 0,
        ], 'id = ?', [$id]);

        self::saveGroupPermissions($id, $permissions);
    }

    /**
     * Delete a group.
     */
    public static function deleteGroup(int $id): void
    {
        $db = Database::getInstance();
        $db->delete('permission_groups', 'id = ?', [$id]);
    }

    /**
     * Save permissions for a group (replaces all existing).
     */
    private static function saveGroupPermissions(int $groupId, array $permissions): void
    {
        $db = Database::getInstance();

        // Delete existing
        $db->delete('group_permissions', 'group_id = ?', [$groupId]);

        // Insert new
        $validLevels = ['none', 'read', 'full'];
        foreach ($permissions as $pageKey => $level) {
            if (!array_key_exists($pageKey, self::PAGE_KEYS)) {
                continue;
            }
            if (!in_array($level, $validLevels, true)) {
                $level = 'none';
            }
            $db->insert('group_permissions', [
                'group_id'     => $groupId,
                'page_key'     => $pageKey,
                'access_level' => $level,
            ]);
        }
    }

    /* ------------------------------------------------------------------
     *  User-Group membership
     * ----------------------------------------------------------------*/

    /**
     * Get groups for a user.
     */
    public static function getUserGroups(int $userId): array
    {
        try {
            $db = Database::getInstance();
            return $db->fetchAll(
                "SELECT pg.* FROM permission_groups pg
                 JOIN user_group_members ugm ON ugm.group_id = pg.id
                 WHERE ugm.user_id = ?
                 ORDER BY pg.name",
                [$userId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Set group memberships for a user (replaces existing).
     */
    public static function setUserGroups(int $userId, array $groupIds): void
    {
        $db = Database::getInstance();

        // Delete existing memberships
        $db->delete('user_group_members', 'user_id = ?', [$userId]);

        // Insert new
        foreach ($groupIds as $gid) {
            $gid = (int) $gid;
            if ($gid <= 0) continue;
            $db->insert('user_group_members', [
                'user_id'  => $userId,
                'group_id' => $gid,
            ]);
        }
    }
}
