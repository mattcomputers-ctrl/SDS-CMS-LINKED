<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/finished-goods" class="search-form">
        <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Search product code or description...">
        <select name="family">
            <option value="">All Families</option>
            <?php foreach ($families as $f): ?>
                <option value="<?= e($f) ?>" <?= ($filters['family'] === $f) ? 'selected' : '' ?>><?= e($f) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm">Search</button>
    </form>
    <?php if (is_editor()): ?>
        <a href="/finished-goods/create" class="btn btn-primary">+ Add Product</a>
    <?php endif; ?>
</div>

<p class="text-muted"><?= $total ?> product(s) found.</p>

<table class="table">
    <thead>
        <tr>
            <th><a href="?sort=product_code&dir=<?= ($filters['sort'] === 'product_code' && $filters['dir'] === 'asc') ? 'desc' : 'asc' ?>&search=<?= e($filters['search']) ?>&family=<?= e($filters['family']) ?>">Product Code</a></th>
            <th>Description</th>
            <th>Family</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><strong><a href="/finished-goods/<?= (int) $item['id'] ?>/edit"><?= e($item['product_code']) ?></a></strong></td>
            <td><?= e($item['description']) ?></td>
            <td><?= e($item['family'] ?? '—') ?></td>
            <td><?= (int) $item['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
            <td>
                <a href="/finished-goods/<?= (int) $item['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <a href="/formulas/<?= (int) $item['id'] ?>" class="btn btn-sm btn-outline">Formula</a>
                <a href="/sds/<?= (int) $item['id'] ?>" class="btn btn-sm btn-outline">SDS</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
