<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h2>Generate GHS Labels</h2>
    <p class="text-muted">Generate GHS-compliant product labels with hazard information, pictograms, and lot tracking.</p>

    <form method="POST" action="/labels/generate" id="labelForm" target="_blank">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="finished_good_id">Product <span class="text-danger">*</span></label>
            <select name="finished_good_id" id="finished_good_id" class="searchable-select" required>
                <option value="">— Select a product —</option>
                <?php foreach ($finishedGoods as $fg): ?>
                    <option value="<?= (int) $fg['id'] ?>"><?= e($fg['product_code']) ?> — <?= e($fg['description']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row" style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="flex: 1; min-width: 200px;">
                <label for="lot_number">Lot Number <span class="text-danger">*</span></label>
                <input type="text" name="lot_number" id="lot_number" class="input" required
                       pattern="\d{1,12}" maxlength="12" inputmode="numeric"
                       placeholder="123456789"
                       title="Lot number must be 1 to 12 digits">
                <small class="text-muted">Up to 12 digits</small>
            </div>

            <div class="form-group" style="flex: 1; min-width: 200px;">
                <label for="template_id">Label Template <span class="text-danger">*</span></label>
                <select name="template_id" id="template_id" class="input" required>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= $t['is_default'] ? 'selected' : '' ?>>
                            <?= e($t['name']) ?> — <?= e($t['description']) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (empty($templates)): ?>
                        <option value="" disabled>No templates — create one first</option>
                    <?php endif; ?>
                </select>
                <small class="text-muted"><a href="/label-templates">Manage templates</a></small>
            </div>

            <div class="form-group" style="flex: 1; min-width: 200px;">
                <label for="net_weight_value">Net Weight</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" name="net_weight_value" id="net_weight_value" class="input"
                           placeholder="e.g. 5" maxlength="10" inputmode="decimal"
                           style="flex: 1; min-width: 80px;">
                    <?php if (!empty($netWeightUnits)): ?>
                    <select name="net_weight_unit" id="net_weight_unit" class="input" style="flex: 0 0 auto; min-width: 80px;">
                        <option value="">—</option>
                        <?php foreach ($netWeightUnits as $unit): ?>
                            <option value="<?= e($unit) ?>"><?= e($unit) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="text" name="net_weight_unit" id="net_weight_unit" class="input"
                           placeholder="Unit" maxlength="10" style="flex: 0 0 80px;">
                    <?php endif; ?>
                </div>
                <small class="text-muted">Optional<?php if (empty($netWeightUnits)): ?> — <a href="/admin/settings">configure units</a><?php endif; ?></small>
            </div>

            <div class="form-group" style="flex: 0 0 150px;">
                <label for="quantity">Quantity</label>
                <input type="number" name="quantity" id="quantity" class="input" value="1" min="1" max="500">
                <small class="text-muted">Number of labels</small>
            </div>
        </div>

        <div class="form-group" style="margin-top: 0.5rem;">
            <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="private_label" id="private_label" value="1">
                <span>Private Label?</span>
            </label>
            <small class="text-muted" style="display: block;">Hides manufacturer info from the label</small>
        </div>

        <div style="margin-top: 1rem;">
            <button type="submit" class="btn btn-primary">Generate Label PDF</button>
        </div>
    </form>
</div>

<?php if (!empty($templates)): ?>
<div class="card" style="margin-top: 1.5rem;">
    <h3>Available Templates</h3>
    <table class="table">
        <thead>
            <tr><th>Name</th><th>Dimensions</th><th>Labels/Sheet</th><th>Layout</th><th>Default Font</th></tr>
        </thead>
        <tbody>
            <?php foreach ($templates as $t): ?>
            <tr>
                <td>
                    <strong><?= e($t['name']) ?></strong>
                    <?php if ($t['is_default']): ?>
                        <span class="badge badge-admin">Default</span>
                    <?php endif; ?>
                </td>
                <td><?= number_format((float) $t['label_width'] / 25.4, 4) ?>" &times; <?= number_format((float) $t['label_height'] / 25.4, 4) ?>"</td>
                <td><?= (int) $t['cols'] * (int) $t['rows'] ?></td>
                <td><?= (int) $t['cols'] ?> cols &times; <?= (int) $t['rows'] ?> rows</td>
                <td><?= number_format((float) $t['default_font_size'], 1) ?>pt</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card" style="margin-top: 1.5rem;">
    <h3>GHS Label Elements</h3>
    <ul>
        <li><strong>Product Identifier</strong> — Product name and item code</li>
        <li><strong>Signal Word</strong> — "DANGER" or "WARNING"</li>
        <li><strong>Hazard Statements</strong> — Describe the nature and degree of hazard (H-codes)</li>
        <li><strong>Pictograms</strong> — GHS hazard pictograms (red diamond symbols)</li>
        <li><strong>Precautionary Statements</strong> — Prevention, response, storage, disposal (P-codes)</li>
        <li><strong>Supplier Identification</strong> — Company name, address, and phone number</li>
        <li><strong>Lot Number</strong> — Up to 12-digit production lot identifier</li>
    </ul>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Only allow digits in lot number field
    var lotInput = document.getElementById('lot_number');
    if (lotInput) {
        lotInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 12);
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
