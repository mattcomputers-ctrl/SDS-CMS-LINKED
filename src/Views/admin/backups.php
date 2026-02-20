<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h2>Create New Backup</h2>
    <p class="text-muted mb-1">Create a backup of your SDS System data. Choose the type of backup below.</p>

    <form method="POST" action="/admin/backups/create" style="margin-bottom: 2rem;">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>Backup Type</label>
                <select name="backup_type" id="backup_type">
                    <option value="full">Full Backup (Database + Files)</option>
                    <option value="content">Content Only (Data Tables)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Notes (optional)</label>
                <input type="text" name="notes" placeholder="e.g. Before server migration">
            </div>
        </div>

        <div id="backup-type-info" style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: #f0f4f8; border-radius: 4px; font-size: 0.9rem;">
            <strong>Full Backup</strong> includes the complete database (all tables, users, settings) plus uploaded supplier SDS files and generated PDFs.
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create Backup</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top: 1.5rem;">
    <h2>Existing Backups</h2>

    <?php if (empty($backups)): ?>
        <p class="text-muted">No backups have been created yet.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th>Created By</th>
                    <th>Created At</th>
                    <th>Notes</th>
                    <th style="width: 240px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td style="font-family: monospace; font-size: 0.85rem;"><?= e($backup['filename']) ?></td>
                    <td>
                        <span class="badge badge-<?= $backup['backup_type'] === 'full' ? 'admin' : 'editor' ?>">
                            <?= e($backup['backup_type']) ?>
                        </span>
                    </td>
                    <td><?= self_format_bytes((int) $backup['file_size']) ?></td>
                    <td><?= e($backup['created_by_name'] ?? 'System') ?></td>
                    <td><?= format_date($backup['created_at'] ?? '') ?></td>
                    <td><?= e($backup['notes'] ?? '') ?></td>
                    <td>
                        <a href="/admin/backups/<?= (int) $backup['id'] ?>/download" class="btn btn-sm btn-outline">Download</a>

                        <form method="POST" action="/admin/backups/<?= (int) $backup['id'] ?>/restore"
                              style="display: inline;" class="restore-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="confirm_restore" value="1">
                            <button type="submit" class="btn btn-sm btn-warning">Restore</button>
                        </form>

                        <form method="POST" action="/admin/backups/<?= (int) $backup['id'] ?>/delete"
                              style="display: inline;" class="delete-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var select = document.getElementById('backup_type');
    var info = document.getElementById('backup-type-info');

    var descriptions = {
        full: '<strong>Full Backup</strong> includes the complete database (all tables, users, settings) plus uploaded supplier SDS files and generated PDFs.',
        content: '<strong>Content Only Backup</strong> includes only data tables: raw materials, formulas, finished goods, regulatory lists, and SDS versions. Does <strong>not</strong> include users, settings, or uploaded files. Use this to transfer content to a clean install without overwriting configuration.'
    };

    if (select) {
        select.addEventListener('change', function () {
            info.innerHTML = descriptions[this.value] || '';
        });
    }

    // Confirm before restore
    document.querySelectorAll('.restore-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var type = this.closest('tr').querySelector('.badge').textContent.trim();
            var msg = 'Are you sure you want to restore this backup?\n\n';
            if (type === 'full') {
                msg += 'WARNING: A full restore will OVERWRITE the entire database (including users and settings) and all uploaded files.\n\n';
            } else {
                msg += 'A content restore will overwrite data tables (raw materials, formulas, etc.) but will NOT change users, settings, or uploaded files.\n\n';
            }
            msg += 'This action cannot be undone. Consider creating a backup first.';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // Confirm before delete
    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('Delete this backup file? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
});
</script>

<?php
// Local helper for file size formatting (avoid depending on controller)
function self_format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
