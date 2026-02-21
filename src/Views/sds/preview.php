<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/sds/<?= (int) $finishedGood['id'] ?>">&larr; Back to SDS Versions</a></p>

<div class="sds-preview">
    <div class="sds-header">
        <?php if (!empty($sds['meta']['company_logo_path'])): ?>
            <img src="<?= e($sds['meta']['company_logo_path']) ?>" alt="Company Logo" style="max-height: 60px; max-width: 250px; margin-bottom: 0.5rem;">
        <?php endif; ?>
        <h2>SAFETY DATA SHEET</h2>
        <p class="text-muted">Preview &mdash; <?= e(strtoupper($language)) ?> &mdash; Generated <?= date('m/d/Y H:i') ?></p>
    </div>

    <?php foreach ($sds['sections'] as $num => $section): ?>
    <div class="sds-section" id="section-<?= $num ?>">
        <h3 class="sds-section-title">SECTION <?= $num ?>: <?= e(strtoupper($section['title'] ?? '')) ?></h3>

        <?php if ($num === 2): // ── Hazard Identification ── ?>
            <?php if (!empty($section['signal_word'])): ?>
                <p class="signal-word signal-<?= strtolower($section['signal_word']) ?>" style="font-size: 1.3rem; font-weight: bold; color: <?= $section['signal_word'] === 'Danger' ? '#DC0000' : '#FF8C00' ?>;">
                    <?= e(strtoupper($section['signal_word'])) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($section['pictograms'])): ?>
                <div class="sds-pictograms" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0.5rem 0;">
                    <strong>Pictograms:</strong>
                    <?php foreach ($section['pictograms'] as $code): ?>
                        <span style="display: inline-flex; flex-direction: column; align-items: center;">
                            <img src="/assets/pictograms/<?= e($code) ?>.svg"
                                 alt="<?= e($code) ?>"
                                 title="<?= e($code) ?> — <?= e(\SDS\Services\GHSStatements::pictogramName($code)) ?>"
                                 style="width: 60px; height: 60px;"
                                 onerror="this.outerHTML='<span class=\'badge\'><?= e($code) ?></span>'">
                            <small style="font-size: 0.7rem; color: #666;"><?= e(\SDS\Services\GHSStatements::pictogramName($code)) ?></small>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($section['hazard_classes'])): ?>
                <p><strong>GHS Classification:</strong></p>
                <ul>
                <?php
                    $seen = [];
                    foreach ($section['hazard_classes'] as $hc):
                        $label = trim(($hc['class'] ?? '') . ' ' . ($hc['category'] ?? ''));
                        if ($label !== '' && !isset($seen[$label])):
                            $seen[$label] = true;
                ?>
                    <li><?= e($label) ?></li>
                <?php endif; endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($section['h_statements'])): ?>
                <p><strong>Hazard Statements:</strong></p>
                <ul>
                <?php foreach ($section['h_statements'] as $s): ?>
                    <li><strong><?= e($s['code']) ?></strong><?php if (!empty($s['text'])): ?>: <?= e($s['text']) ?><?php endif; ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($section['p_statements'])): ?>
                <p><strong>Precautionary Statements:</strong></p>
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
                <p><strong>Recommended Personal Protective Equipment (PPE):</strong></p>
                <ul>
                <?php if (!empty($ppe['respiratory'])): ?>
                    <li><strong>Respiratory:</strong> <?= e($ppe['respiratory']) ?></li>
                <?php endif; ?>
                <?php if (!empty($ppe['hand_protection'])): ?>
                    <li><strong>Hand Protection:</strong> <?= e($ppe['hand_protection']) ?></li>
                <?php endif; ?>
                <?php if (!empty($ppe['eye_protection'])): ?>
                    <li><strong>Eye Protection:</strong> <?= e($ppe['eye_protection']) ?></li>
                <?php endif; ?>
                <?php if (!empty($ppe['skin_protection'])): ?>
                    <li><strong>Skin/Body Protection:</strong> <?= e($ppe['skin_protection']) ?></li>
                <?php endif; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($section['other_hazards']) && $section['other_hazards'] !== 'None known.'): ?>
                <p><strong>Other Hazards:</strong> <?= e($section['other_hazards']) ?></p>
            <?php endif; ?>

        <?php elseif ($num === 3): // ── Composition ── ?>
            <p><strong>Type:</strong> <?= e($section['substance_or_mixture'] ?? 'Mixture') ?></p>
            <?php if (!empty($section['components'])): ?>
            <table class="table table-sm">
                <thead><tr><th>CAS</th><th>Chemical Name</th><th>Concentration</th></tr></thead>
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
                <thead><tr><th>CAS</th><th>Chemical</th><th>Type</th><th>Value</th><th>Units</th><th>Notes</th></tr></thead>
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
            <?php foreach ($section as $key => $val): ?>
                <?php if (!is_string($val) || $key === 'title' || $val === '' || $key === 'exposure_limits') continue; ?>
                <p><strong><?= e(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= e($val) ?></p>
            <?php endforeach; ?>

        <?php elseif ($num === 11): // ── Toxicological Information ── ?>
            <p><strong>Acute Toxicity:</strong> <?= e($section['acute_toxicity'] ?? '') ?></p>
            <p><strong>Chronic Effects:</strong> <?= e($section['chronic_effects'] ?? '') ?></p>

            <p><strong>Carcinogenicity:</strong></p>
            <div style="white-space: pre-wrap; margin-left: 1rem;"><?= e($section['carcinogenicity'] ?? '') ?></div>

            <?php if (!empty($section['component_toxicology'])): ?>
                <h4 style="margin-top: 1rem;">Component Toxicological Data</h4>
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
                                <thead><tr><th>Limit Type</th><th>Value</th><th>Units</th><th>Notes</th></tr></thead>
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

            <?php
                $carcinogenResult = $section['carcinogen_result'] ?? [];
                if (!empty($carcinogenResult['has_carcinogens'])):
            ?>
                <div style="margin-top: 0.5rem;">
                    <img src="/assets/pictograms/GHS08.svg" alt="GHS08 - Health Hazard" style="width: 40px; height: 40px; vertical-align: middle;"
                         onerror="this.outerHTML='<span class=\'badge\'>GHS08</span>'">
                    <span style="color: #d9534f; font-weight: bold;">Health Hazard</span>
                </div>
            <?php endif; ?>

        <?php elseif ($num === 15): // ── Regulatory Information ── ?>
            <p><strong>OSHA Status:</strong> <?= e($section['osha_status'] ?? '') ?></p>
            <p><strong>TSCA Status:</strong> <?= e($section['tsca_status'] ?? '') ?></p>

            <?php
                $sara = $section['sara_313'] ?? [];
                if (!empty($sara['listed_chemicals'] ?? [])):
            ?>
                <h4>SARA 313 / TRI Reporting</h4>
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
                <h4 style="margin-top: 1rem;">Clean Air Act Section 112(b) — Hazardous Air Pollutants (HAPs)</h4>
                <table class="table table-sm">
                    <thead><tr><th>Chemical Name</th><th>CAS</th><th>Category</th><th style="text-align: right;">Weight %</th></tr></thead>
                    <tbody>
                    <?php foreach ($hap['hap_chemicals'] as $chem): ?>
                        <tr>
                            <td><?= e($chem['chemical_name']) ?></td>
                            <td><?= e($chem['cas_number']) ?></td>
                            <td><?= e($chem['category']) ?></td>
                            <td style="text-align: right;"><?= number_format((float) $chem['concentration_pct'], 2) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: bold; border-top: 2px solid #333;">
                            <td colspan="3">Total HAP Content</td>
                            <td style="text-align: right;"><?= number_format((float) $hap['total_hap_pct'], 2) ?>%</td>
                        </tr>
                    </tfoot>
                </table>
            <?php elseif (isset($hap['has_haps'])): ?>
                <p><strong>Hazardous Air Pollutants (HAPs):</strong> This product does not contain any EPA HAPs listed under Clean Air Act Section 112(b).</p>
            <?php endif; ?>

            <?php
                $prop65 = $section['prop65'] ?? [];
                if (!empty($prop65['requires_warning'])):
            ?>
                <div style="margin: 1rem 0; padding: 0.75rem; border: 2px solid #d9534f; background: #fff5f5;">
                    <h4 style="color: #d9534f; margin: 0 0 0.5rem 0;">
                        <img src="/assets/pictograms/GHS08.svg" alt="Warning" style="width: 30px; height: 30px; vertical-align: middle; margin-right: 6px;"
                             onerror="this.outerHTML=''">
                        California Proposition 65
                    </h4>
                    <p style="margin: 0 0 0.5rem 0;"><?= e($prop65['warning_text'] ?? '') ?></p>

                    <?php if (!empty($prop65['listed_chemicals'])): ?>
                        <table class="table table-sm" style="font-size: 0.85rem;">
                            <thead><tr><th>Chemical</th><th>CAS</th><th>Conc%</th><th>Listing Type</th></tr></thead>
                            <tbody>
                            <?php foreach ($prop65['listed_chemicals'] as $chem): ?>
                                <tr>
                                    <td><?= e($chem['chemical_name']) ?></td>
                                    <td><?= e($chem['cas_number']) ?></td>
                                    <td><?= number_format((float) ($chem['concentration_pct'] ?? 0), 2) ?></td>
                                    <td><?= e(implode(', ', $chem['toxicity_type'] ?? [])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($section['state_regs']) && empty($prop65['requires_warning'])): ?>
                <p><strong>State Regulations:</strong> <?= e($section['state_regs']) ?></p>
            <?php endif; ?>

            <?php if (!empty($section['note'])): ?>
                <p class="text-muted"><em><?= e($section['note']) ?></em></p>
            <?php endif; ?>

        <?php else: // ── Generic section ── ?>
            <?php foreach ($section as $key => $val): ?>
                <?php if ($key === 'title' || $key === 'hazard_classes' || $key === 'component_toxicology' || $key === 'carcinogen_result' || $key === 'prop65' || $key === 'sara_313' || $key === 'hap') continue; ?>
                <?php if (is_string($val) && $val !== ''): ?>
                    <p><strong><?= e(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= e($val) ?></p>
                <?php elseif (is_numeric($val)): ?>
                    <p><strong><?= e(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= $val ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($sds['legal_disclaimer'])): ?>
    <div class="sds-section" id="section-disclaimer">
        <h3 class="sds-section-title">DISCLAIMER</h3>
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
