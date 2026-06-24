<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h2>Create Private Label SDS</h2>
    <p class="text-muted">Generate an SDS with a different manufacturer identity. The SDS will use the selected product's hazard data with the chosen manufacturer's information in Section 1.</p>

    <?php if (empty($manufacturers)): ?>
        <div class="alert alert-warning">
            No manufacturers configured. <a href="/manufacturers/create">Add a manufacturer</a> before creating private label SDS documents.
        </div>
    <?php else: ?>
    <form method="POST" action="/private-label/generate" id="privateLabelForm">
        <?= csrf_field() ?>
        <input type="hidden" name="finished_good_id" id="resolved_fg_id">
        <input type="hidden" name="alias_id" id="resolved_alias_id">

        <h3>Product</h3>
        <div class="form-group">
            <label for="product_search">Product Code or Alias <span class="text-danger">*</span></label>
            <select id="product_search" class="searchable-select" required>
                <option value="">— Search by product code or alias —</option>
                <?php foreach ($products as $p): ?>
                    <?php if ($p['type'] === 'fg'): ?>
                        <option value="fg:<?= (int) $p['fg_id'] ?>"
                                data-fg-id="<?= (int) $p['fg_id'] ?>"
                                data-alias-id=""
                                ><?= e($p['code']) ?> — <?= e($p['description']) ?></option>
                    <?php else: ?>
                        <option value="alias:<?= (int) $p['alias_id'] ?>"
                                data-fg-id="<?= (int) $p['fg_id'] ?>"
                                data-alias-id="<?= (int) $p['alias_id'] ?>"
                                ><?= e($p['code']) ?> — <?= e($p['description']) ?> (base: <?= e($p['base_code']) ?>)</option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Type a base product code or alias / item code</small>
        </div>
        <div id="productInfo" style="display: none; margin-top: -0.5rem; margin-bottom: 1rem;">
            <small id="productInfoText" class="text-muted"></small>
        </div>

        <h3>Manufacturer</h3>
        <div class="form-group">
            <label for="manufacturer_id">Manufacturer <span class="text-danger">*</span></label>
            <select name="manufacturer_id" id="manufacturer_id" class="input" required>
                <option value="">— Select a manufacturer —</option>
                <?php foreach ($manufacturers as $m): ?>
                    <option value="<?= (int) $m['id'] ?>">
                        <?= e($m['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">This manufacturer's name, address, and contact info will appear on the SDS</small>
        </div>

        <div class="form-group">
            <label for="change_summary">Notes (optional)</label>
            <input type="text" id="change_summary" name="change_summary"
                   placeholder="e.g. Initial private label SDS for customer XYZ">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Generate Private Label SDS</button>
            <button type="button" id="livePreviewBtn" class="btn btn-outline">Preview</button>
            <a href="/private-label" class="btn btn-outline">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var productSelect = document.getElementById('product_search');
    var fgIdInput     = document.getElementById('resolved_fg_id');
    var aliasIdInput  = document.getElementById('resolved_alias_id');
    var infoDiv       = document.getElementById('productInfo');
    var infoText      = document.getElementById('productInfoText');
    var form          = document.getElementById('privateLabelForm');

    function syncHiddenInputs() {
        if (!productSelect) return;
        var opt = productSelect.options[productSelect.selectedIndex];
        if (!opt || !opt.value) {
            fgIdInput.value = '';
            aliasIdInput.value = '';
            if (infoDiv) infoDiv.style.display = 'none';
            return;
        }

        fgIdInput.value = opt.getAttribute('data-fg-id') || '';
        aliasIdInput.value = opt.getAttribute('data-alias-id') || '';

        if (infoDiv && infoText) {
            if (aliasIdInput.value) {
                infoText.textContent = 'Alias selected — the alias product code and description will appear on the SDS.';
            } else {
                infoText.textContent = 'Base product selected — the base product code will appear on the SDS.';
            }
            infoDiv.style.display = 'block';
        }
    }

    if (productSelect) {
        productSelect.addEventListener('change', syncHiddenInputs);
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            if (!fgIdInput.value) {
                e.preventDefault();
                alert('Please select a product or alias.');
            }
        });
    }

    var previewBtn = document.getElementById('livePreviewBtn');
    if (previewBtn) {
        previewBtn.addEventListener('click', function() {
            var fgId = fgIdInput ? fgIdInput.value : '';
            var mfgId = document.getElementById('manufacturer_id') ? document.getElementById('manufacturer_id').value : '';
            if (!fgId || !mfgId) {
                alert('Please select a product and manufacturer before previewing.');
                return;
            }
            var params = 'finished_good_id=' + fgId + '&manufacturer_id=' + mfgId;
            if (aliasIdInput && aliasIdInput.value) {
                params += '&alias_id=' + aliasIdInput.value;
            }
            window.open('/private-label/live-preview?' + params, '_blank');
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
