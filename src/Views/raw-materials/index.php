<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/raw-materials" class="search-form">
        <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Search code, supplier, name...">
        <select name="sds" onchange="this.form.submit()" title="Migration 064: No SDS required">
            <option value="" <?= ($filters['sds'] ?? '') === '' ? 'selected' : '' ?>>All items</option>
            <option value="required" <?= ($filters['sds'] ?? '') === 'required' ? 'selected' : '' ?>>Needs an SDS</option>
            <option value="not_required" <?= ($filters['sds'] ?? '') === 'not_required' ? 'selected' : '' ?>>No SDS required</option>
        </select>
        <button type="submit" class="btn btn-sm">Search</button>
        <?php if ($filters['search'] || ($filters['sds'] ?? '') !== ''): ?><a href="/raw-materials" class="btn btn-sm btn-outline">Clear</a><?php endif; ?>
    </form>
    <?php if (can_edit('raw_materials')): ?>
        <a href="/raw-materials/create" class="btn btn-primary">+ Add Raw Material</a>
    <?php endif; ?>
</div>

<p class="text-muted"><?= $total ?> raw material(s) found.</p>

<?php $canFlag = can_edit('rm_sds_not_required'); ?>
<?php if ($canFlag): ?>
<form method="POST" action="/raw-materials/sds-requirement/bulk" id="sdsRequirementBulk"
      onsubmit="return this.querySelectorAll('input[name=&quot;ids[]&quot;]:checked').length > 0 || (alert('Tick at least one raw material first.'), false);">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/raw-materials') ?>">
    <div class="toolbar" style="margin-bottom: 8px;">
        <span class="text-muted">Ticked items:</span>
        <button type="submit" name="sds_not_required" value="1" class="btn btn-sm btn-outline"
                onclick="return confirm('Mark the ticked items No SDS required? They will be left off resale SDS publishing, labels, the SDS send queue and the SDS worklists.');">Mark No SDS required</button>
        <button type="submit" name="sds_not_required" value="0" class="btn btn-sm btn-outline">Needs an SDS</button>
    </div>
<?php endif; ?>

<table class="table">
    <thead>
        <tr>
            <?php if ($canFlag): ?><th style="width: 28px;"><input type="checkbox" title="Tick all on this page" onclick="document.querySelectorAll('#sdsRequirementBulk input[name=&quot;ids[]&quot;]').forEach(c => c.checked = this.checked)"></th><?php endif; ?>
            <th><a href="?sort=internal_code&dir=<?= $filters['sort'] === 'internal_code' && $filters['dir'] === 'asc' ? 'desc' : 'asc' ?>&search=<?= e($filters['search']) ?>&sds=<?= e($filters['sds'] ?? '') ?>">Code</a></th>
            <th><a href="?sort=supplier&dir=<?= $filters['sort'] === 'supplier' && $filters['dir'] === 'asc' ? 'desc' : 'asc' ?>&search=<?= e($filters['search']) ?>&sds=<?= e($filters['sds'] ?? '') ?>">Supplier</a></th>
            <th>Product Name</th>
            <th>VOC wt%</th>
            <th>SG</th>
            <th>Flash Pt (C)</th>
            <th>SDS</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <?php if ($canFlag): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>"></td><?php endif; ?>
            <td><strong><a href="/raw-materials/<?= (int) $item['id'] ?>/edit"><?= e($item['internal_code']) ?></a></strong>
                <?php if (!empty($item['sds_not_required'])): ?> <span class="badge badge-muted" title="Not a chemical: left off SDS publishing, labels and worklists">No SDS required</span><?php endif; ?></td>
            <td><?= e($item['supplier']) ?></td>
            <td><?= e($item['supplier_product_name']) ?></td>
            <td><?php if (!empty($item['voc_less_than_one'])): ?>VOC &lt;1<?php elseif ($item['voc_wt'] !== null): ?><?= number_format((float) $item['voc_wt'], 2) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td><?= $item['specific_gravity'] !== null ? number_format((float) $item['specific_gravity'], 4) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $item['flash_point_c'] !== null ? number_format((float) $item['flash_point_c'], 1) : '<span class="text-muted">—</span>' ?></td>
            <td>
                <?php if (!empty($item['supplier_sds_path'])): ?>
                    <a href="/raw-materials/<?= (int) $item['id'] ?>/sds" title="View Supplier SDS" class="btn btn-sm btn-outline pdf-link">PDF</a>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="/raw-materials/<?= (int) $item['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <a href="/raw-materials/<?= (int) $item['id'] ?>/constituents" class="btn btn-sm btn-outline">CAS</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($canFlag): ?>
</form>
<?php endif; ?>

<?php include dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
