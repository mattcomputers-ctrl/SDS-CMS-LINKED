<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/admin/exempt-vocs">&larr; Back to Exempt VOC Library</a></p>

<div class="card">
    <form method="POST" action="<?= $mode === 'create' ? '/admin/exempt-vocs' : '/admin/exempt-vocs/' . (int)$item['id'] ?>">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number</label>
                <input type="text" name="cas_number" value="<?= e($item['cas_number'] ?? ($_SESSION['_flash']['_old_input']['cas_number'] ?? '')) ?>" <?= $mode === 'edit' ? 'readonly' : '' ?> required placeholder="e.g. 67-64-1">
            </div>
            <div class="form-group">
                <label>Chemical Name</label>
                <input type="text" name="chemical_name" value="<?= e($item['chemical_name'] ?? ($_SESSION['_flash']['_old_input']['chemical_name'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label>Regulation Reference</label>
                <input type="text" name="regulation_ref" value="<?= e($item['regulation_ref'] ?? ($_SESSION['_flash']['_old_input']['regulation_ref'] ?? '')) ?>" placeholder="e.g. 40 CFR 51.100(s)">
            </div>
            <div class="form-group">
                <label>Notes (optional)</label>
                <input type="text" name="notes" value="<?= e($item['notes'] ?? ($_SESSION['_flash']['_old_input']['notes'] ?? '')) ?>">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $mode === 'create' ? 'Add Exempt VOC' : 'Update' ?></button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
