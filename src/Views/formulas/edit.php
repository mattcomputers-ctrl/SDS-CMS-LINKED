<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/formulas/<?= (int) $finishedGood['id'] ?>">&larr; Back to Formula</a></p>

<div class="card">
    <p class="text-muted">Saving creates a new formula version. The previous version is preserved for audit.</p>

    <form method="POST" action="/formulas/<?= (int) $finishedGood['id'] ?>" id="formulaForm">
        <?= csrf_field() ?>

        <table class="table" id="formulaLinesTable">
            <thead>
                <tr><th>#</th><th>Component Code</th><th>Description</th><th>Weight %</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            $lines = $formula ? ($formula['lines'] ?? []) : [];
            if (empty($lines)) {
                $lines = [['component_code' => '', 'pct' => '', 'component_description' => '']];
            }
            foreach ($lines as $i => $line):
                $componentCode = '';
                $componentDesc = '';
                if (!empty($line['internal_code'])) {
                    $componentCode = $line['internal_code'];
                    $componentDesc = $line['supplier_product_name'] ?? '';
                } elseif (!empty($line['component_product_code'])) {
                    $componentCode = $line['component_product_code'];
                    $componentDesc = $line['component_description'] ?? '';
                } elseif (!empty($line['component_code'])) {
                    $componentCode = $line['component_code'];
                    $componentDesc = $line['component_description'] ?? '';
                }
            ?>
                <tr class="formula-line">
                    <td><?= $i + 1 ?></td>
                    <td>
                        <input type="text" name="component_code[<?= $i ?>]" value="<?= e($componentCode) ?>"
                               class="input-sm component-code" placeholder="e.g. VEC3218"
                               autocomplete="off" spellcheck="false" required>
                    </td>
                    <td>
                        <span class="component-desc"><?= e($componentDesc) ?></span>
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
var lookupTimers = {};

function lookupComponent(input) {
    var code = input.value.trim();
    var row = input.closest('tr');
    var descSpan = row.querySelector('.component-desc');

    if (code === '') {
        descSpan.textContent = '';
        descSpan.style.color = '';
        return;
    }

    var key = input.name;
    clearTimeout(lookupTimers[key]);
    lookupTimers[key] = setTimeout(function() {
        fetch('/finished-goods/component-lookup?code=' + encodeURIComponent(code))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.found) {
                    descSpan.textContent = data.description;
                    descSpan.style.color = '';
                    input.style.borderColor = '';
                } else {
                    descSpan.textContent = 'Not found';
                    descSpan.style.color = '#dc3545';
                    input.style.borderColor = '#dc3545';
                }
            })
            .catch(function() {
                descSpan.textContent = 'Lookup error';
                descSpan.style.color = '#dc3545';
            });
    }, 300);
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

    tr.innerHTML = '<td>' + (idx + 1) + '</td>' +
        '<td><input type="text" name="component_code[' + idx + ']" class="input-sm component-code" placeholder="e.g. VEC3218" autocomplete="off" spellcheck="false" required></td>' +
        '<td><span class="component-desc"></span></td>' +
        '<td><input type="number" name="pct[' + idx + ']" step="0.0001" min="0" max="100" class="input-sm formula-pct" required></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger remove-line">X</button></td>';
    tbody.appendChild(tr);
    tr.querySelector('.component-code').focus();
});

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('formula-pct')) updateTotal();
    if (e.target.classList.contains('component-code')) lookupComponent(e.target);
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-line')) {
        if (document.querySelectorAll('.formula-line').length > 1) {
            e.target.closest('tr').remove();
            updateTotal();
        }
    }
});

document.querySelectorAll('.component-code').forEach(function(input) {
    if (input.value.trim() !== '' && input.closest('tr').querySelector('.component-desc').textContent.trim() === '') {
        lookupComponent(input);
    }
});

updateTotal();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
