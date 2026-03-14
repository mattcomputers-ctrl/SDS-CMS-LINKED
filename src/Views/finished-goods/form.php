<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/finished-goods/' . (int) $item['id'] : '/finished-goods';
$lines  = $formula ? ($formula['lines'] ?? []) : [];
$selfId = $isEdit ? (int) $item['id'] : 0;
?>

<div class="card">
    <form method="POST" action="<?= $action ?>" id="finishedGoodForm">
        <?= csrf_field() ?>
        <?php if ($isEdit && !empty($item['updated_at'])): ?>
            <input type="hidden" name="expected_updated_at" value="<?= e($item['updated_at']) ?>">
        <?php endif; ?>

        <!-- Product Information -->
        <h3>Product Information</h3>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="product_code">Product Code *</label>
                <input type="text" id="product_code" name="product_code"
                       value="<?= e(old('product_code', $item['product_code'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="family">Product Family</label>
                <select id="family" name="family">
                    <option value="">— Select —</option>
                    <?php foreach ($families as $f): ?>
                        <option value="<?= e($f) ?>" <?= (old('family', $item['family'] ?? '') === $f) ? 'selected' : '' ?>><?= e($f) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full-width">
                <label for="description">Description</label>
                <input type="text" id="description" name="description"
                       value="<?= e(old('description', $item['description'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="is_active">Status</label>
                <select id="is_active" name="is_active">
                    <option value="1" <?= ((int) old('is_active', (string) ($item['is_active'] ?? 1))) === 1 ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= ((int) old('is_active', (string) ($item['is_active'] ?? 1))) === 0 ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <!-- Recommended Use & Restrictions -->
        <h3>SDS Product Use</h3>
        <p class="text-muted">These fields appear in SDS Section 1 — Product Identification.</p>
        <div class="form-grid-2col">
            <div class="form-group full-width">
                <label for="recommended_use">Recommended Use</label>
                <input type="text" id="recommended_use" name="recommended_use"
                       value="<?= e(old('recommended_use', $item['recommended_use'] ?? '')) ?>"
                       placeholder="e.g. Industrial ink for offset printing">
            </div>
            <div class="form-group full-width">
                <label for="restrictions_on_use">Restrictions on Use</label>
                <input type="text" id="restrictions_on_use" name="restrictions_on_use"
                       value="<?= e(old('restrictions_on_use', $item['restrictions_on_use'] ?? '')) ?>"
                       placeholder="e.g. Not for food contact or consumer use">
            </div>
        </div>

        <!-- Physical Properties (SDS Section 9) -->
        <h3>Physical Properties</h3>
        <p class="text-muted">These fields appear in SDS Section 9 — Physical and Chemical Properties.</p>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="physical_state">Physical State</label>
                <select id="physical_state" name="physical_state">
                    <option value="">— Select —</option>
                    <?php foreach ($physicalStates as $ps): ?>
                        <option value="<?= e($ps) ?>" <?= (old('physical_state', $item['physical_state'] ?? '') === $ps) ? 'selected' : '' ?>><?= e($ps) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="color">Color</label>
                <select id="color" name="color">
                    <option value="">— Select —</option>
                    <?php foreach ($colorOptions as $co): ?>
                        <option value="<?= e($co) ?>" <?= (old('color', $item['color'] ?? '') === $co) ? 'selected' : '' ?>><?= e($co) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Formula -->
        <h3>Formula</h3>
        <?php if ($isEdit && $formula): ?>
            <p class="text-muted">Current formula version: v<?= (int) $formula['version'] ?>.
                Saving changes here creates a new version. Previous versions are preserved.
                <a href="/formulas/<?= (int) $item['id'] ?>">View formula history</a>
            </p>
        <?php else: ?>
            <p class="text-muted">Define the formula by adding components and their weight percentages (must total 100%).</p>
        <?php endif; ?>

        <table class="table" id="formulaLinesTable">
            <thead>
                <tr><th>#</th><th>Component</th><th>Weight %</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            if (empty($lines)) {
                $lines = [['raw_material_id' => '', 'finished_good_component_id' => '', 'pct' => '', 'line_type' => 'raw_material']];
            }
            foreach ($lines as $i => $line):
                $lineType = $line['line_type'] ?? 'raw_material';
                $selectedValue = '';
                if ($lineType === 'finished_good' && !empty($line['finished_good_component_id'])) {
                    $selectedValue = 'fg_' . (int) $line['finished_good_component_id'];
                } elseif (!empty($line['raw_material_id'])) {
                    $selectedValue = 'rm_' . (int) $line['raw_material_id'];
                }
            ?>
                <tr class="formula-line">
                    <td><?= $i + 1 ?></td>
                    <td>
                        <input type="hidden" name="line_type[<?= $i ?>]" class="line-type-hidden" value="<?= e($lineType) ?>">
                        <input type="hidden" name="raw_material_id[<?= $i ?>]" class="rm-id-hidden" value="<?= $lineType === 'raw_material' ? (int) ($line['raw_material_id'] ?? 0) : '' ?>">
                        <input type="hidden" name="finished_good_component_id[<?= $i ?>]" class="fg-id-hidden" value="<?= $lineType === 'finished_good' ? (int) ($line['finished_good_component_id'] ?? 0) : '' ?>">
                        <select class="searchable-select component-select" data-index="<?= $i ?>">
                            <option value="">— Select —</option>
                            <optgroup label="Raw Materials">
                                <?php foreach ($rawMaterials as $rm): ?>
                                    <option value="rm_<?= (int) $rm['id'] ?>" data-type="raw_material" data-id="<?= (int) $rm['id'] ?>" <?= $selectedValue === 'rm_' . (int) $rm['id'] ? 'selected' : '' ?>>
                                        <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name'] ?: $rm['supplier']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Finished Goods">
                                <?php foreach ($finishedGoods as $fg): ?>
                                    <option value="fg_<?= (int) $fg['id'] ?>" data-type="finished_good" data-id="<?= (int) $fg['id'] ?>" <?= $selectedValue === 'fg_' . (int) $fg['id'] ? 'selected' : '' ?>>
                                        <?= e($fg['product_code']) ?> — <?= e($fg['description']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </td>
                    <td><input type="number" name="pct[<?= $i ?>]" value="<?= e((string) ($line['pct'] ?? '')) ?>" step="0.0001" min="0" max="100" class="input-sm formula-pct"></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-line">X</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2" style="text-align: right;">Total:</th>
                    <th id="totalPct">0.00%</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>

        <div style="margin-bottom: 1rem;">
            <button type="button" id="addLine" class="btn btn-sm btn-outline">+ Add Line</button>
        </div>

        <div class="form-group">
            <label for="formula_notes">Formula Notes</label>
            <textarea id="formula_notes" name="formula_notes" rows="2" placeholder="Optional notes about this formula version..."><?= e(old('formula_notes', $formula['notes'] ?? '')) ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?> Finished Good</button>
            <a href="/finished-goods" class="btn btn-outline">Cancel</a>
            <?php if ($isEdit): ?>
                <a href="/formulas/<?= (int) $item['id'] ?>" class="btn btn-outline">Formula History</a>
                <a href="/formulas/<?= (int) $item['id'] ?>/calculate" class="btn btn-outline">Run Calculations</a>
                <a href="/sds/<?= (int) $item['id'] ?>" class="btn btn-outline">View SDS</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
var componentOptions = [];
<?php foreach ($rawMaterials as $rm): ?>
componentOptions.push({value: 'rm_<?= (int) $rm['id'] ?>', type: 'raw_material', id: <?= (int) $rm['id'] ?>, label: <?= json_encode($rm['internal_code'] . ' — ' . ($rm['supplier_product_name'] ?: $rm['supplier'])) ?>, group: 'Raw Materials'});
<?php endforeach; ?>
<?php foreach ($finishedGoods as $fg): ?>
componentOptions.push({value: 'fg_<?= (int) $fg['id'] ?>', type: 'finished_good', id: <?= (int) $fg['id'] ?>, label: <?= json_encode($fg['product_code'] . ' — ' . $fg['description']) ?>, group: 'Finished Goods'});
<?php endforeach; ?>

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function syncHiddenFields(selectEl) {
    var row = selectEl.closest('tr');
    var opt = selectEl.options[selectEl.selectedIndex];
    var lineTypeInput = row.querySelector('.line-type-hidden');
    var rmIdInput = row.querySelector('.rm-id-hidden');
    var fgIdInput = row.querySelector('.fg-id-hidden');

    if (opt && opt.value) {
        var type = opt.getAttribute('data-type');
        var id = opt.getAttribute('data-id');
        lineTypeInput.value = type;
        rmIdInput.value = (type === 'raw_material') ? id : '';
        fgIdInput.value = (type === 'finished_good') ? id : '';
    } else {
        lineTypeInput.value = 'raw_material';
        rmIdInput.value = '';
        fgIdInput.value = '';
    }
}

function updateTotal() {
    var total = 0;
    document.querySelectorAll('.formula-pct').forEach(function(input) {
        total += parseFloat(input.value) || 0;
    });
    var el = document.getElementById('totalPct');
    el.textContent = total.toFixed(2) + '%';
    el.style.color = Math.abs(total - 100) > 0.1 ? '#dc3545' : '';
    el.style.fontWeight = Math.abs(total - 100) > 0.1 ? 'bold' : '';
}

document.getElementById('addLine').addEventListener('click', function() {
    var tbody = document.querySelector('#formulaLinesTable tbody');
    var idx = tbody.querySelectorAll('.formula-line').length;
    var tr = document.createElement('tr');
    tr.className = 'formula-line';

    var options = '<option value="">— Select —</option>';
    var currentGroup = '';
    componentOptions.forEach(function(c) {
        if (c.group !== currentGroup) {
            if (currentGroup) options += '</optgroup>';
            options += '<optgroup label="' + escHtml(c.group) + '">';
            currentGroup = c.group;
        }
        options += '<option value="' + c.value + '" data-type="' + c.type + '" data-id="' + c.id + '">' + escHtml(c.label) + '</option>';
    });
    if (currentGroup) options += '</optgroup>';

    tr.innerHTML = '<td>' + (idx + 1) + '</td>' +
        '<td>' +
            '<input type="hidden" name="line_type[' + idx + ']" class="line-type-hidden" value="raw_material">' +
            '<input type="hidden" name="raw_material_id[' + idx + ']" class="rm-id-hidden" value="">' +
            '<input type="hidden" name="finished_good_component_id[' + idx + ']" class="fg-id-hidden" value="">' +
            '<select class="searchable-select component-select" data-index="' + idx + '">' + options + '</select>' +
        '</td>' +
        '<td><input type="number" name="pct[' + idx + ']" step="0.0001" min="0" max="100" class="input-sm formula-pct"></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger remove-line">X</button></td>';
    tbody.appendChild(tr);
});

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('formula-pct')) updateTotal();
});

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('component-select')) {
        syncHiddenFields(e.target);
    }
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-line')) {
        if (document.querySelectorAll('.formula-line').length > 1) {
            e.target.closest('tr').remove();
            updateTotal();
        }
    }
});

updateTotal();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
