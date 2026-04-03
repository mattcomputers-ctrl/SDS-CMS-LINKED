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

        view('formulas/edit', [
            'pageTitle'     => 'Edit Formula: ' . $fg['product_code'],
            'finishedGood'  => $fg,
            'formula'       => $formula,
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

        $notes = trim($_POST['notes'] ?? '');

        try {
            // Parse formula lines from POST — look up codes to resolve IDs
            $codes = $_POST['component_code'] ?? [];
            $pcts  = $_POST['pct'] ?? [];
            $lines = [];

            foreach ($codes as $i => $code) {
                $code = trim($code);
                $pct  = (float) ($pcts[$i] ?? 0);

                if ($code === '' || $pct <= 0) {
                    continue;
                }

                $line = [
                    'pct'        => $pct,
                    'sort_order' => $i + 1,
                ];

                $rm = RawMaterial::findByCode($code);
                if ($rm) {
                    $line['raw_material_id'] = (int) $rm['id'];
                    $lines[] = $line;
                    continue;
                }

                $fg = FinishedGood::findByProductCode($code);
                if ($fg) {
                    $line['finished_good_component_id'] = (int) $fg['id'];
                    $lines[] = $line;
                    continue;
                }

                throw new \InvalidArgumentException("Component code '{$code}' not found.");
            }

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

        $rawMaterials = RawMaterial::all(['per_page' => 999, 'sort' => 'internal_code', 'dir' => 'asc']);
        $finishedGoods = FinishedGood::all(['per_page' => 999, 'sort' => 'product_code', 'dir' => 'asc']);

        view('formulas/mass-replace', [
            'pageTitle'     => 'Component Mass Replacement',
            'rawMaterials'  => $rawMaterials,
            'finishedGoods' => $finishedGoods,
        ]);
    }

    public function massReplaceSubmit(): void
    {
        if (!can_edit('rm_mass_replace')) {
            redirect('/');
        }

        CSRF::validateRequest();

        $oldType = $_POST['old_type'] ?? 'raw_material';
        $newType = $_POST['new_type'] ?? 'raw_material';

        // Validate types
        $validTypes = ['raw_material', 'finished_good'];
        if (!in_array($oldType, $validTypes, true) || !in_array($newType, $validTypes, true)) {
            $_SESSION['_flash']['error'] = 'Invalid component type selected.';
            redirect('/formulas/mass-replace');
        }

        $oldId = (int) ($oldType === 'finished_good'
            ? ($_POST['old_finished_good_id'] ?? 0)
            : ($_POST['old_raw_material_id'] ?? 0));

        $newId = (int) ($newType === 'finished_good'
            ? ($_POST['new_finished_good_id'] ?? 0)
            : ($_POST['new_raw_material_id'] ?? 0));

        if ($oldId <= 0 || $newId <= 0) {
            $_SESSION['_flash']['error'] = 'Please select both an old and a new component.';
            redirect('/formulas/mass-replace');
        }

        if ($oldType === $newType && $oldId === $newId) {
            $_SESSION['_flash']['error'] = 'Old and new components must be different.';
            redirect('/formulas/mass-replace');
        }

        // Verify old component exists
        if ($oldType === 'finished_good') {
            $oldItem = FinishedGood::findById($oldId);
            $oldLabel = $oldItem ? $oldItem['product_code'] : null;
        } else {
            $oldItem = RawMaterial::findById($oldId);
            $oldLabel = $oldItem ? $oldItem['internal_code'] : null;
        }

        // Verify new component exists
        if ($newType === 'finished_good') {
            $newItem = FinishedGood::findById($newId);
            $newLabel = $newItem ? $newItem['product_code'] : null;
        } else {
            $newItem = RawMaterial::findById($newId);
            $newLabel = $newItem ? $newItem['internal_code'] : null;
        }

        if (!$oldItem || !$newItem) {
            $_SESSION['_flash']['error'] = 'One or both components not found.';
            redirect('/formulas/mass-replace');
        }

        try {
            $count = Formula::massReplaceComponent(
                $oldType, $oldId,
                $newType, $newId,
                current_user_id()
            );

            $oldTypeLabel = $oldType === 'finished_good' ? 'FG' : 'RM';
            $newTypeLabel = $newType === 'finished_good' ? 'FG' : 'RM';

            AuditService::log('formula', 0, 'mass_replace', [
                'old_type'       => $oldType,
                'old_id'         => $oldId,
                'old_label'      => $oldLabel,
                'new_type'       => $newType,
                'new_id'         => $newId,
                'new_label'      => $newLabel,
                'formulas_updated' => $count,
            ]);

            if ($count > 0) {
                $_SESSION['_flash']['success'] = sprintf(
                    'Mass replacement complete: replaced %s "%s" with %s "%s" in %d formula(s). New versions were created for each.',
                    $oldTypeLabel, $oldLabel,
                    $newTypeLabel, $newLabel,
                    $count
                );
            } else {
                $_SESSION['_flash']['warning'] = sprintf(
                    'No current formulas contain %s "%s". Nothing was changed.',
                    $oldTypeLabel, $oldLabel
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
