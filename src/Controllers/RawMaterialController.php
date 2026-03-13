<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\RawMaterial;
use SDS\Services\AuditService;

class RawMaterialController
{
    public function index(): void
    {
        $filters = [
            'search'   => $_GET['search'] ?? '',
            'supplier' => $_GET['supplier'] ?? '',
            'page'     => (int) ($_GET['page'] ?? 1),
            'per_page' => 25,
            'sort'     => $_GET['sort'] ?? 'internal_code',
            'dir'      => $_GET['dir'] ?? 'asc',
        ];

        $items = RawMaterial::all($filters);
        $total = RawMaterial::count($filters);

        view('raw-materials/index', [
            'pageTitle' => 'Raw Materials',
            'items'     => $items,
            'total'     => $total,
            'filters'   => $filters,
            'pages'     => (int) ceil($total / $filters['per_page']),
        ]);
    }

    public function create(): void
    {
        if (!can_edit('raw_materials')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to create raw materials.';
            redirect('/raw-materials');
        }

        view('raw-materials/form', [
            'pageTitle'              => 'Add Raw Material',
            'item'                   => null,
            'mode'                   => 'create',
            'constituents'           => [],
            'sdsHistory'             => [],
            'tradeSecretDescriptions' => $this->loadTradeSecretDescriptions(),
        ]);
    }

    public function store(): void
    {
        if (!can_edit('raw_materials')) {
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $data = $_POST;
        $data['created_by'] = current_user_id();

        // Process checkbox fields (unchecked = not in POST)
        $data['voc_less_than_one'] = !empty($data['voc_less_than_one']) ? 1 : 0;
        $data['flash_point_greater_than'] = !empty($data['flash_point_greater_than']) ? 1 : 0;

        // Build HAPs data JSON from form arrays
        $data['haps_data'] = $this->buildHapsJson();

        // Build Prop 65 data JSON from form arrays
        $prop65Json = $this->buildProp65Json();
        $data['prop65_data'] = $prop65Json;
        $data['is_prop65'] = ($prop65Json !== null) ? 1 : 0;

        try {
            // Handle SDS file upload
            $sdsInfo = $this->handleSdsUpload();
            if ($sdsInfo !== null) {
                $data['supplier_sds_path'] = $sdsInfo['path'];
            }

            $id = RawMaterial::create($data);
            AuditService::log('raw_material', $id, 'create', $data);

            // Save SDS to history table if uploaded
            if ($sdsInfo !== null) {
                RawMaterial::addSds(
                    $id,
                    $sdsInfo['path'],
                    $sdsInfo['original_name'],
                    $sdsInfo['size'],
                    null,
                    current_user_id()
                );
            }

            // Save constituents if provided
            $this->saveConstituentsFromPost($id);

            $_SESSION['_flash']['success'] = 'Raw material created successfully.';
            redirect('/raw-materials');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $data;
            redirect('/raw-materials/create');
        }
    }

    public function edit(string $id): void
    {
        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        $sdsHistory = RawMaterial::getSdsHistory((int) $id);

        view('raw-materials/form', [
            'pageTitle'              => 'Edit: ' . $item['internal_code'],
            'item'                   => $item,
            'mode'                   => 'edit',
            'constituents'           => $item['constituents'] ?? [],
            'sdsHistory'             => $sdsHistory,
            'tradeSecretDescriptions' => $this->loadTradeSecretDescriptions(),
        ]);
    }

    public function update(string $id): void
    {
        if (!can_edit('raw_materials')) {
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        $data = $_POST;
        $data['expected_updated_at'] = $data['updated_at'] ?? null;

        // Process checkbox fields (unchecked = not in POST)
        $data['voc_less_than_one'] = !empty($data['voc_less_than_one']) ? 1 : 0;
        $data['flash_point_greater_than'] = !empty($data['flash_point_greater_than']) ? 1 : 0;

        // Build HAPs data JSON from form arrays
        $data['haps_data'] = $this->buildHapsJson();

        // Build Prop 65 data JSON from form arrays
        $prop65Json = $this->buildProp65Json();
        $data['prop65_data'] = $prop65Json;
        $data['is_prop65'] = ($prop65Json !== null) ? 1 : 0;

        try {
            // Handle SDS file upload — always adds to history, never removes old
            $sdsInfo = $this->handleSdsUpload();
            if ($sdsInfo !== null) {
                $data['supplier_sds_path'] = $sdsInfo['path'];

                // Add to SDS history
                RawMaterial::addSds(
                    (int) $id,
                    $sdsInfo['path'],
                    $sdsInfo['original_name'],
                    $sdsInfo['size'],
                    trim($data['sds_notes'] ?? '') ?: null,
                    current_user_id()
                );
            }

            $diff = AuditService::diff($item, $data);
            RawMaterial::update((int) $id, $data);
            AuditService::log('raw_material', $id, 'update', $diff);

            // Save constituents
            $this->saveConstituentsFromPost((int) $id);

            $_SESSION['_flash']['success'] = 'Raw material updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials/' . $id . '/edit');
    }

    public function delete(string $id): void
    {
        if (!can_manage_users()) {
            $_SESSION['_flash']['error'] = 'Only administrators can delete raw materials.';
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        try {
            RawMaterial::delete((int) $id);
            AuditService::log('raw_material', $id, 'delete');
            $_SESSION['_flash']['success'] = 'Raw material deleted.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials');
    }

    /**
     * Legacy constituents page — redirects to the edit page where constituents are now inline.
     */
    public function constituents(string $id): void
    {
        redirect('/raw-materials/' . $id . '/edit');
    }

    public function viewSds(string $id): void
    {
        $item = RawMaterial::findById((int) $id);
        if ($item === null || empty($item['supplier_sds_path'])) {
            $_SESSION['_flash']['error'] = 'SDS not found for this raw material.';
            redirect('/raw-materials');
        }

        $pdfPath = \SDS\Core\App::basePath() . '/public/uploads/' . $item['supplier_sds_path'];

        if (!file_exists($pdfPath)) {
            $_SESSION['_flash']['error'] = 'SDS file not found on disk.';
            redirect('/raw-materials/' . $id . '/edit');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="SDS_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $item['internal_code']) . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        exit;
    }

    /**
     * View a specific historical SDS file by its raw_material_sds ID.
     */
    public function viewSdsVersion(string $sdsId): void
    {
        $db = \SDS\Core\Database::getInstance();
        $sds = $db->fetch(
            "SELECT rms.*, rm.internal_code
             FROM raw_material_sds rms
             JOIN raw_materials rm ON rm.id = rms.raw_material_id
             WHERE rms.id = ?",
            [(int) $sdsId]
        );

        if ($sds === null) {
            $_SESSION['_flash']['error'] = 'SDS version not found.';
            redirect('/raw-materials');
        }

        $pdfPath = \SDS\Core\App::basePath() . '/public/uploads/' . $sds['file_path'];

        if (!file_exists($pdfPath)) {
            $_SESSION['_flash']['error'] = 'SDS file not found on disk.';
            redirect('/raw-materials/' . $sds['raw_material_id'] . '/edit');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="SDS_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sds['internal_code']) . '_v' . $sds['id'] . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        exit;
    }

    /**
     * Legacy saveConstituents route — now handled via the main update flow.
     */
    public function saveConstituents(string $id): void
    {
        if (!can_edit('raw_materials')) {
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        try {
            $count = $this->saveConstituentsFromPost((int) $id);
            AuditService::log('raw_material', $id, 'update_constituents', [
                'count' => $count,
            ]);
            $_SESSION['_flash']['success'] = 'Constituents saved (' . $count . ' entries).';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials/' . $id . '/edit');
    }

    /**
     * AJAX endpoint: look up a CAS number and return chemical name,
     * exposure limits, and regulatory list membership.
     */
    public function casLookup(): void
    {
        header('Content-Type: application/json');

        $cas = trim($_GET['cas'] ?? '');
        if ($cas === '' || !preg_match('/^\d{2,7}-\d{2}-\d$/', $cas)) {
            echo json_encode(['found' => false]);
            exit;
        }

        try {
            $result = RawMaterial::lookupCas($cas);
            if ($result !== null) {
                $response = [
                    'found'         => true,
                    'cas_number'    => $result['cas_number'],
                    'chemical_name' => $result['chemical_name'],
                ];

                // Attach regulatory list membership
                $lists = RawMaterial::getRegulatoryLists($cas);
                if (!empty($lists)) {
                    $response['regulatory_lists'] = $lists;
                }

                // Attach exposure limits (grouped by source)
                $limits = RawMaterial::getExposureLimits($cas);
                if (!empty($limits)) {
                    $grouped = [];
                    foreach ($limits as $lim) {
                        $src = $lim['source_name'];
                        if (!isset($grouped[$src])) {
                            $grouped[$src] = [];
                        }
                        $grouped[$src][] = [
                            'type'  => $lim['limit_type'],
                            'value' => $lim['value'],
                            'units' => $lim['units'],
                        ];
                    }
                    $response['exposure_limits'] = $grouped;
                }

                echo json_encode($response);
            } else {
                echo json_encode(['found' => false]);
            }
        } catch (\Throwable $e) {
            echo json_encode(['found' => false, 'error' => 'Lookup failed']);
        }
        exit;
    }

    /**
     * Parse constituent data from POST and save.
     *
     * @return int Number of constituents saved.
     */
    private function saveConstituentsFromPost(int $rmId): int
    {
        $constituents = [];
        $casNumbers   = $_POST['cas_number'] ?? [];
        $chemNames    = $_POST['chemical_name'] ?? [];
        $pctMins      = $_POST['pct_min'] ?? [];
        $pctMaxs      = $_POST['pct_max'] ?? [];
        $pctExacts    = $_POST['pct_exact'] ?? [];
        $secrets      = $_POST['is_trade_secret'] ?? [];
        $tsDescs      = $_POST['trade_secret_description'] ?? [];
        $nonHazardous = $_POST['is_non_hazardous'] ?? [];

        foreach ($casNumbers as $i => $cas) {
            $cas = trim($cas);
            if ($cas === '') {
                continue;
            }

            $constituents[] = [
                'cas_number'               => $cas,
                'chemical_name'            => trim($chemNames[$i] ?? ''),
                'pct_min'                  => ($pctMins[$i] ?? '') !== '' ? (float) $pctMins[$i] : null,
                'pct_max'                  => ($pctMaxs[$i] ?? '') !== '' ? (float) $pctMaxs[$i] : null,
                'pct_exact'                => ($pctExacts[$i] ?? '') !== '' ? (float) $pctExacts[$i] : null,
                'is_trade_secret'          => isset($secrets[$i]) ? 1 : 0,
                'trade_secret_description' => isset($secrets[$i]) ? trim($tsDescs[$i] ?? '') : null,
                'is_non_hazardous'         => isset($nonHazardous[$i]) ? 1 : 0,
                'sort_order'               => $i + 1,
            ];
        }

        RawMaterial::saveConstituents($rmId, $constituents);
        return count($constituents);
    }

    /**
     * Build Prop 65 data JSON from the form's repeating Prop 65 rows.
     */
    private function buildProp65Json(): ?string
    {
        $chemNames = $_POST['p65_chemical_name'] ?? [];
        $casNums   = $_POST['p65_cas_number'] ?? [];
        $isTrace   = $_POST['p65_is_trace'] ?? [];
        $toxCancer = $_POST['p65_tox_cancer'] ?? [];
        $toxDev    = $_POST['p65_tox_developmental'] ?? [];
        $toxRepro  = $_POST['p65_tox_reproductive'] ?? [];
        $toxFemale = $_POST['p65_tox_female_reproductive'] ?? [];
        $toxMale   = $_POST['p65_tox_male_reproductive'] ?? [];

        $entries = [];
        foreach ($chemNames as $i => $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $types = [];
            if (!empty($toxCancer[$i])) {
                $types[] = 'cancer';
            }
            if (!empty($toxDev[$i])) {
                $types[] = 'developmental';
            }
            if (!empty($toxRepro[$i])) {
                $types[] = 'reproductive';
            }
            if (!empty($toxFemale[$i])) {
                $types[] = 'female reproductive';
            }
            if (!empty($toxMale[$i])) {
                $types[] = 'male reproductive';
            }

            $entries[] = [
                'chemical_name'  => $name,
                'cas_number'     => trim($casNums[$i] ?? ''),
                'toxicity_types' => implode(', ', $types),
                'is_trace'       => !empty($isTrace[$i]) ? 1 : 0,
            ];
        }

        return !empty($entries) ? json_encode($entries) : null;
    }

    /**
     * Build HAPs data JSON from the form's repeating HAP rows.
     */
    private function buildHapsJson(): ?string
    {
        $hapNames   = $_POST['hap_chemical_name'] ?? [];
        $hapCas     = $_POST['hap_cas_number'] ?? [];
        $hapWtPcts  = $_POST['hap_weight_pct'] ?? [];

        $haps = [];
        foreach ($hapNames as $i => $name) {
            $name = trim($name);
            $wt   = (float) ($hapWtPcts[$i] ?? 0);
            if ($name === '' || $wt <= 0) {
                continue;
            }
            $haps[] = [
                'chemical_name' => $name,
                'cas_number'    => trim($hapCas[$i] ?? ''),
                'weight_pct'    => $wt,
            ];
        }

        return !empty($haps) ? json_encode($haps) : null;
    }

    /**
     * Handle supplier SDS PDF upload.
     *
     * @return array|null  Array with 'path', 'original_name', 'size' on success, null if no upload.
     * @throws \RuntimeException on validation failure.
     */
    private function handleSdsUpload(): ?array
    {
        if (empty($_FILES['supplier_sds']) || $_FILES['supplier_sds']['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $file = $_FILES['supplier_sds'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('SDS file upload failed (error code: ' . $file['error'] . ').');
        }

        // Validate size (20 MB max)
        $maxSize = 20 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new \RuntimeException('SDS file exceeds the 20 MB limit.');
        }

        // Validate MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if ($mime !== 'application/pdf') {
            throw new \RuntimeException('SDS file must be a PDF (detected: ' . $mime . ').');
        }

        // Generate unique filename
        $uploadDir = \SDS\Core\App::basePath() . '/public/uploads/supplier-sds';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = $safeName . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $destPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \RuntimeException('Failed to save uploaded SDS file.');
        }

        return [
            'path'          => 'supplier-sds/' . $filename,
            'original_name' => $file['name'],
            'size'          => $file['size'],
        ];
    }

    /**
     * Load trade secret description options from admin settings.
     *
     * @return string[]
     */
    private function loadTradeSecretDescriptions(): array
    {
        $db = \SDS\Core\Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.trade_secret_descriptions'");
        if (!$row || empty($row['value'])) {
            return [];
        }
        return array_filter(array_map('trim', explode("\n", $row['value'])));
    }
}
