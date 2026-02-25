<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/finished-goods/' . (int) $item['id'] : '/finished-goods';
$lines  = $formula ? ($formula['lines'] ?? []) : [];
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
                <input type="text" id="family" name="family" list="family-list"
                       value="<?= e(old('family', $item['family'] ?? '')) ?>"
                       placeholder="UV offset, aqueous, solvent...">
                <datalist id="family-list">
                    <?php foreach ($families as $f): ?>
                        <option value="<?= e($f) ?>">
                    <?php endforeach; ?>
                </datalist>
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

        <!-- Formula -->
        <h3>Formula</h3>
        <?php if ($isEdit && $formula): ?>
            <p class="text-muted">Current formula version: v<?= (int) $formula['version'] ?>.
                Saving changes here creates a new version. Previous versions are preserved.
                <a href="/formulas/<?= (int) $item['id'] ?>">View formula history</a>
            </p>
        <?php else: ?>
            <p class="text-muted">Define the formula by adding raw materials and their weight percentages (must total 100%).</p>
        <?php endif; ?>

        <table class="table" id="formulaLinesTable">
            <thead>
                <tr><th>#</th><th>Raw Material</th><th>Weight %</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            if (empty($lines)) {
                $lines = [['raw_material_id' => '', 'pct' => '']];
            }
            foreach ($lines as $i => $line):
            ?>
                <tr class="formula-line">
                    <td><?= $i + 1 ?></td>
                    <td>
                        <select name="raw_material_id[<?= $i ?>]">
                            <option value="">— Select —</option>
                            <?php foreach ($rawMaterials as $rm): ?>
                                <option value="<?= (int) $rm['id'] ?>" <?= ((int) ($line['raw_material_id'] ?? 0)) === (int) $rm['id'] ? 'selected' : '' ?>>
                                    <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name'] ?: $rm['supplier']) ?>
                                </option>
                            <?php endforeach; ?>
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
            <textarea id="formula_notes" name="formula_notes" rows="2" placeholder="Optional notes about this formula version..."><?= e($formula['notes'] ?? '') ?></textarea>
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
var rawMaterialOptions = <?= json_encode(array_map(function($rm) {
    return ['id' => (int) $rm['id'], 'label' => $rm['internal_code'] . ' — ' . ($rm['supplier_product_name'] ?: $rm['supplier'])];
}, $rawMaterials)) ?>;

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
    rawMaterialOptions.forEach(function(rm) {
        options += '<option value="' + rm.id + '">' + rm.label.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
    });
    tr.innerHTML = '<td>' + (idx + 1) + '</td>' +
        '<td><select name="raw_material_id[' + idx + ']">' + options + '</select></td>' +
        '<td><input type="number" name="pct[' + idx + ']" step="0.0001" min="0" max="100" class="input-sm formula-pct"></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger remove-line">X</button></td>';
    tbody.appendChild(tr);
});

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('formula-pct')) updateTotal();
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
