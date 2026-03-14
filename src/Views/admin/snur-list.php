<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h2>EPA Significant New Use Rules (SNUR)</h2>
    <p class="text-muted mb-1">
        Manage CAS numbers subject to EPA Significant New Use Rules (40 CFR Part 721).
        When a finished good's formula contains any of these chemicals, it will be flagged in SDS Section 15.
        You can also flag individual raw materials as SNUR-applicable on their edit page.
    </p>

    <!-- Add / Update Form -->
    <form method="POST" action="/admin/snur-list" style="margin-bottom: 2rem; padding: 1rem; background: #f8f9fa; border-radius: 4px;">
        <?= csrf_field() ?>
        <h3 style="margin-top: 0;">Add SNUR Entry</h3>
        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number *</label>
                <input type="text" name="cas_number" id="snur-cas-number" required placeholder="e.g. 68084-62-8" style="font-family: monospace;">
            </div>
            <div class="form-group">
                <label>Chemical Name *</label>
                <input type="text" name="chemical_name" id="snur-chemical-name" required placeholder="e.g. Acrylonitrile-styrene copolymer">
            </div>
            <div class="form-group">
                <label>Rule Citation</label>
                <input type="text" name="rule_citation" placeholder="e.g. 40 CFR 721.10536">
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" placeholder="Brief description of SNUR requirements">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Add / Update SNUR</button>
    </form>

    <!-- Current SNUR List -->
    <?php if (empty($snurs)): ?>
        <p class="text-muted">No SNUR entries have been added yet.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>CAS Number</th>
                    <th>Chemical Name</th>
                    <th>Rule Citation</th>
                    <th>Description</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($snurs as $snur): ?>
                <tr>
                    <td style="font-family: monospace;"><?= e($snur['cas_number']) ?></td>
                    <td><?= e($snur['chemical_name']) ?></td>
                    <td><?= e($snur['rule_citation'] ?? '') ?></td>
                    <td style="max-width: 300px; font-size: 0.85rem;"><?= e($snur['description'] ?? '') ?></td>
                    <td>
                        <form method="POST" action="/admin/snur-list/<?= (int) $snur['id'] ?>/delete"
                              onsubmit="return confirm('Remove this SNUR entry?');" style="display: inline;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
document.getElementById('snur-cas-number').addEventListener('blur', function () {
    var cas = this.value.trim();
    var nameField = document.getElementById('snur-chemical-name');
    if (!cas || nameField.value.trim() !== '') return;
    if (!/^\d{2,7}-\d{2}-\d$/.test(cas)) return;

    fetch('/raw-materials/cas-lookup?cas=' + encodeURIComponent(cas))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.found && data.chemical_name && nameField.value.trim() === '') {
                nameField.value = data.chemical_name;
            }
        })
        .catch(function () { /* silently ignore lookup failures */ });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
