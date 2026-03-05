<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="grid-2col" style="margin-bottom: 1rem;">
    <!-- Upload Item Names -->
    <div class="card">
        <h2 class="card-title">1. Upload Item Names (CSV)</h2>
        <p class="text-muted" style="margin-bottom: 0.75rem;">CSV must contain <strong>Item Name</strong> and <strong>Description</strong> columns.</p>
        <?php if ($hasItemNames): ?>
            <div class="alert alert-success"><?= (int) $itemNameCount ?> item name(s) loaded.</div>
        <?php endif; ?>
        <form method="POST" action="/reports/upload-items" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-group">
                <input type="file" name="item_names_file" accept=".csv,.txt" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top: 0.5rem;">Upload Item Names</button>
        </form>
    </div>

    <!-- Upload Shipping Detail -->
    <div class="card">
        <h2 class="card-title">2. Upload Shipping Detail (CSV)</h2>
        <p class="text-muted" style="margin-bottom: 0.75rem;">CSV must contain <strong>Bill To</strong>, <strong>Ship To</strong>, <strong>Ship To Name</strong>, <strong>Date Shipped</strong>, <strong>Item Name</strong>, and <strong>Qty Shipped</strong> columns.</p>
        <?php if ($hasShippingData): ?>
            <div class="alert alert-success"><?= (int) $shippingCount ?> shipping record(s) loaded.</div>
        <?php endif; ?>
        <form method="POST" action="/reports/upload-shipping" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-group">
                <input type="file" name="shipping_file" accept=".csv,.txt" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top: 0.5rem;">Upload Shipping Detail</button>
        </form>
    </div>
</div>

<?php if ($hasShippingData): ?>
<!-- Generate Report -->
<div class="card" style="margin-bottom: 1rem;">
    <h2 class="card-title">3. Generate HAP / VOC Report</h2>
    <form method="POST" action="/reports/generate" id="reportForm">
        <?= csrf_field() ?>
        <div class="grid-2col">
            <div class="form-group">
                <label for="customer_field">Customer Identifier</label>
                <select name="customer_field" id="customer_field" class="form-control">
                    <option value="ship_to_name">Ship To Name</option>
                    <option value="bill_to">Bill To</option>
                    <option value="ship_to">Ship To</option>
                </select>
            </div>
            <div class="form-group">
                <label for="customer_value">Customer</label>
                <select name="customer_value" id="customer_value" class="form-control">
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= e($c) ?>"><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="grid-2col">
            <div class="form-group">
                <label for="date_from">Date From</label>
                <input type="date" name="date_from" id="date_from" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="date_to">Date To</label>
                <input type="date" name="date_to" id="date_to" class="form-control" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top: 0.5rem;">Generate Report (CSV)</button>
    </form>
</div>
<?php endif; ?>

<!-- Clear Data -->
<div class="card">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h2 class="card-title" style="margin-bottom: 0.25rem;">Clear Report Data</h2>
            <p class="text-muted">Remove all uploaded data from this session. Data is also automatically cleared on logout.</p>
        </div>
        <form method="POST" action="/reports/clear">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger" onclick="return confirm('Clear all uploaded report data?');">Clear Data</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var fieldSelect = document.getElementById('customer_field');
    var customerSelect = document.getElementById('customer_value');

    if (fieldSelect && customerSelect) {
        fieldSelect.addEventListener('change', function() {
            var field = this.value;
            fetch('/reports/customers?field=' + encodeURIComponent(field))
                .then(function(r) { return r.json(); })
                .then(function(customers) {
                    customerSelect.innerHTML = '<option value="">-- Select Customer --</option>';
                    customers.forEach(function(c) {
                        var opt = document.createElement('option');
                        opt.value = c;
                        opt.textContent = c;
                        customerSelect.appendChild(opt);
                    });
                });
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
