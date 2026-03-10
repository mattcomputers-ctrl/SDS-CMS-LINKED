<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/formulas/<?= (int) $finishedGood['id'] ?>">&larr; Back to Formula</a></p>

<div class="card">
    <p class="text-muted">Saving creates a new formula version. The previous version is preserved for audit.</p>

    <form method="POST" action="/formulas/<?= (int) $finishedGood['id'] ?>" id="formulaForm">
        <?= csrf_field() ?>

        <table class="table" id="formulaLinesTable">
            <thead>
                <tr><th>#</th><th>Type</th><th>Component</th><th>Weight %</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            $lines = $formula ? ($formula['lines'] ?? []) : [];
            if (empty($lines)) {
                $lines = [['raw_material_id' => '', 'finished_good_component_id' => '', 'pct' => '', 'line_type' => 'raw_material']];
            }
            foreach ($lines as $i => $line):
                $lineType = $line['line_type'] ?? 'raw_material';
            ?>
                <tr class="formula-line">
                    <td><?= $i + 1 ?></td>
                    <td>
                        <select name="line_type[<?= $i ?>]" class="line-type-select" data-index="<?= $i ?>">
                            <option value="raw_material" <?= $lineType === 'raw_material' ? 'selected' : '' ?>>Raw Material</option>
                            <option value="finished_good" <?= $lineType === 'finished_good' ? 'selected' : '' ?>>Finished Good</option>
                        </select>
                    </td>
                    <td>
                        <select name="raw_material_id[<?= $i ?>]" class="searchable-select rm-select" data-index="<?= $i ?>" <?= $lineType === 'finished_good' ? 'style="display:none" disabled' : '' ?>>
                            <option value="">— Select Raw Material —</option>
                            <?php foreach ($rawMaterials as $rm): ?>
                                <option value="<?= (int) $rm['id'] ?>" <?= ($lineType === 'raw_material' && (int) ($line['raw_material_id'] ?? 0)) === (int) $rm['id'] ? 'selected' : '' ?>>
                                    <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="finished_good_component_id[<?= $i ?>]" class="searchable-select fg-select" data-index="<?= $i ?>" <?= $lineType === 'raw_material' ? 'style="display:none" disabled' : '' ?>>
                            <option value="">— Select Finished Good —</option>
                            <?php foreach ($finishedGoods as $fg): ?>
                                <option value="<?= (int) $fg['id'] ?>" <?= ($lineType === 'finished_good' && (int) ($line['finished_good_component_id'] ?? 0)) === (int) $fg['id'] ? 'selected' : '' ?>>
                                    <?= e($fg['product_code']) ?> — <?= e($fg['description']) ?>
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
                    <th colspan="3" class="text-right">Total:</th>
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

var finishedGoodOptions = <?= json_encode(array_map(function($fg) {
    return ['id' => (int) $fg['id'], 'label' => $fg['product_code'] . ' — ' . $fg['description']];
}, $finishedGoods)) ?>;

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function updateTotal() {
    var total = 0;
    document.querySelectorAll('.formula-pct').forEach(function(input) {
        total += parseFloat(input.value) || 0;
    });
    var el = document.getElementById('totalPct');
    el.textContent = total.toFixed(2) + '%';
    el.className = Math.abs(total - 100) > 0.1 ? 'text-danger' : '';
}

function toggleLineType(selectEl) {
    var idx = selectEl.getAttribute('data-index');
    var row = selectEl.closest('tr');
    var rmSelect = row.querySelector('.rm-select');
    var fgSelect = row.querySelector('.fg-select');

    if (selectEl.value === 'finished_good') {
        rmSelect.style.display = 'none';
        rmSelect.disabled = true;
        rmSelect.value = '';
        fgSelect.style.display = '';
        fgSelect.disabled = false;
    } else {
        fgSelect.style.display = 'none';
        fgSelect.disabled = true;
        fgSelect.value = '';
        rmSelect.style.display = '';
        rmSelect.disabled = false;
    }
}

document.getElementById('addLine').addEventListener('click', function() {
    var tbody = document.querySelector('#formulaLinesTable tbody');
    var idx = tbody.querySelectorAll('.formula-line').length;
    var tr = document.createElement('tr');
    tr.className = 'formula-line';

    var rmOptions = '<option value="">— Select Raw Material —</option>';
    rawMaterialOptions.forEach(function(rm) {
        rmOptions += '<option value="' + rm.id + '">' + escHtml(rm.label) + '</option>';
    });

    var fgOptions = '<option value="">— Select Finished Good —</option>';
    finishedGoodOptions.forEach(function(fg) {
        fgOptions += '<option value="' + fg.id + '">' + escHtml(fg.label) + '</option>';
    });

    tr.innerHTML = '<td>' + (idx + 1) + '</td>' +
        '<td><select name="line_type[' + idx + ']" class="line-type-select" data-index="' + idx + '">' +
            '<option value="raw_material" selected>Raw Material</option>' +
            '<option value="finished_good">Finished Good</option>' +
        '</select></td>' +
        '<td>' +
            '<select name="raw_material_id[' + idx + ']" class="searchable-select rm-select" data-index="' + idx + '">' + rmOptions + '</select>' +
            '<select name="finished_good_component_id[' + idx + ']" class="searchable-select fg-select" data-index="' + idx + '" style="display:none" disabled>' + fgOptions + '</select>' +
        '</td>' +
        '<td><input type="number" name="pct[' + idx + ']" step="0.0001" min="0" max="100" class="input-sm formula-pct" required></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger remove-line">X</button></td>';
    tbody.appendChild(tr);
});

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('formula-pct')) updateTotal();
});

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('line-type-select')) {
        toggleLineType(e.target);
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
