<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="lookup-container">
    <form method="GET" action="/lookup" class="lookup-form">
        <div class="lookup-search">
            <input type="text" name="q" value="<?= e($query) ?>"
                   placeholder="Search by product code or description..."
                   autofocus autocomplete="off">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($query !== ''): ?>
                <a href="/lookup" class="btn btn-outline" style="margin-left: 0.25rem;">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($query !== ''): ?>
        <p class="text-muted"><?= (int) $total ?> result(s) for "<?= e($query) ?>"</p>
    <?php else: ?>
        <p class="text-muted"><?= (int) $total ?> finished good(s) — showing page <?= (int) ($filters['page'] ?? 1) ?> of <?= max(1, (int) $pages) ?></p>
    <?php endif; ?>

    <?php if (!empty($items)): ?>
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
                    <th>DE</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $r): ?>
                <tr>
                    <td><strong><?= e($r['product_code']) ?></strong></td>
                    <td><?= e($r['description']) ?></td>
                    <td><?= e($r['family'] ?? '—') ?></td>
                    <td><?= (int) $r['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                    <td><?= $r['latest_version'] ? 'v' . (int) $r['latest_version'] . ' (' . format_date($r['latest_date'], 'm/d/Y') . ')' : '—' ?></td>
                    <td><?php if ($r['has_en'] && !empty($r['sds_id_en'])): ?>
                        <a href="/lookup/download/<?= (int) $r['sds_id_en'] ?>" class="badge badge-success" title="Download English SDS v<?= (int) $r['ver_en'] ?>">PDF</a>
                    <?php else: ?>—<?php endif; ?></td>
                    <td><?php if ($r['has_es'] && !empty($r['sds_id_es'])): ?>
                        <a href="/lookup/download/<?= (int) $r['sds_id_es'] ?>" class="badge badge-success" title="Download Spanish SDS v<?= (int) $r['ver_es'] ?>">PDF</a>
                    <?php else: ?>—<?php endif; ?></td>
                    <td><?php if ($r['has_fr'] && !empty($r['sds_id_fr'])): ?>
                        <a href="/lookup/download/<?= (int) $r['sds_id_fr'] ?>" class="badge badge-success" title="Download French SDS v<?= (int) $r['ver_fr'] ?>">PDF</a>
                    <?php else: ?>—<?php endif; ?></td>
                    <td><?php if ($r['has_de'] && !empty($r['sds_id_de'])): ?>
                        <a href="/lookup/download/<?= (int) $r['sds_id_de'] ?>" class="badge badge-success" title="Download German SDS v<?= (int) $r['ver_de'] ?>">PDF</a>
                    <?php else: ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php include dirname(__DIR__) . '/partials/pagination.php'; ?>

    <?php elseif ($query !== ''): ?>
        <p class="text-muted">No results found for "<?= e($query) ?>".</p>
    <?php else: ?>
        <p class="text-muted">No finished goods found.</p>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
