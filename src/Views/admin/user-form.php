<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/admin/users/' . (int) $item['id'] : '/admin/users';
$allGroups = $allGroups ?? [];
$userGroupIds = $userGroupIds ?? [];
?>

<div class="card">
    <form method="POST" action="<?= $action ?>">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username"
                       value="<?= e(old('username', $item['username'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= e(old('email', $item['email'] ?? '')) ?>">
                <small class="text-muted">Optional. Leave blank if not needed.</small>
            </div>
            <div class="form-group">
                <label for="display_name">Display Name</label>
                <input type="text" id="display_name" name="display_name"
                       value="<?= e(old('display_name', $item['display_name'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="role">Role *</label>
                <select id="role" name="role" required>
                    <option value="readonly" <?= old('role', $item['role'] ?? '') === 'readonly' ? 'selected' : '' ?>>Read-Only</option>
                    <option value="editor" <?= old('role', $item['role'] ?? '') === 'editor' ? 'selected' : '' ?>>Editor</option>
                    <option value="admin" <?= old('role', $item['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="sds_book_only" <?= old('role', $item['role'] ?? '') === 'sds_book_only' ? 'selected' : '' ?>>SDS Book Only</option>
                </select>
                <small class="text-muted">Admin users always have full access. SDS Book Only users can only view the SDS Book.</small>
            </div>
            <div class="form-group">
                <label for="password"><?= $isEdit ? 'New Password (leave blank to keep)' : 'Password *' ?></label>
                <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?> autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="is_active">Active</label>
                <select id="is_active" name="is_active">
                    <option value="1" <?= ((int) old('is_active', (string) ($item['is_active'] ?? 1))) === 1 ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= ((int) old('is_active', (string) ($item['is_active'] ?? 1))) === 0 ? 'selected' : '' ?>>No</option>
                </select>
            </div>
        </div>

        <?php if (!empty($allGroups)): ?>
        <h3 style="margin: 1.5rem 0 0.75rem;">Permission Groups</h3>
        <p class="text-muted" style="margin-bottom: 0.75rem;">
            Assign this user to one or more permission groups. Groups control which pages the user can access and what actions they can perform.
        </p>
        <div class="form-group" style="margin-bottom: 1.5rem;">
            <?php foreach ($allGroups as $g): ?>
                <label style="display: block; margin-bottom: 0.4rem;">
                    <input type="checkbox" name="group_ids[]" value="<?= (int) $g['id'] ?>"
                           <?= in_array((int) $g['id'], $userGroupIds) ? 'checked' : '' ?>>
                    <?= e($g['name']) ?>
                    <?php if ((int) $g['is_admin']): ?>
                        <span class="badge badge-admin">Admin</span>
                    <?php endif; ?>
                    <?php if (!empty($g['description'])): ?>
                        <small class="text-muted">— <?= e($g['description']) ?></small>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted" style="margin-top: 1.5rem;">
            No permission groups defined yet. <a href="/admin/groups/create">Create a group</a> to assign per-page permissions.
        </p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update User' : 'Create User' ?></button>
            <a href="/admin/users" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
