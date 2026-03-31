<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/private-label" class="search-form">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search product, alias, or manufacturer...">
        <select name="manufacturer_id">
            <option value="">All Manufacturers</option>
            <?php foreach ($manufacturers as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= $manufacturerFilter === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm">Search</button>
    </form>
    <?php if (can_edit('private_label')): ?>
        <a href="/private-label/create" class="btn btn-primary">+ Create Private Label SDS</a>
    <?php endif; ?>
</div>

<p class="text-muted"><?= count($items) ?> private label SDS document(s) found.</p>

<?php if (empty($items)): ?>
    <div class="card" style="text-align: center; padding: 2rem;">
        <p class="text-muted">No private label SDS documents yet.</p>
        <?php if (can_edit('private_label')): ?>
            <a href="/private-label/create" class="btn btn-primary">Create Your First Private Label SDS</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Product Code</th>
                <th>Description</th>
                <th>Base Product</th>
                <th>Manufacturer</th>
                <th>Version</th>
                <th>Language</th>
                <th>Published</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td>
                    <strong><?= e(!empty($item['alias_code']) ? strip_pack_extension($item['alias_code']) : $item['product_code']) ?></strong>
                </td>
                <td><?= e(!empty($item['alias_description']) ? $item['alias_description'] : $item['fg_description']) ?></td>
                <td><?= e($item['product_code']) ?><?php if (!empty($item['fg_description'])): ?><br><small class="text-muted"><?= e($item['fg_description']) ?></small><?php endif; ?></td>
                <td><?= e($item['manufacturer_name']) ?></td>
                <td>v<?= (int) $item['version'] ?></td>
                <td><?= strtoupper(e($item['language'])) ?></td>
                <td>
                    <?= format_date($item['published_at'], 'm/d/Y g:i A') ?>
                    <?php if (!empty($item['published_by_name'])): ?>
                        <br><small class="text-muted">by <?= e($item['published_by_name']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/private-label/<?= (int) $item['id'] ?>/download" class="btn btn-sm btn-primary">Download</a>
                    <a href="/private-label/<?= (int) $item['id'] ?>/preview" class="btn btn-sm btn-outline" target="_blank">Preview</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
