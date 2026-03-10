<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>CAS Number Determinations</h2>
    <a href="/determinations/create" class="btn btn-primary">+ New CAS Determination</a>
</div>

<p class="text-muted">When federal hazard data is missing for a CAS number, define the hazard determination here. Select hazard statements, H/P codes, and exposure limits. These are clearly marked as non-federal in Section 16.</p>

<!-- Tab Navigation -->
<div class="tab-nav" style="display: flex; gap: 0; border-bottom: 2px solid #003366; margin-bottom: 1rem;">
    <button class="tab-btn active" data-tab="needs" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #003366; color: #fff; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-right: 2px;">
        Needs Determination
        <?php if (!empty($needsDetermination)): ?>
            <span style="background: #dc3545; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($needsDetermination) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="existing" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold;">
        Determinations Made
        <?php if (!empty($items)): ?>
            <span style="background: #28a745; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($items) ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- Tab: Needs Determination -->
<div class="tab-panel" id="tab-needs">
    <?php if (empty($needsDetermination)): ?>
        <div style="text-align: center; padding: 2rem; color: #28a745;">
            <strong>All CAS numbers have federal data or determinations.</strong>
        </div>
    <?php else: ?>
        <p class="text-muted" style="margin-bottom: 0.5rem;">These CAS numbers appear in raw materials but have no federal hazard data or active determination. Click <strong>Create</strong> to enter a determination.</p>
        <table class="table">
            <thead>
                <tr>
                    <th>CAS Number</th>
                    <th>Chemical Name</th>
                    <th>Raw Materials</th>
                    <th>In Formula</th>
                    <th style="width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($needsDetermination as $nd): ?>
                <tr>
                    <td><strong><?= e($nd['cas_number']) ?></strong></td>
                    <td><?= e($nd['chemical_name']) ?></td>
                    <td>
                        <span title="<?= e($nd['raw_material_codes']) ?>"><?= e($nd['raw_material_codes']) ?></span>
                        <?php if ((int)$nd['raw_material_count'] > 1): ?>
                            <small class="text-muted">(<?= (int)$nd['raw_material_count'] ?> materials)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$nd['in_formula']): ?>
                            <span style="color: #28a745; font-weight: bold;">Yes</span>
                        <?php else: ?>
                            <span class="text-muted">No</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/determinations/create?cas=<?= urlencode($nd['cas_number']) ?>" class="btn btn-sm btn-primary">Create</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Tab: Existing Determinations -->
<div class="tab-panel" id="tab-existing" style="display: none;">
    <table class="table">
        <thead>
            <tr>
                <th>CAS Number</th>
                <th>Jurisdiction</th>
                <th>Rationale (excerpt)</th>
                <th>Active</th>
                <th>Created By</th>
                <th>Approved By</th>
                <th>Date</th>
                <th style="width:80px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="8" class="text-muted" style="text-align:center;">No determinations recorded.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><strong><?= e($item['cas_number']) ?></strong></td>
                <td><?= e($item['jurisdiction']) ?></td>
                <td><?= e(mb_strimwidth($item['rationale_text'], 0, 80, '...')) ?></td>
                <td><?= (int)$item['is_active'] ? '<span style="color:green;">Yes</span>' : '<span style="color:#999;">No</span>' ?></td>
                <td><?= e($item['created_by_name'] ?? '—') ?></td>
                <td><?= e($item['approved_by_name'] ?? '—') ?></td>
                <td><?= e(date('m/d/Y', strtotime($item['created_at']))) ?></td>
                <td><a href="/determinations/<?= (int)$item['id'] ?>/edit" class="btn btn-sm">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(function(b) {
            b.style.background = '#e9ecef';
            b.style.color = '#003366';
            b.classList.remove('active');
        });
        btn.style.background = '#003366';
        btn.style.color = '#fff';
        btn.classList.add('active');

        document.querySelectorAll('.tab-panel').forEach(function(p) {
            p.style.display = 'none';
        });
        document.getElementById('tab-' + btn.dataset.tab).style.display = 'block';
    });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
