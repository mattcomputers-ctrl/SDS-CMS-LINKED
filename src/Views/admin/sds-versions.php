<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/admin/sds-versions" class="search-form">
        <input type="text" name="search" value="<?= e($search ?? '') ?>" placeholder="Search by product code, alias, or description...">
        <button type="submit" class="btn btn-sm">Search</button>
        <?php if (!empty($search)): ?><a href="/admin/sds-versions" class="btn btn-sm btn-outline">Clear</a><?php endif; ?>
    </form>
</div>

<table class="table">
    <thead>
        <tr><th>Product</th><th>Alias</th><th>Version</th><th>Lang</th><th>Status</th><th>Published By</th><th>Date</th><th>Deleted?</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($versions as $v): ?>
        <tr class="<?= (int) $v['is_deleted'] ? 'row-deleted' : '' ?>">
            <td><a href="/sds/<?= (int) $v['finished_good_id'] ?>"><?= e($v['product_code']) ?></a></td>
            <td><?= !empty($v['alias_customer_code']) ? e($v['alias_customer_code']) : '<span class="text-muted">—</span>' ?></td>
            <td>v<?= (int) $v['version'] ?></td>
            <td><?= e(strtoupper($v['language'])) ?></td>
            <td><span class="badge badge-<?= $v['status'] ?>"><?= e($v['status']) ?></span></td>
            <td><?= e($v['published_by_name'] ?? '—') ?></td>
            <td><?= format_date($v['published_at'] ?? $v['created_at'], 'm/d/Y H:i') ?></td>
            <td><?= (int) $v['is_deleted'] ? '<span class="text-danger">Yes</span>' : 'No' ?></td>
            <td>
                <?php if ((int) $v['is_deleted']): ?>
                    <form method="POST" action="/admin/sds-versions/<?= (int) $v['id'] ?>/restore" class="inline-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline">Restore</button>
                    </form>
                <?php else: ?>
                    <a href="/sds/trace/<?= (int) $v['id'] ?>" class="btn btn-sm btn-outline">Trace</a>
                    <form method="POST" action="/admin/sds-versions/<?= (int) $v['id'] ?>/delete" class="inline-form"
                          onsubmit="return confirm('Soft-delete this SDS version?')">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (empty($versions)): ?>
<div style="text-align: center; padding: 2rem;" class="text-muted">
    <?php if (!empty($search)): ?>
        <p>No SDS versions match your search.</p>
    <?php else: ?>
        <p>No SDS versions found.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
