<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="grid-2col" style="margin-bottom: 1rem;">
    <!-- Aliases Info -->
    <div class="card">
        <h2 class="card-title">1. Product Aliases</h2>
        <p class="text-muted" style="margin-bottom: 0.75rem;">Aliases link customer-facing codes to internal product codes. Manage aliases on the <a href="/aliases">Aliases page</a>.</p>
        <?php if ($aliasCount > 0): ?>
            <div class="alert alert-success"><?= (int) $aliasCount ?> alias(es) stored in the system.</div>
        <?php else: ?>
            <div class="alert" style="background: #fff3cd; color: #856404; border: 1px solid #ffc107; padding: 0.5rem; border-radius: 4px;">No aliases uploaded yet. <a href="/aliases">Upload aliases</a> to enable SDS export by alias.</div>
        <?php endif; ?>
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
    <p class="text-muted" style="margin-bottom: 0.75rem;">SDS exports will use aliases to match item names. A list of missing items (not yet in the SDS system) will be included when applicable.</p>
    <form id="reportForm">
        <input type="hidden" name="_csrf_token" value="<?= e(\SDS\Core\CSRF::token()) ?>">
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
        <input type="hidden" name="export_language" id="export_language" value="all">
        <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem; flex-wrap: wrap; align-items: center;">
            <button type="submit" class="btn btn-primary" formaction="/reports/generate" formmethod="POST">Download CSV</button>
            <button type="submit" class="btn btn-primary" formaction="/reports/generate-pdf" formmethod="POST">Download PDF</button>
            <div style="position: relative; display: inline-block;">
                <button type="button" class="btn btn-outline" id="exportSdsBtn">Export SDS (ZIP) &#9662;</button>
                <div id="exportSdsMenu" style="display: none; position: absolute; bottom: 100%; left: 0; margin-bottom: 4px; background: #fff; border: 1px solid #ccc; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 100; min-width: 200px;">
                    <a href="#" class="export-sds-option" data-lang="en" style="display: block; padding: 0.5rem 1rem; text-decoration: none; color: #333; white-space: nowrap;">English Only</a>
                    <a href="#" class="export-sds-option" data-lang="es" style="display: block; padding: 0.5rem 1rem; text-decoration: none; color: #333; white-space: nowrap;">Spanish Only</a>
                    <a href="#" class="export-sds-option" data-lang="fr" style="display: block; padding: 0.5rem 1rem; text-decoration: none; color: #333; white-space: nowrap;">French Only</a>
                    <a href="#" class="export-sds-option" data-lang="de" style="display: block; padding: 0.5rem 1rem; text-decoration: none; color: #333; white-space: nowrap;">German Only</a>
                    <div style="border-top: 1px solid #eee;"></div>
                    <a href="#" class="export-sds-option" data-lang="all" style="display: block; padding: 0.5rem 1rem; text-decoration: none; color: #333; font-weight: bold; white-space: nowrap;">All Languages</a>
                </div>
            </div>
        </div>
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

    // Export SDS dropdown
    var exportBtn = document.getElementById('exportSdsBtn');
    var exportMenu = document.getElementById('exportSdsMenu');
    var exportForm = document.getElementById('reportForm');
    var exportLangInput = document.getElementById('export_language');

    if (exportBtn && exportMenu) {
        exportBtn.addEventListener('click', function(e) {
            e.preventDefault();
            exportMenu.style.display = exportMenu.style.display === 'none' ? 'block' : 'none';
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!exportBtn.contains(e.target) && !exportMenu.contains(e.target)) {
                exportMenu.style.display = 'none';
            }
        });

        // Handle menu option clicks
        var options = exportMenu.querySelectorAll('.export-sds-option');
        options.forEach(function(opt) {
            opt.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f0f0f0';
            });
            opt.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
            opt.addEventListener('click', function(e) {
                e.preventDefault();
                exportMenu.style.display = 'none';
                exportLangInput.value = this.getAttribute('data-lang');
                exportForm.action = '/reports/export-sds';
                exportForm.method = 'POST';
                exportForm.submit();
            });
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
