<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/raw-materials" class="search-form">
        <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Search code, supplier, name...">
        <button type="submit" class="btn btn-sm">Search</button>
        <?php if ($filters['search']): ?><a href="/raw-materials" class="btn btn-sm btn-outline">Clear</a><?php endif; ?>
    </form>
    <?php if (is_editor()): ?>
        <a href="/raw-materials/create" class="btn btn-primary">+ Add Raw Material</a>
    <?php endif; ?>
</div>

<p class="text-muted"><?= $total ?> raw material(s) found.</p>

<table class="table">
    <thead>
        <tr>
            <th><a href="?sort=internal_code&dir=<?= $filters['sort'] === 'internal_code' && $filters['dir'] === 'asc' ? 'desc' : 'asc' ?>&search=<?= e($filters['search']) ?>">Code</a></th>
            <th><a href="?sort=supplier&dir=<?= $filters['sort'] === 'supplier' && $filters['dir'] === 'asc' ? 'desc' : 'asc' ?>&search=<?= e($filters['search']) ?>">Supplier</a></th>
            <th>Product Name</th>
            <th>VOC wt%</th>
            <th>SG</th>
            <th>Flash Pt (C)</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><strong><a href="/raw-materials/<?= (int) $item['id'] ?>/edit"><?= e($item['internal_code']) ?></a></strong></td>
            <td><?= e($item['supplier']) ?></td>
            <td><?= e($item['supplier_product_name']) ?></td>
            <td><?= $item['voc_wt'] !== null ? number_format((float) $item['voc_wt'], 2) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $item['specific_gravity'] !== null ? number_format((float) $item['specific_gravity'], 4) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $item['flash_point_c'] !== null ? number_format((float) $item['flash_point_c'], 1) : '<span class="text-muted">—</span>' ?></td>
            <td>
                <a href="/raw-materials/<?= (int) $item['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <a href="/raw-materials/<?= (int) $item['id'] ?>/constituents" class="btn btn-sm btn-outline">CAS</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
