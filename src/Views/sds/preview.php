<?php
include dirname(__DIR__) . '/layouts/main.php';
$labels = $sds['meta']['labels'] ?? [];
$doc = $sds['meta']['document'] ?? [];
$l = function(string $key, string $fallback = '') use ($labels) {
    return $labels[$key] ?? ($fallback ?: $key);
};
$sectionPrefix = strtoupper($doc['section_prefix'] ?? 'SECTION');
?>

<p><a href="/sds/<?= (int) $finishedGood['id'] ?>">&larr; Back to SDS Versions</a></p>

<div class="sds-preview">
    <div class="sds-header">
        <?php if (!empty($sds['meta']['company_logo_path'])): ?>
            <img src="<?= e($sds['meta']['company_logo_path']) ?>" alt="Company Logo" style="max-height: 60px; max-width: 250px; margin-bottom: 0.5rem;">
        <?php endif; ?>
        <h2><?= e($doc['title'] ?? 'SAFETY DATA SHEET') ?></h2>
        <p class="text-muted">Preview &mdash; <?= e(strtoupper($language)) ?> &mdash; Generated <?= date('m/d/Y H:i') ?></p>
    </div>

    <?php foreach ($sds['sections'] as $num => $section): ?>
    <div class="sds-section" id="section-<?= $num ?>">
        <h3 class="sds-section-title"><?= e($sectionPrefix) ?> <?= $num ?>: <?= e(strtoupper($section['title'] ?? '')) ?></h3>

        <?php if ($num === 2): // ── Hazard Identification ── ?>
            <?php if (!empty($section['signal_word'])): ?>
                <p class="signal-word signal-<?= strtolower($section['signal_word_en'] ?? $section['signal_word']) ?>" style="font-size: 1.3rem; font-weight: bold; color: <?= ($section['signal_word_en'] ?? $section['signal_word']) === 'Danger' ? '#DC0000' : '#FF8C00' ?>;">
                    <?= e(strtoupper($section['signal_word'])) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($section['pictograms'])): ?>
                <div class="sds-pictograms" style="display: flex; flex-wrap: wrap; gap: 12px; margin: 0.5rem 0;">
                    <strong style="align-self: center;"><?= e($l('pictograms')) ?>:</strong>
                    <?php foreach ($section['pictograms'] as $code):
                        $pictoSrc = \SDS\Services\PictogramHelper::getWebPath($code);
                    ?>
                        <span style="display: inline-flex; flex-direction: column; align-items: center; width: 70px;">
                            <?php if ($pictoSrc): ?>
                            <img src="<?= e($pictoSrc) ?>"
                                 alt="<?= e($code) ?>"
                                 title="<?= e($code) ?> — <?= e(\SDS\Services\GHSStatements::pictogramName($code, $language)) ?>"
                                 style="width: 60px; height: 60px;">
                            <?php else: ?>
                            <span class="badge"><?= e($code) ?></span>
                            <?php endif; ?>
                            <small style="display: block; font-size: 0.7rem; color: #666; text-align: center; width: 100%;"><?= e(\SDS\Services\GHSStatements::pictogramName($code, $language)) ?></small>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($section['hazard_classes'])): ?>
                <p><strong><?= e($l('ghs_classification')) ?>:</strong></p>
                <?php
                    $grouped = \SDS\Services\HazardEngine::groupByHazardType($section['hazard_classes']);
                    $groupLabels = [
                        'physical'      => $l('physical_hazards'),
                        'health'        => $l('health_hazards'),
                        'environmental' => $l('environmental_hazards'),
                    ];
                ?>
                <?php foreach ($groupLabels as $groupKey => $groupLabel): ?>
                    <p style="margin-bottom: 0.2rem;"><strong><?= e($groupLabel) ?>:</strong></p>
                    <?php if (empty($grouped[$groupKey])): ?>
                        <p style="margin-left: 1rem;">None</p>
                    <?php else: ?>
                        <ul>
                        <?php
                            $seen = [];
                            foreach ($grouped[$groupKey] as $hc):
                                $cls = trim($hc['class_translated'] ?? $hc['class'] ?? '');
                                $cat = trim($hc['category_translated'] ?? $hc['category'] ?? '');
                                $label = ($cls !== '' && $cat !== '') ? $cls . ' (' . $cat . ')' : ($cls !== '' ? $cls : $cat);
                                if ($label !== '' && !isset($seen[$label])):
                                    $seen[$label] = true;
                        ?>
                            <li><?= e($label) ?></li>
                        <?php endif; endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($section['h_statements'])): ?>
                <p><strong><?= e($l('hazard_statements')) ?>:</strong></p>
                <ul>
                <?php foreach ($section['h_statements'] as $s): ?>
                    <li><strong><?= e($s['code']) ?></strong><?php if (!empty($s['text'])): ?>: <?= e($s['text']) ?><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($section['p_statements'])): ?>
                <p><strong><?= e($l('precautionary_statements')) ?>:</strong></p>
                <ul>
                <?php foreach ($section['p_statements'] as $s): ?>
                    <li><strong><?= e($s['code']) ?></strong><?php if (!empty($s['text'])): ?>: <?= e($s['text']) ?><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php
                $ppe = $section['ppe_recommendations'] ?? [];
                $hasPPE = !empty($ppe['respiratory']) || !empty($ppe['hand_protection']) || !empty($ppe['eye_protection']) || !empty($ppe['skin_protection']);
            ?>
            <?php if ($hasPPE): ?>
                <p><strong><?= e($l('ppe_recommendations')) ?>:</strong></p>
                <?php
                    $ppeItems = [
                        'eye_protection'  => ['code' => 'PPE-eye',        'labelKey' => 'ppe_wear_eye'],
                        'hand_protection' => ['code' => 'PPE-hand',       'labelKey' => 'ppe_wear_gloves'],
                        'respiratory'     => ['code' => 'PPE-respiratory', 'labelKey' => 'ppe_wear_respiratory'],
                        'skin_protection' => ['code' => 'PPE-skin',       'labelKey' => 'ppe_wear_skin'],
                    ];
                ?>
                <div style="display: flex; flex-wrap: wrap; gap: 16px; margin: 0.5rem 0;">
                    <?php foreach ($ppeItems as $field => $info):
                        if (empty($ppe[$field])) continue;
                        $ppeSrc = \SDS\Services\PictogramHelper::getWebPath($info['code']);
                    ?>
                        <span style="display: inline-flex; flex-direction: column; align-items: center; width: 80px;">
                            <?php if ($ppeSrc): ?>
                            <img src="<?= e($ppeSrc) ?>"
                                 alt="<?= e($l($info['labelKey'])) ?>"
                                 style="width: 50px; height: 50px;">
                            <?php endif; ?>
                            <small style="display: block; font-size: 0.65rem; color: #666; text-align: center; width: 100%;"><?= e($l($info['labelKey'])) ?></small>
                        </span>
                    <?php endforeach; ?>
                </div>
                <ul style="margin-top: 0.3rem;">
                <?php if (!empty($ppe['respiratory'])): ?>
                    <li><strong><?= e($l('respiratory')) ?>:</strong> <?= e($ppe['respiratory']) ?></li>
                <?php endif; ?>
                <?php if (!empty($ppe['hand_protection'])): ?>
                    <li><strong><?= e($l('hand_protection')) ?>:</strong> <?= e($ppe['hand_protection']) ?></li>
                <?php endif; ?>
                <?php if (!empty($ppe['eye_protection'])): ?>
                    <li><strong><?= e($l('eye_protection')) ?>:</strong> <?= e($ppe['eye_protection']) ?></li>
                <?php endif; ?>
                <?php if (!empty($ppe['skin_protection'])): ?>
                    <li><strong><?= e($l('skin_body')) ?>:</strong> <?= e($ppe['skin_protection']) ?></li>
                <?php endif; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($section['has_other_hazards'])): ?>
                <p><strong><?= e($l('other_hazards')) ?>:</strong> <?= e($section['other_hazards']) ?></p>
            <?php endif; ?>

        <?php elseif ($num === 3): // ── Composition ── ?>
            <p><strong><?= e($l('type')) ?>:</strong> <?= e($section['substance_or_mixture'] ?? $l('mixture')) ?></p>
            <?php if (!empty($section['components'])): ?>
            <table class="table table-sm">
                <thead><tr><th><?= e($l('cas_number')) ?></th><th><?= e($l('chemical_name')) ?></th><th><?= e($l('concentration')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($section['components'] as $c): ?>
                    <tr>
                        <td><?= e($c['cas_number']) ?></td>
                        <td><?= e($c['chemical_name']) ?></td>
                        <td><?= e($c['concentration_range'] ?? number_format((float) $c['concentration_pct'], 2) . '%') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

        <?php elseif ($num === 8 && !empty($section['exposure_limits'])): // ── Exposure Controls ── ?>
            <table class="table table-sm">
                <thead><tr>
                    <th><?= e($l('el_cas')) ?></th>
                    <th><?= e($l('el_chemical')) ?></th>
                    <th><?= e($l('el_type')) ?></th>
                    <th><?= e($l('el_value')) ?></th>
                    <th><?= e($l('el_units')) ?></th>
                    <th><?= e($l('el_notes')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($section['exposure_limits'] as $el): ?>
                    <tr>
                        <td><?= e($el['cas_number']) ?></td>
                        <td><?= e($el['chemical_name']) ?></td>
                        <td><?= e($el['limit_type']) ?></td>
                        <td><?= e($el['value']) ?></td>
                        <td><?= e($el['units']) ?></td>
                        <td><?= e($el['notes'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
                // Field-key-to-label mapping for section 8 remaining fields
                $sec8LabelMap = [
                    'engineering' => 'engineering_controls',
                    'respiratory' => 'respiratory_protection',
                    'hand_protection' => 'hand_protection',
                    'eye_protection' => 'eye_protection',
                    'skin_protection' => 'skin_protection',
                ];
            ?>
            <?php foreach ($section as $key => $val): ?>
                <?php if (!is_string($val) || $key === 'title' || $val === '' || $key === 'exposure_limits') continue; ?>
                <?php $fieldLabel = isset($sec8LabelMap[$key]) ? $l($sec8LabelMap[$key]) : ucwords(str_replace('_', ' ', $key)); ?>
                <p><strong><?= e($fieldLabel) ?>:</strong> <?= e($val) ?></p>
            <?php endforeach; ?>

        <?php elseif ($num === 9): // ── Physical/Chemical Properties ── ?>
            <?php
                $sec9LabelMap = [
                    'physical_state'       => 'physical_state',
                    'color'                => 'color',
                    'appearance'           => 'appearance',
                    'odor'                 => 'odor',
                    'boiling_point'        => 'boiling_point',
                    'flash_point'          => 'flash_point',
                    'solubility'           => 'solubility',
                    'specific_gravity'     => 'specific_gravity',
                    'voc_lb_per_gal'       => 'voc_lb_gal',
                    'voc_less_water_exempt' => 'voc_less_we',
                    'voc_wt_pct'           => 'voc_wt_pct',
                    'solids_wt_pct'        => 'solids_wt_pct',
                    'solids_vol_pct'       => 'solids_vol_pct',
                ];
            ?>
            <?php foreach ($section as $key => $val): ?>
                <?php if ($key === 'title') continue; ?>
                <?php if (is_string($val) && $val !== ''): ?>
                    <?php $fieldLabel = isset($sec9LabelMap[$key]) ? $l($sec9LabelMap[$key]) : ucwords(str_replace('_', ' ', $key)); ?>
                    <p><strong><?= e($fieldLabel) ?>:</strong> <?= e($val) ?></p>
                <?php elseif (is_numeric($val)): ?>
                    <?php $fieldLabel = isset($sec9LabelMap[$key]) ? $l($sec9LabelMap[$key]) : ucwords(str_replace('_', ' ', $key)); ?>
                    <p><strong><?= e($fieldLabel) ?>:</strong> <?= $val ?></p>
                <?php endif; ?>
            <?php endforeach; ?>

        <?php elseif ($num === 11): // ── Toxicological Information ── ?>
            <p><strong><?= e($l('acute_toxicity')) ?>:</strong> <?= e($section['acute_toxicity'] ?? '') ?></p>
            <p><strong><?= e($l('chronic_effects')) ?>:</strong> <?= e($section['chronic_effects'] ?? '') ?></p>

            <p><strong><?= e($l('carcinogenicity')) ?>:</strong></p>
            <div style="white-space: pre-wrap; margin-left: 1rem;"><?= e($section['carcinogenicity'] ?? '') ?></div>

            <?php if (!empty($section['component_toxicology'])): ?>
                <h4 style="margin-top: 1rem;"><?= e($l('component_tox_data')) ?></h4>
                <?php foreach ($section['component_toxicology'] as $comp): ?>
                    <div style="margin: 0.5rem 0; padding: 0.5rem; background: #f8f8f8; border-left: 3px solid #003366;">
                        <strong><?= e($comp['chemical_name']) ?></strong>
                        (CAS <?= e($comp['cas_number']) ?>) &mdash; <?= number_format((float) $comp['concentration_pct'], 2) ?>%

                        <?php if (!empty($comp['carcinogen_listings'])): ?>
                            <div style="margin-top: 0.3rem;">
                                <?php foreach ($comp['carcinogen_listings'] as $listing): ?>
                                    <span class="badge badge-warning" style="background: #d9534f; color: #fff; padding: 2px 6px; border-radius: 3px; margin-right: 4px;">
                                        <?= e($listing['agency']) ?>: <?= e($listing['classification']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($comp['exposure_limits'])): ?>
                            <table class="table table-sm" style="margin-top: 0.3rem; font-size: 0.85rem;">
                                <thead><tr>
                                    <th><?= e($l('el_type')) ?></th>
                                    <th><?= e($l('el_value')) ?></th>
                                    <th><?= e($l('el_units')) ?></th>
                                    <th><?= e($l('el_notes')) ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($comp['exposure_limits'] as $el): ?>
                                    <tr>
                                        <td><?= e($el['limit_type']) ?></td>
                                        <td><?= e($el['value']) ?></td>
                                        <td><?= e($el['units']) ?></td>
                                        <td><?= e($el['notes'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php /* Pictograms are intentionally NOT shown in Section 11; they appear in Section 2 only. */ ?>

        <?php elseif ($num === 14): // ── Transport Information ── ?>
            <p><strong><?= e($l('un_number')) ?>:</strong> <?= e($section['un_number'] ?? '') ?></p>
            <p><strong><?= e($l('proper_shipping_name')) ?>:</strong> <?= e($section['proper_shipping_name'] ?? '') ?></p>
            <p><strong><?= e($l('transport_hazard_class')) ?>:</strong> <?= e($section['hazard_class'] ?? '') ?></p>
            <p><strong><?= e($l('packing_group')) ?>:</strong> <?= e($section['packing_group'] ?? '') ?></p>
            <?php if (!empty($section['note'])): ?>
                <p class="text-muted"><em><?= e($section['note']) ?></em></p>
            <?php endif; ?>

        <?php elseif ($num === 15): // ── Regulatory Information ── ?>
            <p><strong><?= e($l('osha_status')) ?>:</strong> <?= e($section['osha_status'] ?? '') ?></p>
            <p><strong><?= e($l('tsca_status')) ?>:</strong> <?= e($section['tsca_status'] ?? '') ?></p>

            <?php
                $sara = $section['sara_313'] ?? [];
                if (!empty($sara['listed_chemicals'] ?? [])):
            ?>
                <h4><?= e($l('sara_313_title')) ?></h4>
                <ul>
                <?php foreach ($sara['listed_chemicals'] as $chem): ?>
                    <li><?= e($chem['chemical_name']) ?> (CAS <?= e($chem['cas_number']) ?>) &mdash;
                        <?= number_format((float) ($chem['concentration_pct'] ?? 0), 2) ?>%
                        (de minimis: <?= e($chem['deminimis_pct'] ?? '1.0') ?>%)</li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php
                $hap = $section['hap'] ?? [];
                if (!empty($hap['has_haps'])):
            ?>
                <h4 style="margin-top: 1rem;"><?= e($l('hap_title')) ?></h4>
                <table class="table table-sm">
                    <thead><tr><th><?= e($l('hap_triggering')) ?></th><th style="text-align: right;"><?= e($l('hap_wt_pct')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($hap['hap_chemicals'] as $chem): ?>
                        <tr>
                            <td><?= e($chem['hap_name'] ?? $chem['chemical_name']) ?></td>
                            <td style="text-align: right;"><?= number_format((float) $chem['concentration_pct'], 2) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: bold; border-top: 2px solid #333;">
                            <td><?= e($l('hap_total')) ?></td>
                            <td style="text-align: right;"><?= number_format((float) $hap['total_hap_pct'], 2) ?>%</td>
                        </tr>
                    </tfoot>
                </table>
            <?php elseif (isset($hap['has_haps'])): ?>
                <p><?= e($l('hap_none')) ?></p>
            <?php endif; ?>

            <?php
                $snur = $section['snur'] ?? [];
                if (!empty($snur['has_snur'])):
            ?>
                <h4 style="margin-top: 1rem;"><?= e($l('snur_title')) ?></h4>
                <ul>
                <?php foreach ($snur['listed_chemicals'] as $chem): ?>
                    <li>
                        <?= e($chem['chemical_name']) ?> (CAS <?= e($chem['cas_number']) ?>)
                        <?php if (!empty($chem['rule_citation'])): ?>
                            &mdash; <?= e($chem['rule_citation']) ?>
                        <?php endif; ?>
                        <?php if (!empty($chem['description'])): ?>
                            <br><em style="font-size: 0.85em; color: #666;"><?= e($chem['description']) ?></em>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php
                $prop65 = $section['prop65'] ?? [];
                if (!empty($prop65['requires_warning'])):
            ?>
                <div style="margin: 1rem 0;">
                    <h4 style="margin: 0 0 0.5rem 0;">
                        <?php $prop65Src = \SDS\Services\PictogramHelper::getWebPath('PROP65'); ?>
                        <?php if ($prop65Src): ?>
                        <img src="<?= e($prop65Src) ?>" alt="Warning" style="width: 30px; height: 30px; vertical-align: middle; margin-right: 6px;">
                        <?php endif; ?>
                        <?= e($l('prop65_title')) ?>
                    </h4>
                    <p style="margin: 0;"><?= e($prop65['warning_text'] ?? '') ?></p>
                </div>
            <?php else: ?>
                <p><strong><?= e($l('prop65_title')) ?>:</strong> <?= e($l('prop65_none')) ?></p>
            <?php endif; ?>

            <?php if (!empty($section['state_regs']) && empty($prop65['requires_warning'])): ?>
                <p><strong><?= e($l('state_regulations')) ?>:</strong> <?= e($section['state_regs']) ?></p>
            <?php endif; ?>

            <?php if (!empty($section['note'])): ?>
                <p class="text-muted"><em><?= e($section['note']) ?></em></p>
            <?php endif; ?>

        <?php else: // ── Generic section ── ?>
            <?php
                // Field-key-to-label mapping for generic sections
                $genericLabelMap = [
                    'inhalation'           => 'inhalation',
                    'skin'                 => 'skin_contact',
                    'eyes'                 => 'eye_contact',
                    'ingestion'            => 'ingestion',
                    'notes'                => 'notes_to_physician',
                    'suitable_media'       => 'suitable_media',
                    'unsuitable_media'     => 'unsuitable_media',
                    'specific_hazards'     => 'specific_hazards',
                    'firefighter_advice'   => 'firefighter_advice',
                    'personal_precautions' => 'personal_precautions',
                    'environmental'        => 'environmental_precautions',
                    'containment'          => 'containment_cleanup',
                    'handling'             => 'handling',
                    'storage'              => 'storage',
                    'reactivity'           => 'reactivity',
                    'stability'            => 'chemical_stability',
                    'conditions_avoid'     => 'conditions_avoid',
                    'incompatible'         => 'incompatible_materials',
                    'decomposition'        => 'decomposition_products',
                    'ecotoxicity'          => 'ecotoxicity',
                    'persistence'          => 'persistence',
                    'bioaccumulation'      => 'bioaccumulation',
                    'methods'              => 'disposal_methods',
                    'note'                 => 'note',
                    'revision_date'        => 'revision_date',
                    'abbreviations'        => 'abbreviations',
                    'disclaimer'           => 'disclaimer',
                ];
            ?>
            <?php foreach ($section as $key => $val): ?>
                <?php if ($key === 'title' || $key === 'hazard_classes' || $key === 'component_toxicology' || $key === 'carcinogen_result' || $key === 'prop65' || $key === 'sara_313' || $key === 'hap' || $key === 'has_other_hazards' || $key === 'uv_acrylate_note') continue; ?>
                <?php if (is_string($val) && $val !== ''): ?>
                    <?php $fieldLabel = isset($genericLabelMap[$key]) ? $l($genericLabelMap[$key]) : ucwords(str_replace('_', ' ', $key)); ?>
                    <p><strong><?= e($fieldLabel) ?>:</strong> <?= e($val) ?></p>
                <?php elseif (is_numeric($val)): ?>
                    <?php $fieldLabel = isset($genericLabelMap[$key]) ? $l($genericLabelMap[$key]) : ucwords(str_replace('_', ' ', $key)); ?>
                    <p><strong><?= e($fieldLabel) ?>:</strong> <?= $val ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($sds['legal_disclaimer'])): ?>
    <div class="sds-section" id="section-disclaimer">
        <h3 class="sds-section-title"><?= e($l('disclaimer')) ?></h3>
        <p style="white-space: pre-wrap;"><?= e($sds['legal_disclaimer']) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($sds['warnings'])): ?>
    <div class="alert alert-warning">
        <strong>Warnings:</strong>
        <ul>
        <?php foreach ($sds['warnings'] as $w): ?>
            <li><?= e($w) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
