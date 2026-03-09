<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? '/admin/groups/' . (int) $group['id'] : '/admin/groups';
$permissions = $group['permissions'] ?? [];
?>

<div class="card">
    <form method="POST" action="<?= $action ?>">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label for="name">Group Name *</label>
                <input type="text" id="name" name="name"
                       value="<?= e(old('name', $group['name'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <input type="text" id="description" name="description"
                       value="<?= e(old('description', $group['description'] ?? '')) ?>">
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
            <label>
                <input type="checkbox" name="is_admin" value="1"
                       <?= (!empty($group['is_admin']) || old('is_admin')) ? 'checked' : '' ?>>
                <strong>Admin Group</strong> — Members have full access to all pages and can manage users/groups.
            </label>
        </div>

        <h3 style="margin-bottom: 0.75rem;">Page Permissions</h3>
        <p class="text-muted" style="margin-bottom: 1rem;">
            Set the access level for each page. Admin groups automatically have full access regardless of these settings.
        </p>

        <table class="table" style="max-width: 700px;">
            <thead>
                <tr>
                    <th>Page / Function</th>
                    <th>Access Level</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pageKeys as $key => $label): ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td>
                        <select name="permissions[<?= e($key) ?>]">
                            <?php foreach ($accessLevels as $level => $levelLabel): ?>
                                <option value="<?= e($level) ?>"
                                    <?= ($permissions[$key] ?? 'none') === $level ? 'selected' : '' ?>>
                                    <?= e($levelLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($isEdit && !empty($group['members'])): ?>
        <h3 style="margin: 1.5rem 0 0.75rem;">Current Members</h3>
        <ul>
            <?php foreach ($group['members'] as $member): ?>
                <li>
                    <a href="/admin/users/<?= (int) $member['id'] ?>/edit">
                        <?= e($member['display_name'] ?: $member['username']) ?>
                    </a>
                    (<?= e($member['username']) ?>)
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="form-actions" style="margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update Group' : 'Create Group' ?></button>
            <a href="/admin/groups" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
