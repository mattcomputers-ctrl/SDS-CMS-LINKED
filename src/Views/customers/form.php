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
                <form method="POST" action="/customers/<?= (int) $item['id'] ?>/delete" style="display: inline;" onsubmit="return confirm('Delete this customer? SDS send history will also be removed.');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
