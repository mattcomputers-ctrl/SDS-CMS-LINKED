<?php

declare(strict_types=1);

namespace SDS\Controllers;

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
            $key = preg_replace('/[^a-zA-Z0-9_.]/', '', $key);
            if ($key === '') {
                continue;
            }
            // Convert double-underscore separator back to dot for DB storage
            $key = str_replace('__', '.', $key);

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

        view('admin/determination-form', [
            'pageTitle' => 'Edit CAS Determination: ' . $item['cas_number'],
            'item'      => $item,
            'mode'      => 'edit',
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
     * Build the determination JSON from checkbox-based form submission.
     *
     * Merges auto-populated codes (from hazard statement selections) with
     * manually selected H/P codes, resolves signal word and pictograms,
     * and includes exposure limit data.
     */
    private function buildDeterminationJson(): array
    {
        // Selected hazard classifications (checkbox keys)
        $selectedHazards = json_decode($_POST['selected_hazards_json'] ?? '[]', true) ?: [];

        // Resolve auto-populated data from GHS hazard data
        $ghsData = \SDS\Services\GHSHazardData::all();
        $autoHCodes = [];
        $autoPCodes = [];
        $autoPictograms = [];
        $autoSignalWord = null;
        $hazardClasses = [];
        $signalHierarchy = ['Danger' => 2, 'Warning' => 1];

        foreach ($selectedHazards as $key) {
            if (!isset($ghsData[$key])) {
                continue;
            }
            $entry = $ghsData[$key];
            $hazardClasses[] = $entry['class'] . ' ' . $entry['category'];

            foreach ($entry['h_codes'] as $code) {
                $autoHCodes[$code] = true;
            }
            foreach ($entry['p_codes'] as $code) {
                $autoPCodes[$code] = true;
            }
            foreach ($entry['pictograms'] as $pic) {
                $autoPictograms[$pic] = true;
            }
            if ($entry['signal_word'] !== null) {
                $newPri = $signalHierarchy[$entry['signal_word']] ?? 0;
                $curPri = $autoSignalWord ? ($signalHierarchy[$autoSignalWord] ?? 0) : 0;
                if ($newPri > $curPri) {
                    $autoSignalWord = $entry['signal_word'];
                }
            }
        }

        // Manually selected H/P codes
        $manualHCodes = $_POST['h_codes_manual'] ?? [];
        $manualPCodes = $_POST['p_codes_manual'] ?? [];

        // Merge auto + manual codes (de-duplicate)
        $allHCodes = array_keys($autoHCodes);
        foreach ($manualHCodes as $code) {
            $code = trim($code);
            if ($code !== '' && !in_array($code, $allHCodes, true)) {
                $allHCodes[] = $code;
            }
        }
        sort($allHCodes);

        $allPCodes = array_keys($autoPCodes);
        foreach ($manualPCodes as $code) {
            $code = trim($code);
            if ($code !== '' && !in_array($code, $allPCodes, true)) {
                $allPCodes[] = $code;
            }
        }
        sort($allPCodes);

        // Apply pictogram precedence
        $pictogramKeys = array_keys($autoPictograms);
        if (in_array('GHS06', $pictogramKeys) || in_array('GHS05', $pictogramKeys)) {
            $pictogramKeys = array_filter($pictogramKeys, fn($p) => $p !== 'GHS07');
        }
        sort($pictogramKeys);

        // Signal word: manual override takes precedence
        $signalWord = trim($_POST['signal_word'] ?? '');
        if ($signalWord === '') {
            $signalWord = $autoSignalWord ?? '';
        }

        // Exposure limits
        $exposureLimits = json_decode($_POST['exposure_limits_json'] ?? '[]', true) ?: [];

        return [
            'selected_hazards' => json_encode($selectedHazards),
            'hazard_classes'   => implode(', ', array_unique($hazardClasses)),
            'signal_word'      => $signalWord,
            'h_statements'     => implode(', ', $allHCodes),
            'p_statements'     => implode(', ', $allPCodes),
            'pictograms'       => implode(', ', $pictogramKeys),
            'exposure_limits'  => json_encode($exposureLimits),
            'basis'            => trim($_POST['basis'] ?? ''),
        ];
    }

    /* ------------------------------------------------------------------
     *  Pictograms
     * ----------------------------------------------------------------*/

    public function pictograms(): void
    {
        $this->requireAdmin();

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
        $this->requireAdmin();
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
        $this->requireAdmin();
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

    /* ------------------------------------------------------------------
     *  Backups
     * ----------------------------------------------------------------*/

    public function backups(): void
    {
        $this->requireAdmin();

        $backups = BackupService::listAll();

        view('admin/backups', [
            'pageTitle' => 'Backup &amp; Restore',
            'backups'   => $backups,
        ]);
    }

    public function createBackup(): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        $type  = in_array($_POST['backup_type'] ?? '', ['full', 'content'], true)
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

    public function restoreBackup(string $id): void
    {
        $this->requireAdmin();
        CSRF::validateRequest();

        if (empty($_POST['confirm_restore'])) {
            $_SESSION['_flash']['error'] = 'You must check the confirmation box to restore a backup.';
            redirect('/admin/backups');
            return;
        }

        try {
            $result = BackupService::restore((int) $id, true);
            AuditService::log('backup', $id, 'restore');

            $_SESSION['_flash']['success'] = 'Backup restored successfully.'
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
        $this->requireAdmin();

        $db = Database::getInstance();
        $snurs = $db->fetchAll("SELECT * FROM snur_list ORDER BY cas_number ASC");

        view('admin/snur-list', [
            'pageTitle' => 'SNUR List',
            'snurs'     => $snurs,
        ]);
    }

    public function storeSnur(): void
    {
        $this->requireAdmin();
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
        $this->requireAdmin();
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
}
