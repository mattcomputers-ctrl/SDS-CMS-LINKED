<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php $hasFilter = $search !== '' || $manufacturerFilter > 0 || $fgFilter > 0 || $itemFilter > 0; ?>

<p><a href="/private-label">&larr; Back to manufacturers</a></p>

<div class="toolbar">
    <form method="GET" action="/private-label/documents" class="search-form">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search code, description, base product, or manufacturer...">
        <select name="manufacturer_id">
            <option value="">All Manufacturers</option>
            <?php foreach ($manufacturers as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= $manufacturerFilter === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($fgFilter > 0): ?><input type="hidden" name="fg_id" value="<?= (int) $fgFilter ?>"><?php endif; ?>
        <?php if ($itemFilter > 0): ?><input type="hidden" name="item_id" value="<?= (int) $itemFilter ?>"><?php endif; ?>
        <button type="submit" class="btn btn-sm">Search</button>
        <?php if ($hasFilter): ?>
            <a href="/private-label/documents" class="btn btn-sm btn-outline">Clear</a>
        <?php endif; ?>
    </form>
    <?php if ($manufacturerFilter > 0): ?>
        <a href="/private-label/manufacturer/<?= (int) $manufacturerFilter ?>" class="btn btn-outline">Open manufacturer page</a>
    <?php endif; ?>
</div>

<p class="text-muted">
    <?= count($items) ?> private label SDS document(s) found.
    This is the flat audit view of every published row; the code and description shown are the ones frozen at publish time.
    <?php if ($itemFilter > 0): ?>Showing one item &mdash; <a href="/private-label/items/<?= (int) $itemFilter ?>/history">open its history page</a>.<?php endif; ?>
</p>

<?php if (empty($items)): ?>
    <div class="card" style="text-align: center; padding: 2rem;">
        <p class="text-muted"><?= $hasFilter ? 'No private label SDS documents match the current filters.' : 'No private label SDS documents yet. Open a manufacturer to add items and publish.' ?></p>
    </div>
<?php else: ?>
    <div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Product Code</th>
                <th>Description</th>
                <th>Base Product</th>
                <th>Manufacturer</th>
                <th>Item</th>
                <th>Version</th>
                <th>Language</th>
                <th>Published</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $doc): ?>
            <tr>
                <td><strong><?= e($doc['display_code']) ?></strong></td>
                <td><?= e($doc['display_description']) ?></td>
                <td>
                    <a href="/sds/<?= (int) $doc['finished_good_id'] ?>"><?= e((string) ($doc['fg_product_code'] ?? '')) ?></a>
                    <?php if (!empty($doc['fg_description'])): ?><br><small class="text-muted"><?= e($doc['fg_description']) ?></small><?php endif; ?>
                </td>
                <td><a href="/private-label/manufacturer/<?= (int) $doc['manufacturer_id'] ?>"><?= e((string) ($doc['manufacturer_name'] ?? '')) ?></a></td>
                <td>
                    <?php if (!empty($doc['item_id'])): ?>
                        <a href="/private-label/items/<?= (int) $doc['item_id'] ?>/history" class="btn btn-sm btn-outline">History</a>
                    <?php else: ?>
                        <span class="badge badge-muted" title="Published before the item registry existed and could not be linked to an item">unlinked (legacy)</span>
                    <?php endif; ?>
                </td>
                <td>
                    v<?= (int) $doc['version'] ?>
                    <br><small class="text-muted"><?= isset($doc['source_fg_version']) && $doc['source_fg_version'] !== null ? 'from base v' . (int) $doc['source_fg_version'] : 'base version unknown' ?></small>
                </td>
                <td><?= e(strtoupper((string) $doc['language'])) ?></td>
                <td>
                    <?= !empty($doc['published_at']) ? format_date((string) $doc['published_at'], 'm/d/Y g:i A') : '&mdash;' ?>
                    <?php if (!empty($doc['published_by_name'])): ?>
                        <br><small class="text-muted">by <?= e($doc['published_by_name']) ?></small>
                    <?php endif; ?>
                </td>
                <td style="white-space: nowrap;">
                    <?php if (!empty($doc['pdf_path'])): ?>
                        <a href="/private-label/<?= (int) $doc['id'] ?>/download" class="btn btn-sm btn-primary pdf-link">View PDF</a>
                    <?php else: ?>
                        <span class="badge badge-muted">no PDF</span>
                    <?php endif; ?>
                    <a href="/private-label/<?= (int) $doc['id'] ?>/preview" class="btn btn-sm btn-outline" target="_blank">Preview</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
