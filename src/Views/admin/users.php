<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/admin/users" class="search-form">
        <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Search users...">
        <select name="role">
            <option value="">All Roles</option>
            <option value="admin" <?= ($filters['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
            <option value="editor" <?= ($filters['role'] === 'editor') ? 'selected' : '' ?>>Editor</option>
            <option value="readonly" <?= ($filters['role'] === 'readonly') ? 'selected' : '' ?>>Read-Only</option>
        </select>
        <button type="submit" class="btn btn-sm">Filter</button>
    </form>
    <a href="/admin/users/create" class="btn btn-primary">+ Add User</a>
</div>

<table class="table">
    <thead>
        <tr><th>Username</th><th>Display Name</th><th>Email</th><th>Role</th><th>Active</th><th>Last Login</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><strong><?= e($item['username']) ?></strong></td>
            <td><?= e($item['display_name']) ?></td>
            <td><?= e($item['email']) ?></td>
            <td><span class="badge badge-<?= $item['role'] ?>"><?= e($item['role']) ?></span></td>
            <td><?= (int) $item['is_active'] ? 'Yes' : 'No' ?></td>
            <td><?= $item['last_login'] ? format_date($item['last_login'], 'm/d/Y H:i') : 'Never' ?></td>
            <td><a href="/admin/users/<?= (int) $item['id'] ?>/edit" class="btn btn-sm">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
