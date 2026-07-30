<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\User;
use SDS\Services\AuditService;
use SDS\Services\BackupService;
use SDS\Services\NetworkService;
use SDS\Services\PermissionService;
use SDS\Services\TrainingDataService;
use SDS\Services\FederalData\Connectors\PubChemConnector;
use SDS\Services\FederalData\Connectors\NIOSHConnector;

class AdminController
{
    /* ------------------------------------------------------------------
     *  Before filter — admin only
     * ----------------------------------------------------------------*/

    private function requireAdmin(): void
    {
        if (!can_manage_users()) {
            $this->sendForbidden();
        }
    }

    /**
     * Require a specific permission level on a page key.
     * Defaults to 'read' (any access). Pass 'full' for write operations.
     */
    private function requirePageAccess(string $pageKey, string $minLevel = 'read'): void
    {
        $userId = current_user_id();
        if ($minLevel === 'full') {
            if (!PermissionService::canEdit($userId, $pageKey)) {
                $this->sendForbidden();
            }
        } else {
            if (!PermissionService::canRead($userId, $pageKey)) {
                $this->sendForbidden();
            }
        }
    }

    private function sendForbidden(): never
    {
        http_response_code(403);
        $viewFile = dirname(__DIR__) . '/Views/errors/403.php';
        if (file_exists($viewFile)) {
            include $viewFile;
        } else {
            echo '<h1>403 — Forbidden</h1>';
        }
        exit;
    }

    /* ------------------------------------------------------------------
     *  Users
     * ----------------------------------------------------------------*/

    public function users(): void
    {
        $this->requireAdmin();

        $filters = [
            'search'   => $_GET['search'] ?? '',
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

        $allGroups = PermissionService::allGroups();

        view('admin/user-form', [
            'pageTitle'    => 'Create User',
            'item'         => null,
            'mode'         => 'create',
            'allGroups'    => $allGroups,
            'userGroupId'  => null,
        ]);
    }

    public function storeUser(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        try {
            $id = User::create($_POST);

            // Save permission group assignment
            $groupId = $_POST['group_id'] ?? '';
            if ($groupId !== '') {
                PermissionService::setUserGroups($id, [(int) $groupId]);
            }

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

        $allGroups = PermissionService::allGroups();
        $userGroups = PermissionService::getUserGroups((int) $id);
        $userGroupId = !empty($userGroups) ? (int) $userGroups[0]['id'] : null;

        view('admin/user-form', [
            'pageTitle'    => 'Edit User: ' . $item['username'],
            'item'         => $item,
            'mode'         => 'edit',
            'allGroups'    => $allGroups,
            'userGroupId'  => $userGroupId,
        ]);
    }

    public function updateUser(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        try {
            User::updateUser((int) $id, $_POST);

            // Save permission group assignment
            $groupId = $_POST['group_id'] ?? '';
            if ($groupId !== '') {
                PermissionService::setUserGroups((int) $id, [(int) $groupId]);
            }

            AuditService::log('user', $id, 'update');
            $_SESSION['_flash']['success'] = 'User updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/admin/users/' . $id . '/edit');
    }

    /* ------------------------------------------------------------------
     *  Permission Groups
     * ----------------------------------------------------------------*/

    public function groups(): void
    {
        $this->requireAdmin();

        $groups = PermissionService::allGroups();

        view('admin/groups', [
            'pageTitle' => 'Permission Groups',
            'groups'    => $groups,
        ]);
    }

    public function createGroup(): void
    {
        $this->requireAdmin();

        view('admin/group-form', [
            'pageTitle' => 'Create Permission Group',
            'group'     => null,
            'mode'      => 'create',
            'pageKeys'  => PermissionService::PAGE_KEYS,
            'accessLevels' => PermissionService::ACCESS_LEVELS,
        ]);
    }

    public function storeGroup(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isAdmin     = !empty($_POST['is_admin']);
        $permissions = $_POST['permissions'] ?? [];

        if ($name === '') {
            $_SESSION['_flash']['error'] = 'Group name is required.';
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/admin/groups/create');
        }

        try {
            $id = PermissionService::createGroup($name, $description, $isAdmin, $permissions);
            AuditService::log('permission_group', $id, 'create', ['name' => $name]);
            $_SESSION['_flash']['success'] = 'Permission group created.';
            redirect('/admin/groups');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/admin/groups/create');
        }
    }

    public function editGroup(string $id): void
    {
        $this->requireAdmin();

        $group = PermissionService::findGroup((int) $id);
        if ($group === null) {
            $_SESSION['_flash']['error'] = 'Group not found.';
            redirect('/admin/groups');
        }

        view('admin/group-form', [
            'pageTitle'    => 'Edit Group: ' . $group['name'],
            'group'        => $group,
            'mode'         => 'edit',
            'pageKeys'     => PermissionService::PAGE_KEYS,
            'accessLevels' => PermissionService::ACCESS_LEVELS,
        ]);
    }

    public function updateGroup(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isAdmin     = !empty($_POST['is_admin']);
        $permissions = $_POST['permissions'] ?? [];

        if ($name === '') {
            $_SESSION['_flash']['error'] = 'Group name is required.';
            redirect('/admin/groups/' . $id . '/edit');
        }

        try {
            PermissionService::updateGroup((int) $id, $name, $description, $isAdmin, $permissions);
            AuditService::log('permission_group', $id, 'update', ['name' => $name]);
            $_SESSION['_flash']['success'] = 'Permission group updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/admin/groups/' . $id . '/edit');
    }

    public function deleteGroup(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $group = PermissionService::findGroup((int) $id);
        if ($group === null) {
            $_SESSION['_flash']['error'] = 'Group not found.';
            redirect('/admin/groups');
        }

        try {
            PermissionService::deleteGroup((int) $id);
            AuditService::log('permission_group', $id, 'delete', ['name' => $group['name']]);
            $_SESSION['_flash']['success'] = 'Permission group deleted.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/admin/groups');
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

        // Resolve inhalation-only CAS names from prop65_list
        $inhalationCasNames = [];
        $casSetting = $settings['sds.inhalation_only_cas'] ?? "1333-86-4\n13463-67-7";
        $casLines = array_filter(array_map('trim', explode("\n", $casSetting)));
        foreach ($casLines as $line) {
            if ($line === '' || str_starts_with($line, '#')) continue;
            // Support legacy "CAS | Name" format — extract just the CAS
            $cas = trim(explode('|', $line, 2)[0]);
            if ($cas === '') continue;
            $p65 = $db->fetch("SELECT chemical_name FROM prop65_list WHERE cas_number = ?", [$cas]);
            $inhalationCasNames[$cas] = $p65 ? $p65['chemical_name'] : '(not in Prop 65 list)';
        }

        view('admin/settings', [
            'pageTitle' => 'System Settings',
            'settings'  => $settings,
            'inhalationCasNames' => $inhalationCasNames,
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

        // Handle login logo removal
        if (!empty($_POST['remove_login_logo'])) {
            $currentLogin = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'login.logo_path'");
            if ($currentLogin && $currentLogin['value']) {
                $absPath = \SDS\Core\App::basePath() . '/public' . $currentLogin['value'];
                if (file_exists($absPath)) {
                    unlink($absPath);
                }
            }
            $this->saveSetting($db, 'login.logo_path', '');
        }

        // Handle login logo upload
        if (!empty($_FILES['login_logo']['tmp_name']) && $_FILES['login_logo']['error'] === UPLOAD_ERR_OK) {
            $loginLogoError = $this->processImageUpload($db, 'login_logo', 'login-logo', 'login.logo_path');
            if ($loginLogoError !== null) {
                $_SESSION['_flash']['error'] = $loginLogoError;
                redirect('/admin/settings');
                return;
            }
        }

        // Save all text settings
        // Form field names use '__' (double underscore) as separator instead of '.'
        // because PHP converts dots in POST field names to underscores.
        foreach ($_POST as $key => $value) {
            if (in_array($key, ['_csrf_token', 'remove_logo', 'remove_login_logo'], true)) {
                continue;
            }
            $key = preg_replace('/[^a-zA-Z0-9_.\[\]]/', '', $key);
            $key = rtrim($key, '[]');
            if ($key === '') {
                continue;
            }
            // Convert double-underscore separator back to dot for DB storage
            $key = str_replace('__', '.', $key);

            if (is_array($value)) {
                if ($key === 'cms_sync.active_hours') {
                    $value = implode(',', array_map('intval', $value));
                } else {
                    continue;
                }
            }

            $this->saveSetting($db, $key, $value);
        }

        // If no active hours checkboxes were checked, save empty string
        if (!isset($_POST['cms_sync__active_hours'])) {
            $this->saveSetting($db, 'cms_sync.active_hours', '');
        }

        // The run minute lives in the system crontab, not in the schedule
        // gate inside cron/cms-sync.php — rewrite the crontab line so the
        // job actually fires at the chosen minute.
        $crontabWarning = null;
        if (isset($_POST['cms_sync__run_minute']) && ctype_digit(trim((string) $_POST['cms_sync__run_minute']))) {
            $crontabWarning = $this->syncCmsCrontabMinute((int) trim((string) $_POST['cms_sync__run_minute']));
        }

        AuditService::log('settings', 'global', 'update');
        if ($crontabWarning !== null) {
            $_SESSION['_flash']['error'] = $crontabWarning;
        } else {
            $_SESSION['_flash']['success'] = 'Settings saved.';
        }
        redirect('/admin/settings');
    }

    /**
     * Rewrite this user's crontab so the cms-sync entry fires at the
     * given minute past the hour. The schedule gate inside the script
     * only decides IF a fired run proceeds (frequency, active hours) —
     * WHEN it fires is purely the crontab's minute field, which used to
     * be hardcoded to 7 by the installer.
     *
     * Returns a warning message on failure, or null on success.
     */
    private function syncCmsCrontabMinute(int $minute): ?string
    {
        $minute   = max(0, min(59, $minute));
        $basePath = \SDS\Core\App::basePath();
        $newLine  = "{$minute} * * * * cd {$basePath} && /usr/bin/php cron/cms-sync.php >> storage/logs/cms-sync.log 2>&1";
        $manualHint = "To set it manually run: crontab -u www-data -e and change the cms-sync line to: {$newLine}";

        $lines = [];
        exec('crontab -l 2>/dev/null', $lines);

        // Already at the right minute — nothing to do.
        foreach ($lines as $line) {
            if (str_contains($line, 'cron/cms-sync.php') && preg_match('/^\s*' . $minute . '\s+\*/', $line)) {
                return null;
            }
        }

        $kept = [];
        foreach ($lines as $line) {
            if (str_contains($line, 'cron/cms-sync.php') || str_contains($line, '# CMS Sync:')) {
                continue;
            }
            $kept[] = $line;
        }
        $kept[] = '# CMS Sync: import items, formulas, aliases, shipments from CMS (hourly)';
        $kept[] = $newLine;

        $tmp = tempnam(sys_get_temp_dir(), 'cron');
        if ($tmp === false || file_put_contents($tmp, implode("\n", $kept) . "\n") === false) {
            return 'Settings saved, but the cron schedule could not be written to a temp file. ' . $manualHint;
        }

        $rc = 1;
        $out = [];
        exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        @unlink($tmp);

        if ($rc !== 0) {
            return 'Settings saved, but updating the system crontab failed ('
                . trim(implode(' ', $out)) . '). The sync will keep firing at the old minute. ' . $manualHint;
        }

        // Verify the line landed.
        $verify = [];
        exec('crontab -l 2>/dev/null', $verify);
        foreach ($verify as $line) {
            if (str_contains($line, 'cron/cms-sync.php') && preg_match('/^\s*' . $minute . '\s+\*/', $line)) {
                return null;
            }
        }

        return 'Settings saved, but the crontab update could not be verified. ' . $manualHint;
    }

    /**
     * AJAX: bump all SDSs containing inhalation-only CAS numbers.
     */
    public function bumpInhalationCas(): void
    {
        $this->requireAdmin();

        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!CSRF::validate($csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            return;
        }

        $casText = trim($input['cas_list'] ?? '');
        if ($casText === '') {
            echo json_encode(['success' => false, 'message' => 'No CAS numbers provided.']);
            return;
        }

        $casList = [];
        foreach (explode("\n", $casText) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $cas = trim(explode('|', $line, 2)[0]);
            if ($cas !== '') {
                $casList[] = $cas;
            }
        }

        if (empty($casList)) {
            echo json_encode(['success' => false, 'message' => 'No valid CAS numbers found.']);
            return;
        }

        $bumped = \SDS\Services\RegulatoryListBumper::bumpByCasMany($casList);

        AuditService::log('settings', 'inhalation_only_cas', 'bump', [
            'cas_list' => $casList,
            'raw_materials_bumped' => $bumped,
        ]);

        $casCount = count($casList);
        $msg = "Bumped {$bumped} raw material(s) across {$casCount} CAS number(s). Affected SDSs will regenerate on next bulk publish.";
        echo json_encode(['success' => true, 'message' => $msg, 'bumped' => $bumped]);
    }

    /**
     * Bump every raw material so all unblocked finished goods are marked
     * stale and get regenerated at the next Bulk SDS Publish.
     *
     * Blocked finished goods (those with a raw material that has never been
     * user-reviewed) stay blocked and are skipped by bulk publish's own
     * eligibility gate, so this only causes unblocked FGs to republish.
     */
    public function bumpAllUnblockedSds(): void
    {
        $this->requireAdmin();

        header('Content-Type: application/json');
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!CSRF::validate($csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            return;
        }

        $db = Database::getInstance();

        // Bump the upstream timestamp on every raw material. Bulk publish's
        // staleness check (published_at vs MAX upstream updated_at) then sees
        // every unblocked FG as stale; blocked FGs remain blocked regardless.
        $bumped = $db->query("UPDATE raw_materials SET updated_at = UTC_TIMESTAMP()")->rowCount();

        // Best-effort count of how many FGs will actually be regenerated.
        $eligibleCount = null;
        try {
            $result = BulkPublishController::computeEligibleFinishedGoods($db);
            $eligibleCount = count($result['eligible'] ?? []);
        } catch (\Throwable $e) {
            // Non-fatal — the bump already succeeded.
        }

        AuditService::log('settings', 'bump_all_unblocked_sds', 'bump', [
            'raw_materials_bumped' => $bumped,
            'eligible_fg'          => $eligibleCount,
        ]);

        $countPart = $eligibleCount !== null
            ? "{$eligibleCount} unblocked finished good(s) will be regenerated"
            : 'All unblocked finished goods will be regenerated';
        $msg = "Bumped {$bumped} raw material(s). {$countPart} at the next Bulk SDS Publish — "
             . 'expect it to take a long time to complete, likely several hours.';
        echo json_encode(['success' => true, 'message' => $msg, 'eligible' => $eligibleCount]);
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

    /**
     * Generic image upload handler for settings.
     *
     * @param Database $db           Database instance
     * @param string   $fileKey      $_FILES key (e.g. 'login_logo')
     * @param string   $filenameBase Base filename without extension (e.g. 'login-logo')
     * @param string   $settingKey   Settings key to store the path (e.g. 'login.logo_path')
     * @return string|null  Error message, or null on success.
     */
    private function processImageUpload(Database $db, string $fileKey, string $filenameBase, string $settingKey): ?string
    {
        $file = $_FILES[$fileKey];

        if ($file['size'] > 2 * 1024 * 1024) {
            return 'Image file is too large. Maximum size is 2 MB.';
        }

        $allowed = ['image/png', 'image/jpeg', 'image/gif'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            return 'Invalid file type. Only PNG, JPG, and GIF are accepted.';
        }

        $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
        $ext = $extMap[$mime];

        // Delete previous file if it exists
        $current = $db->fetch("SELECT `value` FROM settings WHERE `key` = ?", [$settingKey]);
        if ($current && $current['value']) {
            $oldPath = \SDS\Core\App::basePath() . '/public' . $current['value'];
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        $uploadDir = \SDS\Core\App::basePath() . '/public/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = $filenameBase . '.' . $ext;
        $destPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return 'Failed to save the uploaded file.';
        }

        $this->saveSetting($db, $settingKey, '/uploads/' . $filename);

        return null;
    }

    /* ------------------------------------------------------------------
     *  Exempt VOC Library
     * ----------------------------------------------------------------*/

    public function exemptVocs(): void
    {
        $this->requirePageAccess('exempt_vocs');
        $db = Database::getInstance();

        $items = $db->fetchAll("SELECT * FROM exempt_voc_list ORDER BY cas_number");

        view('admin/exempt-vocs', [
            'pageTitle' => 'Exempt VOC Library',
            'items'     => $items,
        ]);
    }

    public function createExemptVoc(): void
    {
        $this->requirePageAccess('exempt_vocs', 'full');
        view('admin/exempt-voc-form', [
            'pageTitle' => 'Add Exempt VOC',
            'item'      => null,
            'mode'      => 'create',
        ]);
    }

    public function storeExemptVoc(): void
    {
        $this->requirePageAccess('exempt_vocs', 'full');
        CSRF::validateRequest();

        $db = Database::getInstance();
        $cas = trim($_POST['cas_number'] ?? '');

        if ($cas === '' || !preg_match('/^\d{1,7}-\d{2}-\d$/', $cas)) {
            $_SESSION['_flash']['error'] = 'A valid CAS number is required.';
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/exempt-vocs/create');
            return;
        }

        $existing = $db->fetch("SELECT id FROM exempt_voc_list WHERE cas_number = ?", [$cas]);
        if ($existing) {
            $_SESSION['_flash']['error'] = "CAS {$cas} is already in the exempt list.";
            redirect('/exempt-vocs');
            return;
        }

        $db->insert('exempt_voc_list', [
            'cas_number'     => $cas,
            'chemical_name'  => trim($_POST['chemical_name'] ?? ''),
            'regulation_ref' => trim($_POST['regulation_ref'] ?? ''),
            'notes'          => trim($_POST['notes'] ?? '') ?: null,
        ]);

        AuditService::log('exempt_voc', $cas, 'create');
        $_SESSION['_flash']['success'] = "Exempt VOC {$cas} added.";
        redirect('/exempt-vocs');
    }

    public function editExemptVoc(string $id): void
    {
        $this->requirePageAccess('exempt_vocs', 'full');
        $db = Database::getInstance();
        $item = $db->fetch("SELECT * FROM exempt_voc_list WHERE id = ?", [(int) $id]);
        if (!$item) {
            $_SESSION['_flash']['error'] = 'Exempt VOC not found.';
            redirect('/exempt-vocs');
            return;
        }

        view('admin/exempt-voc-form', [
            'pageTitle' => 'Edit Exempt VOC: ' . $item['cas_number'],
            'item'      => $item,
            'mode'      => 'edit',
        ]);
    }

    public function updateExemptVoc(string $id): void
    {
        $this->requirePageAccess('exempt_vocs', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $db->update('exempt_voc_list', [
            'chemical_name'  => trim($_POST['chemical_name'] ?? ''),
            'regulation_ref' => trim($_POST['regulation_ref'] ?? ''),
            'notes'          => trim($_POST['notes'] ?? '') ?: null,
        ], 'id = ?', [(int) $id]);

        AuditService::log('exempt_voc', $id, 'update');
        $_SESSION['_flash']['success'] = 'Exempt VOC updated.';
        redirect('/exempt-vocs');
    }

    public function deleteExemptVoc(string $id): void
    {
        $this->requirePageAccess('exempt_vocs', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $item = $db->fetch("SELECT cas_number FROM exempt_voc_list WHERE id = ?", [(int) $id]);
        $db->query("DELETE FROM exempt_voc_list WHERE id = ?", [(int) $id]);

        AuditService::log('exempt_voc', $item['cas_number'] ?? $id, 'delete');
        $_SESSION['_flash']['success'] = 'Exempt VOC removed.';
        redirect('/exempt-vocs');
    }

    /* ------------------------------------------------------------------
     *  California Prop 65 List
     *
     *  Entries saved through this UI are tagged source_ref='manual' and
     *  are preserved verbatim by scripts/import-prop65-list.php so a
     *  future OEHHA refresh won't stomp them.
     * ----------------------------------------------------------------*/

    private const PROP65_TOXICITY_OPTIONS = [
        'cancer',
        'developmental',
        'female reproductive',
        'male reproductive',
    ];

    public function prop65(): void
    {
        $this->requirePageAccess('prop65_list');
        $db = Database::getInstance();

        $q      = trim((string) ($_GET['q'] ?? ''));
        $source = (string) ($_GET['source'] ?? 'all');

        $where  = [];
        $params = [];
        if ($q !== '') {
            $where[]  = '(cas_number LIKE ? OR chemical_name LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($source === 'manual') {
            $where[] = "source_ref = 'manual'";
        } elseif ($source === 'oehha') {
            $where[] = "source_ref LIKE 'OEHHA%'";
        } elseif ($source === 'seed') {
            $where[] = 'source_ref IS NULL';
        }
        $sql = 'SELECT * FROM prop65_list';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY chemical_name';

        $items = $db->fetchAll($sql, $params);

        $counts = $db->fetch(
            "SELECT
                SUM(CASE WHEN source_ref = 'manual'         THEN 1 ELSE 0 END) AS manual,
                SUM(CASE WHEN source_ref LIKE 'OEHHA%'      THEN 1 ELSE 0 END) AS oehha,
                SUM(CASE WHEN source_ref IS NULL            THEN 1 ELSE 0 END) AS seed,
                COUNT(*)                                                       AS total
             FROM prop65_list"
        );

        view('admin/prop65', [
            'pageTitle' => 'California Prop 65 List',
            'items'     => $items,
            'q'         => $q,
            'source'    => $source,
            'counts'    => $counts,
        ]);
    }

    public function createProp65(): void
    {
        $this->requirePageAccess('prop65_list', 'full');
        view('admin/prop65-form', [
            'pageTitle'        => 'Add Prop 65 Chemical',
            'item'             => null,
            'mode'             => 'create',
            'toxicityOptions'  => self::PROP65_TOXICITY_OPTIONS,
        ]);
    }

    public function storeProp65(): void
    {
        $this->requirePageAccess('prop65_list', 'full');
        CSRF::validateRequest();

        $db   = Database::getInstance();
        $data = $this->collectProp65Input();

        if ($data === null) {
            redirect('/prop65/create');
            return;
        }

        $existing = $db->fetch("SELECT id FROM prop65_list WHERE cas_number = ?", [$data['cas_number']]);
        if ($existing) {
            $_SESSION['_flash']['error'] = "CAS {$data['cas_number']} is already in the Prop 65 list. Edit the existing entry instead.";
            redirect('/prop65');
            return;
        }

        $db->insert('prop65_list', array_merge($data, ['source_ref' => 'manual']));

        $bumped = \SDS\Services\RegulatoryListBumper::bumpByCas($data['cas_number']);
        $queued = \SDS\Services\RegulatoryListBumper::queueSdsUpdatesByCas(
            [$data['cas_number']],
            current_user_id(),
            'Prop 65 listing added for CAS ' . $data['cas_number']
                . (($data['chemical_name'] ?? '') !== '' ? ' (' . $data['chemical_name'] . ')' : '')
        );
        AuditService::log('prop65_list', $data['cas_number'], 'create');
        $_SESSION['_flash']['success'] = "Prop 65 entry added for CAS {$data['cas_number']}." . self::bumpedTail($bumped) . self::queuedTail($queued);
        redirect('/prop65');
    }

    public function editProp65(string $id): void
    {
        $this->requirePageAccess('prop65_list', 'full');
        $db = Database::getInstance();

        $item = $db->fetch("SELECT * FROM prop65_list WHERE id = ?", [(int) $id]);
        if (!$item) {
            $_SESSION['_flash']['error'] = 'Prop 65 entry not found.';
            redirect('/prop65');
            return;
        }

        view('admin/prop65-form', [
            'pageTitle'       => 'Edit Prop 65 Chemical: ' . $item['cas_number'],
            'item'            => $item,
            'mode'            => 'edit',
            'toxicityOptions' => self::PROP65_TOXICITY_OPTIONS,
        ]);
    }

    public function updateProp65(string $id): void
    {
        $this->requirePageAccess('prop65_list', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $item = $db->fetch("SELECT * FROM prop65_list WHERE id = ?", [(int) $id]);
        if (!$item) {
            $_SESSION['_flash']['error'] = 'Prop 65 entry not found.';
            redirect('/prop65');
            return;
        }

        $data = $this->collectProp65Input();
        if ($data === null) {
            redirect('/prop65/' . (int) $id . '/edit');
            return;
        }

        // If the CAS changed, make sure the new CAS isn't already taken.
        if ($data['cas_number'] !== $item['cas_number']) {
            $clash = $db->fetch(
                "SELECT id FROM prop65_list WHERE cas_number = ? AND id <> ?",
                [$data['cas_number'], (int) $id]
            );
            if ($clash) {
                $_SESSION['_flash']['error'] = "CAS {$data['cas_number']} is already in the Prop 65 list.";
                redirect('/prop65/' . (int) $id . '/edit');
                return;
            }
        }

        $db->update(
            'prop65_list',
            array_merge($data, ['source_ref' => 'manual']),
            'id = ?',
            [(int) $id]
        );

        // Bump RMs for both the old CAS (if changed) and the new CAS,
        // since either side may now produce different SDS regulatory
        // text. Same-CAS edits dedupe naturally inside the bumper.
        $cases = [$data['cas_number']];
        if ($item['cas_number'] !== $data['cas_number']) {
            $cases[] = $item['cas_number'];
        }
        $bumped = \SDS\Services\RegulatoryListBumper::bumpByCasMany($cases);

        // Flag downstream published SDSs for review/regeneration so the
        // updated Prop 65 text (name, toxicity) propagates.
        $queued = \SDS\Services\RegulatoryListBumper::queueSdsUpdatesByCas(
            $cases,
            current_user_id(),
            'Prop 65 list updated for CAS ' . $data['cas_number']
                . (($data['chemical_name'] ?? '') !== '' ? ' (' . $data['chemical_name'] . ')' : '')
        );

        AuditService::log('prop65_list', $data['cas_number'], 'update');
        $_SESSION['_flash']['success'] = 'Prop 65 entry updated.' . self::bumpedTail($bumped) . self::queuedTail($queued);
        redirect('/prop65');
    }

    public function deleteProp65(string $id): void
    {
        $this->requirePageAccess('prop65_list', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $item = $db->fetch("SELECT cas_number FROM prop65_list WHERE id = ?", [(int) $id]);
        $db->query("DELETE FROM prop65_list WHERE id = ?", [(int) $id]);

        $bumped = 0;
        $queued = 0;
        if (isset($item['cas_number'])) {
            $bumped = \SDS\Services\RegulatoryListBumper::bumpByCas($item['cas_number']);
            $queued = \SDS\Services\RegulatoryListBumper::queueSdsUpdatesByCas(
                [$item['cas_number']],
                current_user_id(),
                'Prop 65 listing removed for CAS ' . $item['cas_number']
            );
        }

        AuditService::log('prop65_list', $item['cas_number'] ?? $id, 'delete');
        $_SESSION['_flash']['success'] = 'Prop 65 entry removed.' . self::bumpedTail($bumped) . self::queuedTail($queued);
        redirect('/prop65');
    }

    /**
     * Collect, validate, and normalize Prop 65 form input. Returns null
     * and sets a flash error on validation failure.
     */
    private function collectProp65Input(): ?array
    {
        $cas  = trim((string) ($_POST['cas_number'] ?? ''));
        $name = trim((string) ($_POST['chemical_name'] ?? ''));
        $date = trim((string) ($_POST['date_listed'] ?? ''));

        if ($cas === '' || !preg_match('/^\d{1,7}-\d{2}-\d$/', $cas)) {
            $_SESSION['_flash']['error'] = 'A valid CAS number is required (e.g. 15625-89-5).';
            $_SESSION['_flash']['_old_input'] = $_POST;
            return null;
        }
        if ($name === '') {
            $_SESSION['_flash']['error'] = 'Chemical name is required.';
            $_SESSION['_flash']['_old_input'] = $_POST;
            return null;
        }

        $rawTox = $_POST['toxicity_type'] ?? [];
        if (!is_array($rawTox)) { $rawTox = [$rawTox]; }
        $tox = [];
        foreach ($rawTox as $t) {
            $t = trim((string) $t);
            if (in_array($t, self::PROP65_TOXICITY_OPTIONS, true)) {
                $tox[] = $t;
            }
        }
        $tox = array_values(array_unique($tox));
        if ($tox === []) {
            $_SESSION['_flash']['error'] = 'Select at least one toxicity type.';
            $_SESSION['_flash']['_old_input'] = $_POST;
            return null;
        }

        $dateSql = null;
        if ($date !== '') {
            $ts = strtotime($date);
            if ($ts === false) {
                $_SESSION['_flash']['error'] = 'Date Listed must be a valid date (YYYY-MM-DD).';
                $_SESSION['_flash']['_old_input'] = $_POST;
                return null;
            }
            $dateSql = date('Y-m-d', $ts);
        }

        sort($tox);
        return [
            'cas_number'    => $cas,
            'chemical_name' => $name,
            'toxicity_type' => implode(',', $tox),
            'date_listed'   => $dateSql,
        ];
    }

    /* ------------------------------------------------------------------
     *  EPA HAP List (Clean Air Act §112(b))
     *
     *  Same pattern as the Prop 65 admin page. Entries saved through
     *  this UI are tagged source_ref='manual' so a future EPA-list
     *  importer (when it lands) can refresh seed-loaded rows without
     *  trampling operator additions.
     * ----------------------------------------------------------------*/

    public function haps(): void
    {
        $this->requirePageAccess('hap_list');
        $db = Database::getInstance();

        $q      = trim((string) ($_GET['q'] ?? ''));
        $source = (string) ($_GET['source'] ?? 'all');

        $where  = [];
        $params = [];
        if ($q !== '') {
            $where[]  = '(cas_number LIKE ? OR chemical_name LIKE ? OR category LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($source === 'manual') {
            $where[] = "source_ref = 'manual'";
        } elseif ($source === 'seed') {
            $where[] = "(source_ref IS NULL OR source_ref <> 'manual')";
        }
        $sql = 'SELECT * FROM hap_list';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY chemical_name';

        $items = $db->fetchAll($sql, $params);

        $counts = $db->fetch(
            "SELECT
                SUM(CASE WHEN source_ref = 'manual'                          THEN 1 ELSE 0 END) AS manual,
                SUM(CASE WHEN source_ref IS NULL OR source_ref <> 'manual'   THEN 1 ELSE 0 END) AS seed,
                COUNT(*) AS total
             FROM hap_list"
        );

        view('admin/haps', [
            'pageTitle' => 'EPA HAP List',
            'items'     => $items,
            'q'         => $q,
            'source'    => $source,
            'counts'    => $counts,
        ]);
    }

    public function createHap(): void
    {
        $this->requirePageAccess('hap_list', 'full');
        view('admin/haps-form', [
            'pageTitle' => 'Add HAP Entry',
            'item'      => null,
            'mode'      => 'create',
        ]);
    }

    public function storeHap(): void
    {
        $this->requirePageAccess('hap_list', 'full');
        CSRF::validateRequest();

        $db   = Database::getInstance();
        $data = $this->collectHapInput();
        if ($data === null) {
            redirect('/haps/create');
            return;
        }

        $existing = $db->fetch("SELECT id FROM hap_list WHERE cas_number = ?", [$data['cas_number']]);
        if ($existing) {
            $_SESSION['_flash']['error'] = "CAS {$data['cas_number']} is already in the HAP list. Edit the existing entry instead.";
            redirect('/haps');
            return;
        }

        $db->insert('hap_list', array_merge($data, ['source_ref' => 'manual']));

        $bumped = \SDS\Services\RegulatoryListBumper::bumpByCas($data['cas_number']);
        AuditService::log('hap_list', $data['cas_number'], 'create');
        $_SESSION['_flash']['success'] = "HAP entry added for CAS {$data['cas_number']}." . self::bumpedTail($bumped);
        redirect('/haps');
    }

    public function editHap(string $id): void
    {
        $this->requirePageAccess('hap_list', 'full');
        $db = Database::getInstance();

        $item = $db->fetch("SELECT * FROM hap_list WHERE id = ?", [(int) $id]);
        if (!$item) {
            $_SESSION['_flash']['error'] = 'HAP entry not found.';
            redirect('/haps');
            return;
        }

        view('admin/haps-form', [
            'pageTitle' => 'Edit HAP Entry: ' . $item['cas_number'],
            'item'      => $item,
            'mode'      => 'edit',
        ]);
    }

    public function updateHap(string $id): void
    {
        $this->requirePageAccess('hap_list', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $item = $db->fetch("SELECT * FROM hap_list WHERE id = ?", [(int) $id]);
        if (!$item) {
            $_SESSION['_flash']['error'] = 'HAP entry not found.';
            redirect('/haps');
            return;
        }

        $data = $this->collectHapInput();
        if ($data === null) {
            redirect('/haps/' . (int) $id . '/edit');
            return;
        }

        // CAS-uniqueness check on rename.
        if ($data['cas_number'] !== $item['cas_number']) {
            $clash = $db->fetch(
                "SELECT id FROM hap_list WHERE cas_number = ? AND id <> ?",
                [$data['cas_number'], (int) $id]
            );
            if ($clash) {
                $_SESSION['_flash']['error'] = "CAS {$data['cas_number']} is already in the HAP list.";
                redirect('/haps/' . (int) $id . '/edit');
                return;
            }
        }

        $db->update(
            'hap_list',
            array_merge($data, ['source_ref' => 'manual']),
            'id = ?',
            [(int) $id]
        );

        $cases = [$data['cas_number']];
        if ($item['cas_number'] !== $data['cas_number']) {
            $cases[] = $item['cas_number'];
        }
        $bumped = \SDS\Services\RegulatoryListBumper::bumpByCasMany($cases);

        AuditService::log('hap_list', $data['cas_number'], 'update');
        $_SESSION['_flash']['success'] = 'HAP entry updated.' . self::bumpedTail($bumped);
        redirect('/haps');
    }

    public function deleteHap(string $id): void
    {
        $this->requirePageAccess('hap_list', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $item = $db->fetch("SELECT cas_number FROM hap_list WHERE id = ?", [(int) $id]);
        $db->query("DELETE FROM hap_list WHERE id = ?", [(int) $id]);

        $bumped = isset($item['cas_number'])
            ? \SDS\Services\RegulatoryListBumper::bumpByCas($item['cas_number'])
            : 0;

        AuditService::log('hap_list', $item['cas_number'] ?? $id, 'delete');
        $_SESSION['_flash']['success'] = 'HAP entry removed.' . self::bumpedTail($bumped);
        redirect('/haps');
    }

    /**
     * Suffix for success flash messages reporting how many RMs were
     * bumped. Empty when zero — keeps the common "no constituents
     * carry this CAS yet" case from cluttering the message.
     */
    private static function bumpedTail(int $bumped): string
    {
        if ($bumped <= 0) {
            return '';
        }
        $unit = $bumped === 1 ? 'raw material' : 'raw materials';
        return " {$bumped} {$unit} flagged for re-publish.";
    }

    private static function queuedTail(int $queued): string
    {
        if ($queued <= 0) {
            return '';
        }
        $unit = $queued === 1 ? 'SDS' : 'SDSs';
        return " {$queued} {$unit} queued for update (see SDS Updates).";
    }

    /**
     * Collect, validate, and normalize HAP form input. Returns null
     * and sets a flash error on validation failure.
     */
    private function collectHapInput(): ?array
    {
        $cas      = trim((string) ($_POST['cas_number']    ?? ''));
        $name     = trim((string) ($_POST['chemical_name'] ?? ''));
        $category = trim((string) ($_POST['category']      ?? ''));

        if ($cas === '' || !preg_match('/^\d{1,7}-\d{2}-\d$/', $cas)) {
            $_SESSION['_flash']['error']     = 'A valid CAS number is required (e.g. 75-07-0).';
            $_SESSION['_flash']['_old_input'] = $_POST;
            return null;
        }
        if ($name === '') {
            $_SESSION['_flash']['error']     = 'Chemical name is required.';
            $_SESSION['_flash']['_old_input'] = $_POST;
            return null;
        }

        return [
            'cas_number'    => $cas,
            'chemical_name' => $name,
            'category'      => $category !== '' ? $category : null,
        ];
    }

    /* ------------------------------------------------------------------
     *  Competent Person Determinations
     * ----------------------------------------------------------------*/

    public function determinations(): void
    {
        $this->requirePageAccess('cas_determinations');
        $db = Database::getInstance();

        // Existing determinations
        $items = $db->fetchAll(
            "SELECT cpd.*, u.display_name AS created_by_name, ua.display_name AS approved_by_name
             FROM competent_person_determinations cpd
             LEFT JOIN users u ON u.id = cpd.created_by
             LEFT JOIN users ua ON ua.id = cpd.approved_by
             ORDER BY cpd.created_at DESC"
        );

        // All distinct CAS numbers from raw material constituents that do
        // not yet have an active determination, excluding non-hazardous and
        // trade-secret entries. CAS numbers with federal data are included
        // so users can review and complete the determination; a flag
        // indicates whether federal data is available.
        $needsDetermination = $db->fetchAll(
            "SELECT rmc.cas_number,
                    rmc.chemical_name,
                    COUNT(DISTINCT rm.id) AS raw_material_count,
                    GROUP_CONCAT(DISTINCT rm.internal_code ORDER BY rm.internal_code SEPARATOR ', ') AS raw_material_codes,
                    MAX(CASE WHEN EXISTS (
                        SELECT 1 FROM formula_lines fl
                        JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
                        WHERE fl.raw_material_id = rm.id
                    ) THEN 1 ELSE 0 END) AS in_formula,
                    MAX(CASE WHEN EXISTS (
                        SELECT 1 FROM hazard_classifications hc
                        JOIN hazard_source_records hsr ON hsr.id = hc.hazard_source_record_id
                        WHERE hc.cas_number = rmc.cas_number AND hsr.is_current = 1
                    ) OR EXISTS (
                        SELECT 1 FROM exposure_limits el
                        JOIN hazard_source_records hsr ON hsr.id = el.hazard_source_record_id
                        WHERE el.cas_number = rmc.cas_number AND hsr.is_current = 1
                    ) THEN 1 ELSE 0 END) AS has_federal_data
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id
             WHERE rmc.cas_number != ''
               AND rmc.is_trade_secret = 0
               AND rmc.is_non_hazardous = 0
               AND NOT EXISTS (
                   SELECT 1 FROM competent_person_determinations cpd
                   WHERE cpd.cas_number = rmc.cas_number AND cpd.is_active = 1
               )
             GROUP BY rmc.cas_number, rmc.chemical_name
             ORDER BY in_formula DESC, has_federal_data ASC, raw_material_count DESC, rmc.chemical_name ASC"
        );

        view('admin/determinations', [
            'pageTitle'          => 'CAS Number Determinations',
            'items'              => $items,
            'needsDetermination' => $needsDetermination,
        ]);
    }

    public function createDetermination(): void
    {
        $this->requirePageAccess('cas_determinations', 'full');

        // Pre-fill CAS number if passed via query string (from the needs-determination list)
        $prefillCas = trim($_GET['cas'] ?? '');
        $item = null;
        if ($prefillCas !== '') {
            $item = ['cas_number' => $prefillCas];

            // Pre-load federal data if available
            $federalDet = $this->buildFederalPrefill($prefillCas);
            if ($federalDet !== null) {
                $item['determination'] = $federalDet;
            }
        }

        view('admin/determination-form', [
            'pageTitle' => 'New CAS Number Determination',
            'item'      => $item,
            'mode'      => 'create',
        ]);
    }

    /**
     * Look up federal hazard classifications and exposure limits for a CAS
     * number and build a determination-compatible data structure to pre-fill
     * the form.
     *
     * @return array|null  Null if no federal data exists.
     */
    private function buildFederalPrefill(string $cas): ?array
    {
        $db = Database::getInstance();

        // Fetch federal hazard classifications
        $hazardRows = $db->fetchAll(
            "SELECT hc.class_name, hc.category, hc.signal_word,
                    hc.h_statements_json, hc.p_statements_json, hc.pictograms_json
             FROM hazard_classifications hc
             JOIN hazard_source_records hsr ON hsr.id = hc.hazard_source_record_id
             WHERE hc.cas_number = ? AND hsr.is_current = 1
             ORDER BY hsr.retrieved_at DESC",
            [$cas]
        );

        // Fetch federal exposure limits
        $limitRows = $db->fetchAll(
            "SELECT el.limit_type, el.value, el.units, el.notes
             FROM exposure_limits el
             JOIN hazard_source_records hsr ON hsr.id = el.hazard_source_record_id
             WHERE el.cas_number = ? AND hsr.is_current = 1",
            [$cas]
        );

        if (empty($hazardRows) && empty($limitRows)) {
            return null;
        }

        // Map federal hazard data to GHS determination keys
        $ghsData = \SDS\Services\GHSHazardData::HAZARD_CLASSIFICATIONS;
        $selectedHazards = [];
        $allHCodes = [];
        $allPCodes = [];
        $pictograms = [];
        $signalWord = null;
        $signalHierarchy = ['Danger' => 2, 'Warning' => 1];

        foreach ($hazardRows as $row) {
            $className = $row['class_name'] ?? '';
            $category  = $row['category'] ?? '';

            // Try to match to a GHS_DATA key ("ClassName - Category")
            $matchKey = $className . ' - ' . $category;
            if (isset($ghsData[$matchKey])) {
                $selectedHazards[] = $matchKey;
            }

            // Collect H-codes from federal data
            $hStmts = json_decode($row['h_statements_json'] ?? '[]', true);
            if (is_array($hStmts)) {
                foreach ($hStmts as $stmt) {
                    $code = is_string($stmt) ? $stmt : ($stmt['code'] ?? '');
                    if ($code !== '') {
                        $allHCodes[$code] = true;
                    }
                }
            }

            // Collect P-codes from federal data
            $pStmts = json_decode($row['p_statements_json'] ?? '[]', true);
            if (is_array($pStmts)) {
                foreach ($pStmts as $stmt) {
                    $code = is_string($stmt) ? $stmt : ($stmt['code'] ?? '');
                    if ($code !== '') {
                        $allPCodes[$code] = true;
                    }
                }
            }

            // Collect pictograms
            $pics = json_decode($row['pictograms_json'] ?? '[]', true);
            if (is_array($pics)) {
                foreach ($pics as $p) {
                    $pictograms[$p] = true;
                }
            }

            // Signal word (highest priority wins)
            $sw = $row['signal_word'] ?? null;
            if ($sw !== null) {
                $newPri = $signalHierarchy[$sw] ?? 0;
                $curPri = $signalWord ? ($signalHierarchy[$signalWord] ?? 0) : 0;
                if ($newPri > $curPri) {
                    $signalWord = $sw;
                }
            }
        }

        $selectedHazards = array_values(array_unique($selectedHazards));

        // Build exposure limits array
        $exposureLimits = [];
        foreach ($limitRows as $el) {
            if (empty($el['value'])) {
                continue;
            }
            $exposureLimits[] = [
                'limit_type' => $el['limit_type'] ?? '',
                'value'      => $el['value'] ?? '',
                'units'      => $el['units'] ?? 'mg/m3',
                'notes'      => $el['notes'] ?? '',
            ];
        }

        // Apply pictogram precedence
        $pictogramKeys = array_keys($pictograms);
        if (in_array('GHS06', $pictogramKeys) || in_array('GHS05', $pictogramKeys)) {
            $pictogramKeys = array_filter($pictogramKeys, fn($p) => $p !== 'GHS07');
        }
        sort($pictogramKeys);

        $hCodes = array_keys($allHCodes);
        sort($hCodes);
        $pCodes = array_keys($allPCodes);
        sort($pCodes);

        return [
            'selected_hazards' => json_encode($selectedHazards),
            'hazard_classes'   => '',
            'signal_word'      => $signalWord ?? '',
            'h_statements'     => implode(', ', $hCodes),
            'p_statements'     => implode(', ', $pCodes),
            'pictograms'       => implode(', ', $pictogramKeys),
            'exposure_limits'  => json_encode($exposureLimits),
            'basis'            => 'Pre-loaded from federal source data',
        ];
    }

    public function storeDetermination(): void
    {
        $this->requirePageAccess('cas_determinations', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $cas = trim($_POST['cas_number'] ?? '');
        $rationale = trim($_POST['rationale_text'] ?? '');

        if ($cas === '' || $rationale === '') {
            $_SESSION['_flash']['error'] = 'CAS number and rationale are required.';
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/determinations/create');
            return;
        }

        $determination = $this->buildDeterminationJson();

        $id = $db->insert('competent_person_determinations', [
            'cas_number'         => $cas,
            'jurisdiction'       => trim($_POST['jurisdiction'] ?? 'US'),
            'determination_json' => json_encode($determination),
            'rationale_text'     => $rationale,
            'is_active'          => 1,
            'created_by'         => current_user_id(),
        ]);

        AuditService::log('competent_determination', $id, 'create', ['cas' => $cas]);
        $_SESSION['_flash']['success'] = "Determination created for CAS {$cas}.";
        redirect('/determinations');
    }

    public function editDetermination(string $id): void
    {
        $this->requirePageAccess('cas_determinations', 'full');
        $db = Database::getInstance();

        $item = $db->fetch(
            "SELECT cpd.*, u.display_name AS created_by_name
             FROM competent_person_determinations cpd
             LEFT JOIN users u ON u.id = cpd.created_by
             WHERE cpd.id = ?",
            [(int) $id]
        );
        if (!$item) {
            $_SESSION['_flash']['error'] = 'Determination not found.';
            redirect('/determinations');
            return;
        }
        $item['determination'] = json_decode($item['determination_json'] ?? '{}', true);

        // Raw materials that carry this CAS — useful when reviewing a
        // determination to see what's actually affected. Flag which ones
        // are in-use by any current formula so the operator knows where
        // changes will propagate.
        $rawMaterials = $db->fetchAll(
            "SELECT rm.id,
                    rm.internal_code,
                    rm.supplier,
                    rm.supplier_product_name,
                    EXISTS (
                        SELECT 1 FROM formula_lines fl
                        JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
                        WHERE fl.raw_material_id = rm.id
                    ) AS in_formula
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id
             WHERE rmc.cas_number = ?
             GROUP BY rm.id, rm.internal_code, rm.supplier, rm.supplier_product_name
             ORDER BY rm.internal_code",
            [$item['cas_number']]
        );

        view('admin/determination-form', [
            'pageTitle'    => 'Edit CAS Determination: ' . $item['cas_number'],
            'item'         => $item,
            'mode'         => 'edit',
            'rawMaterials' => $rawMaterials,
        ]);
    }

    public function updateDetermination(string $id): void
    {
        $this->requirePageAccess('cas_determinations', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $determination = $this->buildDeterminationJson();

        $db->update('competent_person_determinations', [
            'rationale_text'     => trim($_POST['rationale_text'] ?? ''),
            'determination_json' => json_encode($determination),
            'is_active'          => isset($_POST['is_active']) ? 1 : 0,
            'approved_by'        => !empty($_POST['mark_approved']) ? current_user_id() : null,
        ], 'id = ?', [(int) $id]);

        AuditService::log('competent_determination', $id, 'update');
        $_SESSION['_flash']['success'] = 'Determination updated.';
        redirect('/determinations');
    }

    /**
     * Build the determination JSON from the admin's CAS determination form.
     * Thin wrapper around the shared GHSHelper — delegates merging of
     * H/P/pictogram/signal-word data from $_POST.
     */
    private function buildDeterminationJson(): array
    {
        return \SDS\Services\GHSHelper::buildDeterminationJson($_POST);
    }

    /* ------------------------------------------------------------------
     *  Pictograms
     * ----------------------------------------------------------------*/

    public function pictograms(): void
    {
        $this->requirePageAccess('pictograms');

        $codes = \SDS\Services\PictogramHelper::ALL_CODES;
        $names = \SDS\Services\PictogramHelper::NAMES;

        $items = [];
        foreach ($codes as $code) {
            $items[] = [
                'code'       => $code,
                'name'       => $names[$code] ?? $code,
                'web_path'   => \SDS\Services\PictogramHelper::getWebPath($code),
                'has_custom' => \SDS\Services\PictogramHelper::hasCustomUpload($code),
            ];
        }

        view('admin/pictograms', [
            'pageTitle' => 'Pictograms',
            'items'     => $items,
        ]);
    }

    public function uploadPictogram(string $code): void
    {
        $this->requirePageAccess('pictograms', 'full');
        CSRF::validateRequest();

        if (!in_array($code, \SDS\Services\PictogramHelper::ALL_CODES, true)) {
            $_SESSION['_flash']['error'] = 'Invalid pictogram code.';
            redirect('/admin/pictograms');
            return;
        }

        if (empty($_FILES['pictogram_file']) || $_FILES['pictogram_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['_flash']['error'] = 'No file uploaded or upload error.';
            redirect('/admin/pictograms');
            return;
        }

        $file = $_FILES['pictogram_file'];

        // Validate size (2 MB max)
        if ($file['size'] > 2 * 1024 * 1024) {
            $_SESSION['_flash']['error'] = 'File is too large. Maximum size is 2 MB.';
            redirect('/admin/pictograms');
            return;
        }

        // Validate MIME type
        $allowed = ['image/png', 'image/jpeg', 'image/gif'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            $_SESSION['_flash']['error'] = 'Invalid file type. Only PNG, JPG, and GIF are accepted.';
            redirect('/admin/pictograms');
            return;
        }

        $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
        $ext = $extMap[$mime];

        // Delete any existing custom upload for this code
        \SDS\Services\PictogramHelper::deleteCustomUpload($code);

        // Save the new file
        $uploadDir = \SDS\Services\PictogramHelper::getUploadDir();
        $destPath = $uploadDir . '/' . $code . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $_SESSION['_flash']['error'] = 'Failed to save the uploaded file.';
            redirect('/admin/pictograms');
            return;
        }

        $name = \SDS\Services\PictogramHelper::NAMES[$code] ?? $code;
        AuditService::log('pictogram', 0, 'upload', ['code' => $code, 'name' => $name]);
        $_SESSION['_flash']['success'] = "Pictogram \"{$name}\" ({$code}) updated successfully.";
        redirect('/admin/pictograms');
    }

    public function deletePictogram(string $code): void
    {
        $this->requirePageAccess('pictograms', 'full');
        CSRF::validateRequest();

        if (!in_array($code, \SDS\Services\PictogramHelper::ALL_CODES, true)) {
            $_SESSION['_flash']['error'] = 'Invalid pictogram code.';
            redirect('/admin/pictograms');
            return;
        }

        \SDS\Services\PictogramHelper::deleteCustomUpload($code);

        $name = \SDS\Services\PictogramHelper::NAMES[$code] ?? $code;
        AuditService::log('pictogram', 0, 'revert', ['code' => $code, 'name' => $name]);
        $_SESSION['_flash']['success'] = "Pictogram \"{$name}\" ({$code}) reverted to default.";
        redirect('/admin/pictograms');
    }

    /* ------------------------------------------------------------------
     *  Federal Data
     * ----------------------------------------------------------------*/

    /**
     * GET /admin/echa-import — ECHA CLP Annex VI M-factor import page.
     * Shows current M-factor count, last-imported file, and an upload
     * form. Upload drops the CSV at seeds/echa_m_factors.csv and runs
     * import-echa-m-factors.php.
     */
    public function echaImport(): void
    {
        $this->requirePageAccess('federal_data');

        $db = Database::getInstance();

        // Current state: how many hazard_classifications rows carry an
        // M-factor value, and when was the last import?
        $counts = $db->fetch(
            "SELECT
                SUM(CASE WHEN m_factor_acute   IS NOT NULL THEN 1 ELSE 0 END) AS acute_cnt,
                SUM(CASE WHEN m_factor_chronic IS NOT NULL THEN 1 ELSE 0 END) AS chronic_cnt
             FROM hazard_classifications"
        );

        $csvPath = App::basePath() . '/seeds/echa_m_factors.csv';
        $csvExists = file_exists($csvPath);
        $csvMtime  = $csvExists ? date('Y-m-d H:i:s', filemtime($csvPath)) : null;
        $csvSize   = $csvExists ? filesize($csvPath) : 0;

        view('admin/echa-import', [
            'pageTitle'  => 'ECHA M-Factor Import',
            'acuteCount'   => (int) ($counts['acute_cnt']   ?? 0),
            'chronicCount' => (int) ($counts['chronic_cnt'] ?? 0),
            'csvExists'  => $csvExists,
            'csvMtime'   => $csvMtime,
            'csvSize'    => $csvSize,
            'flash'      => $_SESSION['_flash']['echa'] ?? null,
        ]);
        unset($_SESSION['_flash']['echa']);
    }

    /**
     * POST /admin/echa-import/upload — accept a CSV upload, save it to
     * seeds/echa_m_factors.csv, run the import script, and display the
     * results. Idempotent — upload the same file twice and no new
     * synthetic rows are created.
     */
    public function uploadEchaCsv(): void
    {
        $this->requirePageAccess('federal_data', 'full');
        CSRF::validateRequest();

        $file = $_FILES['echa_csv'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $_SESSION['_flash']['echa'] = ['type' => 'error', 'msg' => 'No file uploaded, or upload failed.'];
            redirect('/admin/echa-import');
        }

        // Sanity check: must be CSV-ish (size, extension). Don't verify
        // full schema here — import-echa-m-factors.php does that and
        // reports specific parse problems per row.
        $name = (string) ($file['name'] ?? '');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            $_SESSION['_flash']['echa'] = ['type' => 'error', 'msg' => "File must be .csv (got .{$ext}). If you have an .xls/.xlsx, open in Excel and Save As CSV UTF-8 first."];
            redirect('/admin/echa-import');
        }
        if ((int) $file['size'] > 50 * 1024 * 1024) {
            $_SESSION['_flash']['echa'] = ['type' => 'error', 'msg' => 'File is too large (>50 MB). ECHA Annex VI Table 3 is typically <5 MB as CSV.'];
            redirect('/admin/echa-import');
        }

        // Save to seeds/echa_m_factors.csv, overwriting any prior import.
        $dest = App::basePath() . '/seeds/echa_m_factors.csv';
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $_SESSION['_flash']['echa'] = ['type' => 'error', 'msg' => 'Failed to save uploaded file. Check filesystem permissions on seeds/.'];
            redirect('/admin/echa-import');
        }

        // Run import synchronously, capture output.
        $script = App::basePath() . '/scripts/import-echa-m-factors.php';
        $cmd = escapeshellarg(\php_cli_binary()) . ' '
             . escapeshellarg($script) . ' '
             . escapeshellarg($dest) . ' 2>&1';

        $output = shell_exec($cmd);
        $exit   = 0;  // shell_exec doesn't return exit code; rely on output patterns

        AuditService::log('echa_import', basename($dest), 'upload', [
            'size'   => (int) $file['size'],
            'summary' => self::extractEchaSummary((string) $output),
        ]);

        $_SESSION['_flash']['echa'] = [
            'type'   => 'success',
            'msg'    => 'Import complete.',
            'output' => (string) $output,
        ];
        redirect('/admin/echa-import');
    }

    /**
     * Pull the "Summary" section out of the import script's stdout so
     * the audit log captures row counts without storing the whole dump.
     */
    private static function extractEchaSummary(string $output): string
    {
        if (preg_match('/=== Summary ===(.*)$/s', $output, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    public function federalData(): void
    {
        $this->requirePageAccess('federal_data');

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
        $this->requirePageAccess('federal_data', 'full');
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

        $search = trim($_GET['search'] ?? '');

        $db = Database::getInstance();

        $sql = "SELECT sv.*, fg.product_code, fg.description,
                       u.display_name AS published_by_name,
                       a.customer_code AS alias_customer_code,
                       a.description   AS alias_description
                FROM sds_versions sv
                JOIN finished_goods fg ON fg.id = sv.finished_good_id
                LEFT JOIN users u ON u.id = sv.published_by
                LEFT JOIN aliases a ON a.id = sv.alias_id";

        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= " WHERE (fg.product_code LIKE ?
                         OR fg.description LIKE ?
                         OR a.customer_code LIKE ?
                         OR a.description LIKE ?)";
            $params = [$like, $like, $like, $like];
        }

        $sql .= " ORDER BY sv.created_at DESC LIMIT 200";

        $versions = $db->fetchAll($sql, $params);

        view('admin/sds-versions', [
            'pageTitle' => 'SDS Versions',
            'versions'  => $versions,
            'search'    => $search,
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

    /* ------------------------------------------------------------------
     *  Backups
     * ----------------------------------------------------------------*/

    public function backups(): void
    {
        $this->requireAdmin();

        $backups   = BackupService::listAll();
        $sections  = BackupService::SECTIONS;
        $ftpConfig = BackupService::getFtpConfig();

        view('admin/backups', [
            'pageTitle' => 'Backup & Restore',
            'backups'   => $backups,
            'sections'  => $sections,
            'ftpConfig' => $ftpConfig,
        ]);
    }

    public function createBackup(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $validTypes = array_merge(['full'], array_keys(BackupService::SECTIONS));
        $type  = in_array($_POST['backup_type'] ?? '', $validTypes, true)
               ? $_POST['backup_type']
               : 'full';
        $notes = trim($_POST['notes'] ?? '') ?: null;

        try {
            $result = BackupService::create($type, $notes);
            AuditService::log('backup', (string) $result['id'], 'create', [
                'type'     => $type,
                'filename' => $result['filename'],
            ]);

            $sizeStr = self::formatBytes($result['file_size']);
            $_SESSION['_flash']['success'] = "Backup created: {$result['filename']} ({$sizeStr})";
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Backup failed: ' . $e->getMessage();
        }

        redirect('/admin/backups');
    }

    public function uploadBackup(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $notes = trim($_POST['notes'] ?? '') ?: null;

        try {
            $file = $_FILES['backup_file'] ?? null;
            if (!$file) {
                throw new \RuntimeException('No file was uploaded.');
            }

            $result = BackupService::importUploadedFile($file, $notes);
            AuditService::log('backup', (string) $result['id'], 'upload', [
                'filename' => $result['filename'],
                'size'     => $result['file_size'],
            ]);

            $sizeStr = self::formatBytes($result['file_size']);
            $_SESSION['_flash']['success'] = "Backup uploaded: {$result['filename']} ({$sizeStr}). You can now restore it from the list below.";
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Upload failed: ' . $e->getMessage();
        }

        redirect('/admin/backups');
    }

    public function restoreBackup(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        if (empty($_POST['confirm_restore'])) {
            $_SESSION['_flash']['error'] = 'You must confirm to restore a backup.';
            redirect('/admin/backups');
            return;
        }

        try {
            $result = BackupService::restore((int) $id, true);
            AuditService::log('backup', $id, 'restore', [
                'type'            => $result['type'] ?? 'unknown',
                'tables_restored' => $result['tables_restored'],
            ]);

            $typeLabel = $result['type'] ?? 'unknown';
            $sectionInfo = BackupService::SECTIONS[$typeLabel]['label'] ?? ucfirst(str_replace('_', ' ', $typeLabel));
            $_SESSION['_flash']['success'] = "Backup restored successfully ({$sectionInfo})."
                . ($result['files_restored'] ? ' Files were also restored.' : '');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Restore failed: ' . $e->getMessage();
        }

        redirect('/admin/backups');
    }

    public function deleteBackup(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        BackupService::delete((int) $id);
        AuditService::log('backup', $id, 'delete');
        $_SESSION['_flash']['success'] = 'Backup deleted.';
        redirect('/admin/backups');
    }

    public function downloadBackup(string $id): void
    {
        $this->requireAdmin();

        $path = BackupService::getFilePath((int) $id);
        if ($path === null) {
            $_SESSION['_flash']['error'] = 'Backup file not found.';
            redirect('/admin/backups');
            return;
        }

        $filename = basename($path);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function saveFtpSettings(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $config = [
            'ftp_enabled'        => !empty($_POST['ftp_enabled']) ? '1' : '0',
            'ftp_host'           => trim($_POST['ftp_host'] ?? ''),
            'ftp_port'           => trim($_POST['ftp_port'] ?? '21'),
            'ftp_username'       => trim($_POST['ftp_username'] ?? ''),
            'ftp_password'       => $_POST['ftp_password'] ?? '',
            'ftp_path'           => trim($_POST['ftp_path'] ?? ''),
            'ftp_passive'        => !empty($_POST['ftp_passive']) ? '1' : '0',
            'ftp_ssl'            => !empty($_POST['ftp_ssl']) ? '1' : '0',
            'schedule_enabled'   => !empty($_POST['schedule_enabled']) ? '1' : '0',
            'schedule_frequency' => in_array($_POST['schedule_frequency'] ?? '', ['daily', 'weekly', 'monthly'], true)
                                     ? $_POST['schedule_frequency'] : 'daily',
            'schedule_type'      => in_array($_POST['schedule_type'] ?? '', array_merge(['full'], array_keys(BackupService::SECTIONS)), true)
                                     ? $_POST['schedule_type'] : 'full',
            'schedule_time'      => preg_match('/^\d{2}:\d{2}$/', $_POST['schedule_time'] ?? '') ? $_POST['schedule_time'] : '02:00',
            'schedule_retention' => max(1, min(100, (int) ($_POST['schedule_retention'] ?? 10))),
        ];

        BackupService::saveFtpConfig($config);
        AuditService::log('backup_settings', 'ftp', 'update');
        $_SESSION['_flash']['success'] = 'Backup schedule and FTP settings saved.';
        redirect('/admin/backups');
    }

    public function testFtpConnection(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $config = [
            'ftp_host'     => trim($_POST['ftp_host'] ?? ''),
            'ftp_port'     => trim($_POST['ftp_port'] ?? '21'),
            'ftp_username' => trim($_POST['ftp_username'] ?? ''),
            'ftp_password' => $_POST['ftp_password'] ?? '',
            'ftp_path'     => trim($_POST['ftp_path'] ?? ''),
            'ftp_passive'  => !empty($_POST['ftp_passive']) ? '1' : '0',
            'ftp_ssl'      => !empty($_POST['ftp_ssl']) ? '1' : '0',
        ];

        $result = BackupService::testFtpConnection($config);

        if ($result['success']) {
            $_SESSION['_flash']['success'] = $result['message'];
        } else {
            $_SESSION['_flash']['error'] = $result['message'];
        }

        redirect('/admin/backups');
    }

    public function uploadBackupToFtp(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $path = BackupService::getFilePath((int) $id);
        if ($path === null) {
            $_SESSION['_flash']['error'] = 'Backup file not found.';
            redirect('/admin/backups');
            return;
        }

        $result = BackupService::uploadToFtp($path, basename($path));
        AuditService::log('backup', $id, 'ftp_upload', [
            'success' => $result['success'],
            'message' => $result['message'],
        ]);

        if ($result['success']) {
            $_SESSION['_flash']['success'] = $result['message'];
        } else {
            $_SESSION['_flash']['error'] = $result['message'];
        }

        redirect('/admin/backups');
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /* ------------------------------------------------------------------
     *  Storage
     * ----------------------------------------------------------------*/

    public function storage(): void
    {
        $this->requireAdmin();

        $basePath = \SDS\Core\App::basePath();

        // Total Drive (/ filesystem)
        $totalBytes = (int) (@disk_total_space('/') ?: 0);
        $freeBytes  = (int) (@disk_free_space('/') ?: 0);
        $usedBytes  = $totalBytes - $freeBytes;

        $categories = [];

        $categories[] = [
            'label'        => 'Total Drive',
            'total'        => self::formatBytes($totalBytes),
            'used'         => self::formatBytes($usedBytes),
            'free'         => self::formatBytes($freeBytes),
            'used_percent' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : 0,
            'is_drive'     => true,
        ];

        // Directory-based categories
        $dirs = [
            'Supplier SDSs'         => $basePath . '/public/uploads/supplier-sds',
            'Generated PDFs'        => $basePath . '/public/generated-pdfs',
            'Logs, Cache, and Temp'  => $basePath . '/storage',
        ];

        foreach ($dirs as $label => $path) {
            $bytes = 0;
            if (is_dir($path)) {
                $bytes = $this->directorySize($path);
            }
            $categories[] = [
                'label'        => $label,
                'size'         => self::formatBytes($bytes),
                'used_percent' => $totalBytes > 0 ? round(($bytes / $totalBytes) * 100, 1) : 0,
                'is_drive'     => false,
            ];
        }

        view('admin/storage', [
            'pageTitle'   => 'Storage',
            'categories'  => $categories,
        ]);
    }

    /**
     * Recursively calculate the total size of a directory in bytes.
     */
    private function directorySize(string $path): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    /* ------------------------------------------------------------------
     *  Training Data
     * ----------------------------------------------------------------*/

    public function trainingData(): void
    {
        $this->requireAdmin();

        $db = Database::getInstance();
        $rmCount = (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM raw_materials")['cnt'] ?? 0);
        $fgCount = (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM finished_goods")['cnt'] ?? 0);

        view('admin/training-data', [
            'pageTitle'      => 'Training Data',
            'rawMaterialCount' => $rmCount,
            'finishedGoodCount' => $fgCount,
        ]);
    }

    public function generateTrainingData(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $db = Database::getInstance();

        // Prevent generating if data already exists
        $rmCount = (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM raw_materials")['cnt'] ?? 0);
        $fgCount = (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM finished_goods")['cnt'] ?? 0);

        if ($rmCount > 0 || $fgCount > 0) {
            $_SESSION['_flash']['error'] = 'Training data cannot be generated while raw materials or finished goods exist. Use Purge Data first to clear existing data.';
            redirect('/admin/training-data');
            return;
        }

        try {
            $result = TrainingDataService::generate(current_user_id());
            AuditService::log('system', 'training_data', 'generate', $result);

            $_SESSION['_flash']['success'] = sprintf(
                'Training data created: %d raw materials, %d finished goods, %d formulas.',
                $result['raw_materials'],
                $result['finished_goods'],
                $result['formulas']
            );
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Training data generation failed: ' . $e->getMessage();
        }

        redirect('/admin/training-data');
    }

    public function downloadTrainingCsv(string $type): void
    {
        $this->requireAdmin();

        if ($type === 'alias') {
            $csv = TrainingDataService::generateAliasCsv();
            $filename = 'training_item_aliases.csv';
        } elseif ($type === 'shipping') {
            $csv = TrainingDataService::generateShippingCsv();
            $filename = 'training_shipping_detail.csv';
        } else {
            $_SESSION['_flash']['error'] = 'Invalid CSV type.';
            redirect('/admin/training-data');
            return;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $csv;
        exit;
    }

    /* ------------------------------------------------------------------
     *  Purge Data
     * ----------------------------------------------------------------*/

    public function purgeData(): void
    {
        $this->requireAdmin();

        view('admin/purge-data', [
            'pageTitle' => 'Purge All Data',
        ]);
    }

    public function executePurgeData(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        // 1. Verify the confirmation keyword is exactly "DELETE"
        $confirmation = $_POST['confirm_delete'] ?? '';
        if ($confirmation !== 'DELETE') {
            $_SESSION['_flash']['error'] = 'You must type DELETE (in all caps) to confirm the data purge.';
            redirect('/admin/purge-data');
            return;
        }

        // 2. Re-authenticate admin credentials
        $username = trim($_POST['admin_username'] ?? '');
        $password = $_POST['admin_password'] ?? '';

        if ($username === '' || $password === '') {
            $_SESSION['_flash']['error'] = 'Admin username and password are required.';
            redirect('/admin/purge-data');
            return;
        }

        $admin = User::authenticate($username, $password);
        if ($admin === false) {
            $_SESSION['_flash']['error'] = 'Invalid admin credentials. The purge was NOT executed.';
            redirect('/admin/purge-data');
            return;
        }

        // Verify the authenticated user is in an admin permission group
        $adminGroups = PermissionService::getUserGroups((int) $admin['id']);
        $isAdminUser = false;
        foreach ($adminGroups as $g) {
            if ((int) $g['is_admin']) {
                $isAdminUser = true;
                break;
            }
        }
        if (!$isAdminUser) {
            $_SESSION['_flash']['error'] = 'Invalid admin credentials. The purge was NOT executed.';
            redirect('/admin/purge-data');
            return;
        }

        // 3. Execute the purge
        $db = Database::getInstance();

        try {
            $db->query("SET FOREIGN_KEY_CHECKS = 0");

            // Tables to truncate — user-created content only.
            // Preserved: settings, users, schema_migrations, pictograms (files),
            // seed/regulatory data (sara313_list, exempt_voc_list, hap_list,
            // prop65_list, carcinogen_list), and hazard/exposure reference data
            // (hazard_source_records, hazard_classifications, exposure_limits,
            // dot_transport_info, cas_master).
            $tables = [
                'sds_generation_trace',
                'text_overrides',
                'sds_versions',
                'formula_lines',
                'formulas',
                'raw_material_sds',
                'raw_material_constituents',
                'raw_materials',
                'finished_goods',
                'competent_person_determinations',
                'dataset_refresh_log',
                'audit_log',
                'backups',
            ];

            foreach ($tables as $table) {
                $db->query("TRUNCATE TABLE `{$table}`");
            }

            $db->query("SET FOREIGN_KEY_CHECKS = 1");

            // 4. Remove uploaded files (supplier SDS, generated PDFs) but NOT pictograms
            $basePath = \SDS\Core\App::basePath();
            $dirsToClean = [
                $basePath . '/public/uploads/supplier-sds',
                $basePath . '/public/generated-pdfs',
            ];
            foreach ($dirsToClean as $dir) {
                if (is_dir($dir)) {
                    $this->removeDirectoryContents($dir);
                }
            }

            // 5. Log the purge (new audit_log entry after truncation)
            AuditService::log('system', 'purge', 'purge_all_data', [
                'executed_by' => $username,
                'tables_purged' => $tables,
            ]);

            $_SESSION['_flash']['success'] = 'All data has been purged. Settings, users, pictograms, and regulatory seed data were preserved.';
        } catch (\Throwable $e) {
            $db->query("SET FOREIGN_KEY_CHECKS = 1");
            $_SESSION['_flash']['error'] = 'Purge failed: ' . $e->getMessage();
        }

        redirect('/admin/purge-data');
    }

    /**
     * Remove all files and subdirectories within a directory, but keep the directory itself.
     */
    private function removeDirectoryContents(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    /* ------------------------------------------------------------------
     *  Network Settings
     * ----------------------------------------------------------------*/

    public function networkSettings(): void
    {
        $this->requireAdmin();

        $config = NetworkService::getCurrentConfig();

        view('admin/network-settings', [
            'pageTitle' => 'Network Settings',
            'network'   => $config,
        ]);
    }

    public function saveNetworkSettings(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        if (empty($_POST['confirm_network'])) {
            $_SESSION['_flash']['error'] = 'You must confirm that you understand the risk of disconnection.';
            redirect('/admin/network-settings');
            return;
        }

        $config = [
            'ip_address'  => $_POST['ip_address'] ?? '',
            'subnet_mask' => $_POST['subnet_mask'] ?? '',
            'cidr'        => $_POST['cidr'] ?? '',
            'gateway'     => $_POST['gateway'] ?? '',
            'dns_servers' => $_POST['dns_servers'] ?? '',
        ];

        $result = NetworkService::applyConfig($config);

        AuditService::log('network_settings', 'system', 'update', [
            'ip'      => $config['ip_address'],
            'cidr'    => $config['cidr'],
            'gateway' => $config['gateway'],
            'success' => $result['success'],
            'method'  => $result['method'],
        ]);

        // Also update the app server URL setting to match the new IP
        if ($result['success'] && !empty($config['ip_address'])) {
            $db = Database::getInstance();
            $protocol = 'http';
            $currentUrl = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'app.server_url'");
            if ($currentUrl && str_starts_with($currentUrl['value'] ?? '', 'https')) {
                $protocol = 'https';
            }
            $this->saveSetting($db, 'app.server_url', "{$protocol}://{$config['ip_address']}");
        }

        if ($result['success']) {
            $_SESSION['_flash']['success'] = $result['message']
                . ' The server URL has been updated. You may need to reconnect at the new address.';
        } else {
            $_SESSION['_flash']['error'] = $result['message'];
        }

        redirect('/admin/network-settings');
    }

    /* ------------------------------------------------------------------
     *  SNUR List Management
     * ----------------------------------------------------------------*/

    public function snurList(): void
    {
        $this->requirePageAccess('snur_list');

        $db = Database::getInstance();
        $snurs = $db->fetchAll("SELECT * FROM snur_list ORDER BY cas_number ASC");

        view('admin/snur-list', [
            'pageTitle' => 'SNUR List',
            'snurs'     => $snurs,
        ]);
    }

    public function storeSnur(): void
    {
        $this->requirePageAccess('snur_list', 'full');
        CSRF::validateRequest();

        $db = Database::getInstance();

        $casNumber    = trim($_POST['cas_number'] ?? '');
        $chemicalName = trim($_POST['chemical_name'] ?? '');
        $ruleCitation = trim($_POST['rule_citation'] ?? '');
        $description  = trim($_POST['description'] ?? '');

        if ($casNumber === '' || $chemicalName === '') {
            $_SESSION['_flash']['error'] = 'CAS number and chemical name are required.';
            redirect('/admin/snur-list');
            return;
        }

        // Check for duplicate CAS
        $existing = $db->fetch("SELECT id FROM snur_list WHERE cas_number = ?", [$casNumber]);
        if ($existing) {
            // Update existing
            $db->update('snur_list', [
                'chemical_name' => $chemicalName,
                'rule_citation' => $ruleCitation ?: null,
                'description'   => $description ?: null,
            ], 'id = ?', [(int) $existing['id']]);
            $_SESSION['_flash']['success'] = "SNUR entry for CAS {$casNumber} updated.";
        } else {
            $db->insert('snur_list', [
                'cas_number'    => $casNumber,
                'chemical_name' => $chemicalName,
                'rule_citation' => $ruleCitation ?: null,
                'description'   => $description ?: null,
            ]);
            $_SESSION['_flash']['success'] = "SNUR entry for CAS {$casNumber} added.";
        }

        AuditService::log('snur_list', $casNumber, 'upsert', [
            'cas_number'    => $casNumber,
            'chemical_name' => $chemicalName,
        ]);

        redirect('/admin/snur-list');
    }

    public function deleteSnur(string $id): void
    {
        $this->requirePageAccess('snur_list', 'full');
        CSRF::validateRequest();

        $db = Database::getInstance();
        $entry = $db->fetch("SELECT cas_number FROM snur_list WHERE id = ?", [(int) $id]);
        if ($entry) {
            $db->query("DELETE FROM snur_list WHERE id = ?", [(int) $id]);
            AuditService::log('snur_list', $entry['cas_number'], 'delete');
            $_SESSION['_flash']['success'] = 'SNUR entry removed.';
        }

        redirect('/admin/snur-list');
    }

    /* ------------------------------------------------------------------
     *  SARA 313 List Management
     * ----------------------------------------------------------------*/

    public function sara313List(): void
    {
        $this->requirePageAccess('sara313');

        $db = Database::getInstance();
        $search = trim($_GET['search'] ?? '');
        $pbtFilter = $_GET['is_pbt'] ?? '';

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = '(cas_number LIKE ? OR chemical_name LIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
        }
        if ($pbtFilter !== '') {
            $where[] = 'is_pbt = ?';
            $params[] = (int) $pbtFilter;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $items = $db->fetchAll(
            "SELECT * FROM sara313_list {$whereSQL} ORDER BY chemical_name ASC",
            $params
        );

        $totalCount = $db->fetch("SELECT COUNT(*) AS cnt FROM sara313_list");

        view('admin/sara313-list', [
            'pageTitle'  => 'SARA 313 List',
            'items'      => $items,
            'totalCount' => (int) ($totalCount['cnt'] ?? 0),
            'search'     => $search,
            'pbtFilter'  => $pbtFilter,
        ]);
    }

    public function createSara313(): void
    {
        $this->requirePageAccess('sara313', 'full');

        view('admin/sara313-form', [
            'pageTitle' => 'Add SARA 313 Chemical',
            'item'      => null,
            'mode'      => 'create',
        ]);
    }

    public function storeSara313(): void
    {
        $this->requirePageAccess('sara313', 'full');
        CSRF::validateRequest();

        $db = Database::getInstance();
        $cas = trim($_POST['cas_number'] ?? '');

        if ($cas === '' || !preg_match('/^\d{1,7}-\d{2}-\d$/', $cas)) {
            $_SESSION['_flash']['error'] = 'A valid CAS number is required (format: digits-digits-digit).';
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/admin/sara313/create');
            return;
        }

        $existing = $db->fetch("SELECT id FROM sara313_list WHERE cas_number = ?", [$cas]);
        if ($existing) {
            $_SESSION['_flash']['error'] = "CAS {$cas} is already in the SARA 313 list.";
            redirect('/admin/sara313');
            return;
        }

        $db->insert('sara313_list', [
            'cas_number'        => $cas,
            'chemical_name'     => trim($_POST['chemical_name'] ?? ''),
            'category_code'     => trim($_POST['category_code'] ?? '') ?: null,
            'deminimis_pct'     => ($_POST['deminimis_pct'] ?? '') !== '' ? (float) $_POST['deminimis_pct'] : 1.0,
            'is_pbt'            => !empty($_POST['is_pbt']) ? 1 : 0,
            'pbt_threshold_pct' => ($_POST['pbt_threshold_pct'] ?? '') !== '' ? (float) $_POST['pbt_threshold_pct'] : null,
            'source_ref'        => trim($_POST['source_ref'] ?? '') ?: null,
            'last_updated_at'   => date('Y-m-d H:i:s'),
        ]);

        AuditService::log('sara313_list', $cas, 'create');
        $_SESSION['_flash']['success'] = "SARA 313 chemical {$cas} added.";
        redirect('/admin/sara313');
    }

    public function editSara313(string $id): void
    {
        $this->requirePageAccess('sara313', 'full');
        $db = Database::getInstance();
        $item = $db->fetch("SELECT * FROM sara313_list WHERE id = ?", [(int) $id]);
        if (!$item) {
            $_SESSION['_flash']['error'] = 'SARA 313 entry not found.';
            redirect('/admin/sara313');
            return;
        }

        view('admin/sara313-form', [
            'pageTitle' => 'Edit SARA 313: ' . $item['cas_number'],
            'item'      => $item,
            'mode'      => 'edit',
        ]);
    }

    public function updateSara313(string $id): void
    {
        $this->requirePageAccess('sara313', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $item = $db->fetch("SELECT * FROM sara313_list WHERE id = ?", [(int) $id]);
        if (!$item) {
            $_SESSION['_flash']['error'] = 'SARA 313 entry not found.';
            redirect('/admin/sara313');
            return;
        }

        $db->update('sara313_list', [
            'chemical_name'     => trim($_POST['chemical_name'] ?? ''),
            'category_code'     => trim($_POST['category_code'] ?? '') ?: null,
            'deminimis_pct'     => ($_POST['deminimis_pct'] ?? '') !== '' ? (float) $_POST['deminimis_pct'] : 1.0,
            'is_pbt'            => !empty($_POST['is_pbt']) ? 1 : 0,
            'pbt_threshold_pct' => ($_POST['pbt_threshold_pct'] ?? '') !== '' ? (float) $_POST['pbt_threshold_pct'] : null,
            'source_ref'        => trim($_POST['source_ref'] ?? '') ?: null,
            'last_updated_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $id]);

        AuditService::log('sara313_list', $item['cas_number'], 'update');
        $_SESSION['_flash']['success'] = 'SARA 313 entry updated.';
        redirect('/admin/sara313');
    }

    public function deleteSara313(string $id): void
    {
        $this->requirePageAccess('sara313', 'full');
        CSRF::validateRequest();
        $db = Database::getInstance();

        $item = $db->fetch("SELECT cas_number FROM sara313_list WHERE id = ?", [(int) $id]);
        if ($item) {
            $db->query("DELETE FROM sara313_list WHERE id = ?", [(int) $id]);
            AuditService::log('sara313_list', $item['cas_number'], 'delete');
            $_SESSION['_flash']['success'] = 'SARA 313 entry removed.';
        }

        redirect('/admin/sara313');
    }

    public function importSara313(): void
    {
        $this->requirePageAccess('sara313', 'full');
        CSRF::validateRequest();

        if (!isset($_FILES['sara313_file']) || $_FILES['sara313_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['_flash']['error'] = 'Please select a valid CSV file.';
            redirect('/admin/sara313');
            return;
        }

        $ext = strtolower(pathinfo($_FILES['sara313_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            $_SESSION['_flash']['error'] = 'Only CSV files are supported.';
            redirect('/admin/sara313');
            return;
        }

        $result = SARA313Service::importFromCsv($_FILES['sara313_file']['tmp_name']);

        AuditService::log('sara313_list', '0', 'import', [
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
        ]);

        $msg = "CSV imported: {$result['inserted']} added, {$result['updated']} updated.";
        if (!empty($result['errors'])) {
            $msg .= ' ' . count($result['errors']) . ' error(s): ' . implode('; ', array_slice($result['errors'], 0, 5));
        }

        $_SESSION['_flash']['success'] = $msg;
        redirect('/admin/sara313');
    }
}
