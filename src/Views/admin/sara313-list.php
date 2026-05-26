<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <form method="GET" action="/admin/sara313" class="search-form" style="flex: 1;">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search CAS number or chemical name...">
        <select name="is_pbt">
            <option value="">All Chemicals</option>
            <option value="1" <?= $pbtFilter === '1' ? 'selected' : '' ?>>PBT Only</option>
            <option value="0" <?= $pbtFilter === '0' ? 'selected' : '' ?>>Non-PBT Only</option>
        </select>
        <button type="submit" class="btn btn-sm">Search</button>
    </form>
    <a href="/admin/sara313/create" class="btn btn-primary">+ Add Chemical</a>
</div>

<p class="text-muted"><?= count($items) ?> of <?= (int) $totalCount ?> chemicals shown. Chemicals on this list are checked against finished good compositions to determine SARA 313 / TRI reporting requirements.</p>

<div class="card" style="margin-bottom: 1rem;">
    <form method="POST" action="/admin/sara313/import" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
        <?= csrf_field() ?>
        <label style="margin: 0;"><strong>Import CSV:</strong></label>
        <input type="file" name="sara313_file" accept=".csv,.txt" required>
        <button type="submit" class="btn btn-sm" onclick="return confirm('Import SARA 313 data from CSV? Existing entries with matching CAS numbers will be updated.')">Upload</button>
        <span class="text-muted" style="font-size: 0.85rem;">Format: CAS Number, Chemical Name, Category Code, De Minimis %, Is PBT (yes/no), PBT Threshold %</span>
    </form>
</div>

<table class="table">
    <thead>
        <tr>
            <th>CAS Number</th>
            <th>Chemical Name</th>
            <th>Category</th>
            <th>De Minimis %</th>
            <th>PBT</th>
            <th>PBT Threshold %</th>
            <th style="width: 140px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align: center;">No SARA 313 chemicals found.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><strong><?= e($item['cas_number']) ?></strong></td>
            <td><?= e($item['chemical_name']) ?></td>
            <td><?= e($item['category_code'] ?? '-') ?></td>
            <td><?= e(rtrim(rtrim(number_format((float) $item['deminimis_pct'], 4), '0'), '.')) ?>%</td>
            <td>
                <?php if ((int) $item['is_pbt']): ?>
                    <span class="badge badge-admin">PBT</span>
                <?php else: ?>
                    <span class="text-muted">No</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($item['pbt_threshold_pct'] !== null): ?>
                    <?= e(rtrim(rtrim(number_format((float) $item['pbt_threshold_pct'], 4), '0'), '.')) ?>%
                <?php else: ?>
                    <span class="text-muted">-</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="/admin/sara313/<?= (int) $item['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <form method="POST" action="/admin/sara313/<?= (int) $item['id'] ?>/delete" style="display: inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove <?= e($item['cas_number']) ?> from the SARA 313 list?')">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
