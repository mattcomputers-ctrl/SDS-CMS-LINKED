<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><span class="menu-icon">&#128451;</span> SDS PDF Archive</h2>
    </div>

    <p class="text-muted mb-4">
        Archive and remove old published SDS PDF versions to free up disk space.
        The current (latest) version of each SDS is never touched. Raw material / supplier SDS files are excluded.
    </p>

    <!-- ── Old Versions Summary ──────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header"><strong>Old Published Versions</strong></div>
        <div class="card-body">
            <?php if ($dbRowCount === 0): ?>
                <p class="text-success mb-0">No old SDS versions found &mdash; all published PDFs are current.</p>
            <?php else: ?>
                <table class="table table-sm mb-3" style="max-width: 400px;">
                    <tr>
                        <td>Database rows (old versions)</td>
                        <td class="text-end"><strong><?= number_format($dbRowCount) ?></strong></td>
                    </tr>
                    <tr>
                        <td>PDF files on disk</td>
                        <td class="text-end"><strong><?= number_format($fileCount) ?></strong></td>
                    </tr>
                    <?php if ($missingCount > 0): ?>
                    <tr>
                        <td>Missing files (already deleted)</td>
                        <td class="text-end"><span class="text-muted"><?= number_format($missingCount) ?></span></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Total size on disk</td>
                        <td class="text-end"><strong><?= format_bytes($totalBytes) ?></strong></td>
                    </tr>
                </table>

                <form method="POST" action="/admin/sds-archive/generate" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary"
                            onclick="this.disabled=true; this.innerHTML='Generating&hellip;'; this.form.submit();"
                            <?= $fileCount === 0 ? 'disabled' : '' ?>>
                        Generate Archive ZIP
                    </button>
                </form>
                <?php if ($fileCount === 0): ?>
                    <span class="text-muted ms-2">No PDF files on disk to archive.</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Existing Archives ─────────────────────────────────────── -->
    <?php if (!empty($archives)): ?>
    <div class="card mb-4">
        <div class="card-header"><strong>Archive Files</strong></div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($archives as $arc): ?>
                    <tr>
                        <td><?= e($arc['name']) ?></td>
                        <td><?= format_bytes($arc['size']) ?></td>
                        <td><?= date('m/d/Y g:i A', $arc['modified']) ?></td>
                        <td class="text-end">
                            <a href="/admin/sds-archive/download/<?= urlencode($arc['name']) ?>"
                               class="btn btn-sm btn-outline-primary">Download</a>

                            <form method="POST" action="/admin/sds-archive/delete-zip/<?= urlencode($arc['name']) ?>"
                                  class="d-inline" onsubmit="return confirm('Delete this archive ZIP?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete ZIP</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Purge Old PDFs ────────────────────────────────────────── -->
    <?php if ($fileCount > 0): ?>
    <div class="card mb-4 border-danger">
        <div class="card-header bg-danger text-white"><strong>Purge Old PDFs</strong></div>
        <div class="card-body">
            <p>
                Permanently delete <strong><?= number_format($fileCount) ?></strong> old SDS PDF files
                from disk (<?= format_bytes($totalBytes) ?>).
                The database version history is preserved but the PDF files will no longer be downloadable.
            </p>
            <p class="text-danger">
                <strong>This cannot be undone.</strong> Make sure you have downloaded the archive ZIP first.
            </p>
            <form method="POST" action="/admin/sds-archive/purge"
                  onsubmit="return confirm('Are you sure? This will permanently delete <?= $fileCount ?> old PDF files from disk. This cannot be undone.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger"
                        onclick="this.disabled=true; this.innerHTML='Purging&hellip;'; this.form.submit();">
                    Purge <?= number_format($fileCount) ?> Old PDFs (<?= format_bytes($totalBytes) ?>)
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

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
