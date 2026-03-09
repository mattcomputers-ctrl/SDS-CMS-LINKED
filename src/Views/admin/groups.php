<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <div></div>
    <a href="/admin/groups/create" class="btn btn-primary">+ Add Group</a>
</div>

<?php if (empty($groups)): ?>
    <p class="text-muted">No permission groups defined yet. Create one to start assigning per-page permissions.</p>
<?php else: ?>
<table class="table">
    <thead>
        <tr>
            <th>Group Name</th>
            <th>Description</th>
            <th>Admin Group</th>
            <th>Members</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($groups as $group): ?>
        <tr>
            <td><strong><?= e($group['name']) ?></strong></td>
            <td><?= e($group['description']) ?></td>
            <td><?= (int) $group['is_admin'] ? '<span class="badge badge-admin">Yes</span>' : 'No' ?></td>
            <td><?= (int) $group['member_count'] ?></td>
            <td>
                <a href="/admin/groups/<?= (int) $group['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <form method="POST" action="/admin/groups/<?= (int) $group['id'] ?>/delete" style="display: inline;"
                      onsubmit="return confirm('Delete group &quot;<?= e($group['name']) ?>&quot;? Members will lose permissions from this group.');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
