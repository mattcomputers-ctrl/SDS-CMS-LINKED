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
            <tr>
                <td><strong>Raw materials refreshed (description / supplier / code)</strong></td>
                <td><span class="badge badge-muted"><?= number_format((int) ($results['rm_refreshed'] ?? 0)) ?></span></td>
            </tr>
            <tr>
                <td><strong>Aliases created</strong></td>
                <td><span class="badge badge-success"><?= (int) ($results['aliases_created'] ?? 0) ?></span></td>
            </tr>
            <tr>
                <td><strong>Aliases updated</strong></td>
                <td><span class="badge badge-muted"><?= (int) ($results['aliases_updated'] ?? 0) ?></span></td>
            </tr>
            <tr>
                <td><strong>Shipment records imported</strong></td>
                <td><span class="badge badge-success"><?= number_format((int) ($results['shipments_imported'] ?? 0)) ?></span></td>
            </tr>
            <tr>
                <td><strong>Customers created</strong></td>
                <td><span class="badge badge-success"><?= (int) ($results['customers_created'] ?? 0) ?></span></td>
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
    <p class="text-muted">These raw materials were imported but have no constituents saved yet. Sorted by the number of finished goods that depend on them — highest impact first.</p>

    <table class="table">
        <thead>
            <tr>
                <th>Internal Code</th>
                <th>Product Name</th>
                <th title="Finished goods whose formula tree transitively contains this raw material">FGs Using</th>
                <th title="Finished goods that reference this raw material directly in their formula">Direct</th>
                <th>Imported</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results['incomplete_materials'] as $rm): ?>
            <tr>
                <td><strong><?= e($rm['internal_code']) ?></strong></td>
                <td><?= e($rm['supplier_product_name'] ?? '—') ?></td>
                <td><strong><?= (int) ($rm['fg_total_count'] ?? 0) ?></strong></td>
                <td><span class="text-muted"><?= (int) ($rm['fg_direct_count'] ?? 0) ?></span></td>
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
