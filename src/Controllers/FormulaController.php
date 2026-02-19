<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\Formula;
use SDS\Models\FinishedGood;
use SDS\Models\RawMaterial;
use SDS\Services\FormulaCalcService;
use SDS\Services\AuditService;

class FormulaController
{
    public function index(string $finished_good_id): void
    {
        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $formula = Formula::findCurrentByFinishedGood((int) $finished_good_id);

        view('formulas/index', [
            'pageTitle'    => 'Formula: ' . $fg['product_code'],
            'finishedGood' => $fg,
            'formula'      => $formula,
        ]);
    }

    public function edit(string $finished_good_id): void
    {
        if (!is_editor()) {
            redirect('/formulas/' . $finished_good_id);
        }

        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $formula = Formula::findCurrentByFinishedGood((int) $finished_good_id);

        // Get all raw materials for the dropdown
        $rawMaterials = RawMaterial::all(['per_page' => 999, 'sort' => 'internal_code', 'dir' => 'asc']);

        view('formulas/edit', [
            'pageTitle'    => 'Edit Formula: ' . $fg['product_code'],
            'finishedGood' => $fg,
            'formula'      => $formula,
            'rawMaterials' => $rawMaterials,
        ]);
    }

    public function update(string $finished_good_id): void
    {
        if (!is_editor()) {
            redirect('/formulas/' . $finished_good_id);
        }

        CSRF::validateRequest();

        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        // Parse formula lines from POST
        $rmIds     = $_POST['raw_material_id'] ?? [];
        $pcts      = $_POST['pct'] ?? [];
        $lines     = [];

        foreach ($rmIds as $i => $rmId) {
            $rmId = (int) $rmId;
            $pct  = (float) ($pcts[$i] ?? 0);

            if ($rmId <= 0 || $pct <= 0) {
                continue;
            }

            $lines[] = [
                'raw_material_id' => $rmId,
                'pct'             => $pct,
                'sort_order'      => $i + 1,
            ];
        }

        $notes = trim($_POST['notes'] ?? '');

        try {
            $formulaId = Formula::create(
                (int) $finished_good_id,
                $lines,
                $notes ?: null,
                current_user_id()
            );

            AuditService::log('formula', $formulaId, 'create', [
                'finished_good_id' => $finished_good_id,
                'line_count'       => count($lines),
            ]);

            $_SESSION['_flash']['success'] = 'Formula saved (new version created).';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/formulas/' . $finished_good_id);
    }

    public function calculate(string $finished_good_id): void
    {
        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        try {
            $calcService = new FormulaCalcService();
            $result = $calcService->calculate((int) $finished_good_id);

            view('formulas/calculate', [
                'pageTitle'    => 'Calculations: ' . $fg['product_code'],
                'finishedGood' => $fg,
                'result'       => $result,
            ]);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/formulas/' . $finished_good_id);
        }
    }
}
