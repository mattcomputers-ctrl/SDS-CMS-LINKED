<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="grid-2col" style="margin-bottom: 1rem;">
    <!-- Upload Aliases -->
    <div class="card">
        <h2 class="card-title">Upload Aliases (CSV)</h2>
        <p class="text-muted" style="margin-bottom: 0.75rem;">CSV must contain <strong>Customer Code</strong> and <strong>Internal Code</strong> columns. An optional <strong>Description</strong> column is also supported. Existing aliases with the same customer code will be updated.</p>
        <?php if (can_edit('aliases')): ?>
        <form method="POST" action="/aliases/upload" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-group">
                <input type="file" name="aliases_file" accept=".csv,.txt" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top: 0.5rem;">Upload Aliases</button>
        </form>
        <?php else: ?>
            <p class="text-muted">You do not have permission to upload aliases.</p>
        <?php endif; ?>
    </div>

    <!-- Summary / Clear -->
    <div class="card">
        <h2 class="card-title">Alias Summary</h2>
        <p style="font-size: 1.5rem; font-weight: bold; margin: 0.5rem 0;"><?= (int) $total ?></p>
        <p class="text-muted">alias(es) stored in the system.</p>
        <?php if (can_edit('aliases') && $total > 0): ?>
        <form method="POST" action="/aliases/delete-all" style="margin-top: 0.5rem;" onsubmit="return confirm('Delete ALL aliases? This cannot be undone.');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger btn-sm">Delete All Aliases</button>
        </form>
        <?php endif; ?>
    </div>
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
            <th style="width: 80px;">Actions</th>
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
            <td>
                <?php if (can_edit('aliases')): ?>
                <form method="POST" action="/aliases/<?= (int) $item['id'] ?>/delete" style="display:inline;" onsubmit="return confirm('Delete this alias?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">X</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/partials/pagination.php'; ?>

<?php elseif ($total === 0 && $filters['search'] === ''): ?>
<div style="text-align: center; padding: 2rem;" class="text-muted">
    <p>No aliases uploaded yet. Upload a CSV to get started.</p>
</div>
<?php else: ?>
<div style="text-align: center; padding: 2rem;" class="text-muted">
    <p>No aliases match your search.</p>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
