<?php
include dirname(__DIR__) . '/layouts/main.php';
$labels = $sds['meta']['labels'] ?? [];
$doc = $sds['meta']['document'] ?? [];
$sheetLang = (string) ($sds['meta']['language'] ?? $language ?? 'en');
// meta.labels first, then labels.<key> in the sheet language (snapshots generated
// before the key was added to getLabels(), audit #37), then $fallback, then the key.
$l = function(string $key, string $fallback = '') use ($labels, $sheetLang) {
    return $labels[$key]
        ?? \SDS\Services\SDSDocumentStrings::translate($sheetLang, 'labels.' . $key)
        ?? ($fallback ?: $key);
};
// Full dot-notation key in the sheet language (legacy-snapshot fallbacks).
$tx = function(string $key, array $replacements = []) use ($sheetLang) {
    return \SDS\Services\SDSDocumentStrings::translate($sheetLang, $key, $replacements) ?? $key;
};
$sectionPrefix = mb_strtoupper(\SDS\Services\SDSDocumentStrings::resolve($doc, 'section_prefix'), 'UTF-8');
?>

<?php if (!empty($backUrl)): ?>
    <p>
        <a href="<?= e($backUrl) ?>">&larr; <?= e($backLabel ?? 'Back') ?></a>
        <?php if (!empty($livePreviewUrl)): ?>
            &nbsp;|&nbsp; <a href="<?= e($livePreviewUrl) ?>" title="Regenerate this document from current data (not the stored snapshot)">Live preview with current data</a>
        <?php endif; ?>
    </p>
<?php elseif (!empty($finishedGood['id'])): ?>
    <p><a href="/sds/<?= (int) $finishedGood['id'] ?>">&larr; Back to SDS Versions</a></p>
<?php elseif (!empty($privateLabelId)): ?>
    <p><a href="/private-label">&larr; Back to Private Label SDS</a></p>
<?php endif; ?>

<div class="sds-preview">
    <div class="sds-header">
        <?php if (!empty($sds['meta']['company_logo_path'])): ?>
            <img src="<?= e($sds['meta']['company_logo_path']) ?>" alt="<?= e($l('company_logo_alt')) ?>" style="max-height: 60px; max-width: 250px; margin-bottom: 0.5rem;">
        <?php endif; ?>
        <h2><?= e(\SDS\Services\SDSDocumentStrings::resolve($doc, 'title')) ?></h2>
        <p class="text-muted"><?= e($tx('document.preview_banner', ['lang' => strtoupper((string) ($language ?? $sheetLang)), 'date' => date('m/d/Y H:i')])) ?></p>
    </div>

    <?php foreach ($sds['sections'] as $num => $section): ?>
    <div class="sds-section" id="section-<?= $num ?>">
        <h3 class="sds-section-title"><?= e($sectionPrefix) ?> <?= $num ?>: <?= e(mb_strtoupper($section['title'] ?? $tx("section{$num}.title"), 'UTF-8')) ?></h3>

        <?php if ($num === 1): // ── Identification — same field set/order as PDFService::renderSection1() ── ?>
            <?php
                $s1Product = [
                    'product_identifier' => $l('product_identifier'),
                    'product_family'     => $l('product_family'),
                    'recommended_use'    => $l('recommended_use'),
                    'restrictions'       => $l('restrictions'),
                ];
                $s1Supplier = [
                    'manufacturer_name'    => $l('company'),
                    'manufacturer_address' => $l('address'),
                    'manufacturer_phone'   => $l('phone'),
                    'manufacturer_email'   => $l('email'),       // pre-existing snapshots: $l() translation fallback
                    'manufacturer_website' => $l('website'),
                    'emergency_phone'      => $l('emergency'),
                ];
            ?>
            <?php foreach ($s1Product as $k => $lbl): if (is_string($section[$k] ?? null) && $section[$k] !== ''): ?>
                <p><strong><?= e($lbl) ?>:</strong> <?= e($section[$k]) ?></p>
            <?php endif; endforeach; ?>
            <p style="margin-top: 0.6rem; margin-bottom: 0.2rem;"><strong><?= e($l('manufacturer_info')) ?></strong></p>
            <?php foreach ($s1Supplier as $k => $lbl): if (is_string($section[$k] ?? null) && $section[$k] !== ''): ?>
                <p style="margin-left: 1rem; margin-bottom: 0.1rem;"><strong><?= e($lbl) ?>:</strong> <?= e($section[$k]) ?></p>
            <?php endif; endforeach; ?>

        <?php elseif ($num === 2): // ── Hazard Identification ── ?>
            <?php if (empty($section['is_classified'])): ?>
                <p style="margin: 0.5rem 0;"><?= e($section['not_classified_text'] ?? $tx('section2.not_classified')) ?></p>
            <?php endif; ?>
            <?php if (!empty($section['signal_word'])): ?>
                <p class="signal-word signal-<?= strtolower($section['signal_word_en'] ?? $section['signal_word']) ?>" style="font-size: 1.3rem; font-weight: bold; color: <?= ($section['signal_word_en'] ?? $section['signal_word']) === 'Danger' ? '#DC0000' : '#FF8C00' ?>;">
                    <?= e(mb_strtoupper($section['signal_word'], 'UTF-8')) ?>
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
                    // Build an H-code → statement-text lookup so each
                    // classification row includes the phrase text inline —
                    // no separate Hazard Statements list underneath. Try
                    // the code(s) verbatim first (combined codes like
                    // "H300+H310+H330" may index as a single entry), then
                    // fall back to splitting on '+' for the components.
                    $statementsByCode = [];
                    foreach ($section['h_statements'] ?? [] as $stmt) {
                        $c = (string) ($stmt['code'] ?? '');
                        if ($c !== '') {
                            $statementsByCode[$c] = (string) ($stmt['text'] ?? '');
                        }
                    }
                    $lookupStatement = function (array $hCodes) use ($statementsByCode): string {
                        foreach ($hCodes as $c) {
                            if (!empty($statementsByCode[$c])) return $statementsByCode[$c];
                            foreach (explode('+', (string) $c) as $part) {
                                $part = trim($part);
                                if ($part !== '' && !empty($statementsByCode[$part])) {
                                    return $statementsByCode[$part];
                                }
                            }
                        }
                        return '';
                    };
                    // Sort within each category by first H-code number so
                    // more-severe hazards (lower H number) lead the list.
                    $firstHCodeNum = function (array $hCodes): int {
                        if (empty($hCodes)) return 9999;
                        if (preg_match('/H(\d+)/', (string) $hCodes[0], $m)) {
                            return (int) $m[1];
                        }
                        return 9999;
                    };

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
                        <p style="margin-left: 1rem;"><?= e($l('none')) ?></p>
                    <?php else: ?>
                        <?php
                            $sorted = $grouped[$groupKey];
                            usort($sorted, function ($a, $b) use ($firstHCodeNum) {
                                return $firstHCodeNum($a['h_codes'] ?? []) <=> $firstHCodeNum($b['h_codes'] ?? []);
                            });
                            $seen = [];
                            foreach ($sorted as $hc):
                                $cls = trim($hc['class_translated'] ?? $hc['class'] ?? '');
                                $cat = trim($hc['category_translated'] ?? $hc['category'] ?? '');
                                $classLabel = ($cls !== '' && $cat !== '') ? $cls . ' (' . $cat . ')' : ($cls !== '' ? $cls : $cat);
                                $hCodes   = is_array($hc['h_codes'] ?? null) ? $hc['h_codes'] : [];
                                $hcPrefix = !empty($hCodes) ? implode(', ', $hCodes) . ' — ' : '';
                                $stmtText = $lookupStatement($hCodes);
                                $line     = $hcPrefix . $classLabel . ($stmtText !== '' ? ': ' . $stmtText : '');
                                if ($classLabel !== '' && !isset($seen[$line])):
                                    $seen[$line] = true;
                        ?>
                            <p style="margin-left: 1rem; margin-bottom: 0.1rem;"><?= e($line) ?></p>
                        <?php endif; endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php // Hazard statements are rendered inline with the
                  // classification above (H-code + phrase on the same line
                  // under its Physical / Health / Environmental heading) —
                  // no separate Hazard Statements list. ?>

            <?php if (!empty($section['p_statements'])): ?>
                <p><strong><?= e($l('precautionary_statements')) ?>:</strong></p>
                <?php foreach ($section['p_statements'] as $s): ?>
                    <?php /* Keep <strong> tight against the <p> — leading whitespace inside a <p> renders as a visible space, which was making these lines look slightly more indented than the hazard lines. */ ?>
                    <p style="margin-left: 1rem; margin-bottom: 0.1rem;"><strong><?= e($s['code']) ?></strong><?php if (!empty($s['text'])): ?>: <?= e($s['text']) ?><?php endif; ?></p>
                <?php endforeach; ?>
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
                <div style="margin-top: 0.3rem;">
                <?php if (!empty($ppe['respiratory'])): ?>
                    <p style="margin-left: 1rem; margin-bottom: 0.1rem;"><strong><?= e($l('respiratory')) ?>:</strong> <?= e($ppe['respiratory']) ?></p>
                <?php endif; ?>
                <?php if (!empty($ppe['hand_protection'])): ?>
                    <p style="margin-left: 1rem; margin-bottom: 0.1rem;"><strong><?= e($l('hand_protection')) ?>:</strong> <?= e($ppe['hand_protection']) ?></p>
                <?php endif; ?>
                <?php if (!empty($ppe['eye_protection'])): ?>
                    <p style="margin-left: 1rem; margin-bottom: 0.1rem;"><strong><?= e($l('eye_protection')) ?>:</strong> <?= e($ppe['eye_protection']) ?></p>
                <?php endif; ?>
                <?php if (!empty($ppe['skin_protection'])): ?>
                    <p style="margin-left: 1rem; margin-bottom: 0.1rem;"><strong><?= e($l('skin_body')) ?>:</strong> <?= e($ppe['skin_protection']) ?></p>
                <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php // Other hazards (App. D 2(c)) — always shown; override or translated default. ?>
            <?php $otherHazards = trim((string) ($section['other_hazards'] ?? '')); ?>
            <?php if ($otherHazards !== ''): ?>
                <p><strong><?= e($l('other_hazards')) ?>:</strong> <?= e($otherHazards) ?></p>
            <?php endif; ?>

        <?php elseif ($num === 3): // ── Composition ── ?>
            <p><strong><?= e($l('type')) ?>:</strong> <?= e($section['substance_or_mixture'] ?? $l('mixture')) ?></p>
            <?php if (!empty($section['components'])): ?>
            <p class="text-muted" style="font-size: 0.85rem; font-style: italic; margin: 0.25rem 0;"><?= e($l('hazardous_only_note')) ?></p>
            <table class="table table-sm">
                <thead><tr>
                    <th><?= e($l('cas_number')) ?></th>
                    <th><?= e($l('chemical_name')) ?></th>
                    <th><?= e($l('concentration')) ?></th>
                    <th><?= e($l('h_codes')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($section['components'] as $c): ?>
                    <tr>
                        <td><?= e($c['cas_number']) ?></td>
                        <td><?= e($c['chemical_name']) ?></td>
                        <td><?= e((string) ($c['concentration_range'] ?? '')) ?><?php /* band only, never the exact % */ ?></td>
                        <td><?= !empty($c['h_codes']) && is_array($c['h_codes']) ? e(implode(', ', $c['h_codes'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="text-muted" style="font-size: 0.85rem; font-style: italic; margin: 0.25rem 0;"><?= e($l('no_hazardous_note')) ?></p>
            <?php endif; ?>
            <?php if (!empty($section['trade_secret_note'])): ?>
            <p class="text-muted" style="font-size: 0.85rem; font-style: italic; margin: 0.25rem 0;"><?= e($section['trade_secret_note']) ?></p>
            <?php endif; ?>

        <?php elseif ($num === 8): // ── Exposure Controls — same columns/labels as PDFService::renderSection8() ── ?>
            <?php if (!empty($section['exposure_limits'])): ?>
            <table class="table table-sm">
                <thead><tr>
                    <th><?= e($l('el_cas')) ?></th>
                    <th><?= e($l('el_chemical')) ?></th>
                    <th><?= e($l('el_type')) ?></th>
                    <th><?= e($l('el_value')) ?></th>
                    <th><?= e($l('el_units')) ?></th>
                    <th><?= e($l('el_conc_pct')) ?></th>
                    <th><?= e($l('el_notes')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($section['exposure_limits'] as $el): ?>
                    <tr>
                        <td><?= e($el['cas_number'] ?? '') ?></td>
                        <td><?= e($el['chemical_name'] ?? '') ?></td>
                        <td><?= e($el['limit_type'] ?? '') ?></td>
                        <td><?= e($el['value'] ?? '') ?></td>
                        <td><?= e($el['units'] ?? '') ?></td>
                        <?php // Prescribed-range band from SDSGenerator::section8() (audit #8); never the exact %. ?>
                        <td><?= e($el['concentration_range'] ?? '') ?></td>
                        <td><?= e($el['notes'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
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
                <?php if (!is_string($val) || $key === 'title' || $val === '' || $key === 'exposure_limits' || $key === 'uv_acrylate_note') continue; // uv_acrylate_note: pre-#35 snapshots only; PDFService::renderSection8() never prints it ?>
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
                    'voc_wt_pct'           => 'voc_wt_pct',
                    'solids_wt_pct'        => 'solids_wt_pct',
                ];
            ?>
            <?php foreach ($section as $key => $val): ?>
                <?php if ($key === 'title' || $key === 'voc_less_water_exempt' || $key === 'solids_vol_pct') continue; // #18(c): old snapshots still carry the two keys ?>
                <?php if (is_string($val) && $val !== ''): ?>
                    <?php $fieldLabel = isset($sec9LabelMap[$key]) ? $l($sec9LabelMap[$key]) : ucwords(str_replace('_', ' ', $key)); ?>
                    <p><strong><?= e($fieldLabel) ?>:</strong> <?= e($val) ?></p>
                <?php elseif (is_numeric($val)): ?>
                    <?php $fieldLabel = isset($sec9LabelMap[$key]) ? $l($sec9LabelMap[$key]) : ucwords(str_replace('_', ' ', $key)); ?>
                    <p><strong><?= e($fieldLabel) ?>:</strong> <?= $val ?></p>
                <?php endif; ?>
            <?php endforeach; ?>

        <?php elseif ($num === 11): // ── Toxicological Information ── ?>
            <p style="white-space: pre-line;"><strong><?= e($l('acute_toxicity')) ?>:</strong> <?= e($section['acute_toxicity'] ?? '') ?></p>
            <p><strong><?= e($l('chronic_effects')) ?>:</strong> <?= e($section['chronic_effects'] ?? '') ?></p>

            <p><strong><?= e($l('carcinogenicity')) ?>:</strong></p>
            <div style="white-space: pre-wrap; margin-left: 1rem;"><?= e($section['carcinogenicity'] ?? '') ?></div>

            <?php if (!empty($section['component_toxicology'])): ?>
                <h4 style="margin-top: 1rem;"><?= e($l('component_tox_data')) ?></h4>
                <?php foreach ($section['component_toxicology'] as $comp): ?>
                    <div style="margin: 0.5rem 0; padding: 0.5rem; background: #f8f8f8; border-left: 3px solid #003366;">
                        <strong><?= e($comp['chemical_name']) ?></strong>
                        (CAS <?= e($comp['cas_number']) ?>)<?php if (($comp['concentration_range'] ?? '') !== ''): ?> &mdash; <?= e($comp['concentration_range']) ?><?php endif; /* prescribed-range band only, never the exact % */ ?>

                        <?php if (!empty($comp['carcinogen_listings'])): ?>
                            <div style="margin-top: 0.3rem;">
                                <?php foreach ($comp['carcinogen_listings'] as $listing): ?>
                                    <span class="badge badge-warning" style="background: #d9534f; color: #fff; padding: 2px 6px; border-radius: 3px; margin-right: 4px;">
                                        <?= e($listing['agency']) ?>: <?= e($listing['classification']) ?>
                                    </span>
                                    <?php if (!empty($listing['description'])): ?>
                                        <span style="font-size: 0.85rem; margin-right: 8px;"><?= e($listing['description']) ?></span>
                                    <?php endif; ?>
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

            <?php if (!empty($section['uv_acrylate_note'])): // UV acrylate rule-pack note (audit #35) — same position as PDFService::renderSection11() ?>
                <p style="margin-top: 0.5rem;"><strong><?= e($l('uv_acrylate_note')) ?>:</strong> <?= e($section['uv_acrylate_note']) ?></p>
            <?php endif; ?>

            <?php /* Pictograms are intentionally NOT shown in Section 11; they appear in Section 2 only. */ ?>

        <?php elseif ($num === 12): // ── Ecological Information (item #23) ── ?>
            <p><strong><?= e($l('ecotoxicity')) ?>:</strong> <?= e($section['ecotoxicity'] ?? '') ?></p>

            <?php if (!empty($section['component_aquatic']) && is_array($section['component_aquatic'])): ?>
                <h4 style="margin-top: 1rem;"><?= e($l('component_ecotox_data')) ?></h4>
                <table class="table table-sm" style="font-size: 0.85rem;">
                    <thead><tr>
                        <th><?= e($l('chemical_name')) ?></th>
                        <th><?= e($l('cas_number')) ?></th>
                        <th><?= e($l('el_conc_pct')) ?></th>
                        <th><?= e($l('aquatic_acute')) ?></th>
                        <th><?= e($l('aquatic_chronic')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($section['component_aquatic'] as $row): ?>
                        <tr>
                            <td><?= e($row['chemical_name'] ?? '') ?></td>
                            <td><?= e($row['cas_number'] ?? '') ?></td>
                            <td><?= e($row['concentration_range'] ?? '') ?></td><?php /* prescribed-range band only */ ?>
                            <td><?= ($row['acute'] ?? '') !== '' ? e($row['acute']) : '&mdash;' ?></td>
                            <td><?= ($row['chronic'] ?? '') !== '' ? e($row['chronic']) : '&mdash;' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p><strong><?= e($l('persistence')) ?>:</strong> <?= e($section['persistence'] ?? '') ?></p>
            <p><strong><?= e($l('bioaccumulation')) ?>:</strong> <?= e($section['bioaccumulation'] ?? '') ?></p>
            <p><strong><?= e($l('mobility')) ?>:</strong> <?= e($section['mobility'] ?? '') ?></p><?php /* item #24 */ ?>
            <?php /* ghs_note (item #25) is printed by the shared footnote after this if/elseif chain. */ ?>

        <?php elseif ($num === 14): // ── Transport Information ── ?>
            <p><strong><?= e($l('un_number')) ?>:</strong> <?= e($section['un_number'] ?? '') ?></p>
            <p><strong><?= e($l('proper_shipping_name')) ?>:</strong> <?= e($section['proper_shipping_name'] ?? '') ?></p>
            <p><strong><?= e($l('transport_hazard_class')) ?>:</strong> <?= e($section['hazard_class'] ?? '') ?></p>
            <p><strong><?= e($l('packing_group')) ?>:</strong> <?= e($section['packing_group'] ?? '') ?></p>
            <?php if (($section['environmental_hazards'] ?? '') !== ''): // audit #27 ?>
                <p><strong><?= e($l('environmental_hazards')) ?>:</strong> <?= e($section['environmental_hazards']) ?></p>
            <?php endif; ?>
            <?php if (!empty($section['note'])): ?>
                <p><strong><?= e($l('note')) ?>:</strong> <?= e($section['note']) ?></p>
            <?php endif; ?>

        <?php elseif ($num === 15): // ── Regulatory Information ── ?>
            <p><strong><?= e($l('osha_status')) ?>:</strong> <?= e($section['osha_status'] ?? '') ?></p>
            <p><strong><?= e($l('tsca_status')) ?>:</strong> <?= e($section['tsca_status'] ?? '') ?></p>

            <?php
                // SARA 313 / TRI supplier notification (40 CFR 372.45): SARA313Service emits
                // 'reportable' / 'threshold_pct' / 'is_pbt' / 'sara_name'. Always print the
                // heading; list reportable entries or the translated "none" sentence.
                $sara = $section['sara_313'] ?? [];
                if (isset($sara['reportable']) && is_array($sara['reportable'])):
            ?>
                <h4><?= e($l('sara_313_title')) ?></h4>
                <?php if (!empty($sara['reportable'])): ?>
                <p><?= e($l('sara_313_statement')) ?></p>
                <ul>
                <?php foreach ($sara['reportable'] as $chem): ?>
                    <?php
                        $saraName      = (string) ((($chem['sara_name'] ?? '') !== '') ? $chem['sara_name'] : ($chem['chemical_name'] ?? ''));
                        $saraThreshold = rtrim(rtrim(number_format((float) ($chem['threshold_pct'] ?? 1.0), 4), '0'), '.');
                    ?>
                    <li><?= e($saraName) ?> (CAS <?= e($chem['cas_number'] ?? '') ?>) &mdash;
                        <?= e((string) ($chem['concentration_range'] ?? '')) ?><?php /* band only, never the exact % */ ?>
                        (<?= e($l('sara_313_threshold')) ?>: <?= e($saraThreshold) ?>%<?= !empty($chem['is_pbt']) ? '; ' . e($l('sara_313_pbt')) : '' ?>)</li>
                <?php endforeach; ?>
                </ul>
                <p class="text-muted" style="font-size: 0.75rem; font-style: italic;"><?= e($l('sara_313_range_note')) ?></p>
                <?php else: ?>
                <p><?= e($l('sara_313_none')) ?></p>
                <?php endif; ?>
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
                            <td style="text-align: right;"><?= e((string) ($chem['concentration_range'] ?? '')) ?><?php /* band only, never the exact % */ ?></td>
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
                <h4 style="margin-top: 1rem;"><?= e($l('hap_title')) ?></h4>
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
                        <img src="<?= e($prop65Src) ?>" alt="<?= e($l('prop65_pictogram_alt')) ?>" style="width: 30px; height: 30px; vertical-align: middle; margin-right: 6px;">
                        <?php endif; ?>
                        <?= e($l('prop65_title')) ?>
                    </h4>
                    <p style="margin: 0;"><?= e($prop65['warning_text'] ?? '') ?></p>
                    <?php if (!empty($prop65['listed_lines']) && is_array($prop65['listed_lines'])): ?>
                    <p style="margin: 0.5rem 0 0 0;"><strong><?= e($l('prop65_listed')) ?>:</strong></p>
                    <ul style="margin: 0;">
                    <?php foreach ($prop65['listed_lines'] as $p65Line): ?>
                        <li><?= e((string) $p65Line) ?></li>
                    <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p><strong><?= e($l('prop65_title')) ?>:</strong> <?= e($l('prop65_none')) ?></p>
            <?php endif; ?>

            <?php
                // Audit #31: operator's note prints whenever present; skip the
                // pre-#31 snapshot case where state_regs holds the Prop 65 warning.
                $stateRegs = trim((string) ($section['state_regs'] ?? ''));
                if ($stateRegs !== '' && $stateRegs !== trim((string) ($prop65['warning_text'] ?? ''))):
            ?>
                <p><strong><?= e($l('state_regulations')) ?>:</strong> <?= e($stateRegs) ?></p>
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
                    'symptoms'             => 'symptoms_effects',
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
                    'uv_acrylate_note'     => 'uv_acrylate_note', // audit #35 (Sections 4-7)
                    'reactivity'           => 'reactivity',
                    'stability'            => 'chemical_stability',
                    'conditions_avoid'     => 'conditions_avoid',
                    'incompatible'         => 'incompatible_materials',
                    'decomposition'        => 'decomposition_products',
                    'ecotoxicity'          => 'ecotoxicity',
                    'persistence'          => 'persistence',
                    'bioaccumulation'      => 'bioaccumulation',
                    'methods'              => 'disposal_methods',
                    'rcra_classification'  => 'rcra_classification', // audit #26
                    'note'                 => 'note',
                    'version'              => 'version',
                    'effective_date'       => 'effective_date',
                    'revision_date'        => 'revision_date',
                    'revision_note'        => 'revision_note',   // legacy snapshots only (section16() stopped emitting it in #32)
                    'abbreviations'        => 'abbreviations',
                    'disclaimer'           => 'disclaimer',
                ];
            ?>
            <?php foreach ($section as $key => $val): ?>
                <?php if ($key === 'title' || $key === 'hazard_classes' || $key === 'component_toxicology' || $key === 'carcinogen_result' || $key === 'prop65' || $key === 'sara_313' || $key === 'hap' || $key === 'has_other_hazards' || $key === 'ghs_note' || $key === 'flash_point_c') continue; ?>
                <?php if (is_string($val) && $val !== ''): ?>
                    <?php $fieldLabel = isset($genericLabelMap[$key]) ? $l($genericLabelMap[$key], ucwords(str_replace('_', ' ', $key))) : ucwords(str_replace('_', ' ', $key)); ?>
                    <p><strong><?= e($fieldLabel) ?>:</strong> <?= e($val) ?></p>
                <?php elseif (is_numeric($val)): ?>
                    <?php $fieldLabel = isset($genericLabelMap[$key]) ? $l($genericLabelMap[$key], ucwords(str_replace('_', ' ', $key))) : ucwords(str_replace('_', ' ', $key)); ?>
                    <p><strong><?= e($fieldLabel) ?>:</strong> <?= $val ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($section['ghs_note'])): // shared Sections 12-15 footnote (item #25) ?>
            <p class="sds-ghs-note text-muted" style="font-size: 0.75rem; font-style: italic; margin-top: 0.5rem;"><?= e($section['ghs_note']) ?></p>
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
