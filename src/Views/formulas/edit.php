<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/formulas/<?= (int) $finishedGood['id'] ?>">&larr; Back to Formula</a></p>

<div class="card">
    <p class="text-muted">Saving creates a new formula version. The previous version is preserved for audit.</p>

    <form method="POST" action="/formulas/<?= (int) $finishedGood['id'] ?>" id="formulaForm">
        <?= csrf_field() ?>

        <table class="table" id="formulaLinesTable">
            <thead>
                <tr><th>#</th><th>Raw Material</th><th>Weight %</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            $lines = $formula ? ($formula['lines'] ?? []) : [];
            if (empty($lines)) {
                $lines = [['raw_material_id' => '', 'pct' => '']];
            }
            foreach ($lines as $i => $line):
            ?>
                <tr class="formula-line">
                    <td><?= $i + 1 ?></td>
                    <td>
                        <select name="raw_material_id[<?= $i ?>]" required>
                            <option value="">— Select —</option>
                            <?php foreach ($rawMaterials as $rm): ?>
                                <option value="<?= (int) $rm['id'] ?>" <?= ((int) ($line['raw_material_id'] ?? 0)) === (int) $rm['id'] ? 'selected' : '' ?>>
                                    <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" name="pct[<?= $i ?>]" value="<?= e((string) ($line['pct'] ?? '')) ?>" step="0.0001" min="0" max="100" class="input-sm formula-pct" required></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-line">X</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2" class="text-right">Total:</th>
                    <th id="totalPct">0.00%</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>

        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="2"><?= e($formula['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="button" id="addLine" class="btn btn-sm btn-outline">+ Add Line</button>
            <button type="submit" class="btn btn-primary">Save as New Version</button>
        </div>
    </form>
</div>

<script>
var rawMaterialOptions = <?= json_encode(array_map(function($rm) {
    return ['id' => (int) $rm['id'], 'label' => $rm['internal_code'] . ' — ' . $rm['supplier_product_name']];
}, $rawMaterials)) ?>;

function updateTotal() {
    var total = 0;
    document.querySelectorAll('.formula-pct').forEach(function(input) {
        total += parseFloat(input.value) || 0;
    });
    var el = document.getElementById('totalPct');
    el.textContent = total.toFixed(2) + '%';
    el.className = Math.abs(total - 100) > 0.1 ? 'text-danger' : '';
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
        '<td><select name="raw_material_id[' + idx + ']" required>' + options + '</select></td>' +
        '<td><input type="number" name="pct[' + idx + ']" step="0.0001" min="0" max="100" class="input-sm formula-pct" required></td>' +
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
