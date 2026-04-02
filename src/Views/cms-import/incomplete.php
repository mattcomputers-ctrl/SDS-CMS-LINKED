<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php if (empty($incomplete)): ?>
    <div class="alert alert-success">
        All imported raw materials have their details saved. Nothing to do here.
    </div>
<?php else: ?>
    <p class="text-muted"><?= count($incomplete) ?> raw material(s) imported from CMS still need constituents and SDS details.</p>

    <table class="table">
        <thead>
            <tr>
                <th>Internal Code</th>
                <th>Product Name</th>
                <th>CMS Code</th>
                <th>Imported</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($incomplete as $rm): ?>
            <tr>
                <td><strong><?= e($rm['internal_code']) ?></strong></td>
                <td><?= e($rm['supplier_product_name'] ?? '—') ?></td>
                <td><?= e($rm['cms_item_code'] ?? '—') ?></td>
                <td><?= e($rm['created_at'] ?? '') ?></td>
                <td>
                    <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm btn-primary">Add Details</a>
                    <a href="/raw-materials/<?= (int) $rm['id'] ?>/constituents" class="btn btn-sm btn-outline">Constituents</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="/cms-import" class="btn btn-outline">Back to CMS Import</a>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
