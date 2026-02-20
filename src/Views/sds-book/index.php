<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/sds-book" class="search-form" style="flex: 1; display: flex; gap: 0.5rem; align-items: center;">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by internal code, supplier, or product name..." style="flex: 1;">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search): ?>
            <a href="/sds-book" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<p class="text-muted"><?= $total ?> raw material SDS document(s) found. The newest SDS for each raw material is shown.</p>

<?php if (empty($results)): ?>
    <div class="card" style="text-align: center; padding: 2rem;">
        <p class="text-muted">No supplier SDS documents found<?= $search ? ' for "' . e($search) . '"' : '' ?>.</p>
        <p class="text-muted">Supplier SDS files can be uploaded on each raw material's edit page.</p>
    </div>
<?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Raw Material</th>
                <th>Supplier</th>
                <th>Current SDS</th>
                <th>Date</th>
                <th>History</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr>
                <td><strong><?= e($r['product_name']) ?></strong></td>
                <td><?= e($r['supplier']) ?: '<span class="text-muted">—</span>' ?></td>
                <td><?= e($r['filename'] ?? '—') ?></td>
                <td><?= $r['date'] ? e(date('m/d/Y', strtotime($r['date']))) : '<span class="text-muted">—</span>' ?></td>
                <td>
                    <?php if ($r['sds_count'] > 1): ?>
                        <a href="<?= e($r['edit_url']) ?>" class="text-muted"><?= (int) $r['sds_count'] ?> version(s)</a>
                    <?php else: ?>
                        <span class="text-muted">1 version</span>
                    <?php endif; ?>
                </td>
                <td style="white-space: nowrap;">
                    <a href="<?= e($r['view_url']) ?>" target="_blank" class="btn btn-sm btn-primary">View PDF</a>
                    <a href="<?= e($r['edit_url']) ?>" class="btn btn-sm btn-outline">Edit</a>
                    <?php if (is_admin()): ?>
                        <form method="POST" action="/sds-book/delete-supplier/<?= (int) $r['id'] ?>" class="inline-form"
                              onsubmit="return confirm('Clear the current SDS for this raw material? Historical SDS files are preserved.')">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Clear</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <?php
                    $params = ['q' => $search, 'page' => $i];
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
