<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$oldInput = $_SESSION['_flash']['_old_input'] ?? [];
// Clear after read so it doesn't sticky on subsequent loads
unset($_SESSION['_flash']['_old_input']);

$casVal   = $item['cas_number']    ?? ($oldInput['cas_number']    ?? '');
$nameVal  = $item['chemical_name'] ?? ($oldInput['chemical_name'] ?? '');
$dateVal  = $item['date_listed']   ?? ($oldInput['date_listed']   ?? '');

// Current toxicity selection — from DB (comma string) or old input (array)
$currentTox = [];
if (!empty($oldInput['toxicity_type'])) {
    $currentTox = is_array($oldInput['toxicity_type'])
        ? $oldInput['toxicity_type']
        : explode(',', (string) $oldInput['toxicity_type']);
} elseif (!empty($item['toxicity_type'])) {
    $currentTox = array_map('trim', explode(',', (string) $item['toxicity_type']));
}
$currentTox = array_map('trim', $currentTox);

$currentSource = $item['source_ref'] ?? null;
$sourceLabel = 'New';
if ($currentSource === 'manual') {
    $sourceLabel = 'Manual';
} elseif ($currentSource !== null && str_starts_with($currentSource, 'OEHHA')) {
    $sourceLabel = $currentSource;
} elseif ($mode === 'edit') {
    $sourceLabel = 'Seed';
}
?>

<p><a href="/prop65">&larr; Back to Prop 65 List</a></p>

<div class="card">
    <form method="POST" action="<?= $mode === 'create' ? '/prop65' : '/prop65/' . (int) $item['id'] ?>">
        <?= csrf_field() ?>

        <?php if ($mode === 'edit' && $currentSource !== 'manual'): ?>
            <div style="background:#fef3c7; border:1px solid #f59e0b; color:#78350f; padding:0.5rem 0.75rem; border-radius:4px; margin-bottom:1rem;">
                <strong>Note:</strong> this entry is currently tagged <code><?= e($sourceLabel) ?></code>.
                Saving will re-tag it as <strong>Manual</strong>, which protects it from being
                overwritten by future OEHHA imports.
            </div>
        <?php endif; ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number</label>
                <input type="text" name="cas_number" value="<?= e($casVal) ?>"
                       <?= $mode === 'edit' ? 'readonly' : 'required' ?>
                       placeholder="e.g. 15625-89-5">
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
            <div class="form-group" style="grid-column: span 2;">
                <label>Toxicity Type</label>
                <div style="display:flex; flex-wrap:wrap; gap:0.75rem 1.5rem;">
                    <?php foreach ($toxicityOptions as $opt): ?>
                        <label style="display:inline-flex; align-items:center; gap:0.35rem; font-weight:normal;">
                            <input type="checkbox" name="toxicity_type[]" value="<?= e($opt) ?>"
                                   <?= in_array($opt, $currentTox, true) ? 'checked' : '' ?>>
                            <?= e($opt) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small class="text-muted">At least one is required. Multiple types are allowed.</small>
            </div>
            <div class="form-group">
                <label>Date Listed (optional)</label>
                <input type="date" name="date_listed" value="<?= e($dateVal ?? '') ?>">
                <small class="text-muted">When OEHHA added this chemical to the Prop 65 list.</small>
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
            <a href="/prop65" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
