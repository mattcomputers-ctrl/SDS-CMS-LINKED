<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/raw-materials/<?= (int) $item['id'] ?>/edit">&larr; Back to <?= e($item['internal_code']) ?></a></p>

<div class="card">
    <form method="POST" action="/raw-materials/<?= (int) $item['id'] ?>/constituents" id="constituentsForm">
        <?= csrf_field() ?>

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
                    <td><input type="text" name="cas_number[<?= $i ?>]" value="<?= e($c['cas_number']) ?>" placeholder="67-56-1" class="input-sm"></td>
                    <td><input type="text" name="chemical_name[<?= $i ?>]" value="<?= e($c['chemical_name']) ?>" class="input-sm"></td>
                    <td><input type="number" name="pct_min[<?= $i ?>]" value="<?= e((string) ($c['pct_min'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_max[<?= $i ?>]" value="<?= e((string) ($c['pct_max'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_exact[<?= $i ?>]" value="<?= e((string) ($c['pct_exact'] ?? '')) ?>" step="0.0001" class="input-xs"></td>
                    <td><input type="checkbox" name="is_trade_secret[<?= $i ?>]" value="1" <?= ((int) $c['is_trade_secret']) ? 'checked' : '' ?>></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-row">X</button></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="constituent-row">
                    <td><input type="text" name="cas_number[0]" placeholder="67-56-1" class="input-sm"></td>
                    <td><input type="text" name="chemical_name[0]" class="input-sm"></td>
                    <td><input type="number" name="pct_min[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_max[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="number" name="pct_exact[0]" step="0.0001" class="input-xs"></td>
                    <td><input type="checkbox" name="is_trade_secret[0]" value="1"></td>
                    <td><button type="button" class="btn btn-sm btn-danger remove-row">X</button></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="form-actions">
            <button type="button" id="addRow" class="btn btn-sm btn-outline">+ Add Row</button>
            <button type="submit" class="btn btn-primary">Save Constituents</button>
        </div>
    </form>
</div>

<script>
document.getElementById('addRow').addEventListener('click', function() {
    var tbody = document.querySelector('#constituentsTable tbody');
    var rows = tbody.querySelectorAll('.constituent-row');
    var idx = rows.length;
    var tr = document.createElement('tr');
    tr.className = 'constituent-row';
    tr.innerHTML = '<td><input type="text" name="cas_number[' + idx + ']" placeholder="67-56-1" class="input-sm"></td>' +
        '<td><input type="text" name="chemical_name[' + idx + ']" class="input-sm"></td>' +
        '<td><input type="number" name="pct_min[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="number" name="pct_max[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="number" name="pct_exact[' + idx + ']" step="0.0001" class="input-xs"></td>' +
        '<td><input type="checkbox" name="is_trade_secret[' + idx + ']" value="1"></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger remove-row">X</button></td>';
    tbody.appendChild(tr);
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-row')) {
        var row = e.target.closest('tr');
        if (document.querySelectorAll('.constituent-row').length > 1) {
            row.remove();
        }
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
