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
                <label for="label_size">Label Size <span class="text-danger">*</span></label>
                <select name="label_size" id="label_size" class="input" required>
                    <option value="big">Big — OL575WR (3.75" x 2.4375", 8/sheet)</option>
                    <option value="small">Small — OL800WX (2.5" x 1.5625", 18/sheet)</option>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label for="net_weight">Net Weight</label>
                <input type="text" name="net_weight" id="net_weight" class="input"
                       placeholder="e.g. 5 LBS"
                       maxlength="20">
                <small class="text-muted">Optional (e.g. 5 LBS, 1 GAL)</small>
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

<div class="card" style="margin-top: 1.5rem;">
    <h3>Label Information</h3>
    <table class="table">
        <thead>
            <tr><th>Size</th><th>Template</th><th>Dimensions</th><th>Labels/Sheet</th><th>Layout</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Big</strong></td>
                <td>OL575WR</td>
                <td>3.75" &times; 2.4375"</td>
                <td>8</td>
                <td>2 columns &times; 4 rows</td>
            </tr>
            <tr>
                <td><strong>Small</strong></td>
                <td>OL800WX</td>
                <td>2.5" &times; 1.5625"</td>
                <td>18</td>
                <td>3 columns &times; 6 rows</td>
            </tr>
        </tbody>
    </table>

    <h3 style="margin-top: 1rem;">GHS Label Elements</h3>
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
