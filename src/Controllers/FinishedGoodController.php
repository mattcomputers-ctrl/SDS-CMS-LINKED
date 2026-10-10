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
        // Audit #48: no pre-fill — blank use text lets the product family's
        // per-language text (or the translated standard sentence) print.

        // Restore formula lines from flash data if returning from a validation error
        $oldFormula = $_SESSION['_flash']['_old_formula'] ?? null;
        $formula = null;
        if ($oldFormula) {
            $formula = $this->rebuildFormulaFromFlash($oldFormula);
            unset($_SESSION['_flash']['_old_formula']);
        }

        view('finished-goods/form', [
            'pageTitle'      => 'Add Finished Good',
            'item'           => [],
            'mode'           => 'create',
            'families'       => $families,
            'physicalStates' => $physicalStates,
            'colorOptions'   => $colorOptions,
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
        // Audit #3: family picker — a chosen family is a manual override, blank = Auto.
        // The legacy name column is maintained by FamilyResolver, never posted.
        unset($data['family']);
        $data['family_id'] = (int) ($data['family_id'] ?? 0) > 0 ? (int) $data['family_id'] : null;

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

            $_SESSION['_flash']['success'] = 'Finished good created successfully.'
                . $this->recomputeFamilies([$id], 'Finished good ' . $data['product_code'] . ' created');
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
        $formula        = Formula::findCurrentByFinishedGood((int) $id);

        view('finished-goods/form', [
            'pageTitle'      => 'Edit: ' . $item['product_code'],
            'item'           => $item,
            'mode'           => 'edit',
            'families'       => $families,
            'physicalStates' => $physicalStates,
            'colorOptions'   => $colorOptions,
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

            // Audit #3: family picker — a chosen family is a manual override, blank = Auto
            // (FamilyResolver then re-resolves rule / content). Legacy name column not posted.
            // Auto on an item that was NOT manual leaves the columns alone (recomputeFamilies()
            // re-resolves them) so a no-op save never looks like a reassignment / republish flag.
            $post = $_POST;
            unset($post['family']);
            $pickedFamily = (int) ($post['family_id'] ?? 0) > 0 ? (int) $post['family_id'] : null;
            if ($pickedFamily !== null) {
                $post['family_id']     = $pickedFamily;
                $post['family_source'] = 'manual';
            } elseif (($item['family_source'] ?? null) === 'manual') {
                $post['family_id']     = null;   // manual -> Auto: release the override
                $post['family_source'] = null;
            } else {
                unset($post['family_id'], $post['family_source']);
            }

            $diff = AuditService::diff($item, $post);
            FinishedGood::update((int) $id, $post);
            AuditService::log('finished_good', $id, 'update', $diff);

            // Audit #27: the transport product type is SDS content (Section 14).
            if (strtolower(trim((string) ($item['transport_product_type'] ?? ''))) !== strtolower(trim((string) ($post['transport_product_type'] ?? '')))) {
                $this->bumpSdsStalenessForTransportChange((int) $id, current_user_id());
            }

            // Audit #58 — a printed finished-good column changed: list the product
            // on SDS Updates (finished_goods.updated_at already moved with the row,
            // which is what bulk publish reads).
            $sdsColumns = \SDS\Services\ProductStaleness::sdsColumnsChanged($diff);
            if ($sdsColumns !== []) {
                \SDS\Services\ProductStaleness::markFinishedGood(
                    \SDS\Core\Database::getInstance(),
                    (int) $id,
                    'Product data edited: ' . implode(', ', $sdsColumns),
                    current_user_id()
                );
            }

            // Save formula (already validated above)
            if (!empty($formulaLines)) {
                $this->saveFormulaLines((int) $id, $formulaLines);
            }

            $_SESSION['_flash']['success'] = 'Finished good updated.'
                . $this->recomputeFamilies([(int) $id], 'Finished good ' . $item['product_code'] . ' saved');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/finished-goods/' . $id . '/edit');
    }

    /**
     * POST /finished-goods/{id}/hazard-override
     * Save a Phase-5 competent-person hazard override for this finished good.
     */
    public function saveHazardOverride(string $id): void
    {
        // Hazard overrides require the dedicated 'hazard_override' permission
        // — not plain FG edit access. These are competent-person decisions
        // with real regulatory weight (OSHA HazCom 2012 29 CFR 1910.1200(d)
        // requires classifications to be made by a qualified person).
        // Grant this permission only to users who've been designated the
        // SDS competent person.
        if (!can_edit('hazard_override')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to set hazard overrides. Contact an administrator to grant the "FG Hazard Override" permission.';
            redirect('/finished-goods/' . (int) $id . '/edit');
        }
        CSRF::validateRequest();

        $fgId = (int) $id;
        $item = FinishedGood::findById($fgId);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $mode      = trim((string) ($_POST['hazard_override_mode'] ?? 'none'));
        $rationale = trim((string) ($_POST['hazard_override_rationale'] ?? ''));

        $payload = [];
        $rawP = '';
        if ($mode !== 'none') {
            $payload['hazard_classes'] = $this->parseOverrideHazardClasses(
                (string) ($_POST['hazard_override_classes'] ?? '')
            );
            $payload['h_statements']   = trim((string) ($_POST['hazard_override_h_codes']    ?? ''));
            $rawP = trim((string) ($_POST['hazard_override_p_codes'] ?? ''));
            $payload['p_statements']   = \SDS\Services\GHSStatements::normalisePCodeList($rawP); // #39: P281 -> P280
            $payload['pictograms']     = trim((string) ($_POST['hazard_override_pictograms'] ?? ''));
            $payload['signal_word']    = trim((string) ($_POST['hazard_override_signal']     ?? ''));
        }

        try {
            FinishedGood::setHazardOverride($fgId, $mode, $payload, $rationale, current_user_id());
            AuditService::log('finished_good', (string) $fgId, 'hazard_override', [
                'mode'      => $mode,
                'rationale' => $rationale,
                'payload'   => $payload,
            ]);
            // Audit #58 — the classification prints on the SDS: mark it stale.
            \SDS\Services\ProductStaleness::markFinishedGood(
                \SDS\Core\Database::getInstance(),
                $fgId,
                $mode === 'none' ? 'Hazard override cleared' : "Hazard override saved ({$mode})",
                current_user_id()
            );
            $_SESSION['_flash']['success'] = $mode === 'none'
                ? 'Hazard override cleared.'
                : "Hazard override saved in '{$mode}' mode." . (preg_match('/\bP281\b/i', $rawP) ? ' P281 was withdrawn in GHS Rev. 6 and was saved as P280.' : '');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/finished-goods/' . $fgId . '/edit');
    }

    /**
     * Parse the override hazard classes textarea into structured entries.
     * Expected format: one "Class Name | Category" per line.
     */
    private function parseOverrideHazardClasses(string $raw): array
    {
        $entries = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line, 2));
            $class    = $parts[0] ?? '';
            $category = $parts[1] ?? '';
            if ($class === '') continue;
            $entries[] = ['class' => $class, 'category' => $category];
        }
        return $entries;
    }

    /**
     * Parse formula lines from POST into an array suitable for Formula::create().
     * Returns empty array if no valid lines were provided.
     */
    /**
     * AJAX endpoint: look up a component code and return its type, ID, and description.
     * GET /finished-goods/component-lookup?code=XXX
     */
    public function componentLookup(): void
    {
        header('Content-Type: application/json');

        $code = trim($_GET['code'] ?? '');
        if ($code === '') {
            echo json_encode(['found' => false]);
            return;
        }

        // Check raw materials first
        $rm = RawMaterial::findByCode($code);
        if ($rm) {
            echo json_encode([
                'found'       => true,
                'type'        => 'raw_material',
                'id'          => (int) $rm['id'],
                'code'        => $rm['internal_code'],
                'description' => $rm['supplier_product_name'] ?: $rm['supplier'] ?: '',
            ]);
            return;
        }

        // Check finished goods (exact match, then strip pack extension)
        $fg = FinishedGood::findByProductCode($code);
        if (!$fg) {
            $baseCode = strip_pack_extension($code);
            if ($baseCode !== $code) {
                $fg = FinishedGood::findByProductCode($baseCode);
            }
        }
        if ($fg) {
            echo json_encode([
                'found'       => true,
                'type'        => 'finished_good',
                'id'          => (int) $fg['id'],
                'code'        => $fg['product_code'],
                'description' => $fg['description'] ?? '',
            ]);
            return;
        }

        echo json_encode(['found' => false]);
    }

    private function parseFormulaLines(): array
    {
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

            // Look up the code — try raw material first, then finished good
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

            throw new \InvalidArgumentException("Component code '{$code}' not found as a raw material or finished good.");
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
            'component_code' => $_POST['component_code'] ?? [],
            'pct'            => $_POST['pct'] ?? [],
            'formula_notes'  => $_POST['formula_notes'] ?? '',
        ];
    }

    /**
     * Rebuild a formula array from flash session data so the form can re-populate
     * formula lines after a validation error.
     */
    private function rebuildFormulaFromFlash(array $oldFormula): array
    {
        $lines = [];
        $codes = $oldFormula['component_code'] ?? [];
        $pcts  = $oldFormula['pct'] ?? [];

        foreach ($codes as $i => $code) {
            $lines[] = [
                'component_code' => $code,
                'pct'            => $pcts[$i] ?? '',
            ];
        }

        return [
            'lines' => $lines,
            'notes' => $oldFormula['formula_notes'] ?? '',
        ];
    }

    /**
     * Product families for the form picker (audit #3): product_families rows
     * (id, name, is_uv, is_active, ...) ordered by sort_order. ALL rows, like
     * the raw-material form: the view hides inactive families unless one is
     * the item's current manual pick, so an unrelated save cannot post Auto
     * and silently release a manual override on a family that was set
     * inactive later. Managed on Settings > Product Families; the old
     * sds.product_families setting is dead.
     *
     * @return array<int,array>
     */
    private function loadProductFamilies(): array
    {
        return \SDS\Models\ProductFamily::all(false);
    }

    /**
     * Audit #3: after a product save, re-resolve the family of this product and
     * of every product whose formula tree contains it (rule match + content
     * inheritance), applying the result and flagging reassigned items for
     * republish. Returns a flash tail (' Product families: ...') or ''.
     * Must never break the save.
     *
     * @param int[] $fgIds
     */
    private function recomputeFamilies(array $fgIds, string $reason): string
    {
        try {
            $r = \SDS\Services\FamilyResolver::recompute(true, current_user_id(), $reason, ['finished_good_ids' => $fgIds, 'raw_material_ids' => []]);
            return ($r['counts']['rm_changed'] + $r['counts']['fg_changed']) > 0 ? \SDS\Services\FamilyResolver::summaryLine($r) : '';
        } catch (\Throwable $e) {
            return ' Product family recompute failed: ' . $e->getMessage();
        }
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

    /**
     * Audit #27 — a transport_product_type change must republish the product.
     * Bulk publish's staleness check only reads raw_materials.updated_at (see
     * RegulatoryListBumper), so bump the RMs on this FG's current formula
     * (sibling products sharing those RMs are re-rendered too — accepted) and
     * queue an explicit update row so the SDS Updates page lists this FG.
     */
    private function bumpSdsStalenessForTransportChange(int $fgId, ?int $userId): void
    {
        $db = \SDS\Core\Database::getInstance();
        $db->query(
            "UPDATE raw_materials rm
             JOIN formula_lines fl ON fl.raw_material_id = rm.id
             JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
             SET rm.updated_at = UTC_TIMESTAMP()
             WHERE f.finished_good_id = ?",
            [$fgId]
        );
        $hasPublished = $db->fetch(
            "SELECT 1 FROM sds_versions WHERE finished_good_id = ? AND status = 'published' AND is_deleted = 0 AND alias_id IS NULL LIMIT 1",
            [$fgId]
        );
        $pending = $db->fetch("SELECT id FROM sds_update_queue WHERE finished_good_id = ? AND status = 'pending'", [$fgId]);
        if ($hasPublished && !$pending) {
            $db->insert('sds_update_queue', [
                'finished_good_id' => $fgId,
                'reason'           => 'Transport product type changed (SDS Section 14)',
                'source_type'      => 'finished_good',
                'source_id'        => $fgId,
                'queued_by'        => $userId,
            ]);
        }
    }
}
