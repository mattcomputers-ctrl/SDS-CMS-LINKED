<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= (int) $stats['raw_materials'] ?></div>
        <div class="stat-label">Raw Materials</div>
        <a href="/raw-materials" class="stat-link">View All</a>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int) $stats['finished_goods'] ?></div>
        <div class="stat-label">Finished Goods</div>
        <a href="/finished-goods" class="stat-link">View All</a>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int) $stats['published_sds'] ?></div>
        <div class="stat-label">Published SDS</div>
        <a href="/lookup" class="stat-link">Lookup</a>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int) $stats['users'] ?></div>
        <div class="stat-label">Active Users</div>
        <?php if (can_manage_users()): ?><a href="/admin/users" class="stat-link">Manage</a><?php endif; ?>
    </div>
</div>

<div class="grid-2col">
    <div class="card">
        <h2 class="card-title">Recent SDS Activity</h2>
        <?php if (empty($recentSDS)): ?>
            <p class="text-muted">No SDS versions yet.</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr><th>Product</th><th>Version</th><th>Lang</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentSDS as $sds): ?>
                    <tr>
                        <td><a href="/sds/<?= (int) $sds['finished_good_id'] ?>"><?= e($sds['product_code']) ?></a></td>
                        <td>v<?= (int) $sds['version'] ?></td>
                        <td><?= e($sds['language']) ?></td>
                        <td><span class="badge badge-<?= $sds['status'] ?>"><?= e($sds['status']) ?></span></td>
                        <td><?= format_date($sds['created_at'], 'm/d/Y H:i') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="card-title">Recent Audit Activity</h2>
        <?php if (empty($recentAudit)): ?>
            <p class="text-muted">No audit entries yet.</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr><th>User</th><th>Action</th><th>Entity</th><th>Time</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentAudit as $entry): ?>
                    <tr>
                        <td><?= e($entry['user_display_name'] ?? 'System') ?></td>
                        <td><span class="badge"><?= e($entry['action']) ?></span></td>
                        <td><?= e($entry['entity_type']) ?> #<?= e($entry['entity_id']) ?></td>
                        <td><?= format_date($entry['timestamp'], 'm/d/Y H:i') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
