<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/finished-goods/' . (int) $item['id'] : '/finished-goods';
?>

<div class="card">
    <form method="POST" action="<?= $action ?>">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label for="product_code">Product Code *</label>
                <input type="text" id="product_code" name="product_code"
                       value="<?= e(old('product_code', $item['product_code'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="family">Product Family</label>
                <input type="text" id="family" name="family" list="family-list"
                       value="<?= e(old('family', $item['family'] ?? '')) ?>"
                       placeholder="UV offset, aqueous, solvent...">
                <datalist id="family-list">
                    <?php foreach ($families as $f): ?>
                        <option value="<?= e($f) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group full-width">
                <label for="description">Description</label>
                <input type="text" id="description" name="description"
                       value="<?= e(old('description', $item['description'] ?? '')) ?>">
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
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?></button>
            <a href="/finished-goods" class="btn btn-outline">Cancel</a>
            <?php if ($isEdit): ?>
                <a href="/formulas/<?= (int) $item['id'] ?>" class="btn btn-outline">Manage Formula</a>
                <a href="/sds/<?= (int) $item['id'] ?>" class="btn btn-outline">View SDS</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
