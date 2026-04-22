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
