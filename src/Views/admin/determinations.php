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
        <?php $tscaCount = count($tscaReview ?? []); ?>
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
    <?php $canEditTsca = can_edit('cas_determinations'); ?>
    <p class="text-muted" style="margin-bottom: 0.5rem;">
        Constituent CAS numbers that are provided but <strong>not on the <a href="/tsca">TSCA inventory</a></strong> and have no override: products containing them print
        "TSCA inventory status has not been verified for all components" in Section 15 and raise a publish warning. Only CAS numbers on a sheet
        <a href="/bulk-publish">Bulk SDS Publish</a> is cleared to publish are listed; the rest wait (see "Not listed yet").
        Components without a CAS (a trade secret, a raw material with no constituents, constituents that add up to less than 100%) are assumed listed and are not flagged;
        a value with digits that is not a CAS number (e.g. "13463677") is listed so it can be corrected.
        Set <em>Listed</em> for crossover / confidential-inventory CAS, <em>Exempt</em> for exemptions (e.g. polymer exemption), or <em>Not listed</em> when verified absent. A note is required. <em>Auto</em> removes the override.
    </p>
    <?php if (!empty($tscaNotCleared)): ?>
    <details class="text-muted" style="margin-bottom: 0.5rem;">
        <summary>Not listed yet: <?= count($tscaNotCleared) ?> raw material<?= count($tscaNotCleared) === 1 ? '' : 's' ?> with a CAS not on the inventory, only on sheets Bulk SDS Publish is not cleared to publish</summary>
        For example a product bulk publish skips because one of its raw materials has not been reviewed, an inactive product, a resale item with no data entered,
        or a raw material in no current formula and with no sheet of its own. They appear here once bulk publish is cleared to publish a sheet that carries them
        (<a href="/sds-review">SDS Creation Readiness Check</a>):
        <?= e(implode(', ', array_slice($tscaNotCleared, 0, 300))) ?><?= count($tscaNotCleared) > 300 ? ' … and ' . (count($tscaNotCleared) - 300) . ' more' : '' ?>
    </details>
    <?php endif; ?>
    <?php if ($canEditTsca): ?>
    <p class="text-muted" style="margin-bottom: 0.5rem;">
        To save several rows at once, tick them (changing a row's status or note ticks it), then use <strong>Save selected</strong> in the bar at the bottom:
        one save for all of them. The bar can also apply one status and note to every ticked row shown.
    </p>
    <form method="POST" action="/determinations/tsca-batch" id="tscaBatchForm" autocomplete="off" style="display: none;">
        <?= csrf_field() ?>
        <input type="hidden" name="payload" id="tscaBatchPayload" value="">
    </form>
    <?php endif; ?>
    <div style="margin-bottom: 0.5rem;">
        <input type="text" id="tscaFilter" placeholder="Filter by CAS, description or raw material..." style="max-width: 360px;">
    </div>
    <?php if (empty($tscaReview)): ?>
        <div style="text-align: center; padding: 1.5rem; color: #28a745;"><strong>Every constituent CAS on a sheet bulk publish is cleared to publish is on the TSCA inventory or has an override.</strong><?= !empty($tscaNotCleared) ? '<br><span style="color:#fd7e14;">Raw materials only on sheets bulk publish is not cleared to publish are not checked yet: see "Not listed yet" above.</span>' : '' ?></div>
    <?php else: ?>
    <table class="table" id="tscaTable">
        <thead><tr><?php if ($canEditTsca): ?><th style="width:30px;"><input type="checkbox" class="tsca-pick-all" title="Select every row shown in this list" autocomplete="off"></th><?php endif; ?><th style="width:130px;">CAS Number</th><th>Description</th><th>Raw Materials</th><th style="width:90px;">In Formula</th><th style="width:420px;">TSCA Override</th></tr></thead>
        <tbody>
        <?php foreach ($tscaReview as $idx => $r): ?>
            <?php $formId = 'tsca-form-' . $idx; ?>
            <?php if (!empty($r['invalid'])): // a provided CAS that is not a CAS number: correct it on the raw material ?>
            <tr>
                <?php if ($canEditTsca): ?><td></td><?php endif; ?>
                <td><strong><?= e($r['cas_number']) ?></strong><br><small style="color:#dc3545;">not a valid CAS number</small></td>
                <td><?= e($r['chemical_name'] ?? '') ?></td>
                <td><?= e($r['raw_material_codes'] ?? '') ?></td>
                <td><?= (int) ($r['in_formula'] ?? 0) ? '<span style="color:#28a745;font-weight:bold;">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
                <td>Correct the CAS on
                    <?php $i = 0; foreach ((array) ($r['raw_materials'] ?? []) as $code => $rmId): ?><?= $i++ > 0 ? ', ' : '' ?><a href="/raw-materials/<?= (int) $rmId ?>/edit"><?= e((string) $code) ?></a><?php endforeach; ?>
                    (Raw Materials &gt; Constituents); an override cannot be set on it.</td>
            </tr>
            <?php continue; endif; ?>
            <tr data-tsca-kind="cas" data-tsca-key="<?= e($r['cas_number']) ?>" data-tsca-label="CAS <?= e($r['cas_number']) ?>">
                <?php if ($canEditTsca): ?><td><input type="checkbox" class="tsca-pick" aria-label="Select CAS <?= e($r['cas_number']) ?>" autocomplete="off"></td><?php endif; ?>
                <td><strong><?= e($r['cas_number']) ?></strong></td>
                <td><?= e($r['chemical_name'] ?? '') ?></td>
                <td><span title="<?= e($r['raw_material_codes'] ?? '') ?>"><?= e($r['raw_material_codes'] ?? '') ?></span>
                    <?php if ((int) ($r['raw_material_count'] ?? 0) > 1): ?><small class="text-muted">(<?= (int) $r['raw_material_count'] ?> materials)</small><?php endif; ?></td>
                <td><?= (int) ($r['in_formula'] ?? 0) ? '<span style="color:#28a745;font-weight:bold;">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
                <td>
                    <form method="POST" action="/determinations/tsca" id="<?= $formId ?>" autocomplete="off" style="display:flex; gap:0.35rem; align-items:center;">
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
                    <form method="POST" action="/determinations/tsca-rm" autocomplete="off" style="display:flex; gap:0.35rem; align-items:center;">
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
                    <form method="POST" action="/determinations/tsca" id="<?= $formId ?>" autocomplete="off" style="display:flex; gap:0.35rem; align-items:center;">
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

    <?php if ($canEditTsca): ?>
    <div id="tscaBatchBar" style="display: none; position: sticky; bottom: 0; z-index: 10; margin-top: 1rem; padding: 0.6rem 0.75rem; gap: 0.5rem; align-items: center; flex-wrap: wrap;
                                  background: #fff8e6; border: 1px solid #fd7e14; border-radius: 4px; box-shadow: 0 -2px 6px rgba(0,0,0,0.12);">
        <strong><span id="tscaBatchCount">0</span> selected</strong><span id="tscaBatchHidden" class="text-muted"></span>
        <span class="text-muted">Apply to the selected rows:</span>
        <select id="tscaFillStatus" aria-label="Status for the selected rows" autocomplete="off">
            <option value="">Status: keep each row's</option>
            <option value="listed">Listed</option>
            <option value="exempt">Exempt</option>
            <option value="not_listed">Not listed</option>
        </select>
        <input type="text" id="tscaFillNote" maxlength="500" placeholder="Note for the selected rows" aria-label="Note for the selected rows" style="min-width: 260px; flex: 1;">
        <button type="button" id="tscaFillApply" class="btn btn-sm">Apply</button>
        <button type="button" id="tscaBatchSave" class="btn btn-sm btn-primary">Save selected</button>
        <button type="button" id="tscaBatchClear" class="btn btn-sm">Clear selection</button>
    </div>
    <?php endif; ?>
</div>

<!-- Tab: STOT Target Organs (A12(b) / T4a; redesigned 2026-10-21: tick boxes, edited in place) -->
<div class="tab-panel" id="tab-stot" style="display: none;">
    <?php
    $canEditStot   = can_edit('cas_determinations');
    $stotOrganList = $stotOrgans ?? [];
    $stotRouteList = $stotRoutes ?? [];
    // The summary the script also writes after an edit (keep the two in step).
    $stotSummary = static function (array $organs, ?string $route, bool $classified) use ($stotOrganList, $stotRouteList): string {
        if ($organs === []) {
            return '<span class="text-muted">' . ($classified ? 'Generic statement (no organs)' : '—') . '</span>';
        }
        $h = e(implode(', ', array_map(static fn (string $k): string => (string) ($stotOrganList[$k] ?? $k), $organs)));
        return $route !== null ? $h . '<br><small class="text-muted">' . e((string) ($stotRouteList[$route] ?? $route)) . '</small>' : $h;
    };
    $stotPanel = static function (string $x, string $title, array $organs, ?string $route, string $note) use ($stotOrganList, $stotRouteList): string {
        $h = '<fieldset class="stot-panel" data-x="' . e($x) . '" style="flex: 1; min-width: 320px; border: 1px solid #ccd5e0; border-radius: 4px; padding: 0.5rem 0.75rem;">'
           . '<legend style="font-size: 0.95rem; font-weight: bold; width: auto; padding: 0 0.25rem; margin: 0;">' . e($title) . '</legend>'
           . ($note !== '' ? '<div class="text-muted" style="margin-bottom: 0.25rem;"><small>' . e($note) . '</small></div>' : '')
           . '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); gap: 2px 12px;">';
        foreach ($stotOrganList as $key => $label) {
            $h .= '<label style="margin: 0; font-weight: normal;"><input type="checkbox" name="stot_' . e($x) . '_organs[]" value="' . e((string) $key) . '"'
                . (in_array($key, $organs, true) ? ' checked' : '') . ' autocomplete="off"> ' . e((string) $label) . '</label>';
        }
        $h .= '</div><div style="margin-top: 0.4rem; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">'
            . '<label style="margin: 0;">Route <select name="stot_' . e($x) . '_route" class="stot-route" autocomplete="off"' . ($organs === [] ? ' disabled' : '') . '><option value="">Not stated</option>';
        foreach ($stotRouteList as $key => $label) {
            $h .= '<option value="' . e((string) $key) . '"' . ($route === $key ? ' selected' : '') . '>' . e((string) $label) . '</option>';
        }
        return $h . '</select></label><button type="button" class="btn btn-sm stot-clear">Untick all</button></div></fieldset>';
    };
    ?>
    <p class="text-muted" style="margin-bottom: 0.5rem;">
        Target organs and route of exposure from the vendor SDS (Sections 2 and 11), for substances classified for specific target organ toxicity.
        <strong>Single exposure</strong> organs complete H370 / H371 and <strong>repeated exposure</strong> organs complete H372 / H373, e.g.
        "Causes damage to organs (lungs) through prolonged or repeated exposure by inhalation", in Section 2, the Section 4 / 11 symptoms and Section 11, in every language.
        With no organs ticked the generic statement prints. When several substances drive the same statement, organs print only when every one of them has organs (their union prints), and the route only when they all state the same single route.
        Set a route only when the vendor SDS limits the hazard to that route. Saving flags the affected raw materials for republish.
    </p>
    <?php if ($canEditStot): ?>
    <p class="text-muted" style="margin-bottom: 0.5rem;">
        Click <strong>Edit</strong> on a substance to tick or untick its organs. Save one substance, or edit several and use <strong>Save all changes</strong> in the bar at the bottom.
    </p>
    <?php endif; ?>
    <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.5rem;">
        <input type="text" id="stotFilter" placeholder="Filter by CAS, description or organ..." style="max-width: 320px;">
        <label style="margin: 0;"><input type="checkbox" id="stotOnlyMissing" autocomplete="off"> Only substances still without organs</label>
    </div>
    <?php if (empty($stotRows)): ?>
        <p class="text-muted">No substance in use carries H370-H373, and no target organs have been entered.</p>
    <?php else: ?>
    <table class="table" id="stotTable">
        <thead>
            <tr>
                <th style="width: 130px;">CAS Number</th>
                <th>Description</th>
                <th style="width: 100px;" title="STOT codes in the substance's hazard data or determination">Classified</th>
                <th style="width: 70px;">Used in RMs</th>
                <th>Single exposure organs (H370/H371)</th>
                <th>Repeated exposure organs (H372/H373)</th>
                <?php if ($canEditStot): ?><th style="width: 110px;"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($stotRows as $r): ?>
            <?php
            $se      = \SDS\Services\StotTargetOrgans::parseOrgans($r['stot_se_organs'] ?? null);
            $re      = \SDS\Services\StotTargetOrgans::parseOrgans($r['stot_re_organs'] ?? null);
            $seRoute = $se === [] ? null : \SDS\Services\StotTargetOrgans::normaliseRoute($r['stot_se_route'] ?? null);
            $reRoute = $re === [] ? null : \SDS\Services\StotTargetOrgans::normaliseRoute($r['stot_re_route'] ?? null);
            $hasSe   = !empty($r['has_se']);
            $hasRe   = !empty($r['has_re']);
            $codes   = array_filter([$hasSe ? 'H370/H371' : '', $hasRe ? 'H372/H373' : '']);
            $state   = ['cas' => (string) $r['cas_number'], 'se' => $se, 'seRoute' => $seRoute, 're' => $re, 'reRoute' => $reRoute, 'hasSe' => (int) $hasSe, 'hasRe' => (int) $hasRe];
            $missing = ($hasSe && $se === []) || ($hasRe && $re === []);
            ?>
            <tr data-stot="<?= e((string) json_encode($state)) ?>" data-stot-missing="<?= $missing ? 1 : 0 ?>">
                <td><strong><?= e($r['cas_number']) ?></strong></td>
                <td><?= e($r['chemical_name'] ?? '') ?></td>
                <td><?= $codes !== [] ? e(implode(', ', $codes)) : '<span class="text-muted">—</span>' ?></td>
                <td><?= (int) ($r['rm_count'] ?? 0) > 0 ? (int) $r['rm_count'] : '<span class="text-muted">—</span>' ?></td>
                <td class="stot-sum-se"><?= $stotSummary($se, $seRoute, $hasSe) ?></td>
                <td class="stot-sum-re"><?= $stotSummary($re, $reRoute, $hasRe) ?></td>
                <?php if ($canEditStot): ?>
                <td><button type="button" class="btn btn-sm stot-edit">Edit</button>
                    <span class="badge badge-warning stot-changed" style="display: none;">changed</span></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($canEditStot): ?>
    <form method="POST" action="/determinations/stot-batch" id="stotBatchForm" autocomplete="off" style="display: none;">
        <?= csrf_field() ?>
        <input type="hidden" name="payload" id="stotBatchPayload" value="">
    </form>
    <script type="application/json" id="stotMeta"><?= json_encode(['organs' => $stotOrganList, 'routes' => $stotRouteList], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <h4 style="margin-top: 1.5rem;">Another CAS</h4>
    <form method="POST" action="/determinations/stot" id="stot-form-new" autocomplete="off">
        <?= csrf_field() ?>
        <label style="margin-bottom: 0.5rem;">CAS number <input type="text" name="cas_number" placeholder="e.g. 7631-86-9" required style="width: 150px;"></label>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
            <?= $stotPanel('se', 'Single exposure (H370/H371)', [], null, '') ?>
            <?= $stotPanel('re', 'Repeated exposure (H372/H373)', [], null, '') ?>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Save</button>
    </form>

    <div id="stotBatchBar" style="display: none; position: sticky; bottom: 0; z-index: 10; margin-top: 1rem; padding: 0.6rem 0.75rem; gap: 0.5rem; align-items: center; flex-wrap: wrap;
                                  background: #fff8e6; border: 1px solid #fd7e14; border-radius: 4px; box-shadow: 0 -2px 6px rgba(0,0,0,0.12);">
        <strong><span id="stotDirtyCount">0</span> substance(s) changed</strong>
        <button type="button" id="stotSaveAll" class="btn btn-sm btn-primary">Save all changes</button>
        <button type="button" id="stotUndoAll" class="btn btn-sm">Undo all</button>
    </div>
    <?php endif; ?>
    <script>
    // Shared by the STOT and TSCA tab scripts (2026-10-21): each reports its
    // unsaved work; a deliberate save asks about the other's (the reload
    // loses it) and then switches both unsaved-changes guards off.
    window.sdsPage = window.sdsPage || {
        pending: {},
        submitting: false,
        leaveOk: function(self) {
            var lost = [], p = this.pending;
            Object.keys(p).forEach(function(k) { if (k !== self) { var m = p[k](); if (m) lost.push(m); } });
            if (lost.length && !confirm('Saving reloads the page, so these unsaved changes elsewhere on it will be lost: ' + lost.join('; ') + '. Continue?')) return false;
            this.submitting = true;
            return true;
        }
    };
    // STOT tab (2026-10-21): filter; tick boxes per organ, edited in place and
    // saved per substance or all at once as one JSON field (stot-batch).
    (function() {
        var table = document.getElementById('stotTable');
        var filter = document.getElementById('stotFilter'), onlyMissing = document.getElementById('stotOnlyMissing');
        function rows() { return table ? Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-stot]')) : []; }
        function applyFilter() {
            var term = (filter ? filter.value : '').toLowerCase(), missing = !!(onlyMissing && onlyMissing.checked);
            rows().forEach(function(tr) {
                var show = tr.textContent.toLowerCase().indexOf(term) !== -1 && (!missing || tr.getAttribute('data-stot-missing') === '1');
                tr.style.display = show ? '' : 'none';
                if (tr.stotEditor) tr.stotEditor.style.display = show && tr.stotOpen ? '' : 'none';
            });
        }
        if (filter) filter.addEventListener('input', applyFilter);
        if (onlyMissing) onlyMissing.addEventListener('change', applyFilter);

        // A route needs an organ: disabled while none is ticked (not saved
        // then; it keeps its value, so re-ticking an organ brings it back).
        function syncRoutes(scope) {
            scope.querySelectorAll('fieldset.stot-panel').forEach(function(fs) {
                fs.querySelector('.stot-route').disabled = !fs.querySelector('input[type=checkbox]:checked');
            });
        }
        function wireClear(scope, after) {
            scope.querySelectorAll('.stot-clear').forEach(function(b) {
                b.addEventListener('click', function() {
                    b.closest('fieldset').querySelectorAll('input[type=checkbox]').forEach(function(c) { c.checked = false; });
                    syncRoutes(scope);
                    if (after) after();
                });
            });
        }
        var newForm = document.getElementById('stot-form-new');
        if (newForm) {
            newForm.addEventListener('change', function() { syncRoutes(newForm); });
            wireClear(newForm, null);
            newForm.addEventListener('submit', function(ev) { if (!window.sdsPage.leaveOk('stot-new')) ev.preventDefault(); });
        }

        var form = document.getElementById('stotBatchForm'), bar = document.getElementById('stotBatchBar'), metaEl = document.getElementById('stotMeta');
        if (!table || !form || !bar || !metaEl) return;
        var meta = JSON.parse(metaEl.textContent), submitting = false;
        var EXP = [['se', 'Single exposure (H370/H371)', 'hasSe', 'Not classified for single exposure: organs entered here print only once it is.'],
                   ['re', 'Repeated exposure (H372/H373)', 'hasRe', 'Not classified for repeated exposure: organs entered here print only once it is.']];
        function orig(tr) { return JSON.parse(tr.getAttribute('data-stot')); }
        function cur(tr) { return tr.stotState || orig(tr); }
        function same(a, b) {
            return a.se.join() === b.se.join() && a.re.join() === b.re.join() && (a.seRoute || null) === (b.seRoute || null) && (a.reRoute || null) === (b.reRoute || null);
        }
        function isDirty(tr) { return !!tr.stotState && !same(tr.stotState, orig(tr)); }
        function dirty() { return rows().filter(isDirty); }
        window.sdsPage.pending.stot = function() { var n = dirty().length; return n ? n + ' changed STOT substance(s)' : ''; };
        function esc(v) { var d = document.createElement('div'); d.textContent = v; return d.innerHTML; }
        function summary(organs, route, classified) {
            if (!organs.length) return '<span class="text-muted">' + (classified ? 'Generic statement (no organs)' : '—') + '</span>';
            var h = esc(organs.map(function(k) { return meta.organs[k] || k; }).join(', '));
            return route ? h + '<br><small class="text-muted">' + esc(meta.routes[route] || route) + '</small>' : h;
        }
        function refresh(tr) {
            var st = cur(tr), o = orig(tr);
            tr.querySelector('.stot-sum-se').innerHTML = summary(st.se, st.seRoute, !!o.hasSe);
            tr.querySelector('.stot-sum-re').innerHTML = summary(st.re, st.reRoute, !!o.hasRe);
            tr.querySelector('.stot-changed').style.display = isDirty(tr) ? '' : 'none';
            var n = dirty().length;
            document.getElementById('stotDirtyCount').textContent = n;
            bar.style.display = n > 0 ? 'flex' : 'none';
        }
        function panel(x, st, o) {
            var h = '<fieldset class="stot-panel" data-x="' + x[0] + '" style="flex: 1; min-width: 320px; border: 1px solid #ccd5e0; border-radius: 4px; padding: 0.5rem 0.75rem;">'
                + '<legend style="font-size: 0.95rem; font-weight: bold; width: auto; padding: 0 0.25rem; margin: 0;">' + x[1] + '</legend>'
                + (o[x[2]] ? '' : '<div class="text-muted" style="margin-bottom: 0.25rem;"><small>' + x[3] + '</small></div>')
                + '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); gap: 2px 12px;">';
            Object.keys(meta.organs).forEach(function(k) {
                h += '<label style="margin: 0; font-weight: normal;"><input type="checkbox" value="' + esc(k) + '"' + (st[x[0]].indexOf(k) >= 0 ? ' checked' : '')
                    + ' autocomplete="off"> ' + esc(meta.organs[k]) + '</label>';
            });
            h += '</div><div style="margin-top: 0.4rem; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">'
                + '<label style="margin: 0;">Route <select class="stot-route" autocomplete="off"><option value="">Not stated</option>';
            Object.keys(meta.routes).forEach(function(k) {
                h += '<option value="' + esc(k) + '"' + (st[x[0] + 'Route'] === k ? ' selected' : '') + '>' + esc(meta.routes[k]) + '</option>';
            });
            return h + '</select></label><button type="button" class="btn btn-sm stot-clear">Untick all</button></div></fieldset>';
        }
        function editor(tr) {
            var st = cur(tr), o = orig(tr);
            var ed = document.createElement('tr'), td = document.createElement('td');
            ed.className = 'stot-editor';
            td.colSpan = tr.children.length;
            td.style.background = '#f8f9fb';
            td.innerHTML = '<div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.5rem;">' + EXP.map(function(x) { return panel(x, st, o); }).join('') + '</div>'
                + '<div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">'
                + '<button type="button" class="btn btn-sm btn-primary stot-save-one">Save this substance</button>'
                + '<button type="button" class="btn btn-sm stot-undo">Undo changes</button>'
                + '<button type="button" class="btn btn-sm stot-close">Close</button></div>';
            ed.appendChild(td);
            function read() {
                syncRoutes(td);
                var s2 = {se: [], re: [], seRoute: null, reRoute: null};
                td.querySelectorAll('fieldset.stot-panel').forEach(function(fs) {
                    var x = fs.getAttribute('data-x');
                    fs.querySelectorAll('input[type=checkbox]:checked').forEach(function(c) { s2[x].push(c.value); });
                    s2[x] = Object.keys(meta.organs).filter(function(k) { return s2[x].indexOf(k) >= 0; });   // ORGANS order
                    var rs = fs.querySelector('.stot-route');
                    s2[x + 'Route'] = s2[x].length && rs.value ? rs.value : null;   // no organs: no route
                });
                tr.stotState = s2;
                refresh(tr);
            }
            td.addEventListener('change', read);
            wireClear(td, read);
            syncRoutes(td);
            td.querySelector('.stot-save-one').addEventListener('click', function() { save([tr], this); });
            td.querySelector('.stot-undo').addEventListener('click', function() {
                tr.stotState = null;
                ed.remove();
                tr.stotEditor = editor(tr);
                tr.stotOpen = true;
                refresh(tr);
            });
            td.querySelector('.stot-close').addEventListener('click', function() { tr.stotOpen = false; ed.style.display = 'none'; });
            tr.parentNode.insertBefore(ed, tr.nextSibling);
            return ed;
        }
        function save(list, btn) {
            list = list.filter(isDirty);
            if (!list.length) { alert('Nothing has changed.'); return; }
            // Saving reloads the page, so other changed substances are saved
            // together or not at all (never dropped without a word).
            var all = dirty();
            if (all.length > list.length) {
                if (!confirm('You have changed ' + all.length + ' substances. Saving reloads the page, so they are saved together. Save all ' + all.length + '?')) return;
                list = all;
            }
            if (!window.sdsPage.leaveOk('stot')) return;
            document.getElementById('stotBatchPayload').value = JSON.stringify({rows: list.map(function(tr) {
                var st = cur(tr);
                return {cas_number: orig(tr).cas, stot_se_organs: st.se, stot_se_route: st.seRoute, stot_re_organs: st.re, stot_re_route: st.reRoute};
            })});
            submitting = true;
            if (btn) btn.disabled = true;
            form.submit();
        }
        rows().forEach(function(tr) {
            tr.querySelector('.stot-edit').addEventListener('click', function() {
                if (tr.stotEditor && tr.stotOpen) {
                    tr.stotOpen = false;
                    tr.stotEditor.style.display = 'none';
                    return;
                }
                tr.stotEditor = tr.stotEditor || editor(tr);
                tr.stotOpen = true;
                tr.stotEditor.style.display = '';
            });
        });
        document.getElementById('stotSaveAll').addEventListener('click', function() { save(dirty(), this); });
        document.getElementById('stotUndoAll').addEventListener('click', function() {
            var n = dirty().length;
            if (n > 0 && !confirm('Discard the unsaved changes to ' + n + ' substance(s)? This includes any hidden by the filter.')) return;
            rows().forEach(function(tr) {
                tr.stotState = null;
                if (tr.stotEditor) { tr.stotEditor.remove(); tr.stotEditor = null; tr.stotOpen = false; }
                refresh(tr);
            });
        });
        window.addEventListener('beforeunload', function(ev) {
            if (!submitting && !window.sdsPage.submitting && dirty().length > 0) { ev.preventDefault(); ev.returnValue = ''; }
        });
        window.addEventListener('pageshow', function(ev) {
            if (ev.persisted) {
                submitting = false;
                window.sdsPage.submitting = false;
                document.querySelectorAll('#stotSaveAll, .stot-save-one').forEach(function(b) { b.disabled = false; });
            }
        });
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

// TSCA Review batch save (2026-10-21): tick rows, edit each (or apply one
// status / note to all ticked rows), then save them in one request. The rows
// go as one JSON field, so a long selection never meets max_input_vars.
(function() {
    var panel = document.getElementById('tab-tsca');
    var form  = document.getElementById('tscaBatchForm');
    var bar   = document.getElementById('tscaBatchBar');
    if (!panel || !form || !bar) return;
    var submitting = false;
    function rows() { return Array.prototype.slice.call(panel.querySelectorAll('tr[data-tsca-kind]')); }
    function box(r) { return r.querySelector('.tsca-pick'); }
    function picked() { return rows().filter(function(r) { return box(r) && box(r).checked; }); }
    function shown(r) { return r.style.display !== 'none'; }
    function refresh() {
        var n = picked().length, hidden = picked().filter(function(r) { return !shown(r); }).length;
        document.getElementById('tscaBatchCount').textContent = n;
        document.getElementById('tscaBatchHidden').textContent = hidden ? ' (' + hidden + ' hidden by the filter, saved too)' : '';
        bar.style.display = n > 0 ? 'flex' : 'none';
    }
    function short(list) { return list.slice(0, 10).join(', ') + (list.length > 10 ? ' … (+' + (list.length - 10) + ' more)' : ''); }
    rows().forEach(function(r) {
        r.querySelectorAll('select[name="tsca_status"], input[name="tsca_note"]').forEach(function(el) {
            el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', function() {
                if (box(r)) box(r).checked = true;
                refresh();
            });
        });
        if (box(r)) box(r).addEventListener('change', refresh);
        var single = r.querySelector('form');
        if (single) single.addEventListener('submit', function(ev) {
            var others = picked().filter(function(o) { return o !== r; }).length;
            if (others > 0 && !confirm('Save only this row? Saving reloads the page, so your unsaved edits to the other ' + others + ' ticked row(s) will be lost. Cancel, then use "Save selected" to save them together.')) {
                ev.preventDefault();
                return;
            }
            if (window.sdsPage && !window.sdsPage.leaveOk('tsca')) {
                ev.preventDefault();
                return;
            }
            submitting = true;
        });
    });
    panel.querySelectorAll('.tsca-pick-all').forEach(function(all) {
        all.addEventListener('change', function() {
            all.closest('table').querySelectorAll('tbody tr[data-tsca-kind]').forEach(function(r) {
                if (r.style.display !== 'none' && box(r)) box(r).checked = all.checked;
            });
            refresh();
        });
    });
    // Apply changes only ticked rows that are shown. A row that does not
    // offer the chosen status (Not listed on a raw material) is left alone
    // and unticked, so it never saves with a note meant for another status.
    document.getElementById('tscaFillApply').addEventListener('click', function() {
        var st   = document.getElementById('tscaFillStatus').value;
        var note = document.getElementById('tscaFillNote').value;
        var skipped = [];
        picked().filter(shown).forEach(function(r) {
            var sel = r.querySelector('select[name="tsca_status"]');
            if (st && sel && !sel.querySelector('option[value="' + st + '"]')) {
                skipped.push(r.getAttribute('data-tsca-label'));
                box(r).checked = false;
                return;
            }
            if (st && sel) sel.value = st;
            var n = r.querySelector('input[name="tsca_note"]');
            if (n && note.trim() !== '') n.value = note;
        });
        refresh();
        if (skipped.length) {
            alert(skipped.length + ' raw-material row(s) were left unchanged and unticked: "Not listed" applies to CAS rows only (' + short(skipped) + ').');
        }
    });
    var filter = document.getElementById('tscaFilter');
    if (filter) filter.addEventListener('input', refresh);
    document.getElementById('tscaBatchClear').addEventListener('click', function() {
        rows().forEach(function(r) { if (box(r)) box(r).checked = false; r.style.outline = ''; });
        panel.querySelectorAll('.tsca-pick-all').forEach(function(a) { a.checked = false; });
        refresh();
    });
    document.getElementById('tscaBatchSave').addEventListener('click', function() {
        var payload = {cas: [], rm: []}, noStatus = [], noNote = [];
        picked().forEach(function(r) {
            var st    = r.querySelector('select[name="tsca_status"]').value;
            var note  = r.querySelector('input[name="tsca_note"]').value.trim();
            var label = r.getAttribute('data-tsca-label');
            r.style.outline = '';
            if (st === 'auto') { noStatus.push(label); r.style.outline = '2px solid #dc3545'; return; }
            if (note === '')   { noNote.push(label);   r.style.outline = '2px solid #dc3545'; return; }
            if (r.getAttribute('data-tsca-kind') === 'cas') {
                payload.cas.push({cas_number: r.getAttribute('data-tsca-key'), tsca_status: st, tsca_note: note});
            } else {
                payload.rm.push({raw_material_id: parseInt(r.getAttribute('data-tsca-key'), 10), tsca_status: st, tsca_note: note});
            }
        });
        if (noStatus.length || noNote.length) {
            alert((noStatus.length ? 'Choose Listed, Exempt or Not listed for: ' + short(noStatus) + '\n' : '')
                + (noNote.length ? 'A note is required for: ' + short(noNote) : ''));
            return;
        }
        if (payload.cas.length + payload.rm.length === 0) return;
        if (window.sdsPage && !window.sdsPage.leaveOk('tsca')) return;
        document.getElementById('tscaBatchPayload').value = JSON.stringify(payload);
        submitting = true;
        this.disabled = true;
        form.submit();
    });
    if (window.sdsPage) window.sdsPage.pending.tsca = function() { var n = picked().length; return n ? n + ' ticked TSCA row(s)' : ''; };
    window.addEventListener('beforeunload', function(ev) {
        if (!submitting && !(window.sdsPage && window.sdsPage.submitting) && picked().length > 0) { ev.preventDefault(); ev.returnValue = ''; }
    });
    // Back to a page kept in the back-forward cache: usable again.
    window.addEventListener('pageshow', function(ev) {
        if (ev.persisted) {
            submitting = false;
            if (window.sdsPage) window.sdsPage.submitting = false;
            document.getElementById('tscaBatchSave').disabled = false;
            refresh();
        }
    });
    refresh();
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
