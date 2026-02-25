<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="text-muted mb-1">Upload custom pictogram images for GHS hazard, PPE, and regulatory pictograms used on SDS documents. Accepted formats: PNG, JPG, GIF. Max 2 MB each. Revert to the default image at any time.</p>

<div class="pictogram-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
    <?php foreach ($items as $item): ?>
    <div class="card" style="padding: 1rem; text-align: center;">
        <div style="margin-bottom: 0.5rem;">
            <?php if ($item['web_path']): ?>
                <img src="<?= e($item['web_path']) ?>?t=<?= time() ?>"
                     alt="<?= e($item['code']) ?>"
                     style="width: 80px; height: 80px; border: 1px solid #e0e0e0; border-radius: 4px; background: #fff; padding: 2px;">
            <?php else: ?>
                <div style="width: 80px; height: 80px; border: 1px dashed #ccc; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; color: #999; font-size: 0.8rem;">
                    No image
                </div>
            <?php endif; ?>
        </div>

        <strong style="display: block; font-size: 0.9rem;"><?= e($item['code']) ?></strong>
        <small style="color: #666;"><?= e($item['name']) ?></small>

        <?php if ($item['has_custom']): ?>
            <div style="margin-top: 0.25rem;">
                <span class="badge" style="background: #28a745; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 0.7rem;">Custom</span>
            </div>
        <?php endif; ?>

        <form method="POST" action="/admin/pictograms/<?= e($item['code']) ?>/upload" enctype="multipart/form-data" style="margin-top: 0.5rem;">
            <?= csrf_field() ?>
            <input type="file" name="pictogram_file" accept="image/png,image/jpeg,image/gif"
                   style="font-size: 0.75rem; width: 100%; margin-bottom: 0.3rem;">
            <button type="submit" class="btn btn-sm btn-primary" style="width: 100%;">Upload</button>
        </form>

        <?php if ($item['has_custom']): ?>
            <form method="POST" action="/admin/pictograms/<?= e($item['code']) ?>/delete" style="margin-top: 0.3rem;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-outline" style="width: 100%; font-size: 0.75rem;"
                        onclick="return confirm('Revert <?= e($item['code']) ?> to the default image?')">
                    Revert to Default
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
