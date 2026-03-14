<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\FinishedGood;
use SDS\Models\Formula;
use SDS\Models\RawMaterial;
use SDS\Services\AuditService;

class FinishedGoodController
{
    public function index(): void
    {
        $filters = [
            'search'    => $_GET['search'] ?? '',
            'family'    => $_GET['family'] ?? '',
            'is_active' => isset($_GET['is_active']) ? (int) $_GET['is_active'] : null,
            'page'      => (int) ($_GET['page'] ?? 1),
            'per_page'  => 25,
            'sort'      => $_GET['sort'] ?? 'product_code',
            'dir'       => $_GET['dir'] ?? 'asc',
        ];

        $items    = FinishedGood::all($filters);
        $total    = FinishedGood::count($filters);
        $families = FinishedGood::getFamilies();

        view('finished-goods/index', [
            'pageTitle' => 'Finished Goods',
            'items'     => $items,
            'total'     => $total,
            'filters'   => $filters,
            'families'  => $families,
            'pages'     => (int) ceil($total / $filters['per_page']),
        ]);
    }

    public function create(): void
    {
        if (!can_edit('finished_goods')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to create finished goods.';
            redirect('/finished-goods');
        }

        $families       = $this->loadProductFamilies();
        $physicalStates = $this->loadPhysicalStates();
        $colorOptions   = $this->loadColorOptions();
        $rawMaterials   = RawMaterial::all(['per_page' => 999, 'sort' => 'internal_code', 'dir' => 'asc']);
        $finishedGoods  = FinishedGood::all(['per_page' => 999, 'sort' => 'product_code', 'dir' => 'asc']);

        // Pre-fill with default recommended use / restrictions from settings
        $defaults = $this->loadDefaultUseSettings();

        // Restore formula lines from flash data if returning from a validation error
        $oldFormula = $_SESSION['_flash']['_old_formula'] ?? null;
        $formula = null;
        if ($oldFormula) {
            $formula = $this->rebuildFormulaFromFlash($oldFormula);
            unset($_SESSION['_flash']['_old_formula']);
        }

        view('finished-goods/form', [
            'pageTitle'      => 'Add Finished Good',
            'item'           => $defaults,
            'mode'           => 'create',
            'families'       => $families,
            'physicalStates' => $physicalStates,
            'colorOptions'   => $colorOptions,
            'rawMaterials'   => $rawMaterials,
            'finishedGoods'  => $finishedGoods,
            'formula'        => $formula,
        ]);
    }

    public function store(): void
    {
        if (!can_edit('finished_goods')) {
            redirect('/finished-goods');
        }

        CSRF::validateRequest();

        $data = $_POST;
        $data['created_by'] = current_user_id();

        try {
            // Pre-validate formula lines before creating the finished good
            // so we don't save an incomplete entry if the formula is invalid
            $formulaLines = $this->parseFormulaLines();
            if (!empty($formulaLines)) {
                $validationError = Formula::validateTotalPercent($formulaLines);
                if ($validationError !== null) {
                    throw new \InvalidArgumentException($validationError);
                }
            }

            $id = FinishedGood::create($data);
            AuditService::log('finished_good', $id, 'create', $data);

            // Save formula (already validated above)
            if (!empty($formulaLines)) {
                $this->saveFormulaLines($id, $formulaLines);
            }

            $_SESSION['_flash']['success'] = 'Finished good created successfully.';
            redirect('/finished-goods');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $data;
            $_SESSION['_flash']['_old_formula'] = $this->captureFormulaPost();
            redirect('/finished-goods/create');
        }
    }

    public function edit(string $id): void
    {
        $item = FinishedGood::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $families       = $this->loadProductFamilies();
        $physicalStates = $this->loadPhysicalStates();
        $colorOptions   = $this->loadColorOptions();
        $rawMaterials   = RawMaterial::all(['per_page' => 999, 'sort' => 'internal_code', 'dir' => 'asc']);
        $formula        = Formula::findCurrentByFinishedGood((int) $id);

        // Get all finished goods for the dropdown (exclude self)
        $allFinishedGoods = FinishedGood::all(['per_page' => 999, 'sort' => 'product_code', 'dir' => 'asc']);
        $finishedGoods = array_values(array_filter($allFinishedGoods, function ($item) use ($id) {
            return (int) $item['id'] !== (int) $id;
        }));

        view('finished-goods/form', [
            'pageTitle'      => 'Edit: ' . $item['product_code'],
            'item'           => $item,
            'mode'           => 'edit',
            'families'       => $families,
            'physicalStates' => $physicalStates,
            'colorOptions'   => $colorOptions,
            'rawMaterials'   => $rawMaterials,
            'finishedGoods'  => $finishedGoods,
            'formula'        => $formula,
        ]);
    }

    public function update(string $id): void
    {
        if (!can_edit('finished_goods')) {
            redirect('/finished-goods');
        }

        CSRF::validateRequest();

        $item = FinishedGood::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        try {
            // Pre-validate formula lines before updating
            $formulaLines = $this->parseFormulaLines();
            if (!empty($formulaLines)) {
                $validationError = Formula::validateTotalPercent($formulaLines);
                if ($validationError !== null) {
                    throw new \InvalidArgumentException($validationError);
                }
            }

            $diff = AuditService::diff($item, $_POST);
            FinishedGood::update((int) $id, $_POST);
            AuditService::log('finished_good', $id, 'update', $diff);

            // Save formula (already validated above)
            if (!empty($formulaLines)) {
                $this->saveFormulaLines((int) $id, $formulaLines);
            }

            $_SESSION['_flash']['success'] = 'Finished good updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/finished-goods/' . $id . '/edit');
    }

    /**
     * Parse formula lines from POST into an array suitable for Formula::create().
     * Returns empty array if no valid lines were provided.
     */
    private function parseFormulaLines(): array
    {
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

        return $lines;
    }

    /**
     * Save pre-parsed formula lines for a finished good.
     */
    private function saveFormulaLines(int $fgId, array $lines): void
    {
        $notes = trim($_POST['formula_notes'] ?? '');

        $formulaId = Formula::create(
            $fgId,
            $lines,
            $notes ?: null,
            current_user_id()
        );

        AuditService::log('formula', $formulaId, 'create', [
            'finished_good_id' => $fgId,
            'line_count'       => count($lines),
        ]);
    }

    /**
     * Capture the raw formula POST data so it can be preserved in flash session
     * when validation fails, allowing the form to re-populate.
     */
    private function captureFormulaPost(): array
    {
        return [
            'line_type'                  => $_POST['line_type'] ?? [],
            'raw_material_id'            => $_POST['raw_material_id'] ?? [],
            'finished_good_component_id' => $_POST['finished_good_component_id'] ?? [],
            'pct'                        => $_POST['pct'] ?? [],
            'formula_notes'              => $_POST['formula_notes'] ?? '',
        ];
    }

    /**
     * Rebuild a formula array from flash session data so the form can re-populate
     * formula lines after a validation error.
     */
    private function rebuildFormulaFromFlash(array $oldFormula): array
    {
        $lines = [];
        $lineTypes = $oldFormula['line_type'] ?? [];
        $rmIds     = $oldFormula['raw_material_id'] ?? [];
        $fgIds     = $oldFormula['finished_good_component_id'] ?? [];
        $pcts      = $oldFormula['pct'] ?? [];

        foreach ($lineTypes as $i => $type) {
            $lines[] = [
                'line_type'                  => $type,
                'raw_material_id'            => $rmIds[$i] ?? '',
                'finished_good_component_id' => $fgIds[$i] ?? '',
                'pct'                        => $pcts[$i] ?? '',
            ];
        }

        return [
            'lines' => $lines,
            'notes' => $oldFormula['formula_notes'] ?? '',
        ];
    }

    /**
     * Load default recommended use / restrictions from settings for new finished goods.
     */
    private function loadDefaultUseSettings(): array
    {
        $db  = \SDS\Core\Database::getInstance();
        $recRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.default_recommended_use'");
        $resRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.default_restrictions_on_use'");

        return [
            'recommended_use'     => $recRow['value'] ?? '',
            'restrictions_on_use' => $resRow['value'] ?? '',
        ];
    }

    /**
     * Load product families from admin settings, falling back to distinct DB values.
     *
     * @return string[]
     */
    private function loadProductFamilies(): array
    {
        $db  = \SDS\Core\Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.product_families'");
        if ($row && !empty($row['value'])) {
            return array_filter(array_map('trim', explode("\n", $row['value'])));
        }
        // Fallback to distinct families already in use
        return FinishedGood::getFamilies();
    }

    /**
     * Load physical state options from admin settings.
     *
     * @return string[]
     */
    private function loadPhysicalStates(): array
    {
        $db  = \SDS\Core\Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.physical_states'");
        if ($row && !empty($row['value'])) {
            return array_filter(array_map('trim', explode("\n", $row['value'])));
        }
        return ['Liquid', 'Paste', 'Solid', 'Powder', 'Gel', 'Gas'];
    }

    /**
     * Load color options from admin settings.
     *
     * @return string[]
     */
    private function loadColorOptions(): array
    {
        $db  = \SDS\Core\Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.color_options'");
        if ($row && !empty($row['value'])) {
            return array_filter(array_map('trim', explode("\n", $row['value'])));
        }
        return ['Black', 'White', 'Yellow', 'Cyan', 'Magenta', 'Transparent', 'Various'];
    }
}
