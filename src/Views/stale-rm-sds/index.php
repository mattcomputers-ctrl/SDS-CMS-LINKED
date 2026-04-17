<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="text-muted mb-1">
    Raw materials whose supplier SDS is older than
    <strong><?= (int) $staleDays ?> days</strong>,
    or has no confirmation date recorded. Grouped by supplier so one
    email per vendor can cover all their stale SDSs. Change the threshold
    in <a href="/admin/settings">Admin &gt; Settings</a>.
</p>

<?php if ($total === 0): ?>
    <div class="alert alert-success"><strong>All caught up.</strong> No raw materials currently exceed the staleness threshold.</div>
<?php else: ?>

<p class="text-muted"><?= (int) $total ?> raw material<?= $total === 1 ? '' : 's' ?> across <?= count($grouped) ?> supplier<?= count($grouped) === 1 ? '' : 's' ?>.</p>

<?php foreach ($grouped as $supplier => $items): ?>
    <div class="card" style="margin-bottom: 1rem;">
        <h3 style="margin-top: 0;"><?= e($supplier) ?> <span class="text-muted" style="font-weight: normal; font-size: 0.85rem;">(<?= count($items) ?>)</span></h3>

        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Internal Code</th>
                    <th>Product Description</th>
                    <th>Supplier Product Code</th>
                    <th>Last Confirmed</th>
                    <th>Age</th>
                    <th>SDS on File</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $rm): ?>
                <?php
                    $confirmed = $rm['sds_last_confirmed_at'];
                    $ageDays   = $confirmed
                        ? (int) ((time() - strtotime($confirmed)) / 86400)
                        : null;
                ?>
                <tr>
                    <td><strong><?= e($rm['internal_code']) ?></strong></td>
                    <td><?= e($rm['supplier_product_name'] ?? '') ?></td>
                    <td><?= e($rm['supplier_product_code'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($confirmed): ?>
                            <?= e($confirmed) ?>
                        <?php else: ?>
                            <span class="text-muted">Never</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ageDays !== null): ?>
                            <?= $ageDays ?> day<?= $ageDays === 1 ? '' : 's' ?>
                        <?php else: ?>
                            <em class="text-muted">unknown</em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($rm['file_date_received'])): ?>
                            <?= e($rm['file_date_received']) ?>
                        <?php elseif (!empty($rm['file_uploaded_at'])): ?>
                            <small class="text-muted">uploaded <?= e(date('Y-m-d', strtotime($rm['file_uploaded_at']))) ?></small>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm">Edit RM</a>
                        <?php if (can_edit('raw_materials')): ?>
                        <form method="POST"
                              action="/raw-materials/<?= (int) $rm['id'] ?>/confirm-sds-current"
                              style="display: inline;"
                              onsubmit="return confirm('Confirm the existing SDS for <?= e($rm['internal_code']) ?> is still current?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline">Mark Current</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
