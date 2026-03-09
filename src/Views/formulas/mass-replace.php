<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/raw-materials">&larr; Back to Raw Materials</a></p>

<div class="card" style="max-width: 700px;">
    <p class="text-muted">
        Select an old raw material and a new raw material. Every current formula that contains the
        old raw material will be updated: a new formula version is created with the old RM swapped
        1:1 for the new RM (same percentage). Previous formula versions are preserved for audit.
    </p>

    <form method="POST" action="/formulas/mass-replace" id="massReplaceForm">
        <?= csrf_field() ?>

        <div class="form-group" style="margin-bottom: 1.25rem;">
            <label for="old_raw_material_id"><strong>Old Raw Material</strong> (to be replaced)</label>
            <select name="old_raw_material_id" id="old_raw_material_id" required style="width: 100%;" class="searchable-select">
                <option value="">-- Select Old Raw Material --</option>
                <?php foreach ($rawMaterials as $rm): ?>
                    <option value="<?= (int) $rm['id'] ?>">
                        <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 1.25rem;">
            <label for="new_raw_material_id"><strong>New Raw Material</strong> (replacement)</label>
            <select name="new_raw_material_id" id="new_raw_material_id" required style="width: 100%;" class="searchable-select">
                <option value="">-- Select New Raw Material --</option>
                <?php foreach ($rawMaterials as $rm): ?>
                    <option value="<?= (int) $rm['id'] ?>">
                        <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="preview-area" style="display: none; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 1rem; margin-bottom: 1.25rem;">
            <strong>Summary:</strong>
            <p id="preview-text" style="margin: 0.5rem 0 0;"></p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Submit Replacement</button>
            <a href="/raw-materials" class="btn btn-outline" style="margin-left: 0.5rem;">Cancel</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var oldSelect = document.getElementById('old_raw_material_id');
    var newSelect = document.getElementById('new_raw_material_id');
    var submitBtn = document.getElementById('submit-btn');
    var previewArea = document.getElementById('preview-area');
    var previewText = document.getElementById('preview-text');
    var form = document.getElementById('massReplaceForm');

    function updateState() {
        var oldVal = oldSelect.value;
        var newVal = newSelect.value;
        var valid = oldVal !== '' && newVal !== '' && oldVal !== newVal;

        submitBtn.disabled = !valid;

        if (valid) {
            var oldText = oldSelect.options[oldSelect.selectedIndex].text.trim();
            var newText = newSelect.options[newSelect.selectedIndex].text.trim();
            previewText.textContent = 'Replace "' + oldText + '" with "' + newText + '" in all current formulas.';
            previewArea.style.display = 'block';
        } else if (oldVal !== '' && newVal !== '' && oldVal === newVal) {
            previewText.textContent = 'Old and new raw materials must be different.';
            previewArea.style.display = 'block';
        } else {
            previewArea.style.display = 'none';
        }
    }

    oldSelect.addEventListener('change', updateState);
    newSelect.addEventListener('change', updateState);

    form.addEventListener('submit', function (e) {
        var oldText = oldSelect.options[oldSelect.selectedIndex].text.trim();
        var newText = newSelect.options[newSelect.selectedIndex].text.trim();
        var msg = 'Are you sure you want to replace:\n\n'
            + '  "' + oldText + '"\n\nwith:\n\n'
            + '  "' + newText + '"\n\n'
            + 'in ALL current formulas?\n\n'
            + 'New formula versions will be created. This cannot be easily undone.';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
