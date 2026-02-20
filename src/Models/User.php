<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * User Model — authentication, role management, CRUD.
 *
 * Passwords are hashed with Argon2id. Roles: admin, editor, readonly.
 */
class User
{
    /* ------------------------------------------------------------------
     *  Finders
     * ----------------------------------------------------------------*/

    /**
     * Find a user by primary key.
     *
     * @param  int $id
     * @return array|null  User row (password_hash excluded) or null.
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT id, username, email, display_name, role, is_active,
                    created_at, updated_at, last_login
             FROM users WHERE id = ?",
            [$id]
        );
        return $row;
    }

    /**
     * Find a user by username (case-insensitive).
     */
    public static function findByUsername(string $username): ?array
    {
        $db = Database::getInstance();
        return $db->fetch(
            "SELECT id, username, email, password_hash, display_name, role,
                    is_active, created_at, updated_at, last_login
             FROM users WHERE username = ?",
            [$username]
        );
    }

    /**
     * Find a user by email (case-insensitive).
     */
    public static function findByEmail(string $email): ?array
    {
        $db = Database::getInstance();
        return $db->fetch(
            "SELECT id, username, email, display_name, role, is_active,
                    created_at, updated_at, last_login
             FROM users WHERE email = ?",
            [$email]
        );
    }

    /* ------------------------------------------------------------------
     *  Listing & Pagination
     * ----------------------------------------------------------------*/

    /**
     * Return a paginated list of users.
     *
     * Supported $filters keys:
     *   - role       (string)  filter by role
     *   - is_active  (int)     0 or 1
     *   - search     (string)  partial match on username, email, display_name
     *   - page       (int)     page number, default 1
     *   - per_page   (int)     rows per page, default 25
     *   - sort       (string)  column name, default 'username'
     *   - dir        (string)  'asc' or 'desc', default 'asc'
     *
     * @return array  List of user rows (password_hash excluded).
     */
    public static function all(array $filters = []): array
    {
        $db = Database::getInstance();

        $where   = [];
        $params  = [];

        if (!empty($filters['role'])) {
            $where[]  = 'role = ?';
            $params[] = $filters['role'];
        }
        if (isset($filters['is_active'])) {
            $where[]  = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(username LIKE ? OR email LIKE ? OR display_name LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $allowedSorts = ['id', 'username', 'email', 'display_name', 'role', 'created_at', 'last_login'];
        $sort = in_array($filters['sort'] ?? '', $allowedSorts, true)
            ? $filters['sort']
            : 'username';
        $dir = strtolower($filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT id, username, email, display_name, role, is_active,
                       created_at, updated_at, last_login
                FROM users
                {$whereSQL}
                ORDER BY `{$sort}` {$dir}
                LIMIT {$perPage} OFFSET {$offset}";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Count users matching the given filters.
     */
    public static function count(array $filters = []): int
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['role'])) {
            $where[]  = 'role = ?';
            $params[] = $filters['role'];
        }
        if (isset($filters['is_active'])) {
            $where[]  = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(username LIKE ? OR email LIKE ? OR display_name LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $row = $db->fetch("SELECT COUNT(*) AS cnt FROM users {$whereSQL}", $params);
        return (int) ($row['cnt'] ?? 0);
    }

    /* ------------------------------------------------------------------
     *  Create / Update
     * ----------------------------------------------------------------*/

    /**
     * Create a new user.
     *
     * Required keys in $data: username, email, password.
     * Optional: display_name, role, is_active.
     *
     * @return int  New user ID.
     * @throws \InvalidArgumentException on validation failure.
     * @throws \RuntimeException on duplicate username/email.
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        // --- Validation ---
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if ($username === '') {
            throw new \InvalidArgumentException('Username is required.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email address is not valid.');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }

        // Check uniqueness
        if ($email !== '') {
            $existing = $db->fetch("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
        } else {
            $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        }
        if ($existing) {
            throw new \RuntimeException('A user with that username' . ($email ? ' or email' : '') . ' already exists.');
        }

        $allowedRoles = ['admin', 'editor', 'readonly', 'sds_book_only'];
        $role = in_array($data['role'] ?? '', $allowedRoles, true) ? $data['role'] : 'readonly';

        $insertData = [
            'username'      => $username,
            'email'         => $email !== '' ? $email : null,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'display_name'  => trim($data['display_name'] ?? ''),
            'role'          => $role,
            'is_active'     => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ];

        $id = $db->insert('users', $insertData);
        return (int) $id;
    }

    /**
     * Update an existing user.
     *
     * If 'password' key is present and non-empty, it will be re-hashed.
     *
     * @return int  Number of affected rows.
     */
    public static function updateUser(int $id, array $data): int
    {
        $db = Database::getInstance();

        $updateData = [];

        if (isset($data['username']) && trim($data['username']) !== '') {
            // Check uniqueness (exclude self)
            $dup = $db->fetch(
                "SELECT id FROM users WHERE username = ? AND id != ?",
                [trim($data['username']), $id]
            );
            if ($dup) {
                throw new \RuntimeException('Username already taken.');
            }
            $updateData['username'] = trim($data['username']);
        }

        if (array_key_exists('email', $data)) {
            $email = trim($data['email'] ?? '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Email address is not valid.');
            }
            if ($email !== '') {
                $dup = $db->fetch(
                    "SELECT id FROM users WHERE email = ? AND id != ?",
                    [$email, $id]
                );
                if ($dup) {
                    throw new \RuntimeException('Email already taken.');
                }
            }
            $updateData['email'] = $email !== '' ? $email : null;
        }

        if (isset($data['display_name'])) {
            $updateData['display_name'] = trim($data['display_name']);
        }

        if (isset($data['role'])) {
            $allowedRoles = ['admin', 'editor', 'readonly', 'sds_book_only'];
            if (in_array($data['role'], $allowedRoles, true)) {
                $updateData['role'] = $data['role'];
            }
        }

        if (isset($data['is_active'])) {
            $updateData['is_active'] = (int) $data['is_active'];
        }

        // Only re-hash if password provided and non-empty
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                throw new \InvalidArgumentException('Password must be at least 8 characters.');
            }
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        if (empty($updateData)) {
            return 0;
        }

        return $db->update('users', $updateData, 'id = ?', [$id]);
    }

    /* ------------------------------------------------------------------
     *  Authentication
     * ----------------------------------------------------------------*/

    /**
     * Authenticate a user by username and password.
     *
     * @return array|false  User row (without password_hash) on success, false on failure.
     */
    public static function authenticate(string $username, string $password): array|false
    {
        $db = Database::getInstance();

        $user = $db->fetch(
            "SELECT id, username, email, password_hash, display_name, role, is_active,
                    created_at, updated_at, last_login
             FROM users WHERE username = ?",
            [$username]
        );

        if (!$user) {
            return false;
        }

        if (!(int) $user['is_active']) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Check if password needs rehash (algorithm upgrade path)
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $db->update(
                'users',
                ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)],
                'id = ?',
                [$user['id']]
            );
        }

        // Remove sensitive data before returning
        unset($user['password_hash']);

        return $user;
    }

    /**
     * Update the last_login timestamp for a user.
     */
    public static function updateLastLogin(int $id): void
    {
        $db = Database::getInstance();
        $db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }
}
