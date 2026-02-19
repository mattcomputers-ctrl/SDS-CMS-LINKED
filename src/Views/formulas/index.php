<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/finished-goods/<?= (int) $finishedGood['id'] ?>/edit">&larr; Back to <?= e($finishedGood['product_code']) ?></a></p>

<?php if ($formula === null): ?>
    <div class="card">
        <p class="text-muted">No formula defined yet for this product.</p>
        <?php if (is_editor()): ?>
            <a href="/formulas/<?= (int) $finishedGood['id'] ?>/edit" class="btn btn-primary">Create Formula</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2>Version <?= (int) $formula['version'] ?></h2>
            <span class="text-muted">Created <?= format_date($formula['created_at'], 'm/d/Y H:i') ?></span>
        </div>

        <table class="table">
            <thead>
                <tr><th>#</th><th>Raw Material</th><th>Supplier / Product</th><th>Wt%</th></tr>
            </thead>
            <tbody>
            <?php
            $totalPct = 0;
            foreach ($formula['lines'] as $i => $line):
                $totalPct += (float) $line['pct'];
            ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><a href="/raw-materials/<?= (int) $line['raw_material_id'] ?>/edit"><?= e($line['internal_code'] ?? 'RM-' . $line['raw_material_id']) ?></a></td>
                    <td><?= e($line['supplier_product_name'] ?? '') ?></td>
                    <td><?= number_format((float) $line['pct'], 2) ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="text-right">Total:</th>
                    <th class="<?= abs($totalPct - 100) > 0.1 ? 'text-danger' : '' ?>"><?= number_format($totalPct, 2) ?>%</th>
                </tr>
            </tfoot>
        </table>

        <?php if ($formula['notes']): ?>
            <div class="mt-1"><strong>Notes:</strong> <?= e($formula['notes']) ?></div>
        <?php endif; ?>

        <div class="form-actions">
            <?php if (is_editor()): ?>
                <a href="/formulas/<?= (int) $finishedGood['id'] ?>/edit" class="btn btn-primary">Edit Formula</a>
            <?php endif; ?>
            <a href="/formulas/<?= (int) $finishedGood['id'] ?>/calculate" class="btn btn-outline">Run Calculations</a>
            <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview" class="btn btn-outline">Preview SDS</a>
        </div>
    </div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
