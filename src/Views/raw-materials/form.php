<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/raw-materials/' . (int) $item['id'] : '/raw-materials';
?>

<div class="card">
    <form method="POST" action="<?= $action ?>">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="updated_at" value="<?= e($item['updated_at'] ?? '') ?>">
        <?php endif; ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label for="internal_code">Internal Code *</label>
                <input type="text" id="internal_code" name="internal_code"
                       value="<?= e(old('internal_code', $item['internal_code'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="supplier">Supplier</label>
                <input type="text" id="supplier" name="supplier"
                       value="<?= e(old('supplier', $item['supplier'] ?? '')) ?>">
            </div>
            <div class="form-group full-width">
                <label for="supplier_product_name">Supplier Product Name</label>
                <input type="text" id="supplier_product_name" name="supplier_product_name"
                       value="<?= e(old('supplier_product_name', $item['supplier_product_name'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="voc_wt">VOC wt%</label>
                <input type="number" id="voc_wt" name="voc_wt" step="0.0001"
                       value="<?= e(old('voc_wt', $item['voc_wt'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="exempt_voc_wt">Exempt VOC wt%</label>
                <input type="number" id="exempt_voc_wt" name="exempt_voc_wt" step="0.0001"
                       value="<?= e(old('exempt_voc_wt', $item['exempt_voc_wt'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="water_wt">Water wt%</label>
                <input type="number" id="water_wt" name="water_wt" step="0.0001"
                       value="<?= e(old('water_wt', $item['water_wt'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="specific_gravity">Specific Gravity</label>
                <input type="number" id="specific_gravity" name="specific_gravity" step="0.00001"
                       value="<?= e(old('specific_gravity', $item['specific_gravity'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="solids_wt">Solids wt%</label>
                <input type="number" id="solids_wt" name="solids_wt" step="0.0001"
                       value="<?= e(old('solids_wt', $item['solids_wt'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="solids_vol">Solids vol%</label>
                <input type="number" id="solids_vol" name="solids_vol" step="0.0001"
                       value="<?= e(old('solids_vol', $item['solids_vol'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="flash_point_c">Flash Point (C)</label>
                <input type="number" id="flash_point_c" name="flash_point_c" step="0.1"
                       value="<?= e(old('flash_point_c', $item['flash_point_c'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="physical_state">Physical State</label>
                <select id="physical_state" name="physical_state">
                    <option value="">—</option>
                    <?php foreach (['Liquid', 'Solid', 'Paste', 'Powder', 'Gas'] as $state): ?>
                        <option value="<?= $state ?>" <?= (old('physical_state', $item['physical_state'] ?? '') === $state) ? 'selected' : '' ?>><?= $state ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full-width">
                <label for="appearance">Appearance</label>
                <input type="text" id="appearance" name="appearance"
                       value="<?= e(old('appearance', $item['appearance'] ?? '')) ?>">
            </div>
            <div class="form-group full-width">
                <label for="odor">Odor</label>
                <input type="text" id="odor" name="odor"
                       value="<?= e(old('odor', $item['odor'] ?? '')) ?>">
            </div>
            <div class="form-group full-width">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3"><?= e(old('notes', $item['notes'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?> Raw Material</button>
            <a href="/raw-materials" class="btn btn-outline">Cancel</a>
            <?php if ($isEdit): ?>
                <a href="/raw-materials/<?= (int) $item['id'] ?>/constituents" class="btn btn-outline">Manage Constituents</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($isEdit && is_admin()): ?>
<div class="card card-danger">
    <h3>Danger Zone</h3>
    <form method="POST" action="/raw-materials/<?= (int) $item['id'] ?>/delete" onsubmit="return confirm('Delete this raw material? This cannot be undone.');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">Delete Raw Material</button>
    </form>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
