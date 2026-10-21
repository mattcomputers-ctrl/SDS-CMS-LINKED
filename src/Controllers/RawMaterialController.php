<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
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
            // Migration 064: '' = all, 'required', 'not_required'
            'sds'      => in_array($_GET['sds'] ?? '', ['required', 'not_required'], true) ? (string) $_GET['sds'] : '',
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
            'families'               => \SDS\Models\ProductFamily::all(false),   // audit #3 picker
        ]);
    }

    public function store(): void
    {
        if (!can_edit('raw_materials')) {
            redirect('/raw-materials');
        }

        try {
            CSRF::validateRequest();
        } catch (\Throwable $e) {
            // Also fires when the upload exceeds post_max_size (PHP empties
            // $_POST so the token is missing). Keep whatever did arrive.
            $_SESSION['_flash']['error']      = $e->getMessage() . ' Please try again.';
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/raw-materials/create');
        }

        $data = $_POST;
        $data['created_by'] = current_user_id();

        // Audit #3: family picker — a chosen family is a manual override; blank = Auto (rule match).
        $data['family_id']     = (int) ($data['family_id'] ?? 0) > 0 ? (int) $data['family_id'] : null;
        $data['family_source'] = $data['family_id'] !== null ? 'manual' : null;

        // Process checkbox fields (unchecked = not in POST)
        $data['voc_less_than_one'] = !empty($data['voc_less_than_one']) ? 1 : 0;
        $data['flash_point_greater_than'] = !empty($data['flash_point_greater_than']) ? 1 : 0;
        $data['hazardous_no_cas'] = !empty($data['hazardous_no_cas']) && (int) $data['hazardous_no_cas'] === 1 ? 1 : 0;
        // Migration 064: only users with the capability may create an item already marked No SDS required.
        $data['sds_not_required'] = can_edit('rm_sds_not_required') && (int) ($data['sds_not_required'] ?? 0) === 1 ? 1 : 0;

        // Build HAPs data JSON from form arrays
        $data['haps_data'] = $this->buildHapsJson();

        // Build Prop 65 data JSON from form arrays
        $prop65Json = $this->buildProp65Json();
        $data['prop65_data'] = $prop65Json;
        $data['is_prop65'] = ($prop65Json !== null) ? 1 : 0;

        // If trade-secret mode: build manual hazard JSON via shared helper;
        // otherwise clear any prior value so the two data paths stay mutually exclusive.
        if ($data['hazardous_no_cas']) {
            $data['manual_hazard_json'] = json_encode(
                \SDS\Services\GHSHelper::buildDeterminationJson($_POST),
                JSON_UNESCAPED_UNICODE
            );
        } else {
            $data['manual_hazard_json'] = null;
        }

        try {
            // Date Received is required when an SDS file is uploaded.
            // Check BEFORE handleSdsUpload() moves the file into place so a
            // validation failure doesn't leave an orphaned PDF on disk.
            $dateReceived = trim($_POST['sds_date_received'] ?? '');
            $sdsSupplier  = trim($_POST['sds_supplier'] ?? '');
            if ($this->sdsFileSubmitted()) {
                if ($sdsSupplier === '') {
                    throw new \InvalidArgumentException(
                        'SDS Supplier is required when uploading an SDS.'
                    );
                }
                if ($dateReceived === '') {
                    throw new \InvalidArgumentException(
                        'Date Received is required when uploading an SDS.'
                    );
                }
            }
            $sdsInfo = $this->handleSdsUpload();
            if ($sdsInfo !== null) {
                $data['supplier_sds_path']      = $sdsInfo['path'];
                $data['sds_last_confirmed_at']  = $dateReceived;
            }

            $id = RawMaterial::create($data);
            AuditService::log('raw_material', $id, 'create', $data);
            // Migration 063: creating a raw material by hand lifts a CMS
            // import exclusion left by an earlier delete of the same code.
            try {
                Database::getInstance()->query(
                    'DELETE FROM cms_rm_import_exclusions WHERE item_code = ?',
                    [trim((string) $data['internal_code'])]
                );
            } catch (\Throwable $e) {
                // Table missing (migration 063 not applied): nothing to lift.
            }

            // Save SDS to history table if uploaded
            if ($sdsInfo !== null) {
                RawMaterial::addSds(
                    $id,
                    $sdsInfo['path'],
                    $sdsInfo['original_name'],
                    $sdsInfo['size'],
                    trim($_POST['sds_notes'] ?? '') ?: null,
                    current_user_id(),
                    $dateReceived !== '' ? $dateReceived : null,
                    $sdsSupplier !== '' ? $sdsSupplier : null
                );
            }

            // Save constituents only if this RM is NOT in trade-secret mode
            if (!$data['hazardous_no_cas']) {
                $this->saveConstituentsFromPost($id);
            }

            $_SESSION['_flash']['success'] = 'Raw material created successfully.'
                . $this->recomputeFamilies($id, 'Raw material ' . trim((string) $data['internal_code']) . ' created');
            redirect('/raw-materials');
        } catch (\Throwable $e) {
            // Everything the user typed comes back via _old_input (scalars
            // through old(), nested rows rebuilt by the form). The one thing
            // a redirect can't carry is the file — formErrorMessage says so.
            $_SESSION['_flash']['error']      = $this->formErrorMessage($e);
            $_SESSION['_flash']['_old_input'] = $data;
            redirect('/raw-materials/create');
        }
    }

    /**
     * Whether the current request carries an SDS PDF in the multipart body
     * (any upload state other than "no file chosen").
     */
    private function sdsFileSubmitted(): bool
    {
        return isset($_FILES['supplier_sds'])
            && (int) ($_FILES['supplier_sds']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Audit #3: after a raw-material save, re-resolve this RM's family (manual
     * or rule) and the content-inherited family of every product whose
     * formula tree contains it; reassigned items are flagged for republish.
     * Returns a flash tail or ''. Must never break the save.
     */
    private function recomputeFamilies(int $rmId, string $reason): string
    {
        try {
            $r = \SDS\Services\FamilyResolver::recompute(true, current_user_id(), $reason, ['raw_material_ids' => [$rmId], 'finished_good_ids' => []]);
            return ($r['counts']['rm_changed'] + $r['counts']['fg_changed']) > 0 ? \SDS\Services\FamilyResolver::summaryLine($r) : '';
        } catch (\Throwable $e) {
            return ' Product family recompute failed: ' . $e->getMessage();
        }
    }

    /**
     * Flash-friendly error text for a failed create/update. Files can't
     * survive a redirect, so if the submission included an SDS PDF, say so
     * explicitly — everything else is repopulated from _old_input.
     */
    private function formErrorMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($this->sdsFileSubmitted()) {
            $msg .= ' Your entries have been kept — please re-select the SDS PDF, then save again.';
        }
        return $msg;
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
            'families'               => \SDS\Models\ProductFamily::all(false),   // audit #3 picker
            'sdsFlagInfo'            => $this->sdsFlagInfo($item),               // migration 064 card
            'tscaRm'                 => $this->tscaRmInfo($item),                // migration 066 card
        ]);
    }

    /**
     * Raw-material TSCA override card (migration 066, 2026-10-21), so it can
     * be set for any raw material, not only those on the TSCA Review list.
     * Null before the migration has run.
     */
    private function tscaRmInfo(array $item): ?array
    {
        if (!\SDS\Services\TSCAService::hasRawMaterialOverrideColumns()) {
            return null;
        }
        $by = null;
        if (!empty($item['tsca_updated_by'])) {
            $by = Database::getInstance()->fetch("SELECT display_name FROM users WHERE id = ?", [(int) $item['tsca_updated_by']])['display_name'] ?? null;
        }
        return [
            'status'     => (string) ($item['tsca_status'] ?? 'auto'),
            'note'       => (string) ($item['tsca_note'] ?? ''),
            'updated_at' => $item['tsca_updated_at'] ?? null,
            'updated_by' => $by,
            'can_set'    => can_edit('cas_determinations'),
        ];
    }

    /* ------------------------------------------------------------------
     *  No SDS required (migration 064)
     * ----------------------------------------------------------------*/

    /**
     * POST /raw-materials/{id}/sds-requirement
     *
     * Sets or clears "No SDS required" for one raw material. A metadata
     * write (no staleness bump); audited with its own actions, which do not
     * count as a data review. Used by the edit-page card and the worklist
     * "Not a chemical" buttons (validated same-site return_to).
     */
    public function setSdsRequirement(string $id): void
    {
        $back = self::safeReturnTo($_POST['return_to'] ?? null, '/raw-materials/' . (int) $id . '/edit');
        if (!can_edit('rm_sds_not_required')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to mark raw materials as not needing an SDS.';
            redirect($back);
        }
        CSRF::validateRequest();

        $rm = RawMaterial::findById((int) $id);
        if ($rm === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        $flag = (int) ($_POST['sds_not_required'] ?? 0) === 1;
        $r = $this->applySdsRequirement($rm, $flag, trim((string) ($_POST['note'] ?? '')));
        $code = (string) $rm['internal_code'];
        if (!$r['changed']) {
            $_SESSION['_flash']['success'] = $code . ($flag ? ' is already marked No SDS required.' : ' already needs an SDS.');
        } elseif ($flag) {
            $_SESSION['_flash']['success'] = $code . ' marked No SDS required. It is left off SDS publishing, GHS labels, the SDS send queue and the SDS worklists.'
                . ($r['dismissed'] > 0 ? " {$r['dismissed']} pending send-queue item(s) dismissed." : '');
        } else {
            $_SESSION['_flash']['success'] = $code . ' needs an SDS again. Enter its composition, then publish it from the SDS Creation Readiness Check.';
        }
        $warnings = [];
        if ($flag && $r['formula_products'] !== []) {
            $warnings[] = $code . ' is still used in the current formula of: ' . implode(', ', $r['formula_products'])
                . '. Its data still feeds those SDSs; check it belongs in those formulas.';
        }
        if ($flag && ($repNote = $this->representativeNote($rm)) !== null) {
            $warnings[] = $repNote;
        }
        if ($warnings !== []) {
            $_SESSION['_flash']['warning'] = implode(' ', $warnings);
        }
        redirect($back);
    }

    /**
     * POST /raw-materials/sds-requirement/bulk — the Raw Materials list's
     * "Mark selected" action: ids[] + sds_not_required (1 = mark, 0 = clear).
     */
    public function bulkSetSdsRequirement(): void
    {
        $back = self::safeReturnTo($_POST['return_to'] ?? null, '/raw-materials');
        if (!can_edit('rm_sds_not_required')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to mark raw materials as not needing an SDS.';
            redirect($back);
        }
        CSRF::validateRequest();

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            $_SESSION['_flash']['error'] = 'Tick at least one raw material first.';
            redirect($back);
        }
        $flag = (int) ($_POST['sds_not_required'] ?? 0) === 1;

        $changed = [];
        $inFormulas = [];
        $repNotes = [];
        $dismissed = 0;
        foreach ($ids as $rmId) {
            $rm = RawMaterial::findById($rmId);
            if ($rm === null) {
                continue;
            }
            $r = $this->applySdsRequirement($rm, $flag, '', false);
            if ($r['changed']) {
                $changed[] = (string) $rm['internal_code'];
            }
            if ($flag && $r['formula_products'] !== []) {
                $inFormulas[] = $rm['internal_code'] . ' (' . implode(', ', $r['formula_products']) . ')';
            }
            if ($flag && ($repNote = $this->representativeNote($rm)) !== null) {
                $repNotes[] = $repNote;
            }
        }
        if ($flag && $changed !== []) {
            $dismissed = $this->dismissNotRequiredSends();
        }

        $_SESSION['_flash']['success'] = $changed === []
            ? 'Nothing changed.'
            : count($changed) . ' raw material(s) ' . ($flag ? 'marked No SDS required' : 'set back to needing an SDS') . ': ' . implode(', ', $changed) . '.'
                . ($dismissed > 0 ? " {$dismissed} pending send-queue item(s) dismissed." : '');
        $warnings = [];
        if ($inFormulas !== []) {
            $warnings[] = 'Still used in current formulas (their data still feeds those SDSs): ' . implode('; ', $inFormulas) . '.';
        }
        if ($repNotes !== []) {
            $warnings[] = implode(' ', array_unique($repNotes));
        }
        if ($warnings !== []) {
            $_SESSION['_flash']['warning'] = implode(' ', $warnings);
        }
        redirect($back);
    }

    /**
     * Write the flag, audit it with its own action, and (when set) dismiss
     * pending send-queue rows for items that no longer need an SDS.
     *
     * @return array{changed:bool, formula_products:list<string>, dismissed:int}
     */
    private function applySdsRequirement(array $rm, bool $flag, string $note, bool $dismissSends = true): array
    {
        $rmId = (int) $rm['id'];
        $formulaProducts = array_column(RawMaterial::getCurrentFormulaProducts($rmId), 'product_code');
        $changed = RawMaterial::setSdsNotRequired($rmId, $flag);
        $dismissed = 0;
        if ($changed) {
            AuditService::log('raw_material', (string) $rmId, $flag ? 'sds_not_required_set' : 'sds_not_required_cleared', [
                'internal_code'            => $rm['internal_code'],
                'sds_not_required'         => ['old' => $flag ? 0 : 1, 'new' => $flag ? 1 : 0],
                'note'                     => $note !== '' ? mb_substr($note, 0, 255) : null,
                'current_formula_products' => $formulaProducts,
            ]);
            if ($flag && $dismissSends) {
                $dismissed = $this->dismissNotRequiredSends();
            }
        }
        return ['changed' => $changed, 'formula_products' => $formulaProducts, 'dismissed' => $dismissed];
    }

    /**
     * When $rm is not the first raw material of its pack-stripped base, aliases,
     * labels and shipments of that base follow the first one, so flagging this
     * row only stops its own resale SDS and its worklist rows. Null when $rm is
     * the representative or the representative is flagged too.
     */
    private function representativeNote(array $rm): ?string
    {
        $pack = RawMaterial::packSiblings((int) $rm['id']);
        if ($pack['representative_id'] === (int) $rm['id']) {
            return null;
        }
        $rep = Database::getInstance()->fetch('SELECT * FROM raw_materials WHERE id = ?', [$pack['representative_id']]);
        if ($rep === null || RawMaterial::isSdsNotRequired($rep)) {
            return null;
        }
        return 'Aliases, GHS labels and shipments of ' . $pack['base'] . ' follow ' . $rep['internal_code']
            . ' (the first raw material with that base code), which still needs an SDS; mark ' . $rep['internal_code'] . ' too for them to be ignored.';
    }

    /** Dismiss pending SDS send-queue rows whose item is now marked No SDS required. Never breaks the save. */
    private function dismissNotRequiredSends(): int
    {
        try {
            return \SDS\Services\SDSAutoSendService::dismissNotRequiredPending(Database::getInstance());
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Data for the edit page's "No SDS required" card. */
    private function sdsFlagInfo(array $item): array
    {
        $rmId = (int) $item['id'];
        $db = Database::getInstance();
        $pack = RawMaterial::packSiblings($rmId);
        return [
            'flagged'          => RawMaterial::isSdsNotRequired($item),
            'can_set'          => can_edit('rm_sds_not_required'),
            'formula_products' => RawMaterial::getCurrentFormulaProducts($rmId),
            'base'             => $pack['base'],
            'is_representative'=> $pack['representative_id'] === $rmId,
            'representative'   => $pack['representative_id'] === $rmId ? null : $db->fetch('SELECT id, internal_code FROM raw_materials WHERE id = ?', [$pack['representative_id']]),
            'siblings'         => $pack['siblings'],
            'aliases'          => array_column($db->fetchAll(
                'SELECT DISTINCT customer_code FROM aliases WHERE internal_code_base = ? ORDER BY customer_code',
                [$pack['base']]
            ), 'customer_code'),
            'published_count'  => (int) ($db->fetch(
                "SELECT COUNT(*) AS n FROM sds_versions WHERE raw_material_id = ? AND finished_good_id IS NULL AND status = 'published' AND is_deleted = 0",
                [$rmId]
            )['n'] ?? 0),
            'has_composition'  => !empty($item['constituents']) || (int) ($item['hazardous_no_cas'] ?? 0) === 1,
        ];
    }

    /** A same-site path to go back to, else $default (no scheme, no //host, no backslashes). */
    private static function safeReturnTo(mixed $value, string $default): string
    {
        $v = is_string($value) ? trim($value) : '';
        // Any control character or space is refused: browsers drop tabs and
        // newlines from URLs, so "/<TAB>/evil.example" would become "//evil.example".
        if ($v === '' || !str_starts_with($v, '/') || str_starts_with($v, '//') || str_contains($v, '\\') || preg_match('/[\x00-\x20\x7F]/', $v)) {
            return $default;
        }
        return $v;
    }

    public function update(string $id): void
    {
        if (!can_edit('raw_materials')) {
            redirect('/raw-materials');
        }

        try {
            CSRF::validateRequest();
        } catch (\Throwable $e) {
            // Also fires when the upload exceeds post_max_size (PHP empties
            // $_POST so the token is missing). Keep whatever did arrive.
            $_SESSION['_flash']['error']      = $e->getMessage() . ' Please try again.';
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/raw-materials/' . $id . '/edit');
        }

        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        $data = $_POST;
        $data['expected_updated_at'] = $data['updated_at'] ?? null;
        // Migration 064: the flag has its own endpoint (setSdsRequirement): this
        // save re-writes the constituents, which would make every product stale.
        unset($data['sds_not_required']);

        // Audit #3: family picker — a chosen family is a manual override; blank = Auto (rule match).
        // A changed family_id bumps updated_at (ON UPDATE): that is the intended content-change signal.
        // Auto on an item that was NOT manual leaves the columns alone (recomputeFamilies()
        // re-resolves them) so a no-op save never looks like a reassignment / staleness bump.
        $pickedFamily = (int) ($data['family_id'] ?? 0) > 0 ? (int) $data['family_id'] : null;
        if ($pickedFamily !== null) {
            $data['family_id']     = $pickedFamily;
            $data['family_source'] = 'manual';
        } elseif (($item['family_source'] ?? null) === 'manual') {
            $data['family_id']     = null;   // manual -> Auto: release the override
            $data['family_source'] = null;
        } else {
            unset($data['family_id'], $data['family_source']);
        }

        // Process checkbox fields (unchecked = not in POST)
        $data['voc_less_than_one'] = !empty($data['voc_less_than_one']) ? 1 : 0;
        $data['flash_point_greater_than'] = !empty($data['flash_point_greater_than']) ? 1 : 0;
        $data['hazardous_no_cas'] = !empty($data['hazardous_no_cas']) && (int) $data['hazardous_no_cas'] === 1 ? 1 : 0;

        // Build HAPs data JSON from form arrays
        $data['haps_data'] = $this->buildHapsJson();

        // Build Prop 65 data JSON from form arrays
        $prop65Json = $this->buildProp65Json();
        $data['prop65_data'] = $prop65Json;
        $data['is_prop65'] = ($prop65Json !== null) ? 1 : 0;

        // Trade-secret mode is mutually exclusive with CAS constituents.
        if ($data['hazardous_no_cas']) {
            $data['manual_hazard_json'] = json_encode(
                \SDS\Services\GHSHelper::buildDeterminationJson($_POST),
                JSON_UNESCAPED_UNICODE
            );
        } else {
            $data['manual_hazard_json'] = null;
        }

        try {
            // ── Optimistic-lock check FIRST, before any mutation ──────
            // addSds() below updates raw_materials.supplier_sds_path,
            // which bumps updated_at via ON UPDATE CURRENT_TIMESTAMP. If
            // we left the lock check inside Model::update() (where it
            // used to live), the bump would trigger a false "modified
            // by another user" error for a user who did everything in
            // a single save. Doing the check once up front, then passing
            // _skip_lock_check to Model::update(), keeps a real concurrent
            // edit detectable while not self-conflicting on our own writes.
            RawMaterial::checkOptimisticLock((int) $id, $data['expected_updated_at'] ?? null);
            $data['_skip_lock_check'] = true;

            // Handle SDS file upload — always adds to history, never removes old.
            // Date Received is required when an SDS file is uploaded; check
            // BEFORE handleSdsUpload() moves the file into place so a
            // validation failure doesn't leave an orphaned PDF on disk.
            $dateReceived = trim($_POST['sds_date_received'] ?? '');
            $sdsSupplier  = trim($_POST['sds_supplier'] ?? '');
            if ($this->sdsFileSubmitted()) {
                if ($sdsSupplier === '') {
                    throw new \InvalidArgumentException(
                        'SDS Supplier is required when uploading an SDS.'
                    );
                }
                if ($dateReceived === '') {
                    throw new \InvalidArgumentException(
                        'Date Received is required when uploading an SDS.'
                    );
                }
            }
            $sdsInfo = $this->handleSdsUpload();

            if ($sdsInfo !== null) {
                $data['supplier_sds_path']     = $sdsInfo['path'];
                $data['sds_last_confirmed_at'] = $dateReceived;

                // Add to SDS history (addSds will also update sds_last_confirmed_at)
                RawMaterial::addSds(
                    (int) $id,
                    $sdsInfo['path'],
                    $sdsInfo['original_name'],
                    $sdsInfo['size'],
                    trim($data['sds_notes'] ?? '') ?: null,
                    current_user_id(),
                    $dateReceived !== '' ? $dateReceived : null,
                    $sdsSupplier !== '' ? $sdsSupplier : null
                );
            }

            $diff = AuditService::diff($item, $data);
            // A12(c): not_assessed <-> no prints nothing -> metadata-only write (no bump);
            // a change to or from 'yes' rides the normal update (ON UPDATE bump).
            $dustMetadataOnly = RawMaterial::combustibleDustChangeIsMetadataOnly($item['combustible_dust'] ?? null, $data['combustible_dust'] ?? null);
            $dustValue = $dustMetadataOnly ? RawMaterial::normalizeCombustibleDust($data['combustible_dust']) : null;
            if ($dustMetadataOnly) {
                unset($data['combustible_dust']);
            }
            RawMaterial::update((int) $id, $data);
            if ($dustValue !== null) {
                RawMaterial::setCombustibleDustMetadataOnly((int) $id, $dustValue);
            }
            AuditService::log('raw_material', $id, 'update', $diff);

            if ($data['hazardous_no_cas']) {
                // In trade-secret mode: remove any existing constituents so
                // the two data paths stay mutually exclusive.
                \SDS\Core\Database::getInstance()->delete(
                    'raw_material_constituents',
                    'raw_material_id = ?',
                    [(int) $id]
                );
            } else {
                // Normal mode: save constituents from POST.
                $this->saveConstituentsFromPost((int) $id);
            }

            $_SESSION['_flash']['success'] = 'Raw material updated.'
                . $this->recomputeFamilies((int) $id, 'Raw material ' . $item['internal_code'] . ' saved');
        } catch (\Throwable $e) {
            // Preserve the user's in-flight form input so they can fix the
            // error without re-entering anything. Scalars come back via
            // old(); the form rebuilds constituents, Prop 65 / HAP manual
            // rows, checkboxes and the hazard picker from the same array.
            // The one thing a redirect can't carry is the uploaded file —
            // formErrorMessage says so when one was part of the submit.
            $_SESSION['_flash']['error']      = $this->formErrorMessage($e);
            $_SESSION['_flash']['_old_input'] = $_POST;
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
            $rm = RawMaterial::findById((int) $id);
            RawMaterial::delete((int) $id);
            AuditService::log('raw_material', $id, 'delete', ['internal_code' => $rm['internal_code'] ?? null]);
            $_SESSION['_flash']['success'] = 'Raw material deleted.';
            // Migration 063: keep the CMS sync from re-creating it from the
            // Raw Material / Resale GL groups. Creating it by hand lifts this.
            $code = trim((string) ($rm['internal_code'] ?? ''));
            if ($code !== '') {
                try {
                    Database::getInstance()->query(
                        'INSERT IGNORE INTO cms_rm_import_exclusions (item_code, reason, created_by) VALUES (?, ?, ?)',
                        [$code, 'Raw material deleted', current_user_id()]
                    );
                    $_SESSION['_flash']['success'] .= ' The CMS sync will not import ' . $code
                        . ' again unless a formula uses it; to bring it back, create it here by hand.';
                } catch (\Throwable $e) {
                    // Table missing (migration 063 not applied): the delete still stands.
                }
            }
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials');
    }

    /**
     * POST /raw-materials/{id}/confirm-sds-current
     *
     * Marks the existing supplier SDS as still current without requiring
     * a re-upload. Advances sds_last_confirmed_at to today; the PDF's
     * uploaded_at and sds_date_received stay unchanged so there's still
     * an audit trail of the original age.
     */
    /**
     * POST /raw-materials/sds-version/{sdsId}/confirm-current
     *
     * Per-supplier "Supplier Confirmed Current": stamps ONE SDS row's
     * sds_last_confirmed_at with today (the RM-level date is re-synced to
     * the max across suppliers). updated_at is preserved — a confirmation
     * is metadata, not a content change, so downstream SDSs aren't
     * flagged for republish.
     */
    public function confirmSdsVersionCurrent(string $sdsId): void
    {
        if (!can_edit('raw_materials')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $today = date('Y-m-d');
        $rmId  = RawMaterial::markSdsRowConfirmed((int) $sdsId, $today);
        if ($rmId === null) {
            $_SESSION['_flash']['error'] = 'SDS record not found.';
            redirect('/raw-materials');
        }

        AuditService::log('raw_material', (string) $rmId, 'sds_confirmed_current', [
            'raw_material_sds_id'   => (int) $sdsId,
            'sds_last_confirmed_at' => $today,
        ]);

        $_SESSION['_flash']['success'] = 'Supplier SDS marked as still current.';
        redirect('/raw-materials/' . $rmId . '/edit');
    }

    public function confirmSdsCurrent(string $id): void
    {
        if (!can_edit('raw_materials')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $today = date('Y-m-d');
        // markSdsConfirmed deliberately preserves updated_at — confirming
        // that an existing vendor PDF is still current is metadata, not
        // a content change, and must not trigger downstream SDS republish.
        RawMaterial::markSdsConfirmed((int) $id, $today);
        AuditService::log('raw_material', $id, 'sds_confirmed_current', [
            'sds_last_confirmed_at' => $today,
        ]);

        $_SESSION['_flash']['success'] = 'Supplier SDS marked as still current.';
        redirect('/raw-materials/' . (int) $id . '/edit');
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

        $disposition = !empty($_COOKIE['sds_pdf_download']) ? 'attachment' : 'inline';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="SDS_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $item['internal_code']) . '.pdf"');
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

        // Filename: SDS_{code}_{supplier}.pdf so two vendors' SDSs for the
        // same material are distinguishable once downloaded (the RM SDS Book
        // lists one link per vendor). Falls back to the row id when the
        // supplier wasn't recorded.
        $codeSlug     = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $sds['internal_code']);
        $supplierSlug = trim(preg_replace('/[^a-zA-Z0-9]+/', '_', (string) ($sds['supplier'] ?? '')), '_');
        $suffix       = $supplierSlug !== '' ? $supplierSlug : 'v' . (int) $sds['id'];

        $disposition = !empty($_COOKIE['sds_pdf_download']) ? 'attachment' : 'inline';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="SDS_' . $codeSlug . '_' . $suffix . '.pdf"');
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

                // Attach Prop 65 details if the CAS is on the public list.
                // The RM form's Manual Prop 65 section uses this to auto-
                // fill the chemical name + toxicity-type checkboxes.
                $p65 = \SDS\Core\Database::getInstance()->fetch(
                    "SELECT chemical_name, toxicity_type FROM prop65_list WHERE cas_number = ?",
                    [$cas]
                );
                if ($p65 !== null) {
                    $response['prop65'] = [
                        'chemical_name'  => $p65['chemical_name'],
                        'toxicity_types' => \SDS\Services\Prop65Service::normaliseTypes((string) $p65['toxicity_type']),   // #47
                    ];
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
        $tsHCodes     = $_POST['trade_secret_h_codes'] ?? [];

        foreach ($casNumbers as $i => $cas) {
            $cas         = trim($cas);
            $isSecret    = isset($secrets[$i]);
            $description = trim($chemNames[$i] ?? '');

            if ($cas === '' && !$isSecret) {
                continue;
            }

            $constituents[] = [
                'cas_number'               => $cas,
                'chemical_name'            => $description,
                'pct_min'                  => ($pctMins[$i] ?? '') !== '' ? (float) $pctMins[$i] : null,
                'pct_max'                  => ($pctMaxs[$i] ?? '') !== '' ? (float) $pctMaxs[$i] : null,
                'pct_exact'                => ($pctExacts[$i] ?? '') !== '' ? (float) $pctExacts[$i] : null,
                'is_trade_secret'          => $isSecret ? 1 : 0,
                'trade_secret_description' => $isSecret ? trim($tsDescs[$i] ?? '') : null,
                'trade_secret_h_codes'     => $isSecret ? trim($tsHCodes[$i] ?? '') : null,
                'sort_order'               => $i + 1,
            ];
        }

        RawMaterial::saveConstituents($rmId, $constituents);

        $this->autoSaveTradeSecretDescriptions($constituents);

        return count($constituents);
    }

    /**
     * Auto-add any new trade secret descriptions to the saved list.
     */
    private function autoSaveTradeSecretDescriptions(array $constituents): void
    {
        $existing = $this->loadTradeSecretDescriptions();
        $newDescs = [];

        foreach ($constituents as $c) {
            $desc = trim($c['trade_secret_description'] ?? '');
            if ($desc !== '' && !in_array($desc, $existing, true) && !in_array($desc, $newDescs, true)) {
                $newDescs[] = $desc;
            }
        }

        if (empty($newDescs)) {
            return;
        }

        $all = array_merge($existing, $newDescs);
        sort($all);
        $db = \SDS\Core\Database::getInstance();
        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('sds.trade_secret_descriptions', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [implode("\n", $all)]
        );
    }

    /**
     * Build Prop 65 data JSON from the form's repeating Prop 65 rows.
     *
     * After collecting the submitted rows, prune any entry whose CAS
     * number matches one of the constituents posted in the same form.
     * Those constituents are already covered by the Auto Prop 65
     * section (derived from prop65_list at render time), so a manual
     * duplicate would double-count the chemical. Logic is shared with
     * scripts/resave-prop65-raw-materials.php via
     * Prop65Service::pruneManualEntriesAgainstConstituents().
     */
    private function buildProp65Json(): ?string
    {
        $chemNames  = $_POST['p65_chemical_name'] ?? [];
        $casNums    = $_POST['p65_cas_number'] ?? [];
        $isTrace    = $_POST['p65_is_trace'] ?? [];
        $isOverride = $_POST['p65_is_override'] ?? [];
        $toxCancer  = $_POST['p65_tox_cancer'] ?? [];
        $toxDev     = $_POST['p65_tox_developmental'] ?? [];
        $toxRepro   = $_POST['p65_tox_reproductive'] ?? [];
        $toxFemale  = $_POST['p65_tox_female_reproductive'] ?? [];
        $toxMale    = $_POST['p65_tox_male_reproductive'] ?? [];

        $entries = [];
        // Iterate over whichever column is widest so we don't drop rows
        // that have a CAS but no typed name (non-override rows derive
        // the name from prop65_list at render time).
        $rowCount = max(count($chemNames), count($casNums), count($isOverride));
        for ($i = 0; $i < $rowCount; $i++) {
            $name     = trim((string) ($chemNames[$i] ?? ''));
            $cas      = trim((string) ($casNums[$i] ?? ''));
            $override = !empty($isOverride[$i]);

            // Empty row — skip. A row with neither CAS nor name has
            // nothing to act on.
            if ($cas === '' && $name === '') {
                continue;
            }

            // Non-override rows must have a CAS (that's how the service
            // looks them up in prop65_list). Override rows need a name.
            if (!$override && $cas === '') {
                continue;
            }
            if ($override && $name === '') {
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
                'cas_number'     => $cas,
                'toxicity_types' => implode(', ', $types),
                'is_trace'       => !empty($isTrace[$i]) ? 1 : 0,
                'is_override'    => $override ? 1 : 0,
            ];
        }

        // Reconstruct the constituents list that this same POST request
        // is about to save so the prune helper can drop any manual entry
        // whose CAS is already in composition.
        $postedConstituents = [];
        $postedCas          = $_POST['cas_number'] ?? [];
        foreach ($postedCas as $cas) {
            $cas = trim((string) $cas);
            if ($cas !== '') {
                $postedConstituents[] = ['cas_number' => $cas];
            }
        }

        $result = \SDS\Services\Prop65Service::pruneManualEntriesAgainstConstituents(
            $postedConstituents,
            $entries
        );
        $pruned = $result['pruned'];

        return !empty($pruned) ? json_encode($pruned) : null;
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

        // Prune manual entries whose CAS already appears in the RM's
        // composition — those are shown by the Auto-detected HAPs section
        // on the edit page (derived from hap_list at render time), so
        // keeping a manual duplicate would double-count the chemical in
        // the generated SDS. Mirrors the Prop 65 save path exactly.
        if (!empty($haps)) {
            $postedConstituents = [];
            foreach (($_POST['cas_number'] ?? []) as $cas) {
                $cas = trim((string) $cas);
                if ($cas !== '') {
                    $postedConstituents[] = ['cas_number' => $cas];
                }
            }
            $result = \SDS\Services\HAPService::pruneManualEntriesAgainstConstituents(
                $postedConstituents,
                $haps
            );
            $haps = $result['pruned'];
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
