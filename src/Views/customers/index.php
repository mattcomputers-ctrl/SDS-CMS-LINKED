<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/customers" class="search-form">
        <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Search customers...">
        <button type="submit" class="btn btn-sm">Search</button>
    </form>
    <?php if (can_edit('customers')): ?>
        <a href="/customers/create" class="btn btn-primary">+ Add Customer</a>
    <?php endif; ?>
</div>

<p class="text-muted"><?= $total ?> customer(s) found.</p>

<table class="table">
    <thead>
        <tr>
            <th>Ship To</th>
            <th>Name</th>
            <th>Regulatory Email</th>
            <th>SDS Mode</th>
            <th>Active Since</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><strong><?= e($item['ship_to']) ?></strong></td>
            <td><?= e($item['ship_to_name'] ?? '') ?></td>
            <td><?= e($item['regulatory_email'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
            <td>
                <?php
                $modeLabels = ['osha' => 'OSHA', 'osha_6mo' => 'OSHA + Every 6mo', 'every_order' => 'Every Order'];
                echo e($modeLabels[$item['sds_send_mode']] ?? $item['sds_send_mode']);
                ?>
            </td>
            <td><?= !empty($item['sds_send_active_since']) ? e($item['sds_send_active_since']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= (int) $item['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
            <td>
                <a href="/customers/<?= (int) $item['id'] ?>/edit" class="btn btn-sm">Edit</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
