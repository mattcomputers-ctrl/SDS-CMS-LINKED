<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card" style="margin-bottom: 1rem;">
    <h2 class="card-title">Alias Summary</h2>
    <p style="font-size: 1.5rem; font-weight: bold; margin: 0.5rem 0;"><?= (int) $total ?></p>
    <p class="text-muted">alias(es) synced from CMS.
        <?php if ($lastSync): ?>
            Last sync: <?= e($lastSync) ?>
        <?php endif; ?>
    </p>
</div>

<!-- Search & Listing -->
<div class="toolbar">
    <form method="GET" action="/aliases" class="search-form">
        <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Search aliases...">
        <button type="submit" class="btn btn-sm">Search</button>
        <?php if ($filters['search']): ?><a href="/aliases" class="btn btn-sm btn-outline">Clear</a><?php endif; ?>
    </form>
</div>

<?php if (!empty($items)): ?>
<table class="table">
    <thead>
        <tr>
            <th>Customer Code</th>
            <th>Description</th>
            <th>Internal Code</th>
            <th>Base Code</th>
            <th>Finished Good</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><strong><?= e($item['customer_code']) ?></strong></td>
            <td><?= e($item['description']) ?: '<span class="text-muted">—</span>' ?></td>
            <td><?= e($item['internal_code']) ?></td>
            <td><?= e($item['internal_code_base']) ?></td>
            <td>
                <?php if ($item['fg_id']): ?>
                    <a href="/finished-goods/<?= (int) $item['fg_id'] ?>/edit"><?= e($item['fg_product_code']) ?></a>
                    <small class="text-muted"><?= e($item['fg_description']) ?></small>
                <?php else: ?>
                    <span class="text-muted">Not in system</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/partials/pagination.php'; ?>

<?php elseif ($total === 0 && $filters['search'] === ''): ?>
<div style="text-align: center; padding: 2rem;" class="text-muted">
    <p>No aliases synced yet. Run a <a href="/cms-import">CMS Import</a> to sync aliases.</p>
</div>
<?php else: ?>
<div style="text-align: center; padding: 2rem;" class="text-muted">
    <p>No aliases match your search.</p>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
