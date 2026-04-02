<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php if (!$configured): ?>
    <div class="alert alert-warning">
        <strong>CMS database not configured.</strong>
        Add a <code>cms_db</code> section to <code>config/config.php</code> with your MSSQL connection details.
    </div>
<?php else: ?>

    <?php if (!empty($incomplete)): ?>
    <div class="alert alert-info">
        <strong><?= count($incomplete) ?> raw material(s)</strong> imported from CMS still need details (constituents, SDS, etc.).
        <a href="/cms-import/incomplete" class="btn btn-sm" style="margin-left: 8px;">View Checklist</a>
    </div>
    <?php endif; ?>

    <?php
        $newCount     = count(array_filter($items, fn($i) => $i['import_status'] === 'new'));
        $revisedCount = count(array_filter($items, fn($i) => $i['import_status'] === 'revised'));
        $currentCount = count(array_filter($items, fn($i) => $i['import_status'] === 'current'));
    ?>

    <div class="toolbar">
        <div>
            <strong><?= count($items) ?></strong> CMS items with formulas.
            <span class="text-muted">
                <?= $newCount ?> new,
                <?= $revisedCount ?> revised,
                <?= $currentCount ?> up to date
            </span>
        </div>
    </div>

    <?php if ($revisedCount > 0): ?>
    <div class="alert alert-warning" style="margin-bottom: 16px;">
        <strong><?= $revisedCount ?> formula(s) have been revised in CMS</strong> since the last import. Running an import will create new formula versions for these items.
    </div>
    <?php endif; ?>

    <table class="table">
        <thead>
            <tr>
                <th>Item Code</th>
                <th>Description</th>
                <th>CMS Recipe</th>
                <th>Ingredients</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td>
                    <strong><?= e($item['ItemCode']) ?></strong>
                    <?php if ($item['sds_entity_id']): ?>
                        <a href="/finished-goods/<?= (int) $item['sds_entity_id'] ?>/edit" class="btn btn-sm btn-outline" style="margin-left: 4px; padding: 1px 6px; font-size: 0.75em;">view</a>
                    <?php endif; ?>
                </td>
                <td><?= e($item['Description'] ?? '') ?></td>
                <td>
                    <?= e($item['RecipeNumber'] ?? '—') ?>
                    <?php if ($item['import_status'] === 'revised'): ?>
                        <br><small class="text-muted">was: <?= e($item['last_recipe'] ?? '?') ?></small>
                    <?php endif; ?>
                </td>
                <td><?= (int) $item['ingredient_count'] ?></td>
                <td>
                    <?php if ($item['import_status'] === 'new'): ?>
                        <span class="badge badge-success">New</span>
                    <?php elseif ($item['import_status'] === 'revised'): ?>
                        <span class="badge badge-warning">Revised</span>
                    <?php else: ?>
                        <span class="badge badge-muted">Current</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($newCount > 0 || $revisedCount > 0): ?>
    <div style="margin-top: 20px; display: flex; gap: 12px;">
        <form method="POST" action="/cms-import/preview">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline">Preview Import</button>
        </form>
        <form method="POST" action="/cms-import/import" onsubmit="return confirm('Import and sync all CMS items? This will create new items and update revised formulas.');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Run Import</button>
        </form>
    </div>
    <?php else: ?>
    <p class="text-muted" style="margin-top: 20px;">All CMS items are up to date. Nothing to import.</p>
    <?php endif; ?>

<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
