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
        <?php if (!empty($tscaReview)): ?>
            <span style="background: #fd7e14; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($tscaReview) ?></span>
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
                <td><?= e(date('m/d/Y', strtotime($item['created_at']))) ?></td>
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
    </p>
    <div style="margin-bottom: 0.5rem;">
        <input type="text" id="descFilter" placeholder="Filter by CAS or description..." style="max-width: 320px;">
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
        <div style="text-align: center; padding: 1.5rem; color: #28a745;"><strong>Every constituent CAS in use is on the TSCA inventory or has an override.</strong></div>
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
                <td><small><?= e($o['tsca_updated_by_name'] ?? '—') ?><?= !empty($o['tsca_updated_at']) ? ' · ' . e(date('m/d/Y', strtotime($o['tsca_updated_at']))) : '' ?></small></td>
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
