<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\User;
use SDS\Services\AuditService;
use SDS\Services\FederalData\Connectors\PubChemConnector;
use SDS\Services\FederalData\Connectors\NIOSHConnector;

class AdminController
{
    /* ------------------------------------------------------------------
     *  Before filter — admin only
     * ----------------------------------------------------------------*/

    private function requireAdmin(): void
    {
        if (!is_admin()) {
            http_response_code(403);
            $viewFile = dirname(__DIR__) . '/Views/errors/403.php';
            if (file_exists($viewFile)) {
                include $viewFile;
            } else {
                echo '<h1>403 — Forbidden</h1>';
            }
            exit;
        }
    }

    /* ------------------------------------------------------------------
     *  Users
     * ----------------------------------------------------------------*/

    public function users(): void
    {
        $this->requireAdmin();

        $filters = [
            'search'   => $_GET['search'] ?? '',
            'role'     => $_GET['role'] ?? '',
            'page'     => (int) ($_GET['page'] ?? 1),
            'per_page' => 25,
        ];

        $items = User::all($filters);
        $total = User::count($filters);

        view('admin/users', [
            'pageTitle' => 'Manage Users',
            'items'     => $items,
            'total'     => $total,
            'filters'   => $filters,
            'pages'     => (int) ceil($total / $filters['per_page']),
        ]);
    }

    public function createUser(): void
    {
        $this->requireAdmin();

        view('admin/user-form', [
            'pageTitle' => 'Create User',
            'item'      => null,
            'mode'      => 'create',
        ]);
    }

    public function storeUser(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        try {
            $id = User::create($_POST);
            AuditService::log('user', $id, 'create', ['username' => $_POST['username'] ?? '']);
            $_SESSION['_flash']['success'] = 'User created successfully.';
            redirect('/admin/users');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/admin/users/create');
        }
    }

    public function editUser(string $id): void
    {
        $this->requireAdmin();

        $item = User::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'User not found.';
            redirect('/admin/users');
        }

        view('admin/user-form', [
            'pageTitle' => 'Edit User: ' . $item['username'],
            'item'      => $item,
            'mode'      => 'edit',
        ]);
    }

    public function updateUser(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        try {
            User::updateUser((int) $id, $_POST);
            AuditService::log('user', $id, 'update');
            $_SESSION['_flash']['success'] = 'User updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/admin/users/' . $id . '/edit');
    }

    /* ------------------------------------------------------------------
     *  Settings
     * ----------------------------------------------------------------*/

    public function settings(): void
    {
        $this->requireAdmin();

        $db = Database::getInstance();
        $rows = $db->fetchAll("SELECT `key`, `value` FROM settings ORDER BY `key`");
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        view('admin/settings', [
            'pageTitle' => 'System Settings',
            'settings'  => $settings,
        ]);
    }

    public function saveSettings(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $db = Database::getInstance();

        // Handle logo removal
        if (!empty($_POST['remove_logo'])) {
            $currentLogo = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'company.logo_path'");
            if ($currentLogo && $currentLogo['value']) {
                $absPath = \SDS\Core\App::basePath() . '/public' . $currentLogo['value'];
                if (file_exists($absPath)) {
                    unlink($absPath);
                }
            }
            $this->saveSetting($db, 'company.logo_path', '');
        }

        // Handle logo upload
        if (!empty($_FILES['company_logo']['tmp_name']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $logoError = $this->processLogoUpload($db);
            if ($logoError !== null) {
                $_SESSION['_flash']['error'] = $logoError;
                redirect('/admin/settings');
                return;
            }
        }

        // Save all text settings
        foreach ($_POST as $key => $value) {
            if (in_array($key, ['_csrf_token', 'remove_logo'], true)) {
                continue;
            }
            $key = preg_replace('/[^a-zA-Z0-9_.]/', '', $key);
            if ($key === '') {
                continue;
            }

            $this->saveSetting($db, $key, $value);
        }

        AuditService::log('settings', 'global', 'update');
        $_SESSION['_flash']['success'] = 'Settings saved.';
        redirect('/admin/settings');
    }

    /**
     * Upsert a single setting key/value.
     */
    private function saveSetting(Database $db, string $key, string $value): void
    {
        $existing = $db->fetch("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $db->update('settings', ['value' => $value], '`key` = ?', [$key]);
        } else {
            $db->insert('settings', ['key' => $key, 'value' => $value]);
        }
    }

    /**
     * Validate and save an uploaded company logo.
     *
     * @return string|null  Error message, or null on success.
     */
    private function processLogoUpload(Database $db): ?string
    {
        $file = $_FILES['company_logo'];

        // Validate size (2 MB max)
        if ($file['size'] > 2 * 1024 * 1024) {
            return 'Logo file is too large. Maximum size is 2 MB.';
        }

        // Validate MIME type
        $allowed = ['image/png', 'image/jpeg', 'image/gif'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            return 'Invalid file type. Only PNG, JPG, and GIF are accepted.';
        }

        // Determine extension from MIME
        $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
        $ext = $extMap[$mime];

        // Delete previous logo if it exists
        $currentLogo = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'company.logo_path'");
        if ($currentLogo && $currentLogo['value']) {
            $oldPath = \SDS\Core\App::basePath() . '/public' . $currentLogo['value'];
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        // Save to public/uploads/
        $uploadDir = \SDS\Core\App::basePath() . '/public/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'company-logo.' . $ext;
        $destPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return 'Failed to save the uploaded file.';
        }

        // Store the web-accessible path
        $this->saveSetting($db, 'company.logo_path', '/uploads/' . $filename);

        return null;
    }

    /* ------------------------------------------------------------------
     *  Federal Data
     * ----------------------------------------------------------------*/

    public function federalData(): void
    {
        $this->requireAdmin();

        $db = Database::getInstance();

        $sources = $db->fetchAll(
            "SELECT source_name, COUNT(*) AS record_count, MAX(retrieved_at) AS last_refresh
             FROM hazard_source_records
             GROUP BY source_name
             ORDER BY source_name"
        );

        $refreshLog = $db->fetchAll(
            "SELECT * FROM dataset_refresh_log ORDER BY started_at DESC LIMIT 20"
        );

        view('admin/federal-data', [
            'pageTitle'  => 'Federal Data Sources',
            'sources'    => $sources,
            'refreshLog' => $refreshLog,
        ]);
    }

    public function refreshFederalData(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $source = $_POST['source'] ?? '';
        $db = Database::getInstance();

        // Get all unique CAS numbers in the system
        $casList = array_column(
            $db->fetchAll("SELECT DISTINCT cas_number FROM raw_material_constituents ORDER BY cas_number"),
            'cas_number'
        );

        // Log the refresh
        $logId = $db->insert('dataset_refresh_log', [
            'source_name' => $source ?: 'all',
            'status'      => 'running',
        ]);

        try {
            $results = ['success' => [], 'failed' => []];

            if ($source === 'pubchem' || $source === '') {
                $connector = new PubChemConnector();
                $r = $connector->refreshAll($casList);
                $results['success'] = array_merge($results['success'], $r['success']);
                $results['failed']  = array_merge($results['failed'], $r['failed']);
            }

            if ($source === 'niosh' || $source === '') {
                $connector = new NIOSHConnector();
                $r = $connector->refreshAll($casList);
                $results['success'] = array_merge($results['success'], $r['success']);
                $results['failed']  = array_merge($results['failed'], $r['failed']);
            }

            $db->update('dataset_refresh_log', [
                'finished_at'       => date('Y-m-d H:i:s'),
                'status'            => empty($results['failed']) ? 'success' : 'partial',
                'records_processed' => count($casList),
                'records_updated'   => count($results['success']),
                'details_json'      => json_encode($results),
            ], 'id = ?', [$logId]);

            $msg = count($results['success']) . ' CAS records refreshed.';
            if (!empty($results['failed'])) {
                $msg .= ' ' . count($results['failed']) . ' failed.';
            }
            $_SESSION['_flash']['success'] = $msg;
        } catch (\Throwable $e) {
            $db->update('dataset_refresh_log', [
                'finished_at'  => date('Y-m-d H:i:s'),
                'status'       => 'error',
                'details_json' => json_encode(['error' => $e->getMessage()]),
            ], 'id = ?', [$logId]);

            $_SESSION['_flash']['error'] = 'Refresh failed: ' . $e->getMessage();
        }

        redirect('/admin/federal-data');
    }

    /* ------------------------------------------------------------------
     *  Audit Log
     * ----------------------------------------------------------------*/

    public function auditLog(): void
    {
        $this->requireAdmin();

        $filters = [
            'entity_type' => $_GET['entity_type'] ?? '',
            'action'      => $_GET['action'] ?? '',
            'from'        => $_GET['from'] ?? '',
            'to'          => $_GET['to'] ?? '',
            'page'        => (int) ($_GET['page'] ?? 1),
            'per_page'    => 50,
        ];

        $entries = AuditService::getEntries($filters);
        $total   = AuditService::count($filters);

        view('admin/audit-log', [
            'pageTitle' => 'Audit Log',
            'entries'   => $entries,
            'total'     => $total,
            'filters'   => $filters,
            'pages'     => (int) ceil($total / $filters['per_page']),
        ]);
    }

    /* ------------------------------------------------------------------
     *  SDS Versions Management
     * ----------------------------------------------------------------*/

    public function sdsVersions(): void
    {
        $this->requireAdmin();

        $db = Database::getInstance();
        $versions = $db->fetchAll(
            "SELECT sv.*, fg.product_code, fg.description,
                    u.display_name AS published_by_name
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             LEFT JOIN users u ON u.id = sv.published_by
             ORDER BY sv.created_at DESC
             LIMIT 100"
        );

        view('admin/sds-versions', [
            'pageTitle' => 'SDS Versions',
            'versions'  => $versions,
        ]);
    }

    public function deleteSdsVersion(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $db = Database::getInstance();
        $db->update('sds_versions', [
            'is_deleted' => 1,
            'deleted_by' => current_user_id(),
            'deleted_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $id]);

        AuditService::log('sds_version', $id, 'soft_delete');
        $_SESSION['_flash']['success'] = 'SDS version soft-deleted.';
        redirect('/admin/sds-versions');
    }

    public function restoreSdsVersion(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $db = Database::getInstance();
        $db->update('sds_versions', [
            'is_deleted' => 0,
            'deleted_by' => null,
            'deleted_at' => null,
        ], 'id = ?', [(int) $id]);

        AuditService::log('sds_version', $id, 'restore');
        $_SESSION['_flash']['success'] = 'SDS version restored.';
        redirect('/admin/sds-versions');
    }
}
