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
                <label for="supplier_product_name">Supplier Product Name</label>
                <input type="text" id="supplier_product_name" name="supplier_product_name"
                       value="<?= e(old('supplier_product_name', $item['supplier_product_name'] ?? '')) ?>">
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
            <div class="form-group full-width">
                <label for="sds_notes">SDS Upload Notes (optional)</label>
                <input type="text" id="sds_notes" name="sds_notes" placeholder="e.g., Revised 2024, new formulation...">
            </div>
        </div>

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
                    <th>Date</th>
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
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="voc_wt">VOC wt%</label>
                <input type="number" id="voc_wt" name="voc_wt" step="0.0001"
                       value="<?= e(old('voc_wt', $item['voc_wt'] ?? '')) ?>">
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
                <label for="flash_point_c">Flash Point (C)</label>
                <input type="number" id="flash_point_c" name="flash_point_c" step="0.1"
                       value="<?= e(old('flash_point_c', $item['flash_point_c'] ?? '')) ?>">
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

        <!-- CAS Constituents (inline) -->
        <h3>CAS Constituents</h3>
        <p class="text-muted">Enter the CAS numbers and concentrations from the supplier SDS. Chemical name will auto-populate when a valid CAS number is entered.</p>

        <table class="table" id="constituentsTable">
            <thead>
                <tr>
                    <th>CAS Number</th>
                    <th>Chemical Name</th>
                    <th>% Min</th>
                    <th>% Max</th>
                    <th>% Exact</th>
                    <th>Trade Secret</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($constituents)): ?>
                <?php foreach ($constituents as $i => $c): ?>
                <tr class="constituent-row">
                    <td><input type="text" name="cas_number[<?= $i ?>]" value="<?= e($c['cas_number']) ?>" placeholder="67-56-1" class="input-sm cas-input"></td>
                    <td><input type="text" name="chemical_name[<?= $i ?>]" value="<?= e($c['chemical_name']) ?>" class="input-sm chem-name-input"></td>
                    <td><input type="number" name="pct_min[<?= $i ?>]" value="<?= e((string) ($c['pct_min'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_max[<?= $i ?>]" value="<?= e((string) ($c['pct_max'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_exact[<?= $i ?>]" value="<?= e((string) ($c['pct_exact'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="checkbox" name="is_trade_secret[<?= $i ?>]" value="1" <?= ((int) ($c['is_trade_secret'] ?? 0)) ? 'checked' : '' ?>></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-row">X</button></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="constituent-row">
                    <td><input type="text" name="cas_number[0]" placeholder="67-56-1" class="input-sm cas-input"></td>
                    <td><input type="text" name="chemical_name[0]" class="input-sm chem-name-input"></td>
                    <td><input type="number" name="pct_min[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_max[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_exact[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="checkbox" name="is_trade_secret[0]" value="1"></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-row">X</button></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-bottom: 1.5rem;">
            <button type="button" id="addRow" class="btn btn-sm btn-outline">+ Add Row</button>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?> Raw Material</button>
            <a href="/raw-materials" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php if ($isEdit && is_admin()): ?>
<div class="card card-danger">
    <h3>Danger Zone</h3>
    <form method="POST" action="/raw-materials/<?= (int) $item['id'] ?>/delete" onsubmit="return confirm('Delete this raw material? This cannot be undone.');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">Delete Raw Material</button>
    </form>
</div>
<?php endif; ?>

<script>
// Add constituent row
document.getElementById('addRow').addEventListener('click', function() {
    var tbody = document.querySelector('#constituentsTable tbody');
    var rows = tbody.querySelectorAll('.constituent-row');
    var idx = rows.length;
    var tr = document.createElement('tr');
    tr.className = 'constituent-row';
    tr.innerHTML = '<td><input type="text" name="cas_number[' + idx + ']" placeholder="67-56-1" class="input-sm cas-input"></td>' +
        '<td><input type="text" name="chemical_name[' + idx + ']" class="input-sm chem-name-input"></td>' +
        '<td><input type="number" name="pct_min[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="number" name="pct_max[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="number" name="pct_exact[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="checkbox" name="is_trade_secret[' + idx + ']" value="1"></td>' +
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

// CAS auto-lookup: when a CAS input loses focus, look up the chemical name
function attachCasLookup(input) {
    input.addEventListener('blur', function() {
        var cas = input.value.trim();
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

        // Look up the chemical name
        var row = input.closest('tr');
        var chemNameInput = row.querySelector('.chem-name-input');

        // Only auto-populate if the chemical name is empty
        if (chemNameInput && chemNameInput.value.trim() === '') {
            chemNameInput.placeholder = 'Looking up...';
            fetch('/raw-materials/cas-lookup?cas=' + encodeURIComponent(cas), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function(resp) {
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    return resp.json();
                })
                .then(function(data) {
                    chemNameInput.placeholder = '';
                    if (data.found && data.chemical_name) {
                        chemNameInput.value = data.chemical_name;
                        chemNameInput.style.borderColor = '#198754';
                        setTimeout(function() { chemNameInput.style.borderColor = ''; }, 2000);
                    } else {
                        chemNameInput.placeholder = 'Not found — enter manually';
                    }
                })
                .catch(function(err) {
                    chemNameInput.placeholder = 'Lookup failed — enter manually';
                    console.error('CAS lookup error:', err);
                });
        }
    });
}

// Attach CAS lookup to all existing inputs
document.querySelectorAll('.cas-input').forEach(attachCasLookup);
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
