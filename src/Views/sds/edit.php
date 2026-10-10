<?php include dirname(__DIR__) . '/layouts/main.php'; ?>
<?php
// Audit #45: one editor for finished goods (/sds/{fg}/edit) and resale raw
// materials (/sds/resale/{rm}/edit); SDSController passes editTarget.
$editTarget = $editTarget ?? [
    'kind'        => 'fg',
    'save_url'    => '/sds/' . (int) $finishedGood['id'] . '/save-edits',
    'back_url'    => '/sds/' . (int) $finishedGood['id'],
    'back_label'  => 'Back to SDS Versions',
    'preview_url' => '/sds/' . (int) $finishedGood['id'] . '/preview?lang=' . rawurlencode($language),
];
$isResaleEdit = ($editTarget['kind'] ?? 'fg') === 'rm';
?>

<p><a href="<?= e($editTarget['back_url']) ?>">&larr; <?= e($editTarget['back_label']) ?></a></p>

<h2><?= $isResaleEdit ? 'Edit Resale SDS' : 'Edit SDS' ?>: <?= e($finishedGood['product_code']) ?></h2>
<?php if ($isResaleEdit): ?>
<p class="text-muted">Resale item: these edits are stored on the raw material. They apply to its resale SDS and to every resale alias SDS built from it, not to finished goods that use this raw material.</p>
<?php endif; ?>
<p class="text-muted">
    Every field below is <strong>automatic</strong> unless you type into it. The grey text is what the generated SDS
    prints right now from formula, hazard and regulatory data, with this product's other saved edits applied; leave a field blank to keep following that data.
    Type only when this product needs different wording &mdash; it is stored as a per-product override for
    <strong><?= e(strtoupper($language)) ?></strong> only (each language has its own overrides).
    Text identical to the automatic value is not stored. &ldquo;Reset to automatic&rdquo; blanks a field; the
    stored override is removed when you save.
</p>

<form method="POST" action="<?= e($editTarget['save_url']) ?>" id="sds-edit-form">
    <?= csrf_field() ?>
    <input type="hidden" name="language" value="<?= e($language) ?>">

    <?php
    /**
     * Audit #36 — one override field. The control holds ONLY the stored
     * override ($overrides). $sds['sections'][$n][$key] is the field's automatic text with the
     * product's OTHER stored overrides applied (SDSController::editorSds,
     * TextOverrideService::hintDefaults, audit #57): shown as the placeholder and, while an override is present, as
     * the read-only "Automatic:" hint under the control. Blank = automatic.
     * Text equal to the automatic value is not stored (SDSController::saveEdits).
     */
    $field = function (int $n, string $key, string $label, string $kind = 'textarea', int $rows = 2, string $help = '') use ($sds, $overrides): string {
        $auto   = $sds['sections'][$n][$key] ?? '';
        $auto   = is_scalar($auto) && !is_bool($auto) ? (string) $auto : '';
        $stored = $overrides[$n][$key] ?? null;
        $isOver = $stored !== null && trim((string) $stored) !== '';
        $value  = $isOver ? (string) $stored : '';
        $name   = "override[{$n}][{$key}]";
        $id     = "ov-{$n}-{$key}";

        $h  = '<div class="form-group ov-field' . ($isOver ? ' is-overridden' : '') . '" data-ov-field>';
        $h .= '<label for="' . e($id) . '">' . e($label)
            . ' <span class="ov-badge ov-badge-over">Overridden</span>'
            . '<span class="ov-badge ov-badge-auto">Automatic</span></label>';
        if ($kind === 'input') {
            $h .= '<input type="text" id="' . e($id) . '" name="' . e($name) . '" class="form-control"'
                . ' value="' . e($value) . '" placeholder="' . e($auto) . '">';
        } else {
            $h .= '<textarea id="' . e($id) . '" name="' . e($name) . '" class="form-control" rows="' . $rows . '"'
                . ' placeholder="' . e($auto) . '">' . e($value) . '</textarea>';
        }
        $h .= '<div class="ov-hint"><span class="ov-hint-label">Automatic:</span> '
            . ($auto !== '' ? e($auto) : '<em>(nothing printed)</em>')
            . ' <button type="button" class="btn btn-outline ov-reset" data-ov-reset>Reset to automatic</button></div>';
        if ($help !== '') {
            $h .= '<small class="text-muted">' . e($help) . '</small>';
        }
        $h .= '</div>';
        return $h;
    };
    ?>

    <?php foreach ($sds['sections'] as $num => $section): ?>
    <div class="card" style="margin-bottom: 1rem; border: 1px solid #ddd; padding: 1rem;">
        <h3 style="background: #003366; color: #fff; padding: 0.5rem; margin: -1rem -1rem 1rem -1rem;">
            SECTION <?= $num ?>: <?= e(strtoupper($section['title'] ?? '')) ?>
        </h3>

        <?php if ($num === 1): ?>
            <p class="text-muted">Section 1 is auto-populated from product and company settings.</p>
            <?= $field(1, 'recommended_use', 'Recommended Use') ?>
            <?= $field(1, 'restrictions', 'Restrictions on Use') ?>

        <?php elseif ($num === 2): ?>
            <p class="text-muted">Hazard data is auto-generated from formula composition and federal data. Signal word, pictograms and PPE below are the automatic values; only "Other Hazards" can be overridden here (PPE sentences are overridden in Section 8).</p>

            <?php if (!empty($section['signal_word'])): ?>
                <p><strong>Signal Word:</strong>
                    <span style="color: <?= $section['signal_word'] === 'Danger' ? '#DC0000' : '#FF8C00' ?>; font-weight: bold;">
                        <?= e($section['signal_word']) ?>
                    </span> (auto-generated)</p>
            <?php endif; ?>

            <?php if (!empty($section['pictograms'])): ?>
                <div style="display: flex; gap: 8px; align-items: center; margin: 0.5rem 0;">
                    <strong>Pictograms:</strong>
                    <?php foreach ($section['pictograms'] as $code):
                        $picWebPath = \SDS\Services\PictogramHelper::getWebPath($code);
                        if (!$picWebPath) $picWebPath = '/assets/pictograms/' . $code . '.svg';
                    ?>
                        <img src="<?= e($picWebPath) ?>" alt="<?= e($code) ?>"
                             style="width: 50px; height: 50px;" onerror="this.outerHTML='<span><?= e($code) ?></span>'">
                    <?php endforeach; ?>
                    <span class="text-muted">(auto-generated)</span>
                </div>
            <?php endif; ?>

            <?php
                $ppe = $section['ppe_recommendations'] ?? [];
                $hasPPE = !empty($ppe['respiratory']) || !empty($ppe['hand_protection']) || !empty($ppe['eye_protection']) || !empty($ppe['skin_protection']);
            ?>
            <?php if ($hasPPE): ?>
                <div style="margin: 0.5rem 0; padding: 0.5rem; background: #f0f4f8; border-left: 3px solid #003366;">
                    <strong>Recommended PPE (auto-derived from H/P codes):</strong>
                    <ul style="margin: 0.3rem 0 0 0; font-size: 0.9rem;">
                    <?php if (!empty($ppe['respiratory'])): ?>
                        <li><strong>Respiratory:</strong> <?= e($ppe['respiratory']) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($ppe['hand_protection'])): ?>
                        <li><strong>Hand:</strong> <?= e($ppe['hand_protection']) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($ppe['eye_protection'])): ?>
                        <li><strong>Eye:</strong> <?= e($ppe['eye_protection']) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($ppe['skin_protection'])): ?>
                        <li><strong>Skin/Body:</strong> <?= e($ppe['skin_protection']) ?></li>
                    <?php endif; ?>
                    </ul>
                    <small class="text-muted">These PPE recommendations are used as defaults in Section 8 unless overridden.</small>
                </div>
            <?php endif; ?>

            <?= $field(2, 'other_hazards', 'Other Hazards', 'textarea', 2, 'Automatic: "None known." Type here only hazards not otherwise classified (e.g. static accumulation, dust explosion).') ?>

        <?php elseif ($num === 3): ?>
            <p class="text-muted">Composition is auto-calculated from the formula. Components shown for reference only.</p>
            <?php if (!empty($section['components'])): ?>
                <table class="table table-sm" style="font-size: 0.85rem;">
                    <thead><tr><th>CAS</th><th>Chemical</th><th>Range</th></tr></thead>
                    <tbody>
                    <?php foreach ($section['components'] as $c): ?>
                        <tr>
                            <td><?= e($c['cas_number']) ?></td>
                            <td><?= e($c['chemical_name']) ?></td>
                            <td><?= e($c['concentration_range'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php elseif ($num === 4): ?>
            <?= $field(4, 'inhalation', 'Inhalation') ?>
            <?= $field(4, 'skin', 'Skin Contact') ?>
            <?= $field(4, 'eyes', 'Eye Contact') ?>
            <?= $field(4, 'ingestion', 'Ingestion') ?>
            <?= $field(4, 'symptoms', 'Most Important Symptoms/Effects, Acute and Delayed', 'textarea', 2, 'Also prints in Section 11 (Toxicological Information).') ?>
            <?= $field(4, 'notes', 'Notes to Physician') ?>

        <?php elseif ($num === 5): ?>
            <?= $field(5, 'suitable_media', 'Suitable Extinguishing Media') ?>
            <?= $field(5, 'unsuitable_media', 'Unsuitable Extinguishing Media') ?>
            <?= $field(5, 'specific_hazards', 'Specific Hazards', 'textarea', 2, 'The "Flash point: …" sentence is kept current from Section 9: it is replaced in your text (or added at the end on a flammable product) every time the sheet is generated, and removed when the product is not flammable.') ?>
            <?= $field(5, 'firefighter_advice', 'Firefighter Advice') ?>

        <?php elseif ($num === 6): ?>
            <?= $field(6, 'personal_precautions', 'Personal Precautions') ?>
            <?= $field(6, 'environmental', 'Environmental Precautions') ?>
            <?= $field(6, 'containment', 'Containment / Cleanup') ?>

        <?php elseif ($num === 7): ?>
            <?= $field(7, 'handling', 'Handling') ?>
            <?= $field(7, 'storage', 'Storage') ?>

        <?php elseif ($num === 8): ?>
            <p class="text-muted">Exposure limits are auto-populated from federal data.</p>
            <?= $field(8, 'engineering', 'Engineering Controls') ?>
            <?= $field(8, 'respiratory', 'Respiratory Protection', 'textarea', 2, 'Also used for the Section 2 PPE line when the hazard data drives it.') ?>
            <?= $field(8, 'hand_protection', 'Hand Protection') ?>
            <?= $field(8, 'eye_protection', 'Eye Protection') ?>
            <?= $field(8, 'skin_protection', 'Skin Protection') ?>

        <?php elseif ($num === 9): ?>
            <?= $field(9, 'appearance', 'Appearance', 'input', 1, 'Automatic: finished-good colour + physical state (the physical state alone when no colour is set). Resale sheets use the raw material\'s appearance.') ?>
            <?= $field(9, 'odor', 'Odor', 'input', 1, 'Automatic: the dominant (highest wt%) raw material\'s odor; "Not determined" prints when no raw material carries one.') ?>
            <?= $field(9, 'boiling_point', 'Initial Boiling Point', 'input', 1, 'Automatic: the lowest raw material boiling point in the formula; "Not determined" prints when no raw material carries one. An edit must be a temperature with its unit, degree sign optional (e.g. "> 150 °C", "300 F"). It is display only: the classification (Flammable Liquids Category 1 vs 2, Section 14 packing group I) always follows the formula value, and an edit that contradicts it is not printed (the preview shows a warning). Text without a temperature is not saved.') ?>
            <?= $field(9, 'flash_point', 'Flash Point', 'input', 1, 'Automatic: the weight-averaged flash point of the raw materials that carry one ("> n" when any of them is a "greater than" value); "Not determined" when none does. An edit must be a temperature with its unit, degree sign optional (e.g. "24 °C", "75 F", "> 93 °C"); Sections 5 and 9 print it. The classification (Sections 2, 5, 7, 10, 13 D001 and 14) always follows the formula value; an edit that contradicts it is not printed and the preview shows a warning. Text without a temperature is not saved.') ?>
            <?= $field(9, 'solubility', 'Solubility', 'input', 1, 'Automatic: derived from the formula\'s raw materials.') ?>
            <?php // #43 / Q8 Appendix D lines with no formula data (product-level value) ?>
            <?= $field(9, 'odor_threshold', 'Odor Threshold', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'ph', 'pH', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'melting_point', 'Melting Point / Freezing Point', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'evaporation_rate', 'Evaporation Rate', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'flammability_solid_gas', 'Flammability (Solid, Gas)', 'input', 1, 'Automatic: "Not applicable" for a Liquid, otherwise "Not determined".') ?>
            <?= $field(9, 'flammability_limits', 'Upper/Lower Flammability or Explosive Limits', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'vapor_pressure', 'Vapor Pressure', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'vapor_density', 'Vapor Density', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'partition_coefficient', 'Partition Coefficient (n-Octanol/Water)', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'auto_ignition_temp', 'Auto-Ignition Temperature', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'decomposition_temp', 'Decomposition Temperature', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <?= $field(9, 'viscosity', 'Viscosity', 'input', 1, 'No formula data: the sheet prints "Not determined" unless a tested value is typed here.') ?>
            <p class="text-muted">VOC, specific gravity, and solids are auto-calculated from the formula (normalised to the formula total; raw materials missing specific gravity or VOC are listed in the preview warnings).</p>

        <?php elseif ($num === 10): ?>
            <?= $field(10, 'reactivity', 'Reactivity') ?>
            <?= $field(10, 'stability', 'Stability') ?>
            <?= $field(10, 'conditions_avoid', 'Conditions to Avoid') ?>
            <?= $field(10, 'incompatible', 'Incompatible Materials', 'textarea', 2, 'Section 7 Storage repeats this list.') ?>
            <?= $field(10, 'decomposition', 'Hazardous Decomposition Products') ?>

        <?php elseif ($num === 11): ?>
            <p class="text-muted">Carcinogen data (IARC/NTP/OSHA) and exposure limits are auto-populated.</p>
            <?= $field(11, 'acute_toxicity', 'Acute Toxicity') ?>
            <?= $field(11, 'chronic_effects', 'Chronic Effects') ?>
            <?= $field(11, 'carcinogenicity', 'Carcinogenicity', 'textarea', 3, 'Automatic: built from the IARC / NTP / OSHA carcinogen registry for the listed components.') ?>
            <?php if (!empty($section['uv_acrylate_note'])): // #65: the Section 11 UV rule-pack note is editable ?>
                <?= $field(11, 'uv_acrylate_note', 'UV Acrylate Information', 'textarea', 2, 'Printed after the component table on UV/LED family products while the UV acrylate rule pack is on. Sections 4-8 carry their UV sentences inside the fields above.') ?>
            <?php endif; ?>

        <?php elseif ($num === 12): ?>
            <?= $field(12, 'ecotoxicity', 'Ecotoxicity') ?>
            <?= $field(12, 'persistence', 'Persistence / Degradability') ?>
            <?= $field(12, 'bioaccumulation', 'Bioaccumulation Potential', 'textarea', 2, 'When PBT components are listed and you override Persistence, the automatic Bioaccumulation line repeats the PBT component list instead of "see Persistence".') ?>
            <?= $field(12, 'mobility', 'Mobility in Soil') ?>

        <?php elseif ($num === 13): ?>
            <?= $field(13, 'methods', 'Disposal Methods') ?>

        <?php elseif ($num === 14): ?>
            <p class="text-muted">Transport data is derived from the flash point, boiling point and hazard classification (audit #27). Leave a field blank to use the derived value shown as its placeholder; anything typed here wins. To replace the derived classification, enter all four of UN Number, Proper Shipping Name, Hazard Class and Packing Group (a value that matches the derived one is kept as part of the full override). A Packing Group and/or Proper Shipping Name edit may stand alone only when the derived classification is regulated; otherwise publishing is blocked. Overrides are saved per language: repeat them on every language tab.</p>
            <?= $field(14, 'un_number', 'UN Number', 'input', 1) ?>
            <?= $field(14, 'proper_shipping_name', 'Proper Shipping Name', 'input', 1) ?>
            <?= $field(14, 'hazard_class', 'Hazard Class', 'input', 1) ?>
            <?= $field(14, 'packing_group', 'Packing Group', 'input', 1) ?>
            <?= $field(14, 'environmental_hazards', 'Environmental Hazards', 'input', 1) ?>

        <?php elseif ($num === 15): ?>
            <p class="text-muted">SARA 313, Prop 65, and SNUR data are auto-populated from regulatory databases and raw material flags.</p>
            <p><strong>OSHA Status</strong> <span class="text-muted">(automatic: follows the Section 2 classification; not editable)</span><br><?= e((string) ($section['osha_status'] ?? '')) ?></p>
            <p><strong>TSCA Status</strong> <span class="text-muted">(automatic: from the TSCA inventory check; not editable)</span><br><?= e((string) ($section['tsca_status'] ?? '')) ?></p>
            <?= $field(15, 'state_regs', 'Additional State Regulations', 'textarea', 2, 'Printed verbatim as the "State Regulations" line at the end of Section 15 (after the Prop 65 block), in every language this override is saved for. Typical content: state right-to-know listings, e.g. "New Jersey Right-to-Know Hazardous Substance List: Toluene (CAS 108-88-3)". Leave blank to omit the line.') ?>

        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="toolbar" style="position: sticky; bottom: 0; background: #fff; padding: 1rem 0; border-top: 2px solid #003366;">
        <button type="submit" class="btn btn-primary">Save Edits</button>
        <a href="<?= e($editTarget['preview_url']) ?>" class="btn btn-outline">Preview</a>
        <a href="<?= e($editTarget['back_url']) ?>" class="btn btn-outline">Cancel</a>
    </div>
</form>

<style>
.form-group { margin-bottom: 0.75rem; }
.form-group label { display: block; font-weight: bold; margin-bottom: 0.25rem; font-size: 0.9rem; }
.form-group textarea, .form-group input[type="text"] { width: 100%; padding: 0.4rem; border: 1px solid #ccc; border-radius: 3px; font-size: 0.9rem; }
.form-group textarea:focus, .form-group input:focus { border-color: #003366; outline: none; }
.ov-field .ov-badge { font-size: 0.7rem; font-weight: normal; padding: 0.05rem 0.4rem; border-radius: 3px; margin-left: 0.4rem; vertical-align: middle; }
.ov-field .ov-badge-over { display: none; background: #f39c12; color: #fff; }
.ov-field .ov-badge-auto { display: inline-block; background: #e5e9ef; color: #555; }
.ov-field.is-overridden .ov-badge-over { display: inline-block; }
.ov-field.is-overridden .ov-badge-auto { display: none; }
.ov-field.is-overridden textarea, .ov-field.is-overridden input[type="text"] { border-left: 4px solid #f39c12; background: #fffaf0; }
.ov-field textarea::placeholder, .ov-field input::placeholder { color: #8a94a0; opacity: 1; }
.ov-hint { display: none; margin-top: 0.25rem; font-size: 0.85rem; color: #555; background: #f4f6f8; padding: 0.35rem 0.5rem; border-radius: 3px; white-space: pre-wrap; }
.ov-hint-label { font-weight: bold; }
.ov-field.is-overridden .ov-hint { display: block; }
.ov-reset { margin-left: 0.5rem; font-size: 0.8rem; padding: 0.1rem 0.5rem; }
</style>

<script>
// Audit #36: flag a field as overridden while it holds text, and let
// "Reset to automatic" blank it (the stored row is deleted on Save).
document.querySelectorAll('[data-ov-field]').forEach(function (wrap) {
    var ctl = wrap.querySelector('textarea, input[type="text"]');
    if (!ctl) { return; }
    var sync = function () { wrap.classList.toggle('is-overridden', ctl.value.trim() !== ''); };
    ctl.addEventListener('input', sync);
    var reset = wrap.querySelector('[data-ov-reset]');
    if (reset) {
        reset.addEventListener('click', function () { ctl.value = ''; sync(); ctl.focus(); });
    }
    sync();
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
