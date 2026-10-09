<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$oldInput = $_SESSION['_flash']['_old_input'] ?? [];
// Clear after read so it doesn't sticky on subsequent loads
unset($_SESSION['_flash']['_old_input']);

$casVal   = $item['cas_number']    ?? ($oldInput['cas_number']    ?? '');
$nameVal  = $item['chemical_name'] ?? ($oldInput['chemical_name'] ?? '');
$flagsVal = $item['flags']         ?? ($oldInput['flags']         ?? '');

// Active: DB value on edit; old input (checkbox present) after a failed
// submit; checked by default on a fresh create form.
if ($item !== null) {
    $activeVal = (int) ($item['is_active_inventory'] ?? 1) === 1;
} elseif ($oldInput !== []) {
    $activeVal = isset($oldInput['is_active_inventory']);
} else {
    $activeVal = true;
}

$currentSource = $item['source_ref'] ?? null;
$sourceLabel = 'New';
if ($currentSource === 'manual') {
    $sourceLabel = 'Manual';
} elseif ($mode === 'edit') {
    $sourceLabel = trim('EPA ' . (string) ($item['source_version'] ?? ''));
}
?>

<p><a href="/tsca">&larr; Back to TSCA Inventory</a></p>

<div class="card">
    <form method="POST" action="<?= $mode === 'create' ? '/tsca' : '/tsca/' . rawurlencode($item['cas_number']) ?>">
        <?= csrf_field() ?>

        <?php if ($mode === 'edit' && $currentSource !== 'manual'): ?>
            <div style="background:#fef3c7; border:1px solid #f59e0b; color:#78350f; padding:0.5rem 0.75rem; border-radius:4px; margin-bottom:1rem;">
                <strong>Note:</strong> this entry is currently tagged <code><?= e($sourceLabel) ?></code>.
                Saving re-tags it as <strong>Manual</strong>, protecting it from future EPA imports.
            </div>
        <?php endif; ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number</label>
                <input type="text" name="cas_number" value="<?= e($casVal) ?>"
                       <?= $mode === 'edit' ? 'readonly' : 'required' ?>
                       placeholder="e.g. 108-88-3">
                <?php if ($mode === 'edit'): ?>
                    <small class="text-muted">CAS is the key — delete and re-create to change it.</small>
                <?php else: ?>
                    <small class="text-muted">Format: digits-digits-digit (1–7 / 2 / 1).</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Chemical Name</label>
                <input type="text" name="chemical_name" value="<?= e($nameVal) ?>" required>
            </div>
            <div class="form-group">
                <label style="display:inline-flex; align-items:center; gap:0.35rem; font-weight:normal;">
                    <input type="checkbox" name="is_active_inventory" value="1" <?= $activeVal ? 'checked' : '' ?>>
                    Active on the EPA inventory (unchecked = INACTIVE, still listed)
                </label>
            </div>
            <div class="form-group">
                <label>Flags (optional)</label>
                <input type="text" name="flags" value="<?= e($flagsVal) ?>" maxlength="50">
                <small class="text-muted">EPA FLAG column, e.g. S, XU, T, P, Y1, Y2 — informational.</small>
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
            <a href="/tsca" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
