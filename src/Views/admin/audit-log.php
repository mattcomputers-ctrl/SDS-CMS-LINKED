<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/admin/audit-log" class="search-form">
        <select name="entity_type">
            <option value="">All Entities</option>
            <?php foreach (['raw_material', 'finished_good', 'formula', 'sds_version', 'user', 'settings'] as $et): ?>
                <option value="<?= $et ?>" <?= ($filters['entity_type'] === $et) ? 'selected' : '' ?>><?= e($et) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="action">
            <option value="">All Actions</option>
            <?php foreach (['create', 'update', 'delete', 'publish', 'login', 'logout'] as $a): ?>
                <option value="<?= $a ?>" <?= ($filters['action'] === $a) ? 'selected' : '' ?>><?= e($a) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($filters['from']) ?>" placeholder="From">
        <input type="date" name="to" value="<?= e($filters['to']) ?>" placeholder="To">
        <button type="submit" class="btn btn-sm">Filter</button>
    </form>
</div>

<p class="text-muted"><?= $total ?> entries found.</p>

<table class="table table-sm">
    <thead>
        <tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>ID</th><th>Details</th></tr>
    </thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
        <tr>
            <td><?= format_date($e['timestamp'], 'm/d/Y H:i:s') ?></td>
            <td><?= e($e['user_display_name'] ?? $e['username'] ?? 'System') ?></td>
            <td><span class="badge"><?= e($e['action']) ?></span></td>
            <td><?= e($e['entity_type']) ?></td>
            <td><?= e($e['entity_id']) ?></td>
            <td>
                <?php if ($e['diff_json']): ?>
                    <details>
                        <summary>Diff</summary>
                        <pre class="trace-data"><?= e(json_encode(json_decode($e['diff_json'], true), JSON_PRETTY_PRINT)) ?></pre>
                    </details>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
