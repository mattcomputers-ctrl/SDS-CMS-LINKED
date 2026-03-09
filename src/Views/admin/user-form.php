<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/admin/users/' . (int) $item['id'] : '/admin/users';
$allGroups = $allGroups ?? [];
$userGroupId = $userGroupId ?? null;
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
                <label for="group_id">Permission Group *</label>
                <select id="group_id" name="group_id" required>
                    <option value="">— Select a group —</option>
                    <?php foreach ($allGroups as $g): ?>
                        <option value="<?= (int) $g['id'] ?>"
                                <?= ((int) old('group_id', (string) ($userGroupId ?? ''))) === (int) $g['id'] ? 'selected' : '' ?>>
                            <?= e($g['name']) ?><?= (int) $g['is_admin'] ? ' (Admin)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Controls which pages the user can access and what actions they can perform.</small>
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

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update User' : 'Create User' ?></button>
            <a href="/admin/users" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
