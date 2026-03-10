<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/">&larr; Back to Dashboard</a></p>

<div class="card" style="max-width: 700px;">
    <p class="text-muted">
        Select an old component and a new component. Every current formula that contains the
        old component will be updated: a new formula version is created with the old component swapped
        1:1 for the new component (same percentage). Previous formula versions are preserved for audit.
    </p>

    <form method="POST" action="/formulas/mass-replace" id="massReplaceForm">
        <?= csrf_field() ?>
        <input type="hidden" name="old_type" id="old_type" value="">
        <input type="hidden" name="old_raw_material_id" id="old_raw_material_id" value="">
        <input type="hidden" name="old_finished_good_id" id="old_finished_good_id" value="">
        <input type="hidden" name="new_type" id="new_type" value="">
        <input type="hidden" name="new_raw_material_id" id="new_raw_material_id" value="">
        <input type="hidden" name="new_finished_good_id" id="new_finished_good_id" value="">

        <div class="form-group" style="margin-bottom: 1.25rem;">
            <label for="old_component"><strong>Old Component</strong> (to be replaced)</label>
            <select id="old_component" required style="width: 100%;" class="searchable-select">
                <option value="">-- Select Component --</option>
                <optgroup label="Raw Materials">
                    <?php foreach ($rawMaterials as $rm): ?>
                        <option value="rm_<?= (int) $rm['id'] ?>" data-type="raw_material" data-id="<?= (int) $rm['id'] ?>">
                            <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Finished Goods">
                    <?php foreach ($finishedGoods as $fg): ?>
                        <option value="fg_<?= (int) $fg['id'] ?>" data-type="finished_good" data-id="<?= (int) $fg['id'] ?>">
                            <?= e($fg['product_code']) ?> — <?= e($fg['description']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 1.25rem;">
            <label for="new_component"><strong>New Component</strong> (replacement)</label>
            <select id="new_component" required style="width: 100%;" class="searchable-select">
                <option value="">-- Select Component --</option>
                <optgroup label="Raw Materials">
                    <?php foreach ($rawMaterials as $rm): ?>
                        <option value="rm_<?= (int) $rm['id'] ?>" data-type="raw_material" data-id="<?= (int) $rm['id'] ?>">
                            <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Finished Goods">
                    <?php foreach ($finishedGoods as $fg): ?>
                        <option value="fg_<?= (int) $fg['id'] ?>" data-type="finished_good" data-id="<?= (int) $fg['id'] ?>">
                            <?= e($fg['product_code']) ?> — <?= e($fg['description']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>

        <div id="preview-area" style="display: none; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 1rem; margin-bottom: 1.25rem;">
            <strong>Summary:</strong>
            <p id="preview-text" style="margin: 0.5rem 0 0;"></p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Submit Replacement</button>
            <a href="/" class="btn btn-outline" style="margin-left: 0.5rem;">Cancel</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var oldSelect = document.getElementById('old_component');
    var newSelect = document.getElementById('new_component');
    var submitBtn = document.getElementById('submit-btn');
    var previewArea = document.getElementById('preview-area');
    var previewText = document.getElementById('preview-text');
    var form = document.getElementById('massReplaceForm');

    function getSelected(selectEl) {
        var opt = selectEl.options[selectEl.selectedIndex];
        if (!opt || !opt.value) return null;
        return {
            value: opt.value,
            type: opt.getAttribute('data-type'),
            id: opt.getAttribute('data-id'),
            text: opt.text.trim()
        };
    }

    function syncHidden() {
        var old = getSelected(oldSelect);
        var nw = getSelected(newSelect);

        // Reset hidden fields
        document.getElementById('old_type').value = old ? old.type : '';
        document.getElementById('old_raw_material_id').value = (old && old.type === 'raw_material') ? old.id : '';
        document.getElementById('old_finished_good_id').value = (old && old.type === 'finished_good') ? old.id : '';
        document.getElementById('new_type').value = nw ? nw.type : '';
        document.getElementById('new_raw_material_id').value = (nw && nw.type === 'raw_material') ? nw.id : '';
        document.getElementById('new_finished_good_id').value = (nw && nw.type === 'finished_good') ? nw.id : '';
    }

    function updateState() {
        syncHidden();

        var old = getSelected(oldSelect);
        var nw = getSelected(newSelect);

        var valid = old && nw && old.value !== nw.value;
        submitBtn.disabled = !valid;

        if (old && nw && old.value === nw.value) {
            previewText.textContent = 'Old and new components must be different.';
            previewArea.style.display = 'block';
        } else if (valid) {
            previewText.textContent = 'Replace "' + old.text + '" with "' + nw.text + '" in all current formulas.';
            previewArea.style.display = 'block';
        } else {
            previewArea.style.display = 'none';
        }
    }

    oldSelect.addEventListener('change', updateState);
    newSelect.addEventListener('change', updateState);

    form.addEventListener('submit', function (e) {
        var old = getSelected(oldSelect);
        var nw = getSelected(newSelect);
        var msg = 'Are you sure you want to replace:\n\n'
            + '  "' + old.text + '"\n\nwith:\n\n'
            + '  "' + nw.text + '"\n\n'
            + 'in ALL current formulas?\n\n'
            + 'New formula versions will be created. This cannot be easily undone.';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
