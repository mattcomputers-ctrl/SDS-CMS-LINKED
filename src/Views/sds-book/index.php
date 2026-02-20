<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/sds-book" class="search-form" style="flex: 1; display: flex; gap: 0.5rem; align-items: center;">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by product code, name, or supplier..." style="flex: 1;">
        <select name="type" style="width: auto;">
            <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All SDS</option>
            <option value="supplier" <?= $type === 'supplier' ? 'selected' : '' ?>>Supplier SDS</option>
            <option value="finished" <?= $type === 'finished' ? 'selected' : '' ?>>Finished Goods SDS</option>
        </select>
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search || $type !== 'all'): ?>
            <a href="/sds-book" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<p class="text-muted"><?= $total ?> safety data sheet(s) found.</p>

<?php if (empty($results)): ?>
    <div class="card" style="text-align: center; padding: 2rem;">
        <p class="text-muted">No SDS documents found<?= $search ? ' for "' . e($search) . '"' : '' ?>.</p>
        <p class="text-muted">Supplier SDS files can be uploaded on each raw material's edit page.</p>
    </div>
<?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Supplier</th>
                <th>Type</th>
                <th>Lang</th>
                <th>Date</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr>
                <td><strong><?= e($r['product_name']) ?></strong></td>
                <td><?= e($r['supplier']) ?: '<span class="text-muted">—</span>' ?></td>
                <td>
                    <?php if ($r['source'] === 'supplier'): ?>
                        <span class="badge" style="background: #0077cc; color: #fff;">Supplier</span>
                    <?php else: ?>
                        <span class="badge" style="background: #28a745; color: #fff;">Finished Good</span>
                    <?php endif; ?>
                    <small class="text-muted"><?= e($r['sds_type']) ?></small>
                </td>
                <td><?= e($r['language']) ?: '—' ?></td>
                <td><?= $r['date'] ? e($r['date']) : '<span class="text-muted">—</span>' ?></td>
                <td>
                    <a href="<?= e($r['view_url']) ?>" target="_blank" class="btn btn-sm btn-primary">View PDF</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <?php
                    $params = ['q' => $search, 'type' => $type, 'page' => $i];
                    $url = '/sds-book?' . http_build_query($params);
                ?>
                <?php if ($i === $page): ?>
                    <span class="pagination-current"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= e($url) ?>" class="btn btn-sm btn-outline"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
