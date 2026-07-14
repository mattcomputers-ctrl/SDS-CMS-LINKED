<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/customers/' . (int) $item['id'] : '/customers';
?>

<div class="card">
    <form method="POST" action="<?= $action ?>">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label for="ship_to">Ship To Code *</label>
                <input type="text" id="ship_to" name="ship_to"
                       value="<?= e(old('ship_to', $item['ship_to'] ?? '')) ?>"
                       required <?= $isEdit ? 'readonly' : '' ?>>
                <?php if ($isEdit): ?>
                    <small class="text-muted">Ship To code cannot be changed after creation.</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="ship_to_name">Ship To Name</label>
                <input type="text" id="ship_to_name" name="ship_to_name"
                       value="<?= e(old('ship_to_name', $item['ship_to_name'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="regulatory_email">Regulatory Email</label>
                <input type="email" id="regulatory_email" name="regulatory_email"
                       value="<?= e(old('regulatory_email', $item['regulatory_email'] ?? '')) ?>"
                       placeholder="regulatory@customer.com">
                <small class="text-muted">SDSs will be sent to this address. Leave blank to disable auto-send.</small>
            </div>
            <div class="form-group">
                <label for="sds_send_mode">SDS Send Mode</label>
                <select id="sds_send_mode" name="sds_send_mode">
                    <?php
                    $currentMode = old('sds_send_mode', $item['sds_send_mode'] ?? 'osha');
                    $modes = [
                        'osha'        => 'OSHA — First shipment + when SDS updated',
                        'osha_6mo'    => 'OSHA + Every 6mo — Same as OSHA, plus every 6 months',
                        'every_order' => 'Every Order — With every shipment',
                    ];
                    foreach ($modes as $val => $label):
                    ?>
                        <option value="<?= e($val) ?>" <?= $currentMode === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>SDS Languages</label>
                <small class="text-muted" style="display: block; margin-bottom: 4px;">Select which language(s) of SDS to send to this customer.</small>
                <?php
                $currentLangs = array_map('trim', explode(',', old('sds_languages', $item['sds_languages'] ?? 'en')));
                $langOptions = ['en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German'];
                foreach ($langOptions as $code => $name):
                ?>
                <label style="display: inline-block; margin-right: 12px; font-weight: normal;">
                    <input type="checkbox" name="sds_languages[]" value="<?= e($code) ?>"
                        <?= in_array($code, $currentLangs) ? 'checked' : '' ?>>
                    <?= e($name) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="form-group">
                <label for="is_active">Status</label>
                <select id="is_active" name="is_active">
                    <option value="1" <?= ((int) old('is_active', (string) ($item['is_active'] ?? 1))) === 1 ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= ((int) old('is_active', (string) ($item['is_active'] ?? 1))) === 0 ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?> Customer</button>
            <a href="/customers" class="btn btn-outline">Cancel</a>
            <?php if ($isEdit && can_edit('customers')): ?>
                <button type="submit" form="deleteCustomerForm" class="btn btn-danger">Delete</button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($isEdit && can_edit('customers')): ?>
    <form method="POST" action="/customers/<?= (int) $item['id'] ?>/delete" id="deleteCustomerForm" style="display:none" onsubmit="return confirm('Delete this customer? SDS send history will also be removed.');">
        <?= csrf_field() ?>
    </form>
    <?php endif; ?>
</div>

<?php if ($isEdit && !empty($shipmentOrders ?? [])): ?>
<div class="card" style="margin-top: 16px;">
    <h3>Shipment Orders</h3>
    <p class="text-muted">Select orders to send SDSs for, then click the button below.</p>

    <form method="POST" action="/customers/<?= (int) $item['id'] ?>/send-orders" id="sendOrdersForm">
        <?= csrf_field() ?>

        <table class="table" id="shipmentOrdersTable">
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="checkAllOrders" title="Select all"></th>
                    <th>Order #</th>
                    <th>Ship Date</th>
                    <th>Items</th>
                    <th style="width: 30px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($shipmentOrders as $key => $order): ?>
                <tr class="order-row" data-order-key="<?= e($key) ?>">
                    <td><input type="checkbox" name="orders[]" value="<?= e($key) ?>" class="order-checkbox"></td>
                    <td><?= e($order['order_number'] ?: '—') ?></td>
                    <td><?= e($order['date_shipped'] ?: '—') ?></td>
                    <td><?= count($order['items']) ?> item(s)</td>
                    <td><button type="button" class="btn btn-sm btn-outline toggle-items" title="Show items">+</button></td>
                </tr>
                <tr class="order-items-row" style="display: none;" data-order-key="<?= e($key) ?>">
                    <td colspan="5" style="padding: 0 0 0 40px; background: var(--bg-secondary, #f8f9fa);">
                        <table class="table" style="margin: 0; border: none;">
                            <thead>
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th>Description</th>
                                    <th>Qty Shipped</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($order['items'] as $lineItem): ?>
                                <tr>
                                    <td><?= e($lineItem['item_code'] ?? '') ?></td>
                                    <td><?= e($lineItem['item_name'] ?? '') ?></td>
                                    <td><?= e($lineItem['item_description'] ?: ($lineItem['item_name_description'] ?? '')) ?></td>
                                    <td><?= e($lineItem['quantity_shipped'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="sendOrdersBtn" disabled>Send All SDSs for Checked Orders</button>
            <span class="text-muted" id="selectedCount">0 orders selected</span>
        </div>
    </form>
</div>

<script>
(function() {
    var checkAll = document.getElementById('checkAllOrders');
    var boxes = document.querySelectorAll('.order-checkbox');
    var btn = document.getElementById('sendOrdersBtn');
    var countLabel = document.getElementById('selectedCount');

    function updateState() {
        var checked = document.querySelectorAll('.order-checkbox:checked').length;
        btn.disabled = checked === 0;
        countLabel.textContent = checked + ' order' + (checked !== 1 ? 's' : '') + ' selected';
        checkAll.checked = checked === boxes.length && boxes.length > 0;
        checkAll.indeterminate = checked > 0 && checked < boxes.length;
    }

    checkAll.addEventListener('change', function() {
        boxes.forEach(function(cb) { cb.checked = checkAll.checked; });
        updateState();
    });
    boxes.forEach(function(cb) { cb.addEventListener('change', updateState); });

    document.querySelectorAll('.toggle-items').forEach(function(toggle) {
        toggle.addEventListener('click', function() {
            var row = this.closest('tr');
            var key = row.getAttribute('data-order-key');
            var itemsRow = document.querySelector('.order-items-row[data-order-key="' + key + '"]');
            var visible = itemsRow.style.display !== 'none';
            itemsRow.style.display = visible ? 'none' : '';
            this.textContent = visible ? '+' : '−';
        });
    });

    document.getElementById('sendOrdersForm').addEventListener('submit', function(e) {
        var checked = document.querySelectorAll('.order-checkbox:checked').length;
        if (checked === 0) { e.preventDefault(); return; }
        if (!confirm('Send SDSs for ' + checked + ' order(s) to ' + <?= json_encode($item['regulatory_email'] ?? '') ?> + '?')) {
            e.preventDefault();
        }
    });
})();
</script>
<?php elseif ($isEdit): ?>
<div class="card" style="margin-top: 16px;">
    <h3>Shipment Orders</h3>
    <p class="text-muted">No shipments have been imported for this customer yet.</p>
</div>
<?php endif; ?>

<?php if ($isEdit && !empty($sendHistory)): ?>
<div class="card" style="margin-top: 16px;">
    <h3>SDS Send History</h3>
    <p class="text-muted"><?= count($sendHistory) ?> record(s). This log proves compliance for regulatory audits.</p>

    <table class="table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Product Code</th>
                <th>SDS Version</th>
                <th>Language(s)</th>
                <th>Sent</th>
                <th>Shipment Date</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sendHistory as $log): ?>
            <tr>
                <td><strong><?= e($log['item_identifier']) ?></strong></td>
                <td><?= e($log['product_code'] ?? '—') ?></td>
                <td>v<?= (int) ($log['sds_version'] ?? 0) ?></td>
                <td><?= e(strtoupper($log['language'] ?? 'en')) ?></td>
                <td><?= e($log['sent_at'] ?? '') ?></td>
                <td><?= e($log['shipment_date'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($isEdit): ?>
<div class="card" style="margin-top: 16px;">
    <h3>SDS Send History</h3>
    <p class="text-muted">No SDSs have been sent to this customer yet.</p>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
