<?php
include dirname(__DIR__) . '/layouts/main.php';
$old = $_SESSION['_flash']['_old_input'] ?? [];
$det = $item['determination'] ?? [];

// Load GHS data for JavaScript auto-population
$ghsData = \SDS\Services\GHSHazardData::forJavaScript();
$allH = \SDS\Services\GHSStatements::allHStatements();
$allP = \SDS\Services\GHSStatements::allPStatements();

// Parse existing selections
$selectedHazards = json_decode($det['selected_hazards'] ?? ($old['selected_hazards_json'] ?? '[]'), true) ?: [];
$selectedHCodes  = !empty($det['h_statements'])
    ? array_map('trim', explode(',', $det['h_statements']))
    : array_map('trim', explode(',', $old['h_codes_manual'] ?? ''));
$selectedPCodes  = !empty($det['p_statements'])
    ? array_map('trim', explode(',', $det['p_statements']))
    : array_map('trim', explode(',', $old['p_codes_manual'] ?? ''));
$selectedHCodes = array_filter($selectedHCodes);
$selectedPCodes = array_filter($selectedPCodes);

// Parse existing exposure limits
$exposureLimits = json_decode($det['exposure_limits'] ?? ($old['exposure_limits_json'] ?? '[]'), true) ?: [];
?>

<p><a href="/determinations">&larr; Back to CAS Determinations</a></p>

<div class="card">
    <form method="POST" action="<?= $mode === 'create' ? '/determinations' : '/determinations/' . (int)$item['id'] ?>" id="determination-form">
        <?= csrf_field() ?>

        <h2><?= $mode === 'create' ? 'New CAS Number Determination' : 'Edit CAS Determination' ?></h2>
        <p class="text-muted mb-1">Define hazard classification for a CAS number. Select hazard statements to auto-populate H/P codes, pictograms, and signal words. You can also manually add H/P codes and exposure limits.</p>

        <?php if ($mode === 'create' && !empty($det['basis']) && str_contains($det['basis'], 'federal')): ?>
        <div style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <strong>Federal data pre-loaded.</strong> Hazard classifications, H/P codes, and exposure limits from federal sources have been filled in below. Review and adjust as needed before saving.
        </div>
        <?php endif; ?>

        <!-- CAS Number & Jurisdiction -->
        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number</label>
                <input type="text" name="cas_number" value="<?= e($item['cas_number'] ?? ($old['cas_number'] ?? '')) ?>" <?= $mode === 'edit' ? 'readonly' : '' ?> required placeholder="e.g. 12345-67-8">
            </div>
            <div class="form-group">
                <label>Jurisdiction</label>
                <input type="text" name="jurisdiction" value="<?= e($item['jurisdiction'] ?? ($old['jurisdiction'] ?? 'US')) ?>">
            </div>
        </div>

        <?php
            $rawMaterials = $rawMaterials ?? [];
            if ($mode === 'edit'):
        ?>
        <!-- Raw materials that contain this CAS — shows the blast radius
             of any change to this determination. -->
        <div class="card" style="background:#f8f9fa; border:1px solid #dee2e6; padding:0.5rem 0.75rem; margin:0.75rem 0;">
            <strong>Raw materials containing CAS <?= e($item['cas_number']) ?>:</strong>
            <?php if (empty($rawMaterials)): ?>
                <span class="text-muted">None — this CAS isn't currently listed as a constituent on any raw material.</span>
            <?php else: ?>
                <span class="text-muted"><?= count($rawMaterials) ?> raw material<?= count($rawMaterials) === 1 ? '' : 's' ?> (<?= count(array_filter($rawMaterials, fn($r) => (int) $r['in_formula'])) ?> in a current formula)</span>
                <table class="table table-sm" style="margin:0.5rem 0 0 0;">
                    <thead>
                        <tr>
                            <th style="width:120px;">Code</th>
                            <th>Supplier</th>
                            <th>Supplier Product</th>
                            <th style="width:90px;">In Formula</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rawMaterials as $rm): ?>
                            <tr>
                                <td><a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" target="_blank"><strong><?= e($rm['internal_code']) ?></strong></a></td>
                                <td><?= e($rm['supplier'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td><?= e($rm['supplier_product_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td><?= (int) $rm['in_formula']
                                    ? '<span style="color:#28a745; font-weight:bold;">Yes</span>'
                                    : '<span class="text-muted">No</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ═══════════════ HAZARD STATEMENT SELECTION ═══════════════ -->
        <h2 class="mt-2">Hazard Classification</h2>
        <p class="text-muted mb-1">Check the hazard statements that apply. H-codes, P-codes, pictograms, and signal words will be automatically populated.</p>

        <div class="hazard-selection" id="hazard-selection">
            <?php
            $grouped = \SDS\Services\GHSHazardData::groupedByClass();

            // Sort each class's entries by their smallest H-code number,
            // and sort each entry's h_codes array, so the page always
            // reads H200 → H201 → ... within a class. Combined codes
            // ("H300+H310") use their first part's number for sorting.
            $hCodeNum = static function (string $code): int {
                $first = explode('+', $code)[0];
                return (int) preg_replace('/[^\d]/', '', $first);
            };
            foreach ($grouped as $className => &$entries) {
                foreach ($entries as &$entry) {
                    usort($entry['h_codes'], fn($a, $b) => $hCodeNum($a) <=> $hCodeNum($b) ?: strcmp($a, $b));
                }
                unset($entry);
                uasort($entries, function ($a, $b) use ($hCodeNum) {
                    $aMin = min(array_map($hCodeNum, $a['h_codes']));
                    $bMin = min(array_map($hCodeNum, $b['h_codes']));
                    return $aMin <=> $bMin ?: strcmp($a['category'], $b['category']);
                });
            }
            unset($entries);

            foreach ($grouped as $className => $entries):
            ?>
            <div class="hazard-class-group">
                <h3 class="hazard-class-header"><?= e($className) ?></h3>
                <?php foreach ($entries as $key => $entry): ?>
                <label class="hazard-checkbox-row">
                    <input type="checkbox" name="hazard_selections[]" value="<?= e($key) ?>"
                           class="hazard-checkbox"
                           data-key="<?= e($key) ?>"
                           <?= in_array($key, $selectedHazards) ? 'checked' : '' ?>>
                    <span class="hazard-cat"><?= e($entry['category']) ?></span>
                    <span class="hazard-h-codes"><?= e(implode(', ', $entry['h_codes'])) ?></span>
                    <span class="hazard-h-text">
                        <?php foreach ($entry['h_codes'] as $hc): ?>
                            <?= e(\SDS\Services\GHSStatements::hText($hc)) ?><?php if ($hc !== end($entry['h_codes'])): ?>; <?php endif; ?>
                        <?php endforeach; ?>
                    </span>
                    <?php if ($entry['signal_word']): ?>
                        <span class="hazard-signal hazard-signal-<?= strtolower($entry['signal_word']) ?>"><?= e($entry['signal_word']) ?></span>
                    <?php endif; ?>
                    <?php foreach ($entry['pictograms'] as $pic):
                        $picWebPath = \SDS\Services\PictogramHelper::getWebPath($pic);
                        if (!$picWebPath) $picWebPath = '/assets/pictograms/' . $pic . '.svg';
                    ?>
                        <img src="<?= e($picWebPath) ?>" alt="<?= e($pic) ?>" class="hazard-picto-icon" title="<?= e(\SDS\Services\GHSStatements::pictogramName($pic)) ?>">
                    <?php endforeach; ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Hidden field to store selected hazard keys as JSON -->
        <input type="hidden" name="selected_hazards_json" id="selected-hazards-json" value="<?= e(json_encode($selectedHazards)) ?>">

        <!-- ═══════════════ AUTO-POPULATED SUMMARY ═══════════════ -->
        <div class="card auto-summary" id="auto-summary" style="margin-top: 1rem; background: #f8f9fa; display: <?= empty($selectedHazards) ? 'none' : 'block' ?>;">
            <h3>Auto-Populated from Selections</h3>
            <div class="form-grid-2col">
                <div class="form-group">
                    <label>Signal Word</label>
                    <div id="auto-signal-word" class="auto-value">—</div>
                </div>
                <div class="form-group">
                    <label>Pictograms</label>
                    <div id="auto-pictograms" class="auto-value">—</div>
                </div>
                <div class="form-group">
                    <label>H-Codes (from hazard selections)</label>
                    <div id="auto-h-codes" class="auto-value">—</div>
                </div>
                <div class="form-group">
                    <label>P-Codes (from hazard selections)</label>
                    <div id="auto-p-codes" class="auto-value" style="font-size: 0.85rem;">—</div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ MANUAL H-CODE SELECTION ═══════════════ -->
        <details class="code-selection-details" style="margin-top: 1rem; border: 1px solid #dee2e6; border-radius: 4px;">
            <summary style="cursor: pointer; padding: 0.6rem 0.9rem; font-size: 1.2rem; font-weight: bold; background: #f8f9fa; list-style: revert;">
                Additional H-Codes <small class="text-muted" style="font-weight:400; font-size:0.8rem;">(optional — beyond auto-populated; click to expand)</small>
            </summary>
            <div style="padding: 0.5rem 0.9rem 0.9rem;">
        <p class="text-muted mb-1">Manually select additional H-codes if needed. Codes already triggered by hazard selections above are marked.</p>

        <div class="code-selection-grid" id="h-code-grid">
            <?php
            // Group H-codes by series
            $hGroups = [
                'Physical (H200)' => [],
                'Health (H300)' => [],
                'Environmental (H400)' => [],
                'Combined' => [],
            ];
            foreach ($allH as $code => $text) {
                if (str_contains($code, '+')) {
                    $hGroups['Combined'][$code] = $text;
                } elseif (str_starts_with($code, 'H2')) {
                    $hGroups['Physical (H200)'][$code] = $text;
                } elseif (str_starts_with($code, 'H3')) {
                    $hGroups['Health (H300)'][$code] = $text;
                } elseif (str_starts_with($code, 'H4')) {
                    $hGroups['Environmental (H400)'][$code] = $text;
                }
            }
            foreach ($hGroups as $groupName => $codes):
                if (empty($codes)) continue;
            ?>
            <div class="code-group">
                <h4 class="code-group-header"><?= e($groupName) ?></h4>
                <?php foreach ($codes as $code => $text): ?>
                <label class="code-checkbox-row" title="<?= e($code . ': ' . $text) ?>">
                    <input type="checkbox" name="h_codes_manual[]" value="<?= e($code) ?>"
                           class="h-code-checkbox" data-code="<?= e($code) ?>"
                           <?= in_array($code, $selectedHCodes) ? 'checked' : '' ?>>
                    <span class="code-label"><?= e($code) ?></span>
                    <span class="code-desc"><?= e($text) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
            </div>
        </details>

        <!-- ═══════════════ MANUAL P-CODE SELECTION ═══════════════ -->
        <details class="code-selection-details" style="margin-top: 0.5rem; border: 1px solid #dee2e6; border-radius: 4px;">
            <summary style="cursor: pointer; padding: 0.6rem 0.9rem; font-size: 1.2rem; font-weight: bold; background: #f8f9fa; list-style: revert;">
                Additional P-Codes <small class="text-muted" style="font-weight:400; font-size:0.8rem;">(optional — beyond auto-populated; click to expand)</small>
            </summary>
            <div style="padding: 0.5rem 0.9rem 0.9rem;">
        <p class="text-muted mb-1">Manually select additional P-codes if needed.</p>

        <div class="code-selection-grid" id="p-code-grid">
            <?php
            $pGroups = [
                'General (P100)' => [],
                'Prevention (P200)' => [],
                'Response (P300)' => [],
                'Storage (P400)' => [],
                'Disposal (P500)' => [],
            ];
            foreach ($allP as $code => $text) {
                // Use primary code for grouping
                $primary = explode('+', $code)[0];
                if (str_starts_with($primary, 'P1')) {
                    $pGroups['General (P100)'][$code] = $text;
                } elseif (str_starts_with($primary, 'P2')) {
                    $pGroups['Prevention (P200)'][$code] = $text;
                } elseif (str_starts_with($primary, 'P3')) {
                    $pGroups['Response (P300)'][$code] = $text;
                } elseif (str_starts_with($primary, 'P4')) {
                    $pGroups['Storage (P400)'][$code] = $text;
                } elseif (str_starts_with($primary, 'P5')) {
                    $pGroups['Disposal (P500)'][$code] = $text;
                }
            }
            foreach ($pGroups as $groupName => $codes):
                if (empty($codes)) continue;
            ?>
            <div class="code-group">
                <h4 class="code-group-header"><?= e($groupName) ?></h4>
                <?php foreach ($codes as $code => $text): ?>
                <label class="code-checkbox-row" title="<?= e($code . ': ' . $text) ?>">
                    <input type="checkbox" name="p_codes_manual[]" value="<?= e($code) ?>"
                           class="p-code-checkbox" data-code="<?= e($code) ?>"
                           <?= in_array($code, $selectedPCodes) ? 'checked' : '' ?>>
                    <span class="code-label"><?= e($code) ?></span>
                    <span class="code-desc"><?= e($text) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
            </div>
        </details>

        <!-- ═══════════════ EXPOSURE LIMITS ═══════════════ -->
        <h2 class="mt-2">Exposure Limits</h2>
        <p class="text-muted mb-1">Add occupational exposure limits (OEL). These will appear in Section 8 of generated SDSs.</p>

        <div id="exposure-limits-container">
            <table class="table table-sm" id="exposure-limits-table">
                <thead>
                    <tr>
                        <th>Limit Type</th>
                        <th>Value</th>
                        <th>Units</th>
                        <th>Notes</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody id="exposure-limits-body">
                    <?php if (!empty($exposureLimits)): ?>
                        <?php foreach ($exposureLimits as $i => $el): ?>
                        <tr class="exposure-limit-row">
                            <td>
                                <select name="exp_limit_type[]">
                                    <option value="PEL-TWA" <?= ($el['limit_type'] ?? '') === 'PEL-TWA' ? 'selected' : '' ?>>OSHA PEL-TWA</option>
                                    <option value="TLV-TWA" <?= ($el['limit_type'] ?? '') === 'TLV-TWA' ? 'selected' : '' ?>>ACGIH TLV-TWA</option>
                                    <option value="REL-TWA" <?= ($el['limit_type'] ?? '') === 'REL-TWA' ? 'selected' : '' ?>>NIOSH REL-TWA</option>
                                    <option value="STEL" <?= ($el['limit_type'] ?? '') === 'STEL' ? 'selected' : '' ?>>STEL</option>
                                    <option value="Ceiling" <?= ($el['limit_type'] ?? '') === 'Ceiling' ? 'selected' : '' ?>>Ceiling</option>
                                    <option value="IDLH" <?= ($el['limit_type'] ?? '') === 'IDLH' ? 'selected' : '' ?>>IDLH</option>
                                    <option value="Other" <?= ($el['limit_type'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </td>
                            <td><input type="text" name="exp_limit_value[]" value="<?= e($el['value'] ?? '') ?>" placeholder="e.g. 50"></td>
                            <td>
                                <select name="exp_limit_units[]">
                                    <option value="mg/m3" <?= ($el['units'] ?? '') === 'mg/m3' ? 'selected' : '' ?>>mg/m&sup3;</option>
                                    <option value="ppm" <?= ($el['units'] ?? '') === 'ppm' ? 'selected' : '' ?>>ppm</option>
                                    <option value="f/cc" <?= ($el['units'] ?? '') === 'f/cc' ? 'selected' : '' ?>>f/cc</option>
                                    <option value="mppcf" <?= ($el['units'] ?? '') === 'mppcf' ? 'selected' : '' ?>>mppcf</option>
                                </select>
                            </td>
                            <td><input type="text" name="exp_limit_notes[]" value="<?= e($el['notes'] ?? '') ?>" placeholder="Optional notes"></td>
                            <td><button type="button" class="btn btn-sm btn-danger remove-exp-limit">X</button></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <button type="button" class="btn btn-sm" id="add-exposure-limit">+ Add Exposure Limit</button>
        </div>

        <!-- Hidden field to store exposure limits as JSON -->
        <input type="hidden" name="exposure_limits_json" id="exposure-limits-json" value="<?= e(json_encode($exposureLimits)) ?>">

        <!-- ═══════════════ SIGNAL WORD OVERRIDE & BASIS ═══════════════ -->
        <h2 class="mt-2">Additional Details</h2>
        <div class="form-grid-2col">
            <div class="form-group">
                <label>Signal Word Override</label>
                <select name="signal_word">
                    <option value="">— Auto (from selections) —</option>
                    <option value="Danger" <?= ($det['signal_word'] ?? '') === 'Danger' ? 'selected' : '' ?>>Danger</option>
                    <option value="Warning" <?= ($det['signal_word'] ?? '') === 'Warning' ? 'selected' : '' ?>>Warning</option>
                </select>
                <small class="text-muted">Leave as "Auto" unless you need to override the auto-determined signal word.</small>
            </div>
            <div class="form-group">
                <label>Basis of Determination</label>
                <input type="text" name="basis" value="<?= e($det['basis'] ?? ($old['basis'] ?? '')) ?>" placeholder="e.g. Supplier SDS, published literature, analogy to similar structure">
            </div>
        </div>

        <!-- ═══════════════ ATE + M-FACTOR (Phase 3c / 4) ═══════════════ -->
        <h2 class="mt-2">Acute Toxicity (ATE) &amp; Aquatic M-Factors</h2>
        <p class="text-muted">
            Optional. Used by the hazard engine's mixture-classification logic.
            Leave blank and the engine will fall back to GHS Table 3.1.2
            category-default ATEs / M-factor = 1.
        </p>
        <div class="form-grid-2col">
            <div class="form-group">
                <label>Oral LD<sub>50</sub> (mg/kg)</label>
                <input type="number" step="any" min="0" name="ate_oral_mg_kg"
                       value="<?= e((string) ($det['ate_oral_mg_kg'] ?? ($old['ate_oral_mg_kg'] ?? ''))) ?>"
                       placeholder="e.g. 1200">
            </div>
            <div class="form-group">
                <label>Dermal LD<sub>50</sub> (mg/kg)</label>
                <input type="number" step="any" min="0" name="ate_dermal_mg_kg"
                       value="<?= e((string) ($det['ate_dermal_mg_kg'] ?? ($old['ate_dermal_mg_kg'] ?? ''))) ?>"
                       placeholder="e.g. 2500">
            </div>
            <div class="form-group">
                <label>Inhalation LC<sub>50</sub> vapour (mg/L · 4 h)</label>
                <input type="number" step="any" min="0" name="ate_inhalation_vapor_mg_l_4h"
                       value="<?= e((string) ($det['ate_inhalation_vapor_mg_l_4h'] ?? ($old['ate_inhalation_vapor_mg_l_4h'] ?? ''))) ?>"
                       placeholder="e.g. 10.5">
            </div>
            <div class="form-group">
                <label>Inhalation LC<sub>50</sub> dust/mist (mg/L · 4 h)</label>
                <input type="number" step="any" min="0" name="ate_inhalation_dust_mg_l_4h"
                       value="<?= e((string) ($det['ate_inhalation_dust_mg_l_4h'] ?? ($old['ate_inhalation_dust_mg_l_4h'] ?? ''))) ?>"
                       placeholder="e.g. 1.2">
            </div>
            <div class="form-group">
                <label>Aquatic acute M-factor</label>
                <input type="number" step="any" min="0" name="m_factor_acute"
                       value="<?= e((string) ($det['m_factor_acute'] ?? ($old['m_factor_acute'] ?? ''))) ?>"
                       placeholder="e.g. 10, 100, 1000">
                <small class="text-muted">Per CLP Annex VI Table 3.1. Default = 1.</small>
            </div>
            <div class="form-group">
                <label>Aquatic chronic M-factor</label>
                <input type="number" step="any" min="0" name="m_factor_chronic"
                       value="<?= e((string) ($det['m_factor_chronic'] ?? ($old['m_factor_chronic'] ?? ''))) ?>"
                       placeholder="e.g. 10, 100, 1000">
                <small class="text-muted">Per CLP Annex VI Table 3.1. Default = 1.</small>
            </div>
        </div>

        <div class="form-group">
            <label>Rationale (required)</label>
            <textarea name="rationale_text" rows="5" required placeholder="Explain why this determination was made and what sources were consulted..."><?= e($item['rationale_text'] ?? ($old['rationale_text'] ?? '')) ?></textarea>
        </div>

        <?php if ($mode === 'edit'): ?>
        <div class="form-grid-2col">
            <div class="form-group">
                <label><input type="checkbox" name="is_active" value="1" <?= (int)($item['is_active'] ?? 1) ? 'checked' : '' ?>> Active</label>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="mark_approved" value="1"> Mark as approved by me</label>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $mode === 'create' ? 'Create Determination' : 'Update' ?></button>
        </div>
    </form>
</div>

<!-- GHS data for JavaScript -->
<script>
window.GHS_DATA = <?= json_encode($ghsData, JSON_UNESCAPED_UNICODE) ?>;
window.PICTOGRAM_PATHS = <?php
    $picPaths = [];
    foreach (\SDS\Services\PictogramHelper::ALL_CODES as $c) {
        $wp = \SDS\Services\PictogramHelper::getWebPath($c);
        if ($wp) $picPaths[$c] = $wp;
    }
    echo json_encode($picPaths, JSON_UNESCAPED_SLASHES);
?>;
</script>
<script src="/js/determination-form.js"></script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
