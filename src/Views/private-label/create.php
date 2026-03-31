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

        <h3>Product Selection</h3>
        <div class="form-group">
            <label for="finished_good_id">Base Product <span class="text-danger">*</span></label>
            <select name="finished_good_id" id="finished_good_id" class="searchable-select" required>
                <option value="">— Select a product —</option>
                <?php foreach ($finishedGoods as $fg): ?>
                    <option value="<?= (int) $fg['id'] ?>" data-product-code="<?= e($fg['product_code']) ?>"><?= e($fg['product_code']) ?> — <?= e($fg['description']) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">The product whose formula and hazard data will be used</small>
        </div>

        <div class="form-group" style="margin-top: 0.5rem;">
            <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="use_alias" id="use_alias" value="1">
                <span>Use alias / item code instead of base product code</span>
            </label>
        </div>

        <div class="form-group" id="aliasSelectGroup" style="display: none;">
            <label for="alias_id">Alias / Item Code</label>
            <select name="alias_id" id="alias_id" class="searchable-select">
                <option value="">— Select an alias —</option>
                <?php foreach ($aliases as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" data-base="<?= e($a['internal_code_base']) ?>">
                        <?= e($a['customer_code']) ?> — <?= e($a['description']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">The alias product code and description will appear on the SDS instead of the base product code</small>
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
            <a href="/private-label" class="btn btn-outline">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var useAliasCheckbox = document.getElementById('use_alias');
    var aliasGroup = document.getElementById('aliasSelectGroup');
    var fgSelect = document.getElementById('finished_good_id');
    var aliasSelect = document.getElementById('alias_id');

    // Store all alias options for filtering
    var allAliasOptions = [];
    if (aliasSelect) {
        for (var i = 1; i < aliasSelect.options.length; i++) {
            allAliasOptions.push({
                value: aliasSelect.options[i].value,
                text: aliasSelect.options[i].text,
                base: aliasSelect.options[i].getAttribute('data-base')
            });
        }
    }

    function filterAliases() {
        if (!aliasSelect || !fgSelect) return;

        var selectedOption = fgSelect.options[fgSelect.selectedIndex];
        var productCode = selectedOption ? selectedOption.getAttribute('data-product-code') : '';

        // Clear existing options (keep the placeholder)
        while (aliasSelect.options.length > 1) {
            aliasSelect.remove(1);
        }
        aliasSelect.value = '';

        // Add only aliases matching the selected product
        allAliasOptions.forEach(function(opt) {
            if (productCode && opt.base === productCode) {
                var option = document.createElement('option');
                option.value = opt.value;
                option.text = opt.text;
                option.setAttribute('data-base', opt.base);
                aliasSelect.add(option);
            }
        });
    }

    if (useAliasCheckbox && aliasGroup) {
        useAliasCheckbox.addEventListener('change', function() {
            aliasGroup.style.display = this.checked ? 'block' : 'none';
            if (!this.checked && aliasSelect) {
                aliasSelect.value = '';
            }
            if (this.checked) {
                filterAliases();
            }
        });
    }

    if (fgSelect) {
        fgSelect.addEventListener('change', function() {
            filterAliases();
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
