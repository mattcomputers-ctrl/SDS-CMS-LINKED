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
        if (!is_editor()) {
            $_SESSION['_flash']['error'] = 'You do not have permission to create raw materials.';
            redirect('/raw-materials');
        }

        view('raw-materials/form', [
            'pageTitle' => 'Add Raw Material',
            'item'      => null,
            'mode'      => 'create',
        ]);
    }

    public function store(): void
    {
        if (!is_editor()) {
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $data = $_POST;
        $data['created_by'] = current_user_id();

        try {
            // Handle SDS file upload
            $sdsPath = $this->handleSdsUpload();
            if ($sdsPath !== null) {
                $data['supplier_sds_path'] = $sdsPath;
            }

            $id = RawMaterial::create($data);
            AuditService::log('raw_material', $id, 'create', $data);
            $_SESSION['_flash']['success'] = 'Raw material created successfully.';
            redirect('/raw-materials/' . $id . '/edit');
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

        view('raw-materials/form', [
            'pageTitle' => 'Edit: ' . $item['internal_code'],
            'item'      => $item,
            'mode'      => 'edit',
        ]);
    }

    public function update(string $id): void
    {
        if (!is_editor()) {
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

        try {
            // Handle SDS removal
            if (!empty($data['remove_sds']) && !empty($item['supplier_sds_path'])) {
                $fullPath = \SDS\Core\App::basePath() . '/public/uploads/' . $item['supplier_sds_path'];
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
                $data['supplier_sds_path'] = null;
            }

            // Handle SDS file upload (replaces existing)
            $sdsPath = $this->handleSdsUpload();
            if ($sdsPath !== null) {
                // Remove old file if it exists
                if (!empty($item['supplier_sds_path'])) {
                    $oldPath = \SDS\Core\App::basePath() . '/public/uploads/' . $item['supplier_sds_path'];
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
                $data['supplier_sds_path'] = $sdsPath;
            }

            $diff = AuditService::diff($item, $data);
            RawMaterial::update((int) $id, $data);
            AuditService::log('raw_material', $id, 'update', $diff);
            $_SESSION['_flash']['success'] = 'Raw material updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials/' . $id . '/edit');
    }

    public function delete(string $id): void
    {
        if (!is_admin()) {
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

    public function constituents(string $id): void
    {
        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        view('raw-materials/constituents', [
            'pageTitle'    => 'Constituents: ' . $item['internal_code'],
            'item'         => $item,
            'constituents' => $item['constituents'] ?? [],
        ]);
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

    public function saveConstituents(string $id): void
    {
        if (!is_editor()) {
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        $constituents = [];
        $casNumbers   = $_POST['cas_number'] ?? [];
        $chemNames    = $_POST['chemical_name'] ?? [];
        $pctMins      = $_POST['pct_min'] ?? [];
        $pctMaxs      = $_POST['pct_max'] ?? [];
        $pctExacts    = $_POST['pct_exact'] ?? [];
        $secrets      = $_POST['is_trade_secret'] ?? [];

        foreach ($casNumbers as $i => $cas) {
            $cas = trim($cas);
            if ($cas === '') {
                continue;
            }

            $constituents[] = [
                'cas_number'      => $cas,
                'chemical_name'   => trim($chemNames[$i] ?? ''),
                'pct_min'         => ($pctMins[$i] ?? '') !== '' ? (float) $pctMins[$i] : null,
                'pct_max'         => ($pctMaxs[$i] ?? '') !== '' ? (float) $pctMaxs[$i] : null,
                'pct_exact'       => ($pctExacts[$i] ?? '') !== '' ? (float) $pctExacts[$i] : null,
                'is_trade_secret' => isset($secrets[$i]) ? 1 : 0,
                'sort_order'      => $i + 1,
            ];
        }

        try {
            RawMaterial::saveConstituents((int) $id, $constituents);
            AuditService::log('raw_material', $id, 'update_constituents', [
                'count' => count($constituents),
            ]);
            $_SESSION['_flash']['success'] = 'Constituents saved (' . count($constituents) . ' entries).';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials/' . $id . '/constituents');
    }

    /**
     * Handle supplier SDS PDF upload.
     *
     * @return string|null  Relative path under public/uploads/ on success, null if no upload.
     * @throws \RuntimeException on validation failure.
     */
    private function handleSdsUpload(): ?string
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

        return 'supplier-sds/' . $filename;
    }
}
