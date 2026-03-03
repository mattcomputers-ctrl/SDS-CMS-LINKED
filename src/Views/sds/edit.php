<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/sds/<?= (int) $finishedGood['id'] ?>">&larr; Back to SDS Versions</a></p>

<h2>Edit SDS: <?= e($finishedGood['product_code']) ?></h2>
<p class="text-muted">
    Edit any section below before publishing. Auto-generated values are pre-filled.
    Changes are saved as text overrides for this product.
</p>

<form method="POST" action="/sds/<?= (int) $finishedGood['id'] ?>/save-edits" id="sds-edit-form">
    <?= csrf_field() ?>
    <input type="hidden" name="language" value="<?= e($language) ?>">

    <?php foreach ($sds['sections'] as $num => $section): ?>
    <div class="card" style="margin-bottom: 1rem; border: 1px solid #ddd; padding: 1rem;">
        <h3 style="background: #003366; color: #fff; padding: 0.5rem; margin: -1rem -1rem 1rem -1rem;">
            SECTION <?= $num ?>: <?= e(strtoupper($section['title'] ?? '')) ?>
        </h3>

        <?php if ($num === 1): ?>
            <p class="text-muted">Section 1 is auto-populated from product and company settings.</p>
            <div class="form-group">
                <label>Recommended Use</label>
                <textarea name="override[1][recommended_use]" class="form-control" rows="2"><?= e($section['recommended_use'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Restrictions on Use</label>
                <textarea name="override[1][restrictions]" class="form-control" rows="2"><?= e($section['restrictions'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 2): ?>
            <p class="text-muted">Hazard data is auto-generated from formula composition and federal data. You can override the "Other Hazards" field.</p>

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

            <div class="form-group">
                <label>Other Hazards</label>
                <textarea name="override[2][other_hazards]" class="form-control" rows="2"><?= e($section['other_hazards'] ?? '') ?></textarea>
            </div>

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
            <div class="form-group">
                <label>Inhalation</label>
                <textarea name="override[4][inhalation]" class="form-control" rows="2"><?= e($section['inhalation'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Skin Contact</label>
                <textarea name="override[4][skin]" class="form-control" rows="2"><?= e($section['skin'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Eye Contact</label>
                <textarea name="override[4][eyes]" class="form-control" rows="2"><?= e($section['eyes'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Ingestion</label>
                <textarea name="override[4][ingestion]" class="form-control" rows="2"><?= e($section['ingestion'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Notes to Physician</label>
                <textarea name="override[4][notes]" class="form-control" rows="2"><?= e($section['notes'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 5): ?>
            <div class="form-group">
                <label>Suitable Extinguishing Media</label>
                <textarea name="override[5][suitable_media]" class="form-control" rows="2"><?= e($section['suitable_media'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Unsuitable Extinguishing Media</label>
                <textarea name="override[5][unsuitable_media]" class="form-control" rows="2"><?= e($section['unsuitable_media'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Specific Hazards</label>
                <textarea name="override[5][specific_hazards]" class="form-control" rows="2"><?= e($section['specific_hazards'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Firefighter Advice</label>
                <textarea name="override[5][firefighter_advice]" class="form-control" rows="2"><?= e($section['firefighter_advice'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 6): ?>
            <div class="form-group">
                <label>Personal Precautions</label>
                <textarea name="override[6][personal_precautions]" class="form-control" rows="2"><?= e($section['personal_precautions'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Environmental Precautions</label>
                <textarea name="override[6][environmental]" class="form-control" rows="2"><?= e($section['environmental'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Containment / Cleanup</label>
                <textarea name="override[6][containment]" class="form-control" rows="2"><?= e($section['containment'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 7): ?>
            <div class="form-group">
                <label>Handling</label>
                <textarea name="override[7][handling]" class="form-control" rows="2"><?= e($section['handling'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Storage</label>
                <textarea name="override[7][storage]" class="form-control" rows="2"><?= e($section['storage'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 8): ?>
            <p class="text-muted">Exposure limits are auto-populated from federal data.</p>
            <div class="form-group">
                <label>Engineering Controls</label>
                <textarea name="override[8][engineering]" class="form-control" rows="2"><?= e($section['engineering'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Respiratory Protection</label>
                <textarea name="override[8][respiratory]" class="form-control" rows="2"><?= e($section['respiratory'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Hand Protection</label>
                <textarea name="override[8][hand_protection]" class="form-control" rows="2"><?= e($section['hand_protection'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Eye Protection</label>
                <textarea name="override[8][eye_protection]" class="form-control" rows="2"><?= e($section['eye_protection'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Skin Protection</label>
                <textarea name="override[8][skin_protection]" class="form-control" rows="2"><?= e($section['skin_protection'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 9): ?>
            <div class="form-group">
                <label>Appearance</label>
                <input type="text" name="override[9][appearance]" class="form-control" value="<?= e($section['appearance'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Odor</label>
                <input type="text" name="override[9][odor]" class="form-control" value="<?= e($section['odor'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Boiling Point</label>
                <input type="text" name="override[9][boiling_point]" class="form-control" value="<?= e($section['boiling_point'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Flash Point</label>
                <input type="text" name="override[9][flash_point]" class="form-control" value="<?= e($section['flash_point'] ?? '') ?>">
            </div>
            <p class="text-muted">VOC, specific gravity, and solids are auto-calculated from formula.</p>

        <?php elseif ($num === 10): ?>
            <div class="form-group">
                <label>Reactivity</label>
                <textarea name="override[10][reactivity]" class="form-control" rows="2"><?= e($section['reactivity'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Stability</label>
                <textarea name="override[10][stability]" class="form-control" rows="2"><?= e($section['stability'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Conditions to Avoid</label>
                <textarea name="override[10][conditions_avoid]" class="form-control" rows="2"><?= e($section['conditions_avoid'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Incompatible Materials</label>
                <textarea name="override[10][incompatible]" class="form-control" rows="2"><?= e($section['incompatible'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Hazardous Decomposition Products</label>
                <textarea name="override[10][decomposition]" class="form-control" rows="2"><?= e($section['decomposition'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 11): ?>
            <p class="text-muted">Carcinogen data (IARC/NTP/OSHA) and exposure limits are auto-populated.</p>
            <div class="form-group">
                <label>Acute Toxicity</label>
                <textarea name="override[11][acute_toxicity]" class="form-control" rows="2"><?= e($section['acute_toxicity'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Chronic Effects</label>
                <textarea name="override[11][chronic_effects]" class="form-control" rows="2"><?= e($section['chronic_effects'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Carcinogenicity (leave blank to use auto-generated data)</label>
                <textarea name="override[11][carcinogenicity]" class="form-control" rows="3"><?= e($overrides[11]['carcinogenicity'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 12): ?>
            <div class="form-group">
                <label>Ecotoxicity</label>
                <textarea name="override[12][ecotoxicity]" class="form-control" rows="2"><?= e($section['ecotoxicity'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Persistence / Degradability</label>
                <textarea name="override[12][persistence]" class="form-control" rows="2"><?= e($section['persistence'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Bioaccumulation Potential</label>
                <textarea name="override[12][bioaccumulation]" class="form-control" rows="2"><?= e($section['bioaccumulation'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 13): ?>
            <div class="form-group">
                <label>Disposal Methods</label>
                <textarea name="override[13][methods]" class="form-control" rows="2"><?= e($section['methods'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 14): ?>
            <div class="form-group">
                <label>UN Number</label>
                <input type="text" name="override[14][un_number]" class="form-control" value="<?= e($section['un_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Proper Shipping Name</label>
                <input type="text" name="override[14][proper_shipping_name]" class="form-control" value="<?= e($section['proper_shipping_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Hazard Class</label>
                <input type="text" name="override[14][hazard_class]" class="form-control" value="<?= e($section['hazard_class'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Packing Group</label>
                <input type="text" name="override[14][packing_group]" class="form-control" value="<?= e($section['packing_group'] ?? '') ?>">
            </div>

        <?php elseif ($num === 15): ?>
            <p class="text-muted">SARA 313 and Prop 65 data are auto-populated from regulatory databases.</p>
            <div class="form-group">
                <label>OSHA Status</label>
                <textarea name="override[15][osha_status]" class="form-control" rows="2"><?= e($section['osha_status'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>TSCA Status</label>
                <textarea name="override[15][tsca_status]" class="form-control" rows="2"><?= e($section['tsca_status'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Additional State Regulations</label>
                <textarea name="override[15][state_regs]" class="form-control" rows="2"><?= e($overrides[15]['state_regs'] ?? '') ?></textarea>
            </div>

        <?php elseif ($num === 16): ?>
            <div class="form-group">
                <label>Revision Note</label>
                <textarea name="override[16][revision_note]" class="form-control" rows="2"><?= e($section['revision_note'] ?? '') ?></textarea>
            </div>

        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="toolbar" style="position: sticky; bottom: 0; background: #fff; padding: 1rem 0; border-top: 2px solid #003366;">
        <button type="submit" class="btn btn-primary">Save Edits</button>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview?lang=<?= e($language) ?>" class="btn btn-outline">Preview</a>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>" class="btn btn-outline">Cancel</a>
    </div>
</form>

<style>
.form-group { margin-bottom: 0.75rem; }
.form-group label { display: block; font-weight: bold; margin-bottom: 0.25rem; font-size: 0.9rem; }
.form-group textarea, .form-group input[type="text"] { width: 100%; padding: 0.4rem; border: 1px solid #ccc; border-radius: 3px; font-size: 0.9rem; }
.form-group textarea:focus, .form-group input:focus { border-color: #003366; outline: none; }
</style>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
