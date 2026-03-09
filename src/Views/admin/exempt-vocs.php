<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>Exempt VOC Library</h2>
    <a href="/exempt-vocs/create" class="btn btn-primary">+ Add Exempt VOC</a>
</div>

<p class="text-muted">CAS numbers listed here are automatically treated as exempt VOC in all VOC calculations.</p>

<table class="table">
    <thead>
        <tr>
            <th>CAS Number</th>
            <th>Chemical Name</th>
            <th>Regulation Reference</th>
            <th>Notes</th>
            <th style="width:120px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
        <tr><td colspan="5" class="text-muted" style="text-align:center;">No exempt VOCs configured.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><strong><?= e($item['cas_number']) ?></strong></td>
            <td><?= e($item['chemical_name']) ?></td>
            <td><?= e($item['regulation_ref'] ?? '') ?></td>
            <td><?= e($item['notes'] ?? '') ?></td>
            <td>
                <a href="/exempt-vocs/<?= (int)$item['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <form method="POST" action="/exempt-vocs/<?= (int)$item['id'] ?>/delete" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove this exempt VOC?')">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
