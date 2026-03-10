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
        if (!can_edit('formulas')) {
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

        // Get all finished goods for the dropdown (exclude self to prevent direct self-reference)
        $allFinishedGoods = FinishedGood::all(['per_page' => 999, 'sort' => 'product_code', 'dir' => 'asc']);
        $finishedGoods = array_filter($allFinishedGoods, function ($item) use ($finished_good_id) {
            return (int) $item['id'] !== (int) $finished_good_id;
        });
        $finishedGoods = array_values($finishedGoods);

        view('formulas/edit', [
            'pageTitle'     => 'Edit Formula: ' . $fg['product_code'],
            'finishedGood'  => $fg,
            'formula'       => $formula,
            'rawMaterials'  => $rawMaterials,
            'finishedGoods' => $finishedGoods,
        ]);
    }

    public function update(string $finished_good_id): void
    {
        if (!can_edit('formulas')) {
            redirect('/formulas/' . $finished_good_id);
        }

        CSRF::validateRequest();

        $fg = FinishedGood::findById((int) $finished_good_id);
        if ($fg === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        // Parse formula lines from POST
        $lineTypes = $_POST['line_type'] ?? [];
        $rmIds     = $_POST['raw_material_id'] ?? [];
        $fgIds     = $_POST['finished_good_component_id'] ?? [];
        $pcts      = $_POST['pct'] ?? [];
        $lines     = [];

        foreach ($lineTypes as $i => $type) {
            $pct = (float) ($pcts[$i] ?? 0);
            if ($pct <= 0) {
                continue;
            }

            $line = [
                'pct'        => $pct,
                'sort_order' => $i + 1,
            ];

            if ($type === 'finished_good') {
                $fgCompId = (int) ($fgIds[$i] ?? 0);
                if ($fgCompId <= 0) {
                    continue;
                }
                $line['finished_good_component_id'] = $fgCompId;
            } else {
                $rmId = (int) ($rmIds[$i] ?? 0);
                if ($rmId <= 0) {
                    continue;
                }
                $line['raw_material_id'] = $rmId;
            }

            $lines[] = $line;
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

    public function massReplace(): void
    {
        if (!can_edit('rm_mass_replace')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to perform mass replacements.';
            redirect('/');
        }

        // Get all raw materials for the dropdowns
        $rawMaterials = RawMaterial::all(['per_page' => 999, 'sort' => 'internal_code', 'dir' => 'asc']);

        view('formulas/mass-replace', [
            'pageTitle'    => 'RM Mass Replacement',
            'rawMaterials' => $rawMaterials,
        ]);
    }

    public function massReplaceSubmit(): void
    {
        if (!can_edit('rm_mass_replace')) {
            redirect('/');
        }

        CSRF::validateRequest();

        $oldRmId = (int) ($_POST['old_raw_material_id'] ?? 0);
        $newRmId = (int) ($_POST['new_raw_material_id'] ?? 0);

        if ($oldRmId <= 0 || $newRmId <= 0) {
            $_SESSION['_flash']['error'] = 'Please select both an old and a new raw material.';
            redirect('/formulas/mass-replace');
        }

        if ($oldRmId === $newRmId) {
            $_SESSION['_flash']['error'] = 'Old and new raw materials must be different.';
            redirect('/formulas/mass-replace');
        }

        // Verify both RMs exist
        $oldRm = RawMaterial::findById($oldRmId);
        $newRm = RawMaterial::findById($newRmId);

        if (!$oldRm || !$newRm) {
            $_SESSION['_flash']['error'] = 'One or both raw materials not found.';
            redirect('/formulas/mass-replace');
        }

        try {
            $count = Formula::massReplaceRawMaterial($oldRmId, $newRmId, current_user_id());

            AuditService::log('formula', 0, 'mass_replace', [
                'old_raw_material_id'   => $oldRmId,
                'old_internal_code'     => $oldRm['internal_code'],
                'new_raw_material_id'   => $newRmId,
                'new_internal_code'     => $newRm['internal_code'],
                'formulas_updated'      => $count,
            ]);

            if ($count > 0) {
                $_SESSION['_flash']['success'] = sprintf(
                    'Mass replacement complete: replaced "%s" with "%s" in %d formula(s). New versions were created for each.',
                    $oldRm['internal_code'],
                    $newRm['internal_code'],
                    $count
                );
            } else {
                $_SESSION['_flash']['warning'] = sprintf(
                    'No current formulas contain "%s". Nothing was changed.',
                    $oldRm['internal_code']
                );
            }
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Mass replacement failed: ' . $e->getMessage();
        }

        redirect('/formulas/mass-replace');
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
