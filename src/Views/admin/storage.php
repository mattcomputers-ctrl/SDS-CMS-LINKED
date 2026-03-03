<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="text-muted">Storage usage overview.</p>

<?php foreach ($categories as $cat): ?>
<div class="card" style="margin-bottom: 1.5rem;">
    <h2><?= e($cat['label']) ?></h2>
    <div class="storage-stats">
        <?php if ($cat['is_drive']): ?>
        <div class="storage-stat">
            <span class="storage-stat-label">Total</span>
            <span class="storage-stat-value"><?= e($cat['total']) ?></span>
        </div>
        <div class="storage-stat">
            <span class="storage-stat-label">Used</span>
            <span class="storage-stat-value"><?= e($cat['used']) ?> <span class="storage-percent">(<?= $cat['used_percent'] ?>%)</span></span>
        </div>
        <div class="storage-stat">
            <span class="storage-stat-label">Free</span>
            <span class="storage-stat-value"><?= e($cat['free']) ?></span>
        </div>
        <?php else: ?>
        <div class="storage-stat">
            <span class="storage-stat-label">Size</span>
            <span class="storage-stat-value"><?= e($cat['size']) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <div class="storage-bar-container">
        <div class="storage-bar-used" style="width: <?= $cat['used_percent'] ?>%"></div>
    </div>
    <div class="storage-bar-legend">
        <span class="storage-legend-item"><span class="storage-legend-swatch storage-legend-used"></span> Used</span>
        <span class="storage-legend-item"><span class="storage-legend-swatch storage-legend-free"></span> Free</span>
    </div>
</div>
<?php endforeach; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
