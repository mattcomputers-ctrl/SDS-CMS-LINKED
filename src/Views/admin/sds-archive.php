<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="text-muted">
    Archive and remove old published SDS PDF versions to free up disk space.
    The current (latest) version of each SDS is never touched. Raw material / supplier SDS files are excluded.
</p>

<!-- Old Versions Summary -->
<div class="card">
    <h2>Old Published Versions</h2>
    <?php if ($dbRowCount === 0): ?>
        <p class="text-muted mb-1">No old SDS versions found &mdash; all published PDFs are current.</p>
    <?php else: ?>
        <table class="table table-sm" style="max-width: 420px;">
            <tr>
                <td>Database rows (old versions)</td>
                <td class="text-right"><strong><?= number_format($dbRowCount) ?></strong></td>
            </tr>
            <tr>
                <td>PDF files on disk</td>
                <td class="text-right"><strong><?= number_format($fileCount) ?></strong></td>
            </tr>
            <?php if ($missingCount > 0): ?>
            <tr>
                <td>Missing files (already deleted)</td>
                <td class="text-right text-muted"><?= number_format($missingCount) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>Total size on disk</td>
                <td class="text-right"><strong><?= format_bytes($totalBytes) ?></strong></td>
            </tr>
        </table>

        <?php if ($fileCount > 0): ?>
            <p class="text-muted mb-1">
                The ZIP is written to storage/exports and needs about <?= format_bytes($totalBytes) ?> of free disk space.
                Large archives can take several minutes; keep this page open until it reloads.
            </p>
            <form method="POST" action="/admin/sds-archive/generate"
                  onsubmit="var b = this.querySelector('button'); b.disabled = true; b.innerHTML = 'Generating&hellip;';">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Generate Archive ZIP</button>
            </form>
        <?php else: ?>
            <p class="text-muted mb-1">No PDF files on disk to archive.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Existing Archives -->
<?php if (!empty($archives)): ?>
<div class="card" style="margin-top: 1.5rem;">
    <h2>Archive Files</h2>
    <div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>File</th>
                <th>Size</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($archives as $arc): ?>
            <tr>
                <td><?= e($arc['name']) ?></td>
                <td><?= format_bytes($arc['size']) ?></td>
                <td><?= date('m/d/Y g:i A', $arc['modified']) ?></td>
                <td>
                    <a href="/admin/sds-archive/download/<?= urlencode($arc['name']) ?>"
                       class="btn btn-sm btn-outline">Download</a>

                    <form method="POST" action="/admin/sds-archive/delete-zip/<?= urlencode($arc['name']) ?>"
                          style="display: inline;" onsubmit="return confirm('Delete this archive ZIP?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete ZIP</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Purge Old PDFs -->
<?php if ($fileCount > 0): ?>
<div class="card card-danger" style="margin-top: 1.5rem;">
    <h2>Purge Old PDFs</h2>
    <p>
        Permanently delete <strong><?= number_format($fileCount) ?></strong> old SDS PDF files
        from disk (<?= format_bytes($totalBytes) ?>).
        The database version history is preserved but the PDF files will no longer be downloadable.
    </p>
    <p class="text-danger">
        <strong>This cannot be undone.</strong> Make sure you have downloaded the archive ZIP first.
    </p>
    <form method="POST" action="/admin/sds-archive/purge"
          onsubmit="if (!confirm('Are you sure? This will permanently delete <?= (int) $fileCount ?> old PDF files from disk. This cannot be undone.')) { return false; } var b = this.querySelector('button'); b.disabled = true; b.innerHTML = 'Purging&hellip;';">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">
            Purge <?= number_format($fileCount) ?> Old PDFs (<?= format_bytes($totalBytes) ?>)
        </button>
    </form>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>

<?php
function format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
?>
