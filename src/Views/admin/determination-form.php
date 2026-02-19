<?php include dirname(__DIR__) . '/layouts/main.php'; ?>
<?php $old = $_SESSION['_flash']['_old_input'] ?? []; ?>

<p><a href="/admin/determinations">&larr; Back to Determinations</a></p>

<div class="card">
    <form method="POST" action="<?= $mode === 'create' ? '/admin/determinations' : '/admin/determinations/' . (int)$item['id'] ?>">
        <?= csrf_field() ?>

        <h2><?= $mode === 'create' ? 'New Competent Person Determination' : 'Edit Determination' ?></h2>
        <p class="text-muted mb-1">Document a manual hazard determination for a CAS number lacking federal data. This serves as the basis for SDS hazard entries when no federal source is available.</p>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number</label>
                <input type="text" name="cas_number" value="<?= e($item['cas_number'] ?? ($old['cas_number'] ?? '')) ?>" <?= $mode === 'edit' ? 'readonly' : '' ?> required placeholder="e.g. 12345-67-8">
            </div>
            <div class="form-group">
                <label>Jurisdiction</label>
                <input type="text" name="jurisdiction" value="<?= e($item['jurisdiction'] ?? ($old['jurisdiction'] ?? 'US')) ?>">
            </div>
        </div>

        <?php $det = $item['determination'] ?? []; ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>Hazard Classes (comma-separated)</label>
                <input type="text" name="hazard_classes" value="<?= e($det['hazard_classes'] ?? ($old['hazard_classes'] ?? '')) ?>" placeholder="e.g. Skin Sensitization Cat 1, Eye Irritation Cat 2A">
            </div>
            <div class="form-group">
                <label>Signal Word</label>
                <select name="signal_word">
                    <option value="">— None —</option>
                    <option value="Danger" <?= ($det['signal_word'] ?? '') === 'Danger' ? 'selected' : '' ?>>Danger</option>
                    <option value="Warning" <?= ($det['signal_word'] ?? '') === 'Warning' ? 'selected' : '' ?>>Warning</option>
                </select>
            </div>
            <div class="form-group">
                <label>H-Statements (comma-separated codes)</label>
                <input type="text" name="h_statements" value="<?= e($det['h_statements'] ?? ($old['h_statements'] ?? '')) ?>" placeholder="e.g. H317, H319">
            </div>
            <div class="form-group">
                <label>P-Statements (comma-separated codes)</label>
                <input type="text" name="p_statements" value="<?= e($det['p_statements'] ?? ($old['p_statements'] ?? '')) ?>" placeholder="e.g. P261, P280, P302+P352">
            </div>
            <div class="form-group full-width">
                <label>Basis of Determination</label>
                <input type="text" name="basis" value="<?= e($det['basis'] ?? ($old['basis'] ?? '')) ?>" placeholder="e.g. Supplier SDS, published literature, analogy to similar structure">
            </div>
        </div>

        <div class="form-group">
            <label>Rationale (required)</label>
            <textarea name="rationale_text" rows="5" required placeholder="Explain why this determination was made and what sources were consulted..."><?= e($item['rationale_text'] ?? ($old['rationale_text'] ?? '')) ?></textarea>
        </div>

        <?php if ($mode === 'edit'): ?>
        <div class="form-grid-2col">
            <div class="form-group">
                <label><input type="checkbox" name="is_active" value="1" <?= (int)($item['is_active'] ?? 1) ? 'checked' : '' ?>> Active</label>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="mark_approved" value="1"> Mark as approved by me</label>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $mode === 'create' ? 'Create Determination' : 'Update' ?></button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
