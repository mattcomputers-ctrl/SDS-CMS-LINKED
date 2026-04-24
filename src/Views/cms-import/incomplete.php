<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<!-- Tab Navigation -->
<div class="tab-nav" style="display: flex; gap: 0; border-bottom: 2px solid #003366; margin-bottom: 1rem;">
    <button class="tab-btn active" data-tab="incomplete" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #003366; color: #fff; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-right: 2px;">
        Needs Details
        <?php if (!empty($incomplete)): ?>
            <span style="background: #dc3545; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($incomplete) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="unblockers" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold;">
        Single-RM Unblock
        <?php if (!empty($unblockers)): ?>
            <span style="background: #28a745; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($unblockers) ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- Tab: Needs Details (existing) -->
<div class="tab-panel" id="tab-incomplete">
    <?php if (empty($incomplete)): ?>
        <div class="alert alert-success">
            All imported raw materials have their details saved. Nothing to do here.
        </div>
    <?php else: ?>
        <p class="text-muted"><?= count($incomplete) ?> raw material(s) imported from CMS still need constituents and SDS details. Sorted by the number of finished goods that depend on them — highest impact first.</p>

        <table class="table">
            <thead>
                <tr>
                    <th>Internal Code</th>
                    <th>Product Name</th>
                    <th title="Finished goods whose formula tree transitively contains this raw material">FGs Using</th>
                    <th title="Finished goods that reference this raw material directly">Direct</th>
                    <th>CMS Code</th>
                    <th>Imported</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($incomplete as $rm): ?>
                <tr>
                    <td><strong><?= e($rm['internal_code']) ?></strong></td>
                    <td><?= e($rm['supplier_product_name'] ?? '—') ?></td>
                    <td><strong><?= (int) ($rm['fg_total_count'] ?? 0) ?></strong></td>
                    <td><span class="text-muted"><?= (int) ($rm['fg_direct_count'] ?? 0) ?></span></td>
                    <td><?= e($rm['cms_item_code'] ?? '—') ?></td>
                    <td><?= e($rm['created_at'] ?? '') ?></td>
                    <td>
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm btn-primary">Add Details</a>
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/constituents" class="btn btn-sm btn-outline">Constituents</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Tab: Single-RM Unblock -->
<div class="tab-panel" id="tab-unblockers" style="display: none;">
    <?php if (empty($unblockers)): ?>
        <div class="alert alert-success">
            No raw material is a single-shot blocker right now. Every finished good with an unreviewed RM has at least two unreviewed RMs in its formula tree, so no one-RM review would fully unblock an FG on its own.
        </div>
    <?php else: ?>
        <p class="text-muted">Raw materials where reviewing <em>just this one</em> would qualify at least one finished good for bulk SDS publish. Sorted by leverage — the top row unblocks the most SDSs per unit of review effort.</p>

        <table class="table">
            <thead>
                <tr>
                    <th>Internal Code</th>
                    <th>Supplier</th>
                    <th>Product Name</th>
                    <th title="Finished goods that would become eligible for bulk publish if this single RM is reviewed">FGs Unblocked</th>
                    <th>Example FG codes</th>
                    <th>CMS Code</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($unblockers as $rm): ?>
                <?php
                    $all      = $rm['all_fgs']     ?? [];
                    $examples = $rm['example_fgs'] ?? [];
                    $extra    = count($all) - count($examples);
                ?>
                <tr>
                    <td><strong><?= e($rm['internal_code']) ?></strong></td>
                    <td><?= e($rm['supplier'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td><?= e($rm['supplier_product_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td><strong><?= (int) $rm['unblock_count'] ?></strong></td>
                    <td>
                        <span title="<?= e(implode(', ', $all)) ?>"><?= e(implode(', ', $examples)) ?></span>
                        <?php if ($extra > 0): ?>
                            <small class="text-muted">(+<?= $extra ?> more)</small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($rm['cms_item_code'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm btn-primary">Review</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div style="margin-top: 20px;">
    <a href="/cms-import" class="btn btn-outline">Back to CMS Import</a>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn').forEach(function (b) {
            b.style.background = '#e9ecef';
            b.style.color = '#003366';
            b.classList.remove('active');
        });
        btn.style.background = '#003366';
        btn.style.color = '#fff';
        btn.classList.add('active');

        document.querySelectorAll('.tab-panel').forEach(function (p) {
            p.style.display = 'none';
        });
        document.getElementById('tab-' + btn.dataset.tab).style.display = 'block';
    });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
