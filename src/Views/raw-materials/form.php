<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/raw-materials/' . (int) $item['id'] : '/raw-materials';
?>

<div class="card">
    <form method="POST" action="<?= $action ?>" enctype="multipart/form-data" id="rawMaterialForm">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="updated_at" value="<?= e($item['updated_at'] ?? '') ?>">
        <?php endif; ?>

        <!-- Basic Information -->
        <h3>Basic Information</h3>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="internal_code">Internal Code *</label>
                <input type="text" id="internal_code" name="internal_code"
                       value="<?= e(old('internal_code', $item['internal_code'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="supplier">Supplier</label>
                <input type="text" id="supplier" name="supplier"
                       value="<?= e(old('supplier', $item['supplier'] ?? '')) ?>">
            </div>
            <div class="form-group full-width">
                <label for="supplier_product_name">Product Description</label>
                <input type="text" id="supplier_product_name" name="supplier_product_name"
                       value="<?= e(old('supplier_product_name', $item['supplier_product_name'] ?? '')) ?>">
            </div>
            <div class="form-group full-width">
                <label for="supplier_product_code">Supplier Product Code</label>
                <input type="text" id="supplier_product_code" name="supplier_product_code"
                       value="<?= e(old('supplier_product_code', $item['supplier_product_code'] ?? '')) ?>">
            </div>
            <div class="form-group full-width">
                <small class="text-muted">Supplier, Product Description, and Supplier Product Code are automatically populated from CMS on each sync. Manual edits will be overwritten when CMS has matching data.</small>
            </div>
        </div>

        <!-- Supplier SDS Upload -->
        <h3>Supplier SDS</h3>
        <div class="form-grid-2col">
            <div class="form-group full-width">
                <?php if ($isEdit && !empty($item['supplier_sds_path'])): ?>
                    <div style="margin-bottom: 0.5rem; padding: 0.5rem; background: #f0f4f8; border-radius: 4px;">
                        <a href="/raw-materials/<?= (int) $item['id'] ?>/sds" target="_blank" class="btn btn-sm btn-primary">View Current SDS</a>
                        <span class="text-muted" style="margin-left: 0.5rem;"><?= e(basename($item['supplier_sds_path'])) ?></span>
                    </div>
                <?php endif; ?>
                <label for="supplier_sds">Upload New SDS (PDF)</label>
                <input type="file" id="supplier_sds" name="supplier_sds" accept=".pdf,application/pdf">
                <small class="text-muted">Upload the supplier's Safety Data Sheet (PDF, max 20 MB). Previous SDS files are preserved in history below.</small>
            </div>
            <div class="form-group">
                <label for="sds_date_received">Date Received from Supplier <span class="text-muted">(required when uploading)</span></label>
                <input type="date" id="sds_date_received" name="sds_date_received" max="<?= e(date('Y-m-d')) ?>">
                <small class="text-muted">The revision/receipt date printed on the supplier SDS. Separate from the upload date.</small>
            </div>
            <div class="form-group">
                <label for="sds_notes">SDS Upload Notes (optional)</label>
                <input type="text" id="sds_notes" name="sds_notes" placeholder="e.g., Revised 2024, new formulation...">
            </div>
        </div>

        <?php if ($isEdit && !empty($item['sds_last_confirmed_at'])): ?>
            <?php
                $daysSince = (int) ((time() - strtotime($item['sds_last_confirmed_at'])) / 86400);
            ?>
            <div class="card" style="margin-top: 0.5rem; padding: 0.5rem 0.75rem; background: #f0f4f8;">
                <strong>Last confirmed current:</strong>
                <?= e($item['sds_last_confirmed_at']) ?>
                <span class="text-muted">(<?= $daysSince ?> day<?= $daysSince === 1 ? '' : 's' ?> ago)</span>
                <button type="submit" form="confirmSdsCurrentForm" class="btn btn-sm btn-outline"
                        style="margin-left: 0.75rem;"
                        title="Use when the vendor confirms the existing SDS is still current — advances the confirmation date without requiring a re-upload.">
                    Supplier Confirmed Current
                </button>
            </div>
        <?php endif; ?>

        <?php if ($isEdit && !empty($sdsHistory)): ?>
        <!-- SDS History -->
        <h3>SDS History</h3>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Notes</th>
                    <th>Uploaded By</th>
                    <th>Upload Date</th>
                    <th>Date Received</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sdsHistory as $idx => $sds): ?>
                <tr<?= $idx === 0 ? ' style="background: #e8f5e9;"' : '' ?>>
                    <td><?= $idx === 0 ? '<strong>Current</strong>' : ($idx + 1) ?></td>
                    <td><?= e($sds['original_filename'] ?: basename($sds['file_path'])) ?></td>
                    <td><?= $sds['file_size'] ? number_format($sds['file_size'] / 1024, 1) . ' KB' : '—' ?></td>
                    <td><?= e($sds['notes'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td><?= e($sds['uploaded_by_name'] ?? '—') ?></td>
                    <td><?= format_date($sds['uploaded_at'], 'm/d/Y H:i') ?></td>
                    <td><?= !empty($sds['sds_date_received']) ? e($sds['sds_date_received']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <a href="/raw-materials/sds-version/<?= (int) $sds['id'] ?>" target="_blank" class="btn btn-sm">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Physical Properties -->
        <h3>Physical Properties</h3>
        <div class="form-grid-3col">
            <div class="form-group">
                <label for="voc_wt">VOC wt%</label>
                <div class="input-with-check">
                    <input type="number" id="voc_wt" name="voc_wt" step="0.0001"
                           value="<?= e(old('voc_wt', $item['voc_wt'] ?? '')) ?>">
                    <label class="inline-check">
                        <input type="checkbox" name="voc_less_than_one" value="1" id="vocLessThanOne"
                               <?= !empty($item['voc_less_than_one']) ? 'checked' : '' ?>>
                        &lt;1% VOC wt%
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label for="exempt_voc_wt">Exempt VOC wt%</label>
                <input type="number" id="exempt_voc_wt" name="exempt_voc_wt" step="0.0001"
                       value="<?= e(old('exempt_voc_wt', $item['exempt_voc_wt'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="water_wt">Water wt%</label>
                <input type="number" id="water_wt" name="water_wt" step="0.0001"
                       value="<?= e(old('water_wt', $item['water_wt'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="specific_gravity">Specific Gravity</label>
                <input type="number" id="specific_gravity" name="specific_gravity" step="0.00001"
                       value="<?= e(old('specific_gravity', $item['specific_gravity'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="solids_wt">Solids wt%</label>
                <input type="number" id="solids_wt" name="solids_wt" step="0.0001"
                       value="<?= e(old('solids_wt', $item['solids_wt'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="solids_vol">Solids vol%</label>
                <input type="number" id="solids_vol" name="solids_vol" step="0.0001"
                       value="<?= e(old('solids_vol', $item['solids_vol'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="flash_point_c">Flash Point (&deg;C)</label>
                <div class="input-with-check">
                    <input type="number" id="flash_point_c" name="flash_point_c" step="0.1"
                           value="<?= e(old('flash_point_c', $item['flash_point_c'] ?? '')) ?>">
                    <label class="inline-check">
                        <input type="checkbox" name="flash_point_greater_than" value="1"
                               <?= !empty($item['flash_point_greater_than']) ? 'checked' : '' ?>>
                        Flash Point Greater than (&gt;)
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label for="physical_state">Physical State</label>
                <select id="physical_state" name="physical_state">
                    <option value="">—</option>
                    <?php foreach (['Liquid', 'Solid', 'Paste', 'Powder', 'Gas'] as $state): ?>
                        <option value="<?= $state ?>" <?= (old('physical_state', $item['physical_state'] ?? '') === $state) ? 'selected' : '' ?>><?= $state ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="solubility">Solubility</label>
                <select id="solubility" name="solubility">
                    <option value="">—</option>
                    <?php foreach (['Insoluble in water', 'Partially soluble in water', 'Soluble in water'] as $sol): ?>
                        <option value="<?= $sol ?>" <?= (old('solubility', $item['solubility'] ?? '') === $sol) ? 'selected' : '' ?>><?= $sol ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full-width">
                <label for="appearance">Appearance</label>
                <input type="text" id="appearance" name="appearance"
                       value="<?= e(old('appearance', $item['appearance'] ?? '')) ?>">
            </div>
            <div class="form-group full-width">
                <label for="odor">Odor</label>
                <input type="text" id="odor" name="odor"
                       value="<?= e(old('odor', $item['odor'] ?? '')) ?>">
            </div>
            <div class="form-group full-width">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3"><?= e(old('notes', $item['notes'] ?? '')) ?></textarea>
            </div>
        </div>

        <!-- Manual GHS Classifications (for trade-secret vendor materials) -->
        <h3>Manual GHS Classifications</h3>
        <p class="text-muted">Use this section only when the vendor SDS provides hazard classifications but does NOT disclose CAS numbers (trade secret). When enabled, this raw material has no CAS constituents, and its GHS classifications flow into downstream SDSs as a combined "Trade Secret" component.</p>

        <div class="form-group">
            <label style="display: inline-flex; align-items: center; gap: 6px;">
                <input type="hidden" name="hazardous_no_cas" value="0">
                <input type="checkbox" id="hazardous_no_cas" name="hazardous_no_cas" value="1"
                       <?= !empty($item['hazardous_no_cas']) ? 'checked' : '' ?>>
                <strong>Hazardous, but no CAS provided — use manual GHS classifications</strong>
            </label>
        </div>

        <?php
            $manualHazard = !empty($item['manual_hazard_json'])
                ? (json_decode($item['manual_hazard_json'], true) ?: [])
                : [];
            $rmSelectedHazards = [];
            if (!empty($manualHazard['selected_hazards'])) {
                $rmSelectedHazards = is_string($manualHazard['selected_hazards'])
                    ? (json_decode($manualHazard['selected_hazards'], true) ?: [])
                    : $manualHazard['selected_hazards'];
            }
        ?>

        <div id="manual-hazard-picker" style="display: <?= !empty($item['hazardous_no_cas']) ? 'block' : 'none' ?>; margin-top: 10px;">
            <p class="text-muted">Check the hazard classifications that apply. Signal word, P statements, and pictograms are auto-derived on save.</p>

            <div class="hazard-selection" style="max-height: 400px; overflow-y: auto; padding: 8px; border: 1px solid #e0e0e0; border-radius: 4px; background: #fafafa;">
                <?php
                $rmGrouped = \SDS\Services\GHSHazardData::groupedByClass();
                foreach ($rmGrouped as $className => $entries):
                ?>
                <div class="hazard-class-group" style="margin-bottom: 8px;">
                    <div style="font-weight: 600; border-bottom: 1px solid #ddd; padding: 4px 0; margin-bottom: 4px;"><?= e($className) ?></div>
                    <?php foreach ($entries as $key => $entry): ?>
                    <label style="display: block; padding: 2px 4px; font-size: 0.875rem;">
                        <input type="checkbox" name="hazard_selections[]" value="<?= e($key) ?>"
                               <?= in_array($key, $rmSelectedHazards, true) ? 'checked' : '' ?>>
                        <strong><?= e($entry['category']) ?></strong>
                        — <?= e(implode(', ', $entry['h_codes'])) ?>
                        <?php if ($entry['signal_word']): ?>
                            <em class="text-muted">(<?= e($entry['signal_word']) ?>)</em>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($manualHazard['h_statements']) || !empty($manualHazard['pictograms'])): ?>
            <div class="card" style="margin-top: 10px; background: #f8f9fa; padding: 8px;">
                <strong>Currently Saved:</strong>
                <div style="font-size: 0.875rem; margin-top: 4px;">
                    <div><strong>Signal Word:</strong> <?= e($manualHazard['signal_word'] ?? '—') ?></div>
                    <div><strong>H Statements:</strong> <?= e($manualHazard['h_statements'] ?? '—') ?></div>
                    <div><strong>P Statements:</strong> <?= e($manualHazard['p_statements'] ?? '—') ?></div>
                    <div><strong>Pictograms:</strong> <?= e($manualHazard['pictograms'] ?? '—') ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- CAS Constituents (inline) -->
        <div id="cas-constituents-section" style="display: <?= !empty($item['hazardous_no_cas']) ? 'none' : 'block' ?>;">
        <h3>CAS Constituents</h3>
        <p class="text-muted">Enter the CAS numbers and concentrations from the supplier SDS. Chemical name will auto-populate when a valid CAS number is entered. Regulatory list membership and exposure limits are shown automatically.</p>

        <table class="table" id="constituentsTable">
            <thead>
                <tr>
                    <th>CAS Number</th>
                    <th>Chemical Name</th>
                    <th>% Min</th>
                    <th>% Max</th>
                    <th>% Exact</th>
                    <th>Trade Secret</th>
                    <th>TS Description</th>
                    <th>TS H Codes</th>
                    <th>Non-Haz</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($constituents)): ?>
                <?php foreach ($constituents as $i => $c): ?>
                <tr class="constituent-row">
                    <td>
                        <input type="text" name="cas_number[<?= $i ?>]" value="<?= e($c['cas_number']) ?>" placeholder="67-56-1" class="input-sm cas-input">
                        <div class="cas-reg-tags"></div>
                    </td>
                    <td>
                        <input type="text" name="chemical_name[<?= $i ?>]" value="<?= e($c['chemical_name']) ?>" class="input-sm chem-name-input">
                        <div class="cas-exposure-info"></div>
                    </td>
                    <td><input type="number" name="pct_min[<?= $i ?>]" value="<?= e((string) ($c['pct_min'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_max[<?= $i ?>]" value="<?= e((string) ($c['pct_max'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_exact[<?= $i ?>]" value="<?= e((string) ($c['pct_exact'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="checkbox" name="is_trade_secret[<?= $i ?>]" value="1" class="ts-checkbox" <?= ((int) ($c['is_trade_secret'] ?? 0)) ? 'checked' : '' ?>></td>
                    <td>
                        <input type="text" name="trade_secret_description[<?= $i ?>]" list="ts-desc-list" value="<?= e($c['trade_secret_description'] ?? '') ?>" placeholder="Type or select..." class="input-sm ts-desc-input" <?= ((int) ($c['is_trade_secret'] ?? 0)) ? '' : 'disabled' ?>>
                    </td>
                    <td>
                        <input type="hidden" name="trade_secret_h_codes[<?= $i ?>]" value="<?= e($c['trade_secret_h_codes'] ?? '') ?>" class="ts-hcodes-hidden">
                        <span class="ts-hcodes-summary" style="font-size:0.8rem;"><?= e($c['trade_secret_h_codes'] ?? '') ?></span>
                        <button type="button" class="btn btn-sm ts-hcodes-btn" <?= ((int) ($c['is_trade_secret'] ?? 0)) ? '' : 'disabled' ?> style="margin-top:2px;">Select</button>
                    </td>
                    <td><input type="checkbox" name="is_non_hazardous[<?= $i ?>]" value="1" <?= ((int) ($c['is_non_hazardous'] ?? 0)) ? 'checked' : '' ?>></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-row">X</button></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="constituent-row">
                    <td>
                        <input type="text" name="cas_number[0]" placeholder="67-56-1" class="input-sm cas-input">
                        <div class="cas-reg-tags"></div>
                    </td>
                    <td>
                        <input type="text" name="chemical_name[0]" class="input-sm chem-name-input">
                        <div class="cas-exposure-info"></div>
                    </td>
                    <td><input type="number" name="pct_min[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_max[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_exact[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="checkbox" name="is_trade_secret[0]" value="1" class="ts-checkbox"></td>
                    <td>
                        <input type="text" name="trade_secret_description[0]" list="ts-desc-list" placeholder="Type or select..." class="input-sm ts-desc-input" disabled>
                    </td>
                    <td>
                        <input type="hidden" name="trade_secret_h_codes[0]" value="" class="ts-hcodes-hidden">
                        <span class="ts-hcodes-summary" style="font-size:0.8rem;"></span>
                        <button type="button" class="btn btn-sm ts-hcodes-btn" disabled style="margin-top:2px;">Select</button>
                    </td>
                    <td><input type="checkbox" name="is_non_hazardous[0]" value="1"></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-row">X</button></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-bottom: 1.5rem;">
            <button type="button" id="addRow" class="btn btn-sm btn-outline">+ Add Row</button>
        </div>

        <!-- Datalist for trade secret descriptions (autocomplete from saved list) -->
        <datalist id="ts-desc-list">
            <?php foreach ($tradeSecretDescriptions ?? [] as $tsd): ?>
                <option value="<?= e($tsd) ?>">
            <?php endforeach; ?>
        </datalist>

        <!-- H Codes picker modal -->
        <div id="tsHcodesModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:9999; background:rgba(0,0,0,0.5);">
            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:8px; width:700px; max-width:95vw; max-height:85vh; display:flex; flex-direction:column;">
                <div style="padding:12px 16px; border-bottom:1px solid #e0e0e0; display:flex; justify-content:space-between; align-items:center;">
                    <strong>Select Hazard Classifications</strong>
                    <button type="button" id="tsHcodesModalClose" style="background:none; border:none; font-size:1.2rem; cursor:pointer;">&times;</button>
                </div>
                <div id="tsHcodesModalBody" style="padding:12px 16px; overflow-y:auto; flex:1;">
                    <?php
                    $tsGrouped = \SDS\Services\GHSHazardData::groupedByClass();
                    foreach ($tsGrouped as $className => $entries):
                    ?>
                    <div class="hazard-class-group" style="margin-bottom:8px;">
                        <div style="font-weight:600; border-bottom:1px solid #ddd; padding:4px 0; margin-bottom:4px;"><?= e($className) ?></div>
                        <?php foreach ($entries as $key => $entry): ?>
                        <label style="display:block; padding:2px 4px; font-size:0.875rem;">
                            <input type="checkbox" class="ts-modal-hazard-cb" data-key="<?= e($key) ?>" data-hcodes="<?= e(implode(',', $entry['h_codes'])) ?>">
                            <strong><?= e($entry['category']) ?></strong>
                            — <?= e(implode(', ', $entry['h_codes'])) ?>
                            <?php if ($entry['signal_word']): ?>
                                <em class="text-muted">(<?= e($entry['signal_word']) ?>)</em>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="padding:12px 16px; border-top:1px solid #e0e0e0; text-align:right;">
                    <button type="button" id="tsHcodesModalApply" class="btn btn-primary btn-sm">Apply</button>
                    <button type="button" id="tsHcodesModalCancel" class="btn btn-sm" style="margin-left:6px;">Cancel</button>
                </div>
            </div>
        </div>

        </div><!-- /#cas-constituents-section -->

        <!-- California Proposition 65 — Auto (derived) + Manual (overrides) -->
        <h3>California Proposition 65</h3>

        <?php
        // ── Build the Auto section from this RM's constituents ─────
        // For every constituent CAS that appears in the seeded
        // prop65_list, render a read-only row showing the list's
        // chemical name + toxicity types. Trace status is derived from
        // the constituent's effective percentage vs the admin-configured
        // threshold (default 0.1 %).
        $p65AutoRows   = [];
        $p65AutoCasSet = [];
        $autoThreshold = \SDS\Services\Prop65Service::autoTraceThresholdPct();

        $constituentCasList = [];
        foreach (($constituents ?? []) as $c) {
            $cas = trim((string) ($c['cas_number'] ?? ''));
            if ($cas !== '') {
                $constituentCasList[$cas] = $c;
            }
        }
        if (!empty($constituentCasList)) {
            $db = \SDS\Core\Database::getInstance();
            $casArr       = array_keys($constituentCasList);
            $placeholders = implode(',', array_fill(0, count($casArr), '?'));
            $listRows = $db->fetchAll(
                "SELECT cas_number, chemical_name, toxicity_type
                 FROM prop65_list
                 WHERE cas_number IN ({$placeholders})",
                $casArr
            );
            foreach ($listRows as $lr) {
                $c = $constituentCasList[$lr['cas_number']] ?? null;
                if ($c === null) { continue; }

                // Effective pct: prefer pct_exact, else upper bound of range
                $eff = null;
                if (isset($c['pct_exact']) && $c['pct_exact'] !== null && $c['pct_exact'] !== '') {
                    $eff = (float) $c['pct_exact'];
                } elseif (isset($c['pct_max']) && $c['pct_max'] !== null && $c['pct_max'] !== '') {
                    $eff = (float) $c['pct_max'];
                } elseif (isset($c['pct_min']) && $c['pct_min'] !== null && $c['pct_min'] !== '') {
                    $eff = (float) $c['pct_min'];
                }

                $p65AutoRows[] = [
                    'cas_number'    => $lr['cas_number'],
                    'chemical_name' => $lr['chemical_name'],
                    'toxicity_type' => $lr['toxicity_type'],
                    'effective_pct' => $eff,
                    'is_trace'      => ($eff !== null) ? ($eff < $autoThreshold) : false,
                ];
                $p65AutoCasSet[$lr['cas_number']] = true;
            }
        }

        // ── Manual section: load existing prop65_data, skipping entries
        //    whose CAS is in the Auto section (those are duplicates and
        //    will be pruned on save).
        $prop65Data = [];
        if (!empty($item['prop65_data'])) {
            $decoded = json_decode($item['prop65_data'], true);
            if (is_array($decoded)) {
                if (array_is_list($decoded) || $decoded === []) {
                    $prop65Data = $decoded;
                } else {
                    $prop65Data = [$decoded];
                }
            }
        }
        // Backward compat: if no JSON data but old fields exist, build one entry
        if (empty($prop65Data) && !empty($item['is_prop65']) && !empty($item['prop65_chemical_name'])) {
            $prop65Data = [[
                'chemical_name'  => $item['prop65_chemical_name'],
                'cas_number'     => '',
                'toxicity_types' => $item['prop65_toxicity_types'] ?? '',
            ]];
        }
        // Hide any entry whose CAS is covered by the Auto section.
        $prop65Data = array_values(array_filter($prop65Data, function ($e) use ($p65AutoCasSet) {
            $cas = trim((string) ($e['cas_number'] ?? ''));
            return $cas === '' || !isset($p65AutoCasSet[$cas]);
        }));
        ?>

        <!-- Auto section: derived from constituents + prop65_list -->
        <h4 style="margin-top: 0.75rem; margin-bottom: 0.25rem;">Auto-detected (from composition)</h4>
        <p class="text-muted" style="margin-top: 0; margin-bottom: 0.5rem;">
            Every constituent whose CAS appears in the California Prop 65 database.
            Name and reason come from the public list; trace is derived from the
            constituent's percentage (threshold: <?= number_format($autoThreshold, 2) ?>%,
            configurable in <a href="/admin/settings">Admin Settings</a>).
            Edit the composition above to change this list.
        </p>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>CAS</th>
                    <th>Chemical Name</th>
                    <th>Toxicity Type(s)</th>
                    <th>Conc.</th>
                    <th>Trace</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($p65AutoRows)): ?>
                <tr><td colspan="5" class="text-muted" style="font-style: italic;">None detected from current composition.</td></tr>
            <?php else: ?>
                <?php foreach ($p65AutoRows as $ar): ?>
                <tr>
                    <td><code><?= e($ar['cas_number']) ?></code></td>
                    <td><?= e($ar['chemical_name']) ?></td>
                    <td><?= e($ar['toxicity_type']) ?></td>
                    <td><?= $ar['effective_pct'] !== null ? e(number_format($ar['effective_pct'], 3)) . '%' : '—' ?></td>
                    <td><?= $ar['is_trace'] ? '<span class="badge badge-muted">trace</span>' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- Manual section: overrides + non-CAS chemicals -->
        <h4 style="margin-top: 1rem; margin-bottom: 0.25rem;">Manual entries</h4>
        <p class="text-muted" style="margin-top: 0; margin-bottom: 0.5rem;">
            Use this for Prop 65 chemicals that are below the Section 3 disclosure
            threshold (i.e. not listed in the composition) but still need a warning
            — typically trace. Enter the CAS and the name + toxicity are derived
            from the public list automatically. Check <strong>Override</strong> to
            force a specific name or toxicity (needed when the CAS isn't on the
            public list). Entries whose CAS also appears in the composition above
            are pruned on save — let the Auto section handle those.
        </p>

        <?php
        // Pre-fetch prop65_list rows for any existing CAS we're about
        // to render so non-override rows can show the authoritative
        // name + toxicity instead of whatever was typed long ago.
        $manualCasList = [];
        foreach ($prop65Data as $p65) {
            $c = trim((string) ($p65['cas_number'] ?? ''));
            if ($c !== '') { $manualCasList[$c] = true; }
        }
        $p65ListByCas = [];
        if (!empty($manualCasList)) {
            $db2 = \SDS\Core\Database::getInstance();
            $casArr2 = array_keys($manualCasList);
            $ph2     = implode(',', array_fill(0, count($casArr2), '?'));
            foreach ($db2->fetchAll(
                "SELECT cas_number, chemical_name, toxicity_type
                   FROM prop65_list WHERE cas_number IN ({$ph2})",
                $casArr2
            ) as $lr) {
                $p65ListByCas[$lr['cas_number']] = $lr;
            }
        }
        ?>

        <table class="table table-sm" id="prop65Table">
            <thead>
                <tr>
                    <th>CAS Number</th>
                    <th>Chemical Name</th>
                    <th>Toxicity Type(s)</th>
                    <th>Flags</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="prop65Body">
            <?php if (!empty($prop65Data)): ?>
                <?php foreach ($prop65Data as $pi => $p65):
                    $entryCas   = trim((string) ($p65['cas_number'] ?? ''));
                    $entryOver  = !empty($p65['is_override']);
                    $listMatch  = (!$entryOver && isset($p65ListByCas[$entryCas])) ? $p65ListByCas[$entryCas] : null;

                    // Non-override rows: prefer prop65_list's values for
                    // display. Override rows: use whatever was stored.
                    $displayName = $listMatch !== null
                        ? $listMatch['chemical_name']
                        : (string) ($p65['chemical_name'] ?? '');
                    $displayTypesRaw = $listMatch !== null
                        ? (string) $listMatch['toxicity_type']
                        : (string) ($p65['toxicity_types'] ?? '');
                    $existingTypes = array_values(array_filter(array_map(
                        'trim',
                        explode(',', $displayTypesRaw)
                    )));
                    $inputsLocked = !$entryOver;
                    $lockAttr = $inputsLocked ? ' readonly' : '';
                    $cbLockAttr = $inputsLocked ? ' disabled' : '';
                ?>
                <tr class="prop65-row" data-override="<?= $entryOver ? '1' : '0' ?>">
                    <td><input type="text" name="p65_cas_number[<?= $pi ?>]" value="<?= e($entryCas) ?>" class="input-sm p65-cas" placeholder="e.g. 13463-67-7"></td>
                    <td>
                        <input type="text" name="p65_chemical_name[<?= $pi ?>]" value="<?= e($displayName) ?>" class="input-sm p65-chem-name" placeholder="Auto from CAS" style="width: 100%;"<?= $lockAttr ?>>
                    </td>
                    <td class="p65-tox-checkboxes">
                        <label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_cancer[<?= $pi ?>]" value="1" <?= in_array('cancer', $existingTypes, true) ? 'checked' : '' ?><?= $cbLockAttr ?>> Cancer</label>
                        <label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_developmental[<?= $pi ?>]" value="1" <?= in_array('developmental', $existingTypes, true) ? 'checked' : '' ?><?= $cbLockAttr ?>> Developmental</label>
                        <label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_reproductive[<?= $pi ?>]" value="1" <?= in_array('reproductive', $existingTypes, true) ? 'checked' : '' ?><?= $cbLockAttr ?>> Reproductive</label>
                        <label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_female_reproductive[<?= $pi ?>]" value="1" <?= in_array('female reproductive', $existingTypes, true) ? 'checked' : '' ?><?= $cbLockAttr ?>> Female Reproductive</label>
                        <label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_male_reproductive[<?= $pi ?>]" value="1" <?= in_array('male reproductive', $existingTypes, true) ? 'checked' : '' ?><?= $cbLockAttr ?>> Male Reproductive</label>
                    </td>
                    <td style="white-space: nowrap;">
                        <label class="inline-check" style="font-size:0.85rem;"><input type="checkbox" class="p65-is-trace" name="p65_is_trace[<?= $pi ?>]" value="1" <?= !empty($p65['is_trace']) ? 'checked' : '' ?>> Trace</label>
                        <label class="inline-check" style="font-size:0.85rem;"><input type="checkbox" class="p65-is-override" name="p65_is_override[<?= $pi ?>]" value="1" <?= $entryOver ? 'checked' : '' ?>> Override</label>
                    </td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-p65">X</button></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-bottom: 1.5rem;">
            <button type="button" id="addProp65Row" class="btn btn-sm btn-outline">+ Add Prop 65 Chemical</button>
        </div>

        <!-- Hazardous Air Pollutants — Auto (derived) + Manual (overrides) -->
        <h3>Hazardous Air Pollutants (HAPs)</h3>

        <?php
        // ── Build the Auto section from this RM's constituents ─────
        // For every constituent CAS that appears in hap_list, render a
        // read-only row showing the list's chemical name + category. Same
        // pattern as the Prop 65 Auto section above.
        $hapAutoRows   = [];
        $hapAutoCasSet = [];

        $constituentCasListForHap = [];
        foreach (($constituents ?? []) as $c) {
            $cas = trim((string) ($c['cas_number'] ?? ''));
            if ($cas !== '') {
                $constituentCasListForHap[$cas] = $c;
            }
        }
        if (!empty($constituentCasListForHap)) {
            $db = \SDS\Core\Database::getInstance();
            $hapCasArr     = array_keys($constituentCasListForHap);
            $hapPlaceholders = implode(',', array_fill(0, count($hapCasArr), '?'));
            $hapListRows = $db->fetchAll(
                "SELECT cas_number, chemical_name, category
                 FROM hap_list
                 WHERE cas_number IN ({$hapPlaceholders})",
                $hapCasArr
            );
            foreach ($hapListRows as $lr) {
                $c = $constituentCasListForHap[$lr['cas_number']] ?? null;
                if ($c === null) { continue; }

                // Effective pct in this RM — prefer pct_exact, then upper
                // bound of range, then lower bound. Same logic Prop 65 uses.
                $eff = null;
                if (isset($c['pct_exact']) && $c['pct_exact'] !== null && $c['pct_exact'] !== '') {
                    $eff = (float) $c['pct_exact'];
                } elseif (isset($c['pct_max']) && $c['pct_max'] !== null && $c['pct_max'] !== '') {
                    $eff = (float) $c['pct_max'];
                } elseif (isset($c['pct_min']) && $c['pct_min'] !== null && $c['pct_min'] !== '') {
                    $eff = (float) $c['pct_min'];
                }

                $hapAutoRows[] = [
                    'cas_number'    => $lr['cas_number'],
                    'chemical_name' => $lr['chemical_name'],
                    'category'      => $lr['category'],
                    'effective_pct' => $eff,
                ];
                $hapAutoCasSet[$lr['cas_number']] = true;
            }
        }

        // ── Manual HAPs: load existing haps_data, skipping entries whose
        //    CAS is already in the Auto section (those are duplicates and
        //    will be pruned on save via HAPService).
        $hapsData = [];
        if (!empty($item['haps_data'])) {
            $hapsData = json_decode($item['haps_data'], true) ?: [];
        }
        $hapsData = array_values(array_filter($hapsData, function ($e) use ($hapAutoCasSet) {
            $cas = trim((string) ($e['cas_number'] ?? ''));
            return $cas === '' || !isset($hapAutoCasSet[$cas]);
        }));
        ?>

        <!-- Auto section: derived from constituents + hap_list -->
        <h4 style="margin-top: 0.75rem; margin-bottom: 0.25rem;">Auto-detected (from composition)</h4>
        <p class="text-muted" style="margin-top: 0; margin-bottom: 0.5rem;">
            Every constituent whose CAS appears on the EPA Clean Air Act §112(b) HAP list.
            Chemical name and category come from the federal list; the weight % reflects
            the constituent's concentration in this raw material. Edit the composition
            above to change this list.
        </p>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>CAS</th>
                    <th>Chemical Name</th>
                    <th>Category</th>
                    <th>Weight %</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($hapAutoRows)): ?>
                <tr><td colspan="4" class="text-muted" style="text-align:center;">No constituents on the HAP list.</td></tr>
            <?php else: ?>
                <?php foreach ($hapAutoRows as $ar): ?>
                    <tr>
                        <td><?= e($ar['cas_number']) ?></td>
                        <td><?= e($ar['chemical_name']) ?></td>
                        <td><?= e($ar['category'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                        <td><?= $ar['effective_pct'] !== null ? number_format($ar['effective_pct'], 4) . '%' : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- Manual section: HAPs without a CAS or operator overrides -->
        <h4 style="margin-top: 1rem; margin-bottom: 0.25rem;">Manual entries</h4>
        <p class="text-muted" style="margin-top: 0; margin-bottom: 0.5rem;">
            Use this for HAPs that don't have a single CAS on the federal list
            (e.g. "Glycol ethers — mixed") or intentional overrides. Rows whose CAS
            is already in the Auto section above will be pruned on save.
        </p>

        <table class="table table-sm" id="hapsTable">
            <thead>
                <tr>
                    <th>HAP Chemical Name</th>
                    <th>CAS Number</th>
                    <th>Weight % in RM</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="hapsBody">
            <?php if (!empty($hapsData)): ?>
                <?php foreach ($hapsData as $hi => $hap): ?>
                <tr class="hap-row">
                    <td><input type="text" name="hap_chemical_name[<?= $hi ?>]" value="<?= e($hap['chemical_name'] ?? '') ?>" class="input-sm" placeholder="e.g. Glycol ethers"></td>
                    <td><input type="text" name="hap_cas_number[<?= $hi ?>]" value="<?= e($hap['cas_number'] ?? '') ?>" class="input-sm" placeholder="(optional)"></td>
                    <td><input type="number" name="hap_weight_pct[<?= $hi ?>]" value="<?= e((string) ($hap['weight_pct'] ?? '')) ?>" step="0.01" min="0" max="100" class="input-xs"></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-hap">X</button></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-bottom: 1.5rem;">
            <button type="button" id="addHapRow" class="btn btn-sm btn-outline">+ Add HAP</button>
        </div>


        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?> Raw Material</button>
            <a href="/raw-materials" class="btn btn-outline">Cancel</a>
        </div>
    </form>

    <?php if ($isEdit && !empty($item['sds_last_confirmed_at'])): ?>
    <form method="POST" action="/raw-materials/<?= (int) $item['id'] ?>/confirm-sds-current" id="confirmSdsCurrentForm" style="display:none">
        <?= csrf_field() ?>
    </form>
    <?php endif; ?>
</div>

<?php if ($isEdit && can_manage_users()): ?>
<div class="card card-danger">
    <h3>Danger Zone</h3>
    <form method="POST" action="/raw-materials/<?= (int) $item['id'] ?>/delete" onsubmit="return confirm('Delete this raw material? This cannot be undone.');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">Delete Raw Material</button>
    </form>
</div>
<?php endif; ?>

<style>
.cas-reg-tags { margin-top: 3px; line-height: 1.6; }
.cas-reg-tags .reg-tag {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    padding: 1px 5px;
    border-radius: 3px;
    margin-right: 3px;
    margin-bottom: 2px;
    white-space: nowrap;
}
.reg-tag-osha    { background: #dbeafe; color: #1e40af; }
.reg-tag-niosh   { background: #dcfce7; color: #166534; }
.reg-tag-acgih   { background: #fef3c7; color: #92400e; }
.reg-tag-prop65  { background: #fecaca; color: #991b1b; }
.reg-tag-sara    { background: #e0e7ff; color: #3730a3; }
.reg-tag-carc    { background: #fca5a5; color: #7f1d1d; }
.reg-tag-hap     { background: #fbcfe8; color: #9d174d; }
.cas-exposure-info {
    margin-top: 3px;
    font-size: 11px;
    color: #555;
    line-height: 1.4;
}
.cas-exposure-info .el-line { white-space: nowrap; }
.p65-tox-checkboxes { display: flex; flex-wrap: wrap; gap: 0.25rem 0.75rem; }
.p65-tox-checkboxes .inline-check { font-size: 0.85rem; white-space: nowrap; }
</style>
<script>
// Trade secret description options from admin settings
// Toggle visibility of CAS constituents vs manual hazard picker
(function() {
    var checkbox = document.getElementById('hazardous_no_cas');
    var picker   = document.getElementById('manual-hazard-picker');
    var cas      = document.getElementById('cas-constituents-section');
    if (checkbox && picker && cas) {
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                picker.style.display = 'block';
                cas.style.display    = 'none';
            } else {
                picker.style.display = 'none';
                cas.style.display    = 'block';
            }
        });
    }
})();

// Add constituent row
document.getElementById('addRow').addEventListener('click', function() {
    var tbody = document.querySelector('#constituentsTable tbody');
    var rows = tbody.querySelectorAll('.constituent-row');
    var idx = rows.length;
    var tr = document.createElement('tr');
    tr.className = 'constituent-row';
    tr.innerHTML = '<td><input type="text" name="cas_number[' + idx + ']" placeholder="67-56-1" class="input-sm cas-input"><div class="cas-reg-tags"></div></td>' +
        '<td><input type="text" name="chemical_name[' + idx + ']" class="input-sm chem-name-input"><div class="cas-exposure-info"></div></td>' +
        '<td><input type="number" name="pct_min[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="number" name="pct_max[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="number" name="pct_exact[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="checkbox" name="is_trade_secret[' + idx + ']" value="1" class="ts-checkbox"></td>' +
        '<td><input type="text" name="trade_secret_description[' + idx + ']" list="ts-desc-list" placeholder="Type or select..." class="input-sm ts-desc-input" disabled></td>' +
        '<td><input type="hidden" name="trade_secret_h_codes[' + idx + ']" value="" class="ts-hcodes-hidden"><span class="ts-hcodes-summary" style="font-size:0.8rem;"></span><button type="button" class="btn btn-sm ts-hcodes-btn" disabled style="margin-top:2px;">Select</button></td>' +
        '<td><input type="checkbox" name="is_non_hazardous[' + idx + ']" value="1"></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger remove-row">X</button></td>';
    tbody.appendChild(tr);
    // Attach CAS lookup to the new row
    attachCasLookup(tr.querySelector('.cas-input'));
});

// Remove constituent row
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-row')) {
        var row = e.target.closest('tr');
        if (document.querySelectorAll('.constituent-row').length > 1) {
            row.remove();
        }
    }
});

// Regulatory tag CSS class mapping
var regTagClasses = {
    'OSHA PEL':   'reg-tag-osha',
    'NIOSH REL':  'reg-tag-niosh',
    'ACGIH TLV':  'reg-tag-acgih',
    'CA Prop 65': 'reg-tag-prop65',
    'SARA 313':   'reg-tag-sara',
    'Carcinogen': 'reg-tag-carc',
    'HAP':        'reg-tag-hap'
};

// Exposure limit source display names (handles both naming conventions)
var sourceDisplayNames = {
    'osha_pel':  'OSHA PEL',
    'OSHA':      'OSHA PEL',
    'niosh':     'NIOSH',
    'NIOSH':     'NIOSH',
    'acgih_tlv': 'ACGIH',
    'ACGIH':     'ACGIH'
};

// Build a compact exposure limit summary string for a source
function formatLimits(limits) {
    var parts = [];
    var seen = {};
    for (var i = 0; i < limits.length; i++) {
        var lim = limits[i];
        // Prefer ppm display, show mg/m3 only if no ppm exists for that type
        var key = lim.type;
        if (seen[key] && lim.units === 'mg/m3') continue;
        seen[key] = true;
        var label = lim.type.replace(/^(PEL|REL|TLV)-/, '');
        parts.push(label + ': ' + lim.value + ' ' + lim.units);
    }
    return parts.join(', ');
}

// Render regulatory tags and exposure info into a row
function renderRegData(row, data) {
    var tagsDiv = row.querySelector('.cas-reg-tags');
    var infoDiv = row.querySelector('.cas-exposure-info');
    if (!tagsDiv || !infoDiv) return;

    // Clear previous
    tagsDiv.innerHTML = '';
    infoDiv.innerHTML = '';

    // Regulatory list tags
    if (data.regulatory_lists && data.regulatory_lists.length > 0) {
        for (var i = 0; i < data.regulatory_lists.length; i++) {
            var listName = data.regulatory_lists[i];
            var span = document.createElement('span');
            span.className = 'reg-tag ' + (regTagClasses[listName] || '');
            span.textContent = listName;
            tagsDiv.appendChild(span);
        }
    }

    // Exposure limit summary
    if (data.exposure_limits) {
        var lines = [];
        for (var src in data.exposure_limits) {
            if (!data.exposure_limits.hasOwnProperty(src)) continue;
            var displayName = sourceDisplayNames[src] || src;
            var summary = formatLimits(data.exposure_limits[src]);
            if (summary) {
                lines.push('<span class="el-line"><strong>' + displayName + ':</strong> ' + summary + '</span>');
            }
        }
        if (lines.length > 0) {
            infoDiv.innerHTML = lines.join('<br>');
        }
    }
}

// CAS auto-lookup: when a CAS input loses focus, look up the chemical name
function attachCasLookup(input) {
    input.addEventListener('blur', function() {
        var cas = input.value.trim();
        var row = input.closest('tr');

        if (cas === '') return;
        if (!/^\d{2,7}-\d{2}-\d$/.test(cas)) {
            input.style.borderColor = '#dc3545';
            input.title = 'Invalid CAS format. Expected: XXXXXXX-YY-Z';
            return;
        }

        // CAS checksum validation
        var parts = cas.split('-');
        var digits = parts[0] + parts[1];
        var check = parseInt(parts[2], 10);
        var sum = 0;
        var weight = 1;
        for (var i = digits.length - 1; i >= 0; i--) {
            sum += parseInt(digits[i], 10) * weight;
            weight++;
        }
        if (sum % 10 !== check) {
            input.style.borderColor = '#fd7e14';
            input.title = 'CAS checksum mismatch — verify number';
            return;
        }

        input.style.borderColor = '#198754';
        input.title = '';

        // Look up the chemical name, regulatory lists, and exposure limits
        var chemNameInput = row.querySelector('.chem-name-input');

        fetch('/raw-materials/cas-lookup?cas=' + encodeURIComponent(cas), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function(resp) {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                return resp.json();
            })
            .then(function(data) {
                if (data.found) {
                    // Auto-populate chemical name if empty
                    if (chemNameInput && chemNameInput.value.trim() === '' && data.chemical_name) {
                        chemNameInput.value = data.chemical_name;
                        chemNameInput.style.borderColor = '#198754';
                        setTimeout(function() { chemNameInput.style.borderColor = ''; }, 2000);
                    }
                    // Render regulatory tags and exposure limits
                    renderRegData(row, data);
                } else {
                    if (chemNameInput && chemNameInput.value.trim() === '') {
                        chemNameInput.placeholder = 'Not found — enter manually';
                    }
                }
            })
            .catch(function(err) {
                if (chemNameInput && chemNameInput.value.trim() === '') {
                    chemNameInput.placeholder = 'Lookup failed — enter manually';
                }
                console.error('CAS lookup error:', err);
            });
    });
}

// Attach CAS lookup to all existing inputs
document.querySelectorAll('.cas-input').forEach(attachCasLookup);

// Auto-lookup existing CAS numbers on page load (for edit mode)
document.querySelectorAll('.cas-input').forEach(function(input) {
    if (input.value.trim() !== '') {
        // Trigger a lookup to show regulatory data for pre-filled CAS numbers
        var cas = input.value.trim();
        var row = input.closest('tr');
        fetch('/raw-materials/cas-lookup?cas=' + encodeURIComponent(cas), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function(resp) { return resp.ok ? resp.json() : null; })
            .then(function(data) {
                if (data && data.found) {
                    renderRegData(row, data);
                }
            })
            .catch(function() {});
    }
});

// ── Trade secret checkbox toggles description + H codes picker ──
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('ts-checkbox')) {
        var row = e.target.closest('tr');
        var descInput = row.querySelector('.ts-desc-input');
        var hcodesBtn = row.querySelector('.ts-hcodes-btn');
        if (descInput) {
            descInput.disabled = !e.target.checked;
            if (!e.target.checked) descInput.value = '';
        }
        if (hcodesBtn) {
            hcodesBtn.disabled = !e.target.checked;
            if (!e.target.checked) {
                var hidden = row.querySelector('.ts-hcodes-hidden');
                var summary = row.querySelector('.ts-hcodes-summary');
                if (hidden) hidden.value = '';
                if (summary) summary.textContent = '';
            }
        }
    }
});

// ── H Codes picker modal logic ──
(function() {
    var modal = document.getElementById('tsHcodesModal');
    var activeRow = null;

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('ts-hcodes-btn')) {
            e.preventDefault();
            activeRow = e.target.closest('tr');
            var hidden = activeRow.querySelector('.ts-hcodes-hidden');
            var currentCodes = (hidden && hidden.value) ? hidden.value.split(',').map(function(s){return s.trim();}) : [];

            // Reset all checkboxes, then check matching ones
            modal.querySelectorAll('.ts-modal-hazard-cb').forEach(function(cb) {
                var cbHCodes = cb.getAttribute('data-hcodes').split(',');
                cb.checked = cbHCodes.some(function(c) { return currentCodes.indexOf(c) !== -1; });
            });

            modal.style.display = 'block';
        }
    });

    document.getElementById('tsHcodesModalClose').addEventListener('click', function() {
        modal.style.display = 'none';
        activeRow = null;
    });
    document.getElementById('tsHcodesModalCancel').addEventListener('click', function() {
        modal.style.display = 'none';
        activeRow = null;
    });

    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
            activeRow = null;
        }
    });

    document.getElementById('tsHcodesModalApply').addEventListener('click', function() {
        if (!activeRow) return;
        var codes = {};
        modal.querySelectorAll('.ts-modal-hazard-cb:checked').forEach(function(cb) {
            cb.getAttribute('data-hcodes').split(',').forEach(function(c) {
                codes[c.trim()] = true;
            });
        });
        var codeList = Object.keys(codes).sort().join(', ');
        var hidden = activeRow.querySelector('.ts-hcodes-hidden');
        var summary = activeRow.querySelector('.ts-hcodes-summary');
        if (hidden) hidden.value = codeList;
        if (summary) summary.textContent = codeList;
        modal.style.display = 'none';
        activeRow = null;
    });
})();

// ── VOC <1% checkbox toggle ────────────────────────────────
document.getElementById('vocLessThanOne').addEventListener('change', function() {
    var vocInput = document.getElementById('voc_wt');
    if (this.checked) {
        vocInput.value = '';
        vocInput.disabled = true;
    } else {
        vocInput.disabled = false;
    }
});

// ── Prop 65 dynamic rows ────────────────────────────────────
// Row layout: CAS | Name (locked unless override) | Toxicity checkboxes
// (locked unless override) | Trace + Override checkboxes | Remove.
//
// Default posture: CAS drives the row. Name + toxicity are read-only
// placeholders; entering a CAS asks the server for the prop65_list
// entry and fills them in. Flipping Override lets the operator type
// whatever they want — used when the CAS isn't on the public list or
// when a different classification is intentional.
//
// Trace is pre-checked on new rows because the typical reason for a
// manual entry is a chemical below the Section 3 disclosure threshold.
document.getElementById('addProp65Row').addEventListener('click', function() {
    var tbody = document.getElementById('prop65Body');
    var idx = tbody.querySelectorAll('.prop65-row').length;
    var tr = document.createElement('tr');
    tr.className = 'prop65-row';
    tr.setAttribute('data-override', '0');
    tr.innerHTML =
        '<td><input type="text" name="p65_cas_number[' + idx + ']" class="input-sm p65-cas" placeholder="e.g. 13463-67-7"></td>' +
        '<td><input type="text" name="p65_chemical_name[' + idx + ']" class="input-sm p65-chem-name" placeholder="Auto from CAS" style="width:100%;" readonly></td>' +
        '<td class="p65-tox-checkboxes">' +
            '<label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_cancer[' + idx + ']" value="1" disabled> Cancer</label>' +
            '<label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_developmental[' + idx + ']" value="1" disabled> Developmental</label>' +
            '<label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_reproductive[' + idx + ']" value="1" disabled> Reproductive</label>' +
            '<label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_female_reproductive[' + idx + ']" value="1" disabled> Female Reproductive</label>' +
            '<label class="inline-check"><input type="checkbox" class="p65-tox" name="p65_tox_male_reproductive[' + idx + ']" value="1" disabled> Male Reproductive</label>' +
        '</td>' +
        '<td style="white-space: nowrap;">' +
            '<label class="inline-check" style="font-size:0.85rem;"><input type="checkbox" class="p65-is-trace" name="p65_is_trace[' + idx + ']" value="1" checked> Trace</label>' +
            '<label class="inline-check" style="font-size:0.85rem;"><input type="checkbox" class="p65-is-override" name="p65_is_override[' + idx + ']" value="1"> Override</label>' +
        '</td>' +
        '<td><button type="button" class="btn btn-sm btn-danger remove-p65">X</button></td>';
    tbody.appendChild(tr);
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-p65')) {
        e.target.closest('tr').remove();
    }
});

// ── Prop 65 override toggle + CAS auto-fill ──────────────────
// The Override checkbox unlocks the name + toxicity inputs. When it's
// flipped off, we also (a) re-lock the inputs and (b) re-fetch from
// the public list if the row has a valid CAS, so operators can't end
// up with an override-only name after accidentally checking the box.
(function () {
    var P65_TOX_TO_CHECKBOX = {
        'cancer': 'p65_tox_cancer',
        'developmental': 'p65_tox_developmental',
        'reproductive': 'p65_tox_reproductive',
        'female reproductive': 'p65_tox_female_reproductive',
        'male reproductive': 'p65_tox_male_reproductive',
    };
    var CAS_PATTERN = /^\d{2,7}-\d{2}-\d$/;

    function setRowLocked(row, locked) {
        row.setAttribute('data-override', locked ? '0' : '1');
        var nameInput = row.querySelector('.p65-chem-name');
        if (nameInput) {
            if (locked) {
                nameInput.setAttribute('readonly', 'readonly');
            } else {
                nameInput.removeAttribute('readonly');
            }
        }
        row.querySelectorAll('.p65-tox').forEach(function (cb) {
            if (locked) {
                cb.setAttribute('disabled', 'disabled');
            } else {
                cb.removeAttribute('disabled');
            }
        });
    }

    function fillFromPublicList(row, cas) {
        if (!CAS_PATTERN.test(cas)) return;
        fetch('/raw-materials/cas-lookup?cas=' + encodeURIComponent(cas))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.found) return;
                var nameInput = row.querySelector('.p65-chem-name');
                if (nameInput) {
                    var fill = (data.prop65 && data.prop65.chemical_name)
                        ? data.prop65.chemical_name
                        : data.chemical_name;
                    if (fill) nameInput.value = fill;
                }
                // Reset all toxicity checkboxes first so the row reflects
                // exactly what the list says (previous values may have
                // been ticked for a different CAS).
                row.querySelectorAll('.p65-tox').forEach(function (cb) {
                    cb.checked = false;
                });
                if (data.prop65 && Array.isArray(data.prop65.toxicity_types)) {
                    data.prop65.toxicity_types.forEach(function (t) {
                        var name = P65_TOX_TO_CHECKBOX[t.toLowerCase()];
                        if (!name) return;
                        var cb = row.querySelector('input[name^="' + name + '["]');
                        if (cb) cb.checked = true;
                    });
                }
            })
            .catch(function () { /* lookup is a convenience, not critical */ });
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t.classList) return;

        // Override toggle → lock/unlock inputs; refetch if CAS present.
        if (t.classList.contains('p65-is-override')) {
            var row = t.closest('tr');
            if (!row) return;
            var isOverride = t.checked;
            setRowLocked(row, !isOverride);
            if (!isOverride) {
                var casInput = row.querySelector('.p65-cas');
                if (casInput) {
                    fillFromPublicList(row, (casInput.value || '').trim());
                }
            }
            return;
        }

        // CAS typed → auto-fill when not overridden. Override rows keep
        // whatever the operator typed.
        if (t.classList.contains('p65-cas')) {
            var row = t.closest('tr');
            if (!row) return;
            if (row.getAttribute('data-override') === '1') return;
            fillFromPublicList(row, (t.value || '').trim());
            return;
        }
    });
})();

// ── HAPs dynamic rows ───────────────────────────────────────
document.getElementById('addHapRow').addEventListener('click', function() {
    var tbody = document.getElementById('hapsBody');
    var idx = tbody.querySelectorAll('.hap-row').length;
    var tr = document.createElement('tr');
    tr.className = 'hap-row';
    tr.innerHTML = '<td><input type="text" name="hap_chemical_name[' + idx + ']" class="input-sm" placeholder="e.g. Toluene"></td>' +
        '<td><input type="text" name="hap_cas_number[' + idx + ']" class="input-sm" placeholder="108-88-3"></td>' +
        '<td><input type="number" name="hap_weight_pct[' + idx + ']" step="0.01" min="0" max="100" class="input-xs"></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger remove-hap">X</button></td>';
    tbody.appendChild(tr);
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-hap')) {
        e.target.closest('tr').remove();
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
