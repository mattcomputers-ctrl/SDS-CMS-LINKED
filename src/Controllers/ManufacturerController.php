<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Models\Manufacturer;
use SDS\Services\AuditService;

class ManufacturerController
{
    public function index(): void
    {
        if (!can_read('manufacturers')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/');
        }

        $search = trim($_GET['search'] ?? '');
        $manufacturers = Manufacturer::all(['search' => $search]);

        view('manufacturers/index', [
            'pageTitle'     => 'Manufacturers',
            'manufacturers' => $manufacturers,
            'search'        => $search,
        ]);
    }

    public function create(): void
    {
        if (!can_edit('manufacturers')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/manufacturers');
        }

        view('manufacturers/form', [
            'pageTitle' => 'Add Manufacturer',
            'mode'      => 'create',
            'item'      => [],
        ]);
    }

    public function store(): void
    {
        if (!can_edit('manufacturers')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/manufacturers');
        }

        CSRF::validateRequest();

        $data = $this->extractFormData();
        $data['created_by'] = current_user_id();

        // Handle logo upload
        $logoPath = $this->processLogoUpload();

        if (is_string($logoPath)) {
            $data['logo_path'] = $logoPath;
        }

        try {
            $id = Manufacturer::create($data);

            AuditService::log('manufacturer', (string) $id, 'create', $data);

            $_SESSION['_flash']['success'] = 'Manufacturer created successfully.';
            redirect('/manufacturers');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/manufacturers/create');
        }
    }

    public function edit(string $id): void
    {
        if (!can_read('manufacturers')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/manufacturers');
        }

        $item = Manufacturer::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Manufacturer not found.';
            redirect('/manufacturers');
        }

        view('manufacturers/form', [
            'pageTitle' => 'Edit Manufacturer: ' . $item['name'],
            'mode'      => 'edit',
            'item'      => $item,
        ]);
    }

    public function update(string $id): void
    {
        if (!can_edit('manufacturers')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/manufacturers');
        }

        CSRF::validateRequest();

        $item = Manufacturer::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Manufacturer not found.';
            redirect('/manufacturers');
        }

        $data = $this->extractFormData();

        // Handle logo upload
        $logoPath = $this->processLogoUpload((int) $id);
        if (is_string($logoPath)) {
            $data['logo_path'] = $logoPath;
        }

        // Handle logo removal
        if (!empty($_POST['remove_logo']) && !empty($item['logo_path'])) {
            $oldPath = App::basePath() . '/public' . $item['logo_path'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
            $data['logo_path'] = null;
        }

        try {
            Manufacturer::update((int) $id, $data);

            AuditService::log('manufacturer', $id, 'update', $data);

            $_SESSION['_flash']['success'] = 'Manufacturer updated successfully.';
            redirect('/manufacturers');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/manufacturers/' . $id . '/edit');
        }
    }

    public function delete(string $id): void
    {
        if (!can_edit('manufacturers')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/manufacturers');
        }

        CSRF::validateRequest();

        try {
            $item = Manufacturer::findById((int) $id);
            if ($item === null) {
                $_SESSION['_flash']['error'] = 'Manufacturer not found.';
                redirect('/manufacturers');
            }

            // Delete logo file if present
            if (!empty($item['logo_path'])) {
                $logoFile = App::basePath() . '/public' . $item['logo_path'];
                if (file_exists($logoFile)) {
                    @unlink($logoFile);
                }
            }

            Manufacturer::delete((int) $id);

            AuditService::log('manufacturer', $id, 'delete', ['name' => $item['name']]);

            $_SESSION['_flash']['success'] = 'Manufacturer deleted.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/manufacturers');
    }

    private function extractFormData(): array
    {
        return [
            'name'            => $_POST['name'] ?? '',
            'address'         => $_POST['address'] ?? '',
            'city'            => $_POST['city'] ?? '',
            'state'           => $_POST['state'] ?? '',
            'zip'             => $_POST['zip'] ?? '',
            'country'         => $_POST['country'] ?? '',
            'phone'           => $_POST['phone'] ?? '',
            'emergency_phone' => $_POST['emergency_phone'] ?? '',
            'email'           => $_POST['email'] ?? '',
            'website'         => $_POST['website'] ?? '',
        ];
    }

    /**
     * Process manufacturer logo upload.
     *
     * @return string|null  Web-accessible logo path on success, null if no upload.
     */
    private function processLogoUpload(?int $manufacturerId = null): ?string
    {
        if (empty($_FILES['logo']['tmp_name']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $file = $_FILES['logo'];

        if ($file['size'] > 2 * 1024 * 1024) {
            $_SESSION['_flash']['warning'] = 'Logo file too large (max 2 MB). Other changes saved.';
            return null;
        }

        $allowed = ['image/png', 'image/jpeg', 'image/gif'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            $_SESSION['_flash']['warning'] = 'Invalid logo file type. Only PNG, JPG, GIF accepted.';
            return null;
        }

        $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
        $ext = $extMap[$mime];

        $uploadDir = App::basePath() . '/public/uploads/manufacturers';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Delete previous logo if updating
        if ($manufacturerId !== null) {
            $existing = Manufacturer::findById($manufacturerId);
            if ($existing && !empty($existing['logo_path'])) {
                $oldPath = App::basePath() . '/public' . $existing['logo_path'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
        }

        $filename = 'mfg-logo-' . ($manufacturerId ?? bin2hex(random_bytes(4))) . '.' . $ext;
        $destPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $_SESSION['_flash']['warning'] = 'Failed to save logo file.';
            return null;
        }

        return '/uploads/manufacturers/' . $filename;
    }
}
