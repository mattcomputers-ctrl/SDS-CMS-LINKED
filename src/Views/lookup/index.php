<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="lookup-container">
    <form method="GET" action="/lookup/search" class="lookup-form">
        <div class="lookup-search">
            <input type="text" name="q" value="<?= e($query) ?>"
                   placeholder="Search by product code or description..."
                   autofocus autocomplete="off">
            <button type="submit" class="btn btn-primary">Search</button>
        </div>
    </form>

    <?php if ($results !== null): ?>
        <?php if (empty($results)): ?>
            <p class="text-muted">No results found for "<?= e($query) ?>".</p>
        <?php else: ?>
            <p class="text-muted"><?= count($results) ?> result(s) for "<?= e($query) ?>"</p>

            <table class="table">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Description</th>
                        <th>Family</th>
                        <th>Status</th>
                        <th>Latest Version</th>
                        <th>EN</th>
                        <th>ES</th>
                        <th>FR</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><strong><?= e($r['product_code']) ?></strong></td>
                        <td><?= e($r['description']) ?></td>
                        <td><?= e($r['family'] ?? '—') ?></td>
                        <td><?= (int) $r['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                        <td><?= $r['latest_version'] ? 'v' . (int) $r['latest_version'] . ' (' . format_date($r['latest_date'], 'm/d/Y') . ')' : '—' ?></td>
                        <td><?= $r['has_en'] ? '<span class="badge badge-success">PDF</span>' : '—' ?></td>
                        <td><?= $r['has_es'] ? '<span class="badge badge-success">PDF</span>' : '—' ?></td>
                        <td><?= $r['has_fr'] ? '<span class="badge badge-success">PDF</span>' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
