<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar">
    <form method="GET" action="/private-label" class="search-form">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search manufacturers...">
        <button type="submit" class="btn btn-sm">Search</button>
        <?php if ($search !== ''): ?>
            <a href="/private-label" class="btn btn-sm btn-outline">Clear</a>
        <?php endif; ?>
    </form>
    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <a href="/private-label/documents" class="btn btn-outline">All documents</a>
        <?php if (can_edit('manufacturers')): ?>
            <a href="/manufacturers/create" class="btn btn-primary">+ Add manufacturer</a>
        <?php endif; ?>
    </div>
</div>

<p class="text-muted"><?= count($summaries) ?> manufacturer(s). Open a manufacturer to manage its private label items and download the latest SDS per language.</p>

<?php if (empty($summaries)): ?>
    <div class="card" style="text-align: center; padding: 2rem;">
        <p class="text-muted">
            <?php if ($search !== ''): ?>
                No manufacturers match "<?= e($search) ?>".
            <?php else: ?>
                No manufacturers yet. Private label SDS documents are created per manufacturer.
            <?php endif; ?>
        </p>
        <?php if (can_edit('manufacturers')): ?>
            <a href="/manufacturers/create" class="btn btn-primary">Add the first manufacturer</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Logo</th>
                <th>Manufacturer</th>
                <th title="Active / total private label items">Items</th>
                <th title="Items with at least one published document">Published</th>
                <th>Stale</th>
                <th>Last published</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($summaries as $m): ?>
            <?php
                $mid            = (int) $m['id'];
                $itemCount      = (int) ($m['item_count'] ?? 0);
                $activeCount    = (int) ($m['active_count'] ?? 0);
                $publishedCount = (int) ($m['published_count'] ?? 0);
                $stale          = (int) ($staleCounts[$mid] ?? 0);
                $never          = (int) ($neverCounts[$mid] ?? 0);
                $openUrl        = '/private-label/manufacturer/' . $mid;
                $place          = implode(', ', array_filter(
                    [(string) ($m['city'] ?? ''), (string) ($m['state'] ?? '')],
                    fn($s) => trim($s) !== ''
                ));
            ?>
            <tr>
                <td style="width: 60px;">
                    <?php if (!empty($m['logo_path'])): ?>
                        <img src="<?= e($m['logo_path']) ?>" alt="Logo" style="max-height: 36px; max-width: 56px;">
                    <?php else: ?>
                        <span class="text-muted">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><a href="<?= e($openUrl) ?>"><?= e($m['name']) ?></a></strong>
                    <?php if ($place !== ''): ?>
                        <br><small class="text-muted"><?= e($place) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $activeCount ?> / <?= $itemCount ?>
                    <?php if ($itemCount === 0): ?><small class="text-muted">(none yet)</small><?php endif; ?>
                </td>
                <td><?= $publishedCount ?></td>
                <td>
                    <?php if ($stale > 0): ?>
                        <span class="badge badge-error" title="Items whose latest document is older than the base SDS, the item identity, or the manufacturer details"><?= $stale ?> stale</span>
                    <?php endif; ?>
                    <?php if ($never > 0): ?>
                        <span class="badge badge-muted" title="Active items with no published document"><?= $never ?> never published</span>
                    <?php endif; ?>
                    <?php if ($stale === 0 && $never === 0): ?>
                        <span class="text-muted">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($m['last_published'])): ?>
                        <?= format_date((string) $m['last_published'], 'm/d/Y g:i A') ?>
                    <?php else: ?>
                        <span class="text-muted">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td style="white-space: nowrap;">
                    <a href="<?= e($openUrl) ?>" class="btn btn-sm btn-primary">Open</a>
                    <?php if (can_edit('manufacturers')): ?>
                        <a href="/manufacturers/<?= $mid ?>/edit" class="btn btn-sm btn-outline">Edit manufacturer</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
