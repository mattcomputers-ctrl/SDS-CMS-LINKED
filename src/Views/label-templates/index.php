<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
            <h2>Label Templates</h2>
            <p class="text-muted">Manage label sheet templates and field layouts for GHS label printing.</p>
        </div>
        <a href="/label-templates/create" class="btn btn-primary">+ New Template</a>
    </div>

    <?php if (empty($templates)): ?>
        <p class="text-muted">No label templates found. Create one to get started.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Label Size</th>
                    <th>Layout</th>
                    <th>Labels/Sheet</th>
                    <th>Default Font</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $t): ?>
                <tr>
                    <td>
                        <strong><?= e($t['name']) ?></strong>
                        <?php if ($t['is_default']): ?>
                            <span class="badge badge-admin">Default</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($t['description']) ?></td>
                    <td><?= number_format((float) $t['label_width'] / 25.4, 4) ?>" &times; <?= number_format((float) $t['label_height'] / 25.4, 4) ?>"</td>
                    <td><?= (int) $t['cols'] ?> cols &times; <?= (int) $t['rows'] ?> rows</td>
                    <td><?= (int) $t['cols'] * (int) $t['rows'] ?></td>
                    <td><?= number_format((float) $t['default_font_size'], 1) ?>pt</td>
                    <td>
                        <a href="/label-templates/<?= (int) $t['id'] ?>/edit" class="btn btn-sm">Edit</a>
                        <form method="POST" action="/label-templates/<?= (int) $t['id'] ?>/delete" style="display: inline;"
                              onsubmit="return confirm('Delete this template?')">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
