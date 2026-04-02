<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card" style="margin-bottom: 20px;">
    <h3>Import Preview</h3>
    <p class="text-muted">This is a dry run. No changes have been made.</p>

    <table class="table" style="max-width: 550px;">
        <tbody>
            <tr>
                <td><strong>Total CMS items with formulas</strong></td>
                <td><?= (int) $preview['total_items'] ?></td>
            </tr>
            <tr>
                <td><strong>Finished goods to create</strong></td>
                <td><span class="badge badge-success"><?= count($preview['fg_to_create']) ?></span></td>
            </tr>
            <tr>
                <td><strong>Formulas to update (revised in CMS)</strong></td>
                <td><span class="badge badge-warning"><?= count($preview['fg_to_update']) ?></span></td>
            </tr>
            <tr>
                <td><strong>Finished goods already up to date</strong></td>
                <td><span class="badge badge-muted"><?= count($preview['fg_current']) ?></span></td>
            </tr>
            <tr>
                <td><strong>Raw materials to create</strong></td>
                <td><span class="badge badge-success"><?= count($preview['rm_to_create']) ?></span></td>
            </tr>
            <tr>
                <td><strong>Raw materials already in system</strong></td>
                <td><span class="badge badge-muted"><?= count($preview['rm_existing']) ?></span></td>
            </tr>
        </tbody>
    </table>
</div>

<?php if (!empty($preview['fg_to_create'])): ?>
<details style="margin-bottom: 16px;">
    <summary><strong><?= count($preview['fg_to_create']) ?> New Finished Goods</strong></summary>
    <ul style="margin-top: 8px; column-count: 3;">
        <?php foreach ($preview['fg_to_create'] as $code): ?>
            <li><?= e($code) ?></li>
        <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>

<?php if (!empty($preview['fg_to_update'])): ?>
<details style="margin-bottom: 16px;" open>
    <summary><strong><?= count($preview['fg_to_update']) ?> Revised Formulas</strong></summary>
    <table class="table" style="margin-top: 8px;">
        <thead>
            <tr>
                <th>Item Code</th>
                <th>Previous Recipe</th>
                <th>New Recipe</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($preview['fg_to_update'] as $upd): ?>
            <tr>
                <td><strong><?= e($upd['code']) ?></strong></td>
                <td><?= e($upd['old_recipe'] ?? '—') ?></td>
                <td><?= e($upd['new_recipe']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>
<?php endif; ?>

<?php if (!empty($preview['rm_to_create'])): ?>
<details style="margin-bottom: 16px;">
    <summary><strong><?= count($preview['rm_to_create']) ?> New Raw Materials</strong></summary>
    <ul style="margin-top: 8px; column-count: 3;">
        <?php foreach ($preview['rm_to_create'] as $code): ?>
            <li><?= e($code) ?></li>
        <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>

<div style="margin-top: 20px; display: flex; gap: 12px;">
    <a href="/cms-import" class="btn btn-outline">Back</a>
    <form method="POST" action="/cms-import/import" onsubmit="return confirm('Proceed with the import?');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary">Confirm Import</button>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
