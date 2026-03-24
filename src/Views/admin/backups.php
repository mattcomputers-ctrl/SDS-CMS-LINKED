<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h2>Create New Backup</h2>
    <p class="text-muted mb-1">Create a backup of your SDS System data. Choose a complete backup or select a specific section.</p>

    <form method="POST" action="/admin/backups/create" style="margin-bottom: 2rem;">
        <?= csrf_field() ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>Backup Type</label>
                <select name="backup_type" id="backup_type">
                    <option value="full">Complete Backup (Everything)</option>
                    <?php foreach ($sections as $key => $section): ?>
                        <option value="<?= e($key) ?>"><?= e($section['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Notes (optional)</label>
                <input type="text" name="notes" placeholder="e.g. Before server migration">
            </div>
        </div>

        <div id="backup-type-info" style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: #f0f4f8; border-radius: 4px; font-size: 0.9rem;">
            <strong>Complete Backup</strong> includes the entire database (all tables, users, settings, regulatory data) plus all uploaded files and generated PDFs. Use this for disaster recovery — a restore will return the system to this exact state.
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create Backup</button>
        </div>
    </form>
</div>

<!-- Scheduled Backup & FTP Settings -->
<div class="card" style="margin-top: 1.5rem;">
    <h2>Scheduled Backups &amp; FTP</h2>
    <p class="text-muted mb-1">Configure automatic backups on a schedule, optionally uploading to an FTP server for off-site storage.</p>

    <form method="POST" action="/admin/backups/ftp-settings">
        <?= csrf_field() ?>

        <h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem; font-size: 1rem;">Backup Schedule</h3>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>
                    <input type="checkbox" name="schedule_enabled" value="1"
                        <?= ($ftpConfig['schedule_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
                    Enable Scheduled Backups
                </label>
                <small class="text-muted">Requires cron job: <code style="font-size: 0.8rem;">0 * * * * /usr/bin/php <?= e(dirname(dirname(dirname(__DIR__)))) ?>/cron/scheduled_backup.php</code></small>
            </div>
        </div>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>Frequency</label>
                <select name="schedule_frequency">
                    <option value="daily" <?= ($ftpConfig['schedule_frequency'] ?? '') === 'daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="weekly" <?= ($ftpConfig['schedule_frequency'] ?? '') === 'weekly' ? 'selected' : '' ?>>Weekly (Sunday)</option>
                    <option value="monthly" <?= ($ftpConfig['schedule_frequency'] ?? '') === 'monthly' ? 'selected' : '' ?>>Monthly (1st)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Time (24h)</label>
                <input type="time" name="schedule_time" value="<?= e($ftpConfig['schedule_time'] ?: '02:00') ?>">
            </div>
            <div class="form-group">
                <label>Backup Section</label>
                <select name="schedule_type">
                    <option value="full" <?= ($ftpConfig['schedule_type'] ?? '') === 'full' ? 'selected' : '' ?>>Complete Backup</option>
                    <?php foreach ($sections as $key => $section): ?>
                        <option value="<?= e($key) ?>" <?= ($ftpConfig['schedule_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($section['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Keep Last N Backups</label>
                <input type="number" name="schedule_retention" min="1" max="100" value="<?= e($ftpConfig['schedule_retention'] ?: '10') ?>">
                <small class="text-muted">Older scheduled backups are automatically deleted.</small>
            </div>
        </div>

        <h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem; font-size: 1rem;">FTP Server (Optional)</h3>
        <p class="text-muted mb-1" style="font-size: 0.85rem;">If configured, scheduled backups will be uploaded to this FTP server. You can also manually upload any backup via the actions column below.</p>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>
                    <input type="checkbox" name="ftp_enabled" value="1"
                        <?= ($ftpConfig['ftp_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
                    Enable FTP Upload
                </label>
            </div>
        </div>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>FTP Host</label>
                <input type="text" name="ftp_host" value="<?= e($ftpConfig['ftp_host'] ?? '') ?>" placeholder="ftp.example.com">
            </div>
            <div class="form-group">
                <label>Port</label>
                <input type="number" name="ftp_port" value="<?= e($ftpConfig['ftp_port'] ?: '21') ?>" min="1" max="65535">
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="ftp_username" value="<?= e($ftpConfig['ftp_username'] ?? '') ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="ftp_password" value="<?= e($ftpConfig['ftp_password'] ?? '') ?>" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label>Remote Directory</label>
                <input type="text" name="ftp_path" value="<?= e($ftpConfig['ftp_path'] ?? '') ?>" placeholder="/backups/sds-system">
            </div>
            <div class="form-group" style="display: flex; gap: 1.5rem; align-items: center; padding-top: 1.5rem;">
                <label style="margin: 0;">
                    <input type="checkbox" name="ftp_passive" value="1"
                        <?= ($ftpConfig['ftp_passive'] ?? '1') === '1' ? 'checked' : '' ?>>
                    Passive Mode
                </label>
                <label style="margin: 0;">
                    <input type="checkbox" name="ftp_ssl" value="1"
                        <?= ($ftpConfig['ftp_ssl'] ?? '') === '1' ? 'checked' : '' ?>>
                    Use FTPS (SSL)
                </label>
            </div>
        </div>

        <div class="form-actions" style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="submit" formaction="/admin/backups/ftp-test" class="btn btn-outline">Test FTP Connection</button>
        </div>
    </form>
</div>

<!-- Existing Backups -->
<div class="card" style="margin-top: 1.5rem;">
    <h2>Existing Backups</h2>

    <?php if (empty($backups)): ?>
        <p class="text-muted">No backups have been created yet.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th>Created By</th>
                    <th>Created At</th>
                    <th>Notes</th>
                    <th style="width: 300px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td style="font-family: monospace; font-size: 0.85rem;"><?= e($backup['filename']) ?></td>
                    <td>
                        <?php
                            $typeKey = $backup['backup_type'];
                            $typeLabel = $typeKey;
                            $badgeClass = 'admin';
                            if ($typeKey === 'full') {
                                $typeLabel = 'Complete';
                                $badgeClass = 'admin';
                            } elseif (isset($sections[$typeKey])) {
                                $typeLabel = $sections[$typeKey]['label'];
                                $badgeClass = 'editor';
                            }
                        ?>
                        <span class="badge badge-<?= $badgeClass ?>">
                            <?= e($typeLabel) ?>
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

                        <?php if (($ftpConfig['ftp_enabled'] ?? '') === '1' && !empty($ftpConfig['ftp_host'])): ?>
                        <form method="POST" action="/admin/backups/<?= (int) $backup['id'] ?>/ftp-upload"
                              style="display: inline;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline" title="Upload to FTP server">FTP</button>
                        </form>
                        <?php endif; ?>

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
        </div>
    <?php endif; ?>
</div>

<!-- Section Reference -->
<div class="card" style="margin-top: 1.5rem;">
    <h2>Backup Section Reference</h2>
    <p class="text-muted mb-1">What each backup section includes. A <strong>Complete Backup</strong> covers all sections plus audit logs and schema metadata.</p>

    <table class="table" style="font-size: 0.9rem;">
        <thead>
            <tr>
                <th>Section</th>
                <th>Description</th>
                <th>Tables</th>
                <th>Files</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sections as $key => $section): ?>
            <tr>
                <td><strong><?= e($section['label']) ?></strong></td>
                <td><?= e($section['desc']) ?></td>
                <td style="font-family: monospace; font-size: 0.8rem;"><?= e(implode(', ', $section['tables'])) ?></td>
                <td style="font-family: monospace; font-size: 0.8rem;"><?= !empty($section['files']) ? e(implode(', ', $section['files'])) : '<em>None</em>' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var select = document.getElementById('backup_type');
    var info = document.getElementById('backup-type-info');

    var descriptions = {
        full: '<strong>Complete Backup</strong> includes the entire database (all tables, users, settings, regulatory data) plus all uploaded files and generated PDFs. Use this for disaster recovery \u2014 a restore will return the system to this exact state.',
        <?php foreach ($sections as $key => $section): ?>
        <?= json_encode($key) ?>: '<strong><?= e($section['label']) ?></strong> \u2014 <?= e($section['desc']) ?>',
        <?php endforeach; ?>
    };

    if (select) {
        select.addEventListener('change', function () {
            info.innerHTML = descriptions[this.value] || '';
        });
    }

    // Confirm before restore
    document.querySelectorAll('.restore-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var typeEl = this.closest('tr').querySelector('.badge');
            var type = typeEl ? typeEl.textContent.trim() : 'unknown';
            var msg = 'Are you sure you want to restore this backup?\n\n';
            if (type === 'Complete') {
                msg += 'WARNING: A complete restore will OVERWRITE the entire database (including users, settings, and all data) and all uploaded files.\n\n';
            } else {
                msg += 'This will REPLACE all data in the "' + type + '" section with data from this backup.\n\n';
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
function self_format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
