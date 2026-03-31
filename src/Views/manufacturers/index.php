<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/manufacturers" class="search-form">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search manufacturers...">
        <button type="submit" class="btn btn-sm">Search</button>
    </form>
    <?php if (can_edit('manufacturers')): ?>
        <a href="/manufacturers/create" class="btn btn-primary">+ Add Manufacturer</a>
    <?php endif; ?>
</div>

<p class="text-muted"><?= count($manufacturers) ?> manufacturer(s) found.</p>

<table class="table">
    <thead>
        <tr>
            <th>Logo</th>
            <th>Name</th>
            <th>Address</th>
            <th>Phone</th>
            <th>Default</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($manufacturers)): ?>
        <tr><td colspan="6" class="text-muted" style="text-align: center;">No manufacturers found. Add one to get started.</td></tr>
    <?php endif; ?>
    <?php foreach ($manufacturers as $m): ?>
        <tr>
            <td style="width: 60px;">
                <?php if (!empty($m['logo_path'])): ?>
                    <img src="<?= e($m['logo_path']) ?>" alt="Logo" style="max-height: 36px; max-width: 56px;">
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td>
                <strong><a href="/manufacturers/<?= (int) $m['id'] ?>/edit"><?= e($m['name']) ?></a></strong>
                <?php if (!empty($m['email'])): ?>
                    <br><small class="text-muted"><?= e($m['email']) ?></small>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $addr = array_filter([
                    $m['address'] ?? '',
                    $m['city'] ?? '',
                    ($m['state'] ?? '') . ' ' . ($m['zip'] ?? ''),
                ], fn($s) => trim($s) !== '');
                echo e(implode(', ', $addr) ?: '—');
                ?>
            </td>
            <td><?= e($m['phone'] ?: '—') ?></td>
            <td>
                <?php if ((int) $m['is_default']): ?>
                    <span class="badge badge-admin">Default</span>
                <?php elseif (can_edit('manufacturers')): ?>
                    <form method="POST" action="/manufacturers/<?= (int) $m['id'] ?>/set-default" style="display: inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline" onclick="return confirm('Set <?= e($m['name']) ?> as default?')">Set Default</button>
                    </form>
                <?php endif; ?>
            </td>
            <td>
                <a href="/manufacturers/<?= (int) $m['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <?php if (can_edit('manufacturers')): ?>
                    <form method="POST" action="/manufacturers/<?= (int) $m['id'] ?>/delete" style="display: inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete manufacturer <?= e($m['name']) ?>?')">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
