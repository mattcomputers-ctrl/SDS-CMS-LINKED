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

        <!-- Old Component -->
        <div class="form-group" style="margin-bottom: 0.75rem;">
            <label><strong>Old Component Type</strong> (to be replaced)</label>
            <select name="old_type" id="old_type" style="width: 100%;">
                <option value="raw_material" selected>Raw Material</option>
                <option value="finished_good">Finished Good</option>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 1.25rem;">
            <select name="old_raw_material_id" id="old_raw_material_id" style="width: 100%;" class="searchable-select old-component-select">
                <option value="">-- Select Raw Material --</option>
                <?php foreach ($rawMaterials as $rm): ?>
                    <option value="<?= (int) $rm['id'] ?>">
                        <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="old_finished_good_id" id="old_finished_good_id" style="width: 100%; display: none;" disabled class="searchable-select old-component-select">
                <option value="">-- Select Finished Good --</option>
                <?php foreach ($finishedGoods as $fg): ?>
                    <option value="<?= (int) $fg['id'] ?>">
                        <?= e($fg['product_code']) ?> — <?= e($fg['description']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- New Component -->
        <div class="form-group" style="margin-bottom: 0.75rem;">
            <label><strong>New Component Type</strong> (replacement)</label>
            <select name="new_type" id="new_type" style="width: 100%;">
                <option value="raw_material" selected>Raw Material</option>
                <option value="finished_good">Finished Good</option>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 1.25rem;">
            <select name="new_raw_material_id" id="new_raw_material_id" style="width: 100%;" class="searchable-select new-component-select">
                <option value="">-- Select Raw Material --</option>
                <?php foreach ($rawMaterials as $rm): ?>
                    <option value="<?= (int) $rm['id'] ?>">
                        <?= e($rm['internal_code']) ?> — <?= e($rm['supplier_product_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="new_finished_good_id" id="new_finished_good_id" style="width: 100%; display: none;" disabled class="searchable-select new-component-select">
                <option value="">-- Select Finished Good --</option>
                <?php foreach ($finishedGoods as $fg): ?>
                    <option value="<?= (int) $fg['id'] ?>">
                        <?= e($fg['product_code']) ?> — <?= e($fg['description']) ?>
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
            <a href="/" class="btn btn-outline" style="margin-left: 0.5rem;">Cancel</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var oldTypeSelect = document.getElementById('old_type');
    var newTypeSelect = document.getElementById('new_type');
    var oldRmSelect = document.getElementById('old_raw_material_id');
    var oldFgSelect = document.getElementById('old_finished_good_id');
    var newRmSelect = document.getElementById('new_raw_material_id');
    var newFgSelect = document.getElementById('new_finished_good_id');
    var submitBtn = document.getElementById('submit-btn');
    var previewArea = document.getElementById('preview-area');
    var previewText = document.getElementById('preview-text');
    var form = document.getElementById('massReplaceForm');

    function toggleSelects(typeSelect, rmSelect, fgSelect) {
        if (typeSelect.value === 'finished_good') {
            rmSelect.style.display = 'none';
            rmSelect.disabled = true;
            rmSelect.value = '';
            fgSelect.style.display = '';
            fgSelect.disabled = false;
        } else {
            fgSelect.style.display = 'none';
            fgSelect.disabled = true;
            fgSelect.value = '';
            rmSelect.style.display = '';
            rmSelect.disabled = false;
        }
        updateState();
    }

    function getActiveSelect(typeSelect, rmSelect, fgSelect) {
        return typeSelect.value === 'finished_good' ? fgSelect : rmSelect;
    }

    function updateState() {
        var oldActive = getActiveSelect(oldTypeSelect, oldRmSelect, oldFgSelect);
        var newActive = getActiveSelect(newTypeSelect, newRmSelect, newFgSelect);

        var oldVal = oldActive.value;
        var newVal = newActive.value;
        var oldType = oldTypeSelect.value;
        var newType = newTypeSelect.value;
        var sameType = (oldType === newType);
        var valid = oldVal !== '' && newVal !== '' && !(sameType && oldVal === newVal);

        submitBtn.disabled = !valid;

        if (valid) {
            var oldText = oldActive.options[oldActive.selectedIndex].text.trim();
            var newText = newActive.options[newActive.selectedIndex].text.trim();
            var oldTypeLabel = oldType === 'finished_good' ? 'FG' : 'RM';
            var newTypeLabel = newType === 'finished_good' ? 'FG' : 'RM';
            previewText.textContent = 'Replace ' + oldTypeLabel + ' "' + oldText + '" with ' + newTypeLabel + ' "' + newText + '" in all current formulas.';
            previewArea.style.display = 'block';
        } else if (sameType && oldVal !== '' && newVal !== '' && oldVal === newVal) {
            previewText.textContent = 'Old and new components must be different.';
            previewArea.style.display = 'block';
        } else {
            previewArea.style.display = 'none';
        }
    }

    oldTypeSelect.addEventListener('change', function () {
        toggleSelects(oldTypeSelect, oldRmSelect, oldFgSelect);
    });
    newTypeSelect.addEventListener('change', function () {
        toggleSelects(newTypeSelect, newRmSelect, newFgSelect);
    });

    oldRmSelect.addEventListener('change', updateState);
    oldFgSelect.addEventListener('change', updateState);
    newRmSelect.addEventListener('change', updateState);
    newFgSelect.addEventListener('change', updateState);

    form.addEventListener('submit', function (e) {
        var oldActive = getActiveSelect(oldTypeSelect, oldRmSelect, oldFgSelect);
        var newActive = getActiveSelect(newTypeSelect, newRmSelect, newFgSelect);
        var oldText = oldActive.options[oldActive.selectedIndex].text.trim();
        var newText = newActive.options[newActive.selectedIndex].text.trim();
        var oldTypeLabel = oldTypeSelect.value === 'finished_good' ? 'Finished Good' : 'Raw Material';
        var newTypeLabel = newTypeSelect.value === 'finished_good' ? 'Finished Good' : 'Raw Material';
        var msg = 'Are you sure you want to replace:\n\n'
            + '  ' + oldTypeLabel + ': "' + oldText + '"\n\nwith:\n\n'
            + '  ' + newTypeLabel + ': "' + newText + '"\n\n'
            + 'in ALL current formulas?\n\n'
            + 'New formula versions will be created. This cannot be easily undone.';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
