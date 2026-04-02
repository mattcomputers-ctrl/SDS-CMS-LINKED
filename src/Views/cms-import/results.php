<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card" style="margin-bottom: 20px;">
    <h3>Import Complete</h3>

    <table class="table" style="max-width: 550px;">
        <tbody>
            <tr>
                <td><strong>Finished goods created</strong></td>
                <td><span class="badge badge-success"><?= count($results['fg_created']) ?></span></td>
            </tr>
            <tr>
                <td><strong>Finished goods skipped (already existed)</strong></td>
                <td><span class="badge badge-muted"><?= count($results['fg_skipped']) ?></span></td>
            </tr>
            <tr>
                <td><strong>Formulas created (new)</strong></td>
                <td><span class="badge badge-success"><?= (int) $results['formulas_created'] ?></span></td>
            </tr>
            <tr>
                <td><strong>Formulas updated (revised in CMS)</strong></td>
                <td><span class="badge badge-warning"><?= (int) $results['formulas_updated'] ?></span></td>
            </tr>
            <tr>
                <td><strong>Formulas skipped (unchanged)</strong></td>
                <td><span class="badge badge-muted"><?= (int) $results['formulas_skipped'] ?></span></td>
            </tr>
            <tr>
                <td><strong>Raw materials created</strong></td>
                <td><span class="badge badge-success"><?= count($results['rm_created']) ?></span></td>
            </tr>
            <tr>
                <td><strong>Raw materials skipped (already existed)</strong></td>
                <td><span class="badge badge-muted"><?= count($results['rm_skipped']) ?></span></td>
            </tr>
        </tbody>
    </table>
</div>

<?php if (!empty($results['errors'])): ?>
<div class="alert alert-warning" style="margin-bottom: 20px;">
    <strong><?= count($results['errors']) ?> error(s) during import:</strong>
    <ul style="margin-top: 8px;">
        <?php foreach ($results['errors'] as $error): ?>
            <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!empty($results['incomplete_materials'])): ?>
<div class="card">
    <h3>Raw Materials Needing Details</h3>
    <p class="text-muted">These raw materials were imported but have no constituents saved yet. Add their CAS composition and supplier SDS to complete them.</p>

    <table class="table">
        <thead>
            <tr>
                <th>Internal Code</th>
                <th>Product Name</th>
                <th>Imported</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results['incomplete_materials'] as $rm): ?>
            <tr>
                <td><strong><?= e($rm['internal_code']) ?></strong></td>
                <td><?= e($rm['supplier_product_name'] ?? '—') ?></td>
                <td><?= e($rm['created_at'] ?? '') ?></td>
                <td>
                    <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm btn-primary">Add Details</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="/cms-import" class="btn btn-outline">Back to CMS Import</a>
    <a href="/finished-goods" class="btn btn-outline">View Finished Goods</a>
    <a href="/raw-materials" class="btn btn-outline">View Raw Materials</a>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
