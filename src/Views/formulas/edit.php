<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/formulas/<?= (int) $finishedGood['id'] ?>">&larr; Back to Formula</a></p>

<div class="card">
    <p class="text-muted">Saving creates a new formula version. The previous version is preserved for audit.</p>

    <form method="POST" action="/formulas/<?= (int) $finishedGood['id'] ?>" id="formulaForm">
        <?= csrf_field() ?>

        <table class="table" id="formulaLinesTable">
            <thead>
                <tr><th>#</th><th>Component</th><th>Weight %</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            $lines = $formula ? ($formula['lines'] ?? []) : [];
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
                        <select class="searchable-select component-select" required data-index="<?= $i ?>">
                            <option value="">— Select —</option>
                            <optgroup label="Raw Materials">
                                <?php foreach ($rawMaterials as $rm): ?>
                                    <option value="rm_<?= (int) $rm['id'] ?>" data-type="raw_material" data-id="<?= (int) $rm['id'] ?>" <?= $selectedValue === 'rm_' . (int) $rm['id'] ? 'selected' : '' ?>>
                                        <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
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
var componentOptions = [];
<?php foreach ($rawMaterials as $rm): ?>
componentOptions.push({value: 'rm_<?= (int) $rm['id'] ?>', type: 'raw_material', id: <?= (int) $rm['id'] ?>, label: <?= json_encode($rm['internal_code'] . ' — ' . $rm['supplier_product_name']) ?>, group: 'Raw Materials'});
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
    el.className = Math.abs(total - 100) > 0.1 ? 'text-danger' : '';
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
            '<select class="searchable-select component-select" required data-index="' + idx + '">' + options + '</select>' +
        '</td>' +
        '<td><input type="number" name="pct[' + idx + ']" step="0.0001" min="0" max="100" class="input-sm formula-pct" required></td>' +
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
