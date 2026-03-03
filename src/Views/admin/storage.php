<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="text-muted">Disk usage for all mounted partitions on this server.</p>

<?php foreach ($partitions as $part): ?>
<div class="card" style="margin-bottom: 1.5rem;">
    <h2><?= e($part['mount_point']) ?></h2>
    <div class="storage-stats">
        <div class="storage-stat">
            <span class="storage-stat-label">Total</span>
            <span class="storage-stat-value"><?= number_format($part['total_gb'], 2) ?> GB</span>
        </div>
        <div class="storage-stat">
            <span class="storage-stat-label">Used</span>
            <span class="storage-stat-value"><?= number_format($part['used_gb'], 2) ?> GB <span class="storage-percent">(<?= $part['used_percent'] ?>%)</span></span>
        </div>
        <div class="storage-stat">
            <span class="storage-stat-label">Free</span>
            <span class="storage-stat-value"><?= number_format($part['free_gb'], 2) ?> GB</span>
        </div>
    </div>
    <div class="storage-bar-container">
        <div class="storage-bar-used" style="width: <?= $part['used_percent'] ?>%"></div>
    </div>
    <div class="storage-bar-legend">
        <span class="storage-legend-item"><span class="storage-legend-swatch storage-legend-used"></span> Used</span>
        <span class="storage-legend-item"><span class="storage-legend-swatch storage-legend-free"></span> Free</span>
    </div>
</div>
<?php endforeach; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
