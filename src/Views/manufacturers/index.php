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

<?php
// id => ['total' => n, 'active' => n] from ManufacturerController::index()
$plCounts   = $plCounts ?? [];
$canOpenPl  = can_read('private_label');
$canAddPl   = can_edit('private_label');
?>

<p class="text-muted"><?= count($manufacturers) ?> manufacturer(s) found.</p>

<table class="table">
    <thead>
        <tr>
            <th>Logo</th>
            <th>Name</th>
            <th>Address</th>
            <th>Phone</th>
            <th>Private label</th>
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
                <?php
                $pl    = $plCounts[(int) $m['id']] ?? ['total' => 0, 'active' => 0];
                $plUrl = '/private-label/manufacturer/' . (int) $m['id'];
                ?>
                <?php if ($pl['total'] > 0): ?>
                    <?php if ($canOpenPl): ?>
                        <a href="<?= $plUrl ?>" title="Open private label items for <?= e($m['name']) ?>"><?= (int) $pl['total'] ?> item(s)</a>
                    <?php else: ?>
                        <?= (int) $pl['total'] ?> item(s)
                    <?php endif; ?>
                    <br><small class="text-muted"><?= (int) $pl['active'] ?> active / <?= (int) $pl['total'] ?> total</small>
                <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php if ($canAddPl): ?>
                        &mdash; <a href="<?= $plUrl ?>" title="Add a private label item for <?= e($m['name']) ?>">add</a>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td>
                <a href="/manufacturers/<?= (int) $m['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <?php if (can_edit('manufacturers')): ?>
                    <form method="POST" action="/manufacturers/<?= (int) $m['id'] ?>/delete" style="display: inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(<?= e(json_encode('Delete manufacturer ' . $m['name'] . '?')) ?>)">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
