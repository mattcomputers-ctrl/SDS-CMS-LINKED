<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Models\LabelTemplate;

class LabelTemplateController
{
    public function index(): void
    {
        $templates = LabelTemplate::all();

        view('label-templates/index', [
            'pageTitle' => 'Label Templates',
            'templates' => $templates,
        ]);
    }

    public function create(): void
    {
        view('label-templates/edit', [
            'pageTitle'  => 'Create Label Template',
            'template'   => null,
            'fieldTypes' => LabelTemplate::fieldTypes(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validateInput();
        if ($data === null) {
            redirect('/label-templates/create');
            return;
        }

        LabelTemplate::create($data);
        $_SESSION['_flash']['success'] = 'Label template created.';
        redirect('/label-templates');
    }

    public function edit(string $id): void
    {
        $template = LabelTemplate::findById((int) $id);
        if (!$template) {
            $_SESSION['_flash']['error'] = 'Template not found.';
            redirect('/label-templates');
            return;
        }

        view('label-templates/edit', [
            'pageTitle'  => 'Edit Label Template',
            'template'   => $template,
            'fieldTypes' => LabelTemplate::fieldTypes(),
        ]);
    }

    public function update(string $id): void
    {
        $template = LabelTemplate::findById((int) $id);
        if (!$template) {
            $_SESSION['_flash']['error'] = 'Template not found.';
            redirect('/label-templates');
            return;
        }

        $data = $this->validateInput();
        if ($data === null) {
            redirect('/label-templates/' . $id . '/edit');
            return;
        }

        LabelTemplate::update((int) $id, $data);
        $_SESSION['_flash']['success'] = 'Label template updated.';
        redirect('/label-templates');
    }

    public function delete(string $id): void
    {
        $template = LabelTemplate::findById((int) $id);
        if (!$template) {
            $_SESSION['_flash']['error'] = 'Template not found.';
            redirect('/label-templates');
            return;
        }

        LabelTemplate::delete((int) $id);
        $_SESSION['_flash']['success'] = 'Label template deleted.';
        redirect('/label-templates');
    }

    private function validateInput(): ?array
    {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['_flash']['error'] = 'Template name is required.';
            return null;
        }

        $labelWidth  = (float) ($_POST['label_width'] ?? 0);
        $labelHeight = (float) ($_POST['label_height'] ?? 0);
        if ($labelWidth <= 0 || $labelHeight <= 0) {
            $_SESSION['_flash']['error'] = 'Label width and height must be positive.';
            return null;
        }

        $fieldLayout = $_POST['field_layout'] ?? '{}';
        $decoded = json_decode($fieldLayout, true);
        if ($decoded === null) {
            $_SESSION['_flash']['error'] = 'Invalid field layout data.';
            return null;
        }

        return [
            'name'              => $name,
            'description'       => trim($_POST['description'] ?? ''),
            'label_width'       => $labelWidth,
            'label_height'      => $labelHeight,
            'cols'              => max(1, (int) ($_POST['cols'] ?? 1)),
            'rows'              => max(1, (int) ($_POST['rows'] ?? 1)),
            'margin_left'       => (float) ($_POST['margin_left'] ?? 0),
            'margin_top'        => (float) ($_POST['margin_top'] ?? 0),
            'h_spacing'         => (float) ($_POST['h_spacing'] ?? 0),
            'v_spacing'         => (float) ($_POST['v_spacing'] ?? 0),
            'default_font_size' => max(1.0, (float) ($_POST['default_font_size'] ?? 7.0)),
            'field_layout'      => json_encode($decoded),
        ];
    }
}
