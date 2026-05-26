<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/admin/sara313">&larr; Back to SARA 313 List</a></p>

<div class="card">
    <form method="POST" action="<?= $mode === 'create' ? '/admin/sara313' : '/admin/sara313/' . (int) $item['id'] ?>">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number</label>
                <input type="text" name="cas_number" value="<?= e($item['cas_number'] ?? ($_SESSION['_flash']['_old_input']['cas_number'] ?? '')) ?>" <?= $mode === 'edit' ? 'readonly' : '' ?> required placeholder="e.g. 7440-02-0">
            </div>
            <div class="form-group">
                <label>Chemical Name</label>
                <input type="text" name="chemical_name" value="<?= e($item['chemical_name'] ?? ($_SESSION['_flash']['_old_input']['chemical_name'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label>Category Code</label>
                <input type="text" name="category_code" value="<?= e($item['category_code'] ?? ($_SESSION['_flash']['_old_input']['category_code'] ?? '')) ?>" placeholder="Optional">
            </div>
            <div class="form-group">
                <label>De Minimis Threshold (%)</label>
                <input type="number" name="deminimis_pct" step="0.0001" min="0" max="100" value="<?= e((string) ($item['deminimis_pct'] ?? ($_SESSION['_flash']['_old_input']['deminimis_pct'] ?? '1.0'))) ?>" required>
                <small class="text-muted">Standard: 1.0%. PBT chemicals typically use 0.1%.</small>
            </div>
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" name="is_pbt" value="1" <?= !empty($item['is_pbt']) || !empty($_SESSION['_flash']['_old_input']['is_pbt']) ? 'checked' : '' ?>>
                    Persistent Bioaccumulative Toxic (PBT)
                </label>
            </div>
            <div class="form-group">
                <label>PBT Threshold (%) — if different from De Minimis</label>
                <input type="number" name="pbt_threshold_pct" step="0.0001" min="0" max="100" value="<?= e((string) ($item['pbt_threshold_pct'] ?? ($_SESSION['_flash']['_old_input']['pbt_threshold_pct'] ?? ''))) ?>" placeholder="Leave blank to use de minimis">
            </div>
            <div class="form-group full-width">
                <label>Source Reference</label>
                <input type="text" name="source_ref" value="<?= e($item['source_ref'] ?? ($_SESSION['_flash']['_old_input']['source_ref'] ?? '')) ?>" placeholder="e.g. 40 CFR 372.65">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $mode === 'create' ? 'Add to SARA 313 List' : 'Update' ?></button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
