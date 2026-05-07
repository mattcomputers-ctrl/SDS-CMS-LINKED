<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$oldInput = $_SESSION['_flash']['_old_input'] ?? [];
unset($_SESSION['_flash']['_old_input']);

$casVal      = $item['cas_number']    ?? ($oldInput['cas_number']    ?? '');
$nameVal     = $item['chemical_name'] ?? ($oldInput['chemical_name'] ?? '');
$categoryVal = $item['category']      ?? ($oldInput['category']      ?? '');

$currentSource = $item['source_ref'] ?? null;
$sourceLabel = 'New';
if ($currentSource === 'manual') {
    $sourceLabel = 'Manual';
} elseif ($mode === 'edit') {
    $sourceLabel = 'Seed';
}
?>

<p><a href="/haps">&larr; Back to HAP List</a></p>

<div class="card">
    <form method="POST" action="<?= $mode === 'create' ? '/haps' : '/haps/' . (int) $item['id'] ?>">
        <?= csrf_field() ?>

        <?php if ($mode === 'edit' && $currentSource !== 'manual'): ?>
            <div style="background:#fef3c7; border:1px solid #f59e0b; color:#78350f; padding:0.5rem 0.75rem; border-radius:4px; margin-bottom:1rem;">
                <strong>Note:</strong> this entry is currently tagged <code><?= e($sourceLabel) ?></code>.
                Saving will re-tag it as <strong>Manual</strong>.
            </div>
        <?php endif; ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number</label>
                <input type="text" name="cas_number" value="<?= e($casVal) ?>"
                       <?= $mode === 'edit' ? 'readonly' : 'required' ?>
                       placeholder="e.g. 75-07-0">
                <?php if ($mode === 'edit'): ?>
                    <small class="text-muted">CAS is the unique key — delete and re-create to change it.</small>
                <?php else: ?>
                    <small class="text-muted">Format: digits-digits-digit (1–7 / 2 / 1).</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Chemical Name</label>
                <input type="text" name="chemical_name" value="<?= e($nameVal) ?>" required>
            </div>
            <div class="form-group">
                <label>Category (optional)</label>
                <input type="text" name="category" value="<?= e($categoryVal ?? '') ?>"
                       placeholder="e.g. Organic, Metal, Particulate, VOC"
                       list="hap-category-suggestions">
                <datalist id="hap-category-suggestions">
                    <option value="VOC">
                    <option value="Organic">
                    <option value="Metal">
                    <option value="Metal Compound">
                    <option value="Particulate">
                    <option value="Inorganic">
                    <option value="PAH">
                    <option value="Halogenated">
                </datalist>
                <small class="text-muted">Free-text label that groups HAPs in admin views. Leave blank if uncategorized.</small>
            </div>
            <?php if ($mode === 'edit'): ?>
                <div class="form-group">
                    <label>Current Source</label>
                    <input type="text" value="<?= e($sourceLabel) ?>" readonly>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $mode === 'create' ? 'Add Entry' : 'Update Entry' ?></button>
            <a href="/haps" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
