<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>CAS Number Determinations</h2>
    <a href="/admin/determinations/create" class="btn btn-primary">+ New CAS Determination</a>
</div>

<p class="text-muted">When federal hazard data is missing for a CAS number, define the hazard determination here. Select hazard statements, H/P codes, and exposure limits. These are clearly marked as non-federal in Section 16.</p>

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
            <td><a href="/admin/determinations/<?= (int)$item['id'] ?>/edit" class="btn btn-sm">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
