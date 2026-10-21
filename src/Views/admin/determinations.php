<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>CAS Number Determinations</h2>
    <a href="/determinations/create" class="btn btn-primary">+ New CAS Determination</a>
</div>

<p class="text-muted">Define hazard determinations for CAS numbers. When federal data is available it will be pre-loaded into the form. Select hazard statements, H/P codes, and exposure limits. Determinations are clearly marked as non-federal in Section 16.</p>

<!-- Tab Navigation -->
<div class="tab-nav" style="display: flex; gap: 0; border-bottom: 2px solid #003366; margin-bottom: 1rem;">
    <button class="tab-btn active" data-tab="needs" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #003366; color: #fff; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-right: 2px;">
        Needs Determination
        <?php if (!empty($needsDetermination)): ?>
            <span style="background: #dc3545; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($needsDetermination) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="existing" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-right: 2px;">
        Determinations Made
        <?php if (!empty($items)): ?>
            <span style="background: #28a745; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($items) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="descriptions" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold;">
        CAS Descriptions
        <?php if (!empty($descriptions)): ?>
            <span style="background: #6c757d; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($descriptions) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="tsca" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-left: 2px;">
        TSCA Review
        <?php $tscaCount = count($tscaReview ?? []) + count($tscaRmReview ?? []); ?>
        <?php if ($tscaCount > 0): ?>
            <span style="background: #fd7e14; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= $tscaCount ?></span>
        <?php endif; ?>
    </button>
    <?php // A12(b) / T4a ?>
    <button class="tab-btn" data-tab="stot" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-left: 2px;">
        STOT Target Organs
        <?php $stotOpen = count(array_filter($stotRows ?? [], static fn(array $r): bool => trim((string) ($r['stot_se_organs'] ?? '')) === '' && trim((string) ($r['stot_re_organs'] ?? '')) === '' && (int) ($r['rm_count'] ?? 0) > 0)); ?>
        <?php if ($stotOpen > 0): ?>
            <span style="background: #6c757d; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;" title="Substances in use with STOT data and no target organs entered"><?= $stotOpen ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- Tab: Needs Determination -->
<div class="tab-panel" id="tab-needs">
    <?php if (empty($needsDetermination)): ?>
        <div style="text-align: center; padding: 2rem; color: #28a745;">
            <strong>All CAS numbers have federal data or determinations.</strong>
        </div>
    <?php else: ?>
        <p class="text-muted" style="margin-bottom: 0.5rem;">These CAS numbers appear in raw materials but have no active determination. Click <strong>Create</strong> to enter a determination. Items with federal data will be pre-loaded into the form.</p>
        <table class="table">
            <thead>
                <tr>
                    <th>CAS Number</th>
                    <th>Chemical Name</th>
                    <th>Raw Materials</th>
                    <th>In Formula</th>
                    <th>Federal Data</th>
                    <th style="width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($needsDetermination as $nd): ?>
                <tr>
                    <td><strong><?= e($nd['cas_number']) ?></strong></td>
                    <td><?= e($nd['chemical_name']) ?></td>
                    <td>
                        <span title="<?= e($nd['raw_material_codes']) ?>"><?= e($nd['raw_material_codes']) ?></span>
                        <?php if ((int)$nd['raw_material_count'] > 1): ?>
                            <small class="text-muted">(<?= (int)$nd['raw_material_count'] ?> materials)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$nd['in_formula']): ?>
                            <span style="color: #28a745; font-weight: bold;">Yes</span>
                        <?php else: ?>
                            <span class="text-muted">No</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)($nd['has_federal_data'] ?? 0)): ?>
                            <span style="color: #0d6efd; font-weight: bold;" title="Federal hazard data or exposure limits are available and will be pre-loaded">Available</span>
                        <?php else: ?>
                            <span class="text-muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/determinations/create?cas=<?= urlencode($nd['cas_number']) ?>" class="btn btn-sm btn-primary">Create</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Tab: Existing Determinations -->
<div class="tab-panel" id="tab-existing" style="display: none;">
    <table class="table">
        <thead>
            <tr>
                <th>CAS Number</th>
                <th>Jurisdiction</th>
                <th>H-Codes</th>
                <th>Rationale (excerpt)</th>
                <th>Active</th>
                <th>Created By</th>
                <th>Date</th>
                <th style="width:80px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="8" class="text-muted" style="text-align:center;">No determinations recorded.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
            <?php
                // determination_json stores h_statements as a comma-separated
                // string (e.g. "H225, H319, H335"). Fallback for edge cases:
                // missing / malformed JSON or pre-schema rows.
                $hCodesStr = '';
                $detRaw = $item['determination_json'] ?? '';
                if ($detRaw !== '') {
                    $det = json_decode((string) $detRaw, true);
                    if (is_array($det)) {
                        $hCodesStr = trim((string) ($det['h_statements'] ?? ''));
                    }
                }
            ?>
            <tr>
                <td><strong><?= e($item['cas_number']) ?></strong></td>
                <td><?= e($item['jurisdiction']) ?></td>
                <td><?= $hCodesStr !== '' ? e($hCodesStr) : '<span class="text-muted">—</span>' ?></td>
                <td><?= e(mb_strimwidth($item['rationale_text'], 0, 80, '...')) ?></td>
                <td><?= (int)$item['is_active'] ? '<span style="color:green;">Yes</span>' : '<span style="color:#999;">No</span>' ?></td>
                <td><?= e($item['created_by_name'] ?? '—') ?></td>
                <td><?= e(\SDS\Services\PublishClock::display($item['created_at'], 'm/d/Y')) ?></td>
                <td><a href="/determinations/<?= (int)$item['id'] ?>/edit" class="btn btn-sm">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Tab: CAS Descriptions -->
<div class="tab-panel" id="tab-descriptions" style="display: none;">
    <p class="text-muted" style="margin-bottom: 0.5rem;">
        One description per CAS number — the single source of truth used on all SDSs and everywhere a CAS appears.
        Saving a description here updates every raw material constituent with that CAS.
        Substances on the <a href="/prop65">Prop 65 list</a> take their description from that page and cannot be edited here.
        The <strong>N / S / Hal</strong> boxes mark substances containing nitrogen, sulfur or a halogen; SDS Section 10 lists
        nitrogen oxides, sulfur oxides or hydrogen halides as decomposition products for every product that contains them.
        Saving flags bumps the affected raw materials for republish.
        The <strong>RCRA metals</strong> box lists the toxicity-characteristic metals a substance contains (As, Ba, Cd, Cr, Pb, Hg, Se, Ag, comma-separated); SDS Section 13 then gives every product containing it the matching D004&ndash;D011 code and TCLP limit.
        The <strong>Acrylate</strong> box marks reactive acrylate / methacrylate monomers and oligomers; UV sheets name them, by this description, in the Section 4 skin sentence. Until the acrylate seed has run, a box shows the automatic result (old CAS list or name rule, source <em>auto</em>); saving it makes the row <code>manual</code>. Unticking it marks the CAS as not an acrylate.
    </p>
    <div style="margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
        <input type="text" id="descFilter" placeholder="Filter by CAS or description..." style="max-width: 320px;">
        <a href="/determinations/element-flags" class="btn btn-sm btn-outline" title="Dry run of the element-flag seed (formula / name keywords) for every CAS, with an Apply button">Seed element flags (preview)</a>
        <a href="/determinations/tc-metals" class="btn btn-sm btn-outline" title="Dry run of the RCRA metal-flag seed (formula / name patterns) for every CAS, with an Apply button">Seed RCRA metal flags (preview)</a>
        <a href="/determinations/acrylate-flags" class="btn btn-sm btn-outline" title="Dry run of the acrylate-flag seed (old CAS list / name rule) for every CAS, with an Apply button">Seed acrylate flags (preview)</a>
    </div>
    <table class="table" id="descTable">
        <thead>
            <tr>
                <th style="width: 140px;">CAS Number</th>
                <th>Description</th>
                <th style="width: 110px;">Source</th>
                <th style="width: 110px;">Used in RMs</th>
                <th style="width: 44px; text-align: center;" title="Contains nitrogen (Section 10: nitrogen oxides)">N</th>
                <th style="width: 44px; text-align: center;" title="Contains sulfur (Section 10: sulfur oxides)">S</th>
                <th style="width: 50px; text-align: center;" title="Contains a halogen (Section 10: hydrogen halides)">Hal</th>
                <th style="width: 150px;" title="RCRA toxicity-characteristic metals contained (Section 13 D004-D011)">RCRA metals</th>
                <th style="width: 90px; text-align: center;" title="Reactive acrylate / methacrylate monomer or oligomer (UV sheets: Section 4 acrylate names)">Acrylate</th>
                <th style="width: 150px;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($descriptions ?? [] as $idx => $d): ?>
            <?php $isP65 = !empty($d['prop65_name']); $formId = 'desc-form-' . $idx; $flagsId = 'flags-form-' . $idx; ?>
            <tr>
                <td>
                    <strong><?= e($d['cas_number']) ?></strong>
                    <?php // Audit #19: element flags post separately (Prop 65 rows have no description form) ?>
                    <form method="POST" action="/determinations/element-flags" id="<?= $flagsId ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cas_number" value="<?= e($d['cas_number']) ?>">
                    </form>
                </td>
                <td>
                    <?php if ($isP65): ?>
                        <?= e($d['prop65_name']) ?>
                    <?php else: ?>
                        <form method="POST" action="/determinations/descriptions" id="<?= $formId ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="cas_number" value="<?= e($d['cas_number']) ?>">
                            <input type="text" name="description" value="<?= e($d['preferred_name'] ?? '') ?>" required style="width: 100%;">
                        </form>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isP65): ?>
                        <span class="badge badge-warning" title="Description managed on the Prop 65 page">Prop 65</span>
                    <?php else: ?>
                        <span class="text-muted">Registry</span>
                    <?php endif; ?>
                </td>
                <td><?= (int) $d['rm_count'] > 0 ? (int) $d['rm_count'] : '<span class="text-muted">—</span>' ?></td>
                <?php foreach (['has_nitrogen' => 'nitrogen', 'has_sulfur' => 'sulfur', 'has_halogen' => 'halogen'] as $flag => $flagLabel): ?>
                    <td style="text-align: center;">
                        <input type="checkbox" name="<?= $flag ?>" value="1" form="<?= $flagsId ?>" <?= !empty($d[$flag]) ? 'checked' : '' ?> title="Contains <?= $flagLabel ?>">
                    </td>
                <?php endforeach; ?>
                <td>
                    <form method="POST" action="/determinations/tc-metals" style="display: flex; gap: 0.25rem; margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cas_number" value="<?= e($d['cas_number']) ?>">
                        <input type="text" name="tc_metals" value="<?= e($d['tc_metals'] ?? '') ?>" placeholder="none" style="width: 80px;" title="As, Ba, Cd, Cr, Pb, Hg, Se, Ag (comma-separated); blank = none">
                        <button type="submit" class="btn btn-sm" title="Save RCRA metals<?= !empty($d['tc_metals_source']) ? ' (source: ' . e($d['tc_metals_source']) . ')' : '' ?>">Set</button>
                    </form>
                </td>
                <td style="text-align: center;">
                    <?php
                    // A12(a): checked = effective state (stored flag, else old CAS list / name rule on the sheet name)
                    $acrStored = $d['is_acrylate'] ?? null;
                    $acrName   = trim((string) ($d['prop65_name'] ?? '')) ?: trim((string) ($d['preferred_name'] ?? ''));
                    $acrOn     = \SDS\Services\UVAcrylateRulePack::effectiveFlag($acrStored, (string) $d['cas_number'], $acrName);
                    $acrSrc    = ($acrStored === null || $acrStored === '') ? 'auto' : (string) ($d['acrylate_source'] ?? '');
                    ?>
                    <form method="POST" action="/determinations/acrylate" style="display: inline-flex; gap: 0.25rem; align-items: center; margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cas_number" value="<?= e($d['cas_number']) ?>">
                        <input type="checkbox" name="is_acrylate" value="1" <?= $acrOn ? 'checked' : '' ?> title="Reactive acrylate / methacrylate (source: <?= e($acrSrc) ?>)">
                        <button type="submit" class="btn btn-sm" title="Save acrylate flag (source: <?= e($acrSrc) ?>)">Set</button>
                    </form>
                </td>
                <td>
                    <?php if ($isP65): ?>
                        <a href="/prop65" class="btn btn-sm">Edit on Prop 65</a>
                    <?php else: ?>
                        <button type="submit" form="<?= $formId ?>" class="btn btn-sm btn-primary">Save</button>
                    <?php endif; ?>
                    <button type="submit" form="<?= $flagsId ?>" class="btn btn-sm" title="Save N / S / Hal flags<?= !empty($d['element_flags_source']) ? ' (source: ' . e($d['element_flags_source']) . ')' : '' ?>">Flags</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Tab: TSCA Review (audit #29) -->
<div class="tab-panel" id="tab-tsca" style="display: none;">
    <p class="text-muted" style="margin-bottom: 0.5rem;">
        Constituent CAS numbers in use that are <strong>not on the <a href="/tsca">TSCA inventory</a></strong> and have no override.
        Products containing them print "TSCA inventory status has not been verified for all components" in Section 15 and raise a publish warning.
        Set <em>Listed</em> for crossover / confidential-inventory CAS, <em>Exempt</em> for exemptions (e.g. polymer exemption), or <em>Not listed</em> when verified absent. A note is required. <em>Auto</em> removes the override.
    </p>
    <div style="margin-bottom: 0.5rem;">
        <input type="text" id="tscaFilter" placeholder="Filter by CAS, description or raw material..." style="max-width: 360px;">
    </div>
    <?php if (empty($tscaReview)): ?>
        <div style="text-align: center; padding: 1.5rem; color: #28a745;"><strong>Every constituent CAS in use is on the TSCA inventory or has an override.</strong><?= !empty($tscaRmReview) ? '<br><span style="color:#fd7e14;">Some raw materials still cannot be checked by CAS: see the list below.</span>' : '' ?></div>
    <?php else: ?>
    <table class="table" id="tscaTable">
        <thead><tr><th style="width:130px;">CAS Number</th><th>Description</th><th>Raw Materials</th><th style="width:90px;">In Formula</th><th style="width:420px;">TSCA Override</th></tr></thead>
        <tbody>
        <?php foreach ($tscaReview as $idx => $r): ?>
            <?php $formId = 'tsca-form-' . $idx; ?>
            <tr>
                <td><strong><?= e($r['cas_number']) ?></strong></td>
                <td><?= e($r['chemical_name'] ?? '') ?></td>
                <td><span title="<?= e($r['raw_material_codes'] ?? '') ?>"><?= e($r['raw_material_codes'] ?? '') ?></span>
                    <?php if ((int) ($r['raw_material_count'] ?? 0) > 1): ?><small class="text-muted">(<?= (int) $r['raw_material_count'] ?> materials)</small><?php endif; ?></td>
                <td><?= (int) ($r['in_formula'] ?? 0) ? '<span style="color:#28a745;font-weight:bold;">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
                <td>
                    <form method="POST" action="/determinations/tsca" id="<?= $formId ?>" style="display:flex; gap:0.35rem; align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cas_number" value="<?= e($r['cas_number']) ?>">
                        <select name="tsca_status">
                            <?php foreach ($tscaOptions as $val => $label): ?>
                                <option value="<?= e($val) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="tsca_note" placeholder="Note (required)" style="flex:1;">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h4 style="margin-top:1.5rem;">Raw materials that cannot be checked by CAS <?php if (!empty($tscaRmReview)): ?><small class="text-muted">(<?= count($tscaRmReview) ?>)</small><?php endif; ?></h4>
    <p class="text-muted" style="margin-bottom: 0.5rem;">
        These have no CAS for some or all of their components (a trade secret, or constituent data not entered), so a CAS override cannot clear them and every product
        using them prints "TSCA inventory status has not been verified for all components". Enter the missing constituents on the raw material, or, when the supplier's SDS
        certifies that every component is on the TSCA inventory (or exempt), set <em>Listed</em> or <em>Exempt</em> here with that basis as the note. It then covers all of the raw material's components.
    </p>
    <?php if (empty($tscaRmEnabled)): ?>
        <p class="text-muted">Available after the database update (migration 066).</p>
    <?php elseif (empty($tscaRmReview)): ?>
        <p style="color: #28a745;"><strong>Every raw material in use can be checked by CAS or has a raw-material override.</strong></p>
    <?php else: ?>
    <table class="table" id="tscaRmTable">
        <thead><tr><th style="width:140px;">Raw Material</th><th>Name</th><th>Why it cannot be checked</th><th style="width:90px;">In Formula</th><th style="width:420px;">Raw-material TSCA Override</th></tr></thead>
        <tbody>
        <?php foreach ($tscaRmReview as $idx => $r): ?>
            <tr>
                <td><strong><a href="/raw-materials/<?= (int) $r['id'] ?>/edit"><?= e($r['internal_code']) ?></a></strong></td>
                <td><?= e($r['name'] ?? '') ?></td>
                <td><small><?= e(implode('; ', array_map([\SDS\Services\TSCAService::class, 'noCasReasonLabel'], (array) $r['reasons']))) ?></small></td>
                <td><?= (int) ($r['in_formula'] ?? 0) ? '<span style="color:#28a745;font-weight:bold;">Yes</span>' : '<span class="text-muted">No (own sheet)</span>' ?></td>
                <td>
                    <form method="POST" action="/determinations/tsca-rm" style="display:flex; gap:0.35rem; align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="raw_material_id" value="<?= (int) $r['id'] ?>">
                        <select name="tsca_status">
                            <?php foreach ($tscaRmOptions as $val => $label): ?>
                                <option value="<?= e($val) ?>"<?= $val === 'listed' ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="tsca_note" placeholder="Basis, e.g. supplier SDS Section 15 (required)" style="flex:1;">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($tscaRmOverrides)): ?>
    <h4 style="margin-top:1.5rem;">Raw-material overrides in effect <small class="text-muted">(<?= count($tscaRmOverrides) ?>)</small></h4>
    <table class="table table-sm" id="tscaRmOverrideTable">
        <thead><tr><th style="width:140px;">Raw Material</th><th>Name</th><th style="width:110px;">Status</th><th>Note</th><th style="width:150px;">Set by</th><th style="width:150px;">Change</th></tr></thead>
        <tbody>
        <?php foreach ($tscaRmOverrides as $o): ?>
            <tr>
                <td><strong><a href="/raw-materials/<?= (int) $o['id'] ?>/edit"><?= e($o['internal_code']) ?></a></strong></td>
                <td><?= e($o['supplier_product_name'] ?? '') ?></td>
                <td><span class="badge badge-warning"><?= e($o['tsca_status']) ?></span></td>
                <td><?= e($o['tsca_note'] ?? '') ?></td>
                <td><small><?= e($o['tsca_updated_by_name'] ?? '—') ?><?= !empty($o['tsca_updated_at']) ? ' · ' . e(\SDS\Services\PublishClock::display($o['tsca_updated_at'], 'm/d/Y')) : '' ?></small></td>
                <td>
                    <form method="POST" action="/determinations/tsca-rm" style="display:flex; gap:0.35rem; align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="raw_material_id" value="<?= (int) $o['id'] ?>">
                        <input type="hidden" name="tsca_note" value="<?= e($o['tsca_note'] ?? '') ?>">
                        <select name="tsca_status">
                            <?php foreach ($tscaRmOptions as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($o['tsca_status'] ?? '') === $val ? 'selected' : '' ?>><?= e($val) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm">Save</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h4 style="margin-top:1.5rem;">Overrides in effect <?php if (!empty($tscaOverrides)): ?><small class="text-muted">(<?= count($tscaOverrides) ?>)</small><?php endif; ?></h4>
    <?php if (empty($tscaOverrides)): ?>
        <p class="text-muted">No TSCA overrides recorded.</p>
    <?php else: ?>
    <table class="table table-sm" id="tscaOverrideTable">
        <thead><tr><th style="width:130px;">CAS Number</th><th>Description</th><th style="width:110px;">Status</th><th>Note</th><th style="width:100px;">On inventory</th><th style="width:90px;">Used in RMs</th><th style="width:150px;">Set by</th><th style="width:150px;">Change</th></tr></thead>
        <tbody>
        <?php foreach ($tscaOverrides as $idx => $o): ?>
            <?php $formId = 'tsca-ov-' . $idx; ?>
            <tr>
                <td><strong><?= e($o['cas_number']) ?></strong></td>
                <td><?= e($o['preferred_name'] ?? '') ?></td>
                <td><span class="badge badge-warning"><?= e($o['tsca_status']) ?></span></td>
                <td><?= e($o['tsca_note'] ?? '') ?></td>
                <td><?= (int) ($o['on_inventory'] ?? 0) ? 'Yes' : '<span class="text-muted">No</span>' ?></td>
                <td><?= (int) ($o['rm_count'] ?? 0) > 0 ? (int) $o['rm_count'] : '<span class="text-muted">—</span>' ?></td>
                <td><small><?= e($o['tsca_updated_by_name'] ?? '—') ?><?= !empty($o['tsca_updated_at']) ? ' · ' . e(\SDS\Services\PublishClock::display($o['tsca_updated_at'], 'm/d/Y')) : '' ?></small></td>
                <td>
                    <form method="POST" action="/determinations/tsca" id="<?= $formId ?>" style="display:flex; gap:0.35rem; align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cas_number" value="<?= e($o['cas_number']) ?>">
                        <input type="hidden" name="tsca_note" value="<?= e($o['tsca_note'] ?? '') ?>">
                        <select name="tsca_status">
                            <?php foreach ($tscaOptions as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($o['tsca_status'] ?? '') === $val ? 'selected' : '' ?>><?= e($val) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm">Save</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Tab: STOT Target Organs (A12(b) / T4a) -->
<div class="tab-panel" id="tab-stot" style="display: none;">
    <p class="text-muted" style="margin-bottom: 0.5rem;">
        Target organs and route of exposure, from the vendor SDS (Sections 2 and 11), for substances classified for specific target organ toxicity.
        <strong>Single exposure</strong> organs complete H370 / H371; <strong>repeated exposure</strong> organs complete H372 / H373, e.g.
        "Causes damage to organs (lungs) through prolonged or repeated exposure by inhalation", in Section 2, the Section 4 / 11 symptoms and the Section 11 repeated-exposure line, in every language.
        Leave the organs blank to keep the generic statement. When several substances drive the same statement, organs print only when every one of them has organs entered (their union prints); the route prints only when they all state the same single route.
        Set a route only when the vendor SDS limits the hazard to that route; otherwise choose "All routes" or leave it blank. Saving bumps the affected raw materials for republish.
    </p>
    <?php
    $stotOrganList = $stotOrgans ?? [];
    $stotRouteList = $stotRoutes ?? [];
    $stotOrganSelect = static function (string $name, array $selected, string $formId) use ($stotOrganList): string {
        $h = '<select name="' . e($name) . '[]" multiple size="4" form="' . e($formId) . '" style="width: 100%;" title="Ctrl/Cmd-click to choose several; none selected = generic statement">';
        foreach ($stotOrganList as $key => $label) {
            $h .= '<option value="' . e((string) $key) . '"' . (in_array($key, $selected, true) ? ' selected' : '') . '>' . e((string) $label) . '</option>';
        }
        return $h . '</select>';
    };
    $stotRouteSelect = static function (string $name, ?string $current, string $formId) use ($stotRouteList): string {
        $h = '<select name="' . e($name) . '" form="' . e($formId) . '"><option value="">Not stated</option>';
        foreach ($stotRouteList as $key => $label) {
            $h .= '<option value="' . e((string) $key) . '"' . ($current === $key ? ' selected' : '') . '>' . e((string) $label) . '</option>';
        }
        return $h . '</select>';
    };
    ?>
    <div style="margin-bottom: 0.5rem;">
        <input type="text" id="stotFilter" placeholder="Filter by CAS or description..." style="max-width: 320px;">
    </div>
    <?php if (empty($stotRows)): ?>
        <p class="text-muted">No substance in use carries H370-H373, and no target organs have been entered.</p>
    <?php else: ?>
    <table class="table" id="stotTable">
        <thead>
            <tr>
                <th style="width: 130px;">CAS Number</th>
                <th>Description</th>
                <th style="width: 90px;" title="STOT codes in the substance's hazard data or determination">Codes</th>
                <th style="width: 70px;">Used in RMs</th>
                <th style="width: 200px;">Single exposure organs (H370/H371)</th>
                <th style="width: 120px;">SE route</th>
                <th style="width: 200px;">Repeated exposure organs (H372/H373)</th>
                <th style="width: 120px;">RE route</th>
                <th style="width: 70px;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($stotRows as $idx => $r): ?>
            <?php $formId = 'stot-form-' . $idx;
                  $codes  = array_filter([!empty($r['has_se']) ? 'H370/H371' : '', !empty($r['has_re']) ? 'H372/H373' : '']); ?>
            <tr>
                <td>
                    <strong><?= e($r['cas_number']) ?></strong>
                    <form method="POST" action="/determinations/stot" id="<?= $formId ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cas_number" value="<?= e($r['cas_number']) ?>">
                    </form>
                </td>
                <td><?= e($r['chemical_name'] ?? '') ?></td>
                <td><?= $codes !== [] ? e(implode(', ', $codes)) : '<span class="text-muted">—</span>' ?></td>
                <td><?= (int) ($r['rm_count'] ?? 0) > 0 ? (int) $r['rm_count'] : '<span class="text-muted">—</span>' ?></td>
                <td><?= $stotOrganSelect('stot_se_organs', \SDS\Services\StotTargetOrgans::parseOrgans($r['stot_se_organs'] ?? null), $formId) ?></td>
                <td><?= $stotRouteSelect('stot_se_route', $r['stot_se_route'] ?? null, $formId) ?></td>
                <td><?= $stotOrganSelect('stot_re_organs', \SDS\Services\StotTargetOrgans::parseOrgans($r['stot_re_organs'] ?? null), $formId) ?></td>
                <td><?= $stotRouteSelect('stot_re_route', $r['stot_re_route'] ?? null, $formId) ?></td>
                <td><button type="submit" form="<?= $formId ?>" class="btn btn-sm btn-primary">Save</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h4 style="margin-top: 1.5rem;">Another CAS</h4>
    <form method="POST" action="/determinations/stot" id="stot-form-new" style="display: flex; gap: 0.5rem; align-items: flex-start; flex-wrap: wrap;">
        <?= csrf_field() ?>
        <input type="text" name="cas_number" placeholder="CAS number" required style="width: 140px;">
        <div style="min-width: 200px;"><small>Single exposure (H370/H371)</small><br><?= $stotOrganSelect('stot_se_organs', [], 'stot-form-new') ?><br><?= $stotRouteSelect('stot_se_route', null, 'stot-form-new') ?></div>
        <div style="min-width: 200px;"><small>Repeated exposure (H372/H373)</small><br><?= $stotOrganSelect('stot_re_organs', [], 'stot-form-new') ?><br><?= $stotRouteSelect('stot_re_route', null, 'stot-form-new') ?></div>
        <button type="submit" class="btn btn-sm btn-primary">Save</button>
    </form>
    <script>
    (function() {
        var f = document.getElementById('stotFilter'), t = document.getElementById('stotTable');
        if (f && t) {
            f.addEventListener('input', function() {
                var term = this.value.toLowerCase();
                t.querySelectorAll('tbody tr').forEach(function(row) {
                    row.style.display = row.textContent.toLowerCase().indexOf(term) === -1 ? 'none' : '';
                });
            });
        }
    })();
    </script>
</div>

<script>
document.getElementById('descFilter').addEventListener('input', function() {
    var term = this.value.toLowerCase();
    document.querySelectorAll('#descTable tbody tr').forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().indexOf(term) === -1 &&
            !Array.from(row.querySelectorAll('input[name="description"]')).some(function(i) {
                return i.value.toLowerCase().indexOf(term) !== -1;
            }) ? 'none' : '';
    });
});

// TSCA Review filter (audit #29) — the table is absent when the list is empty.
(function() {
    var tscaFilter = document.getElementById('tscaFilter');
    var tscaTable  = document.getElementById('tscaTable');
    if (tscaFilter && tscaTable) {
        tscaFilter.addEventListener('input', function() {
            var term = this.value.toLowerCase();
            tscaTable.querySelectorAll('tbody tr').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().indexOf(term) === -1 ? 'none' : '';
            });
        });
    }
})();

document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(function(b) {
            b.style.background = '#e9ecef';
            b.style.color = '#003366';
            b.classList.remove('active');
        });
        btn.style.background = '#003366';
        btn.style.color = '#fff';
        btn.classList.add('active');

        document.querySelectorAll('.tab-panel').forEach(function(p) {
            p.style.display = 'none';
        });
        document.getElementById('tab-' + btn.dataset.tab).style.display = 'block';
    });
});

// Activate a tab from ?tab= so redirects land back where the user was
(function() {
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab) {
        var target = document.querySelector('.tab-btn[data-tab="' + urlTab + '"]');
        if (target) target.click();
    }
})();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
