<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card" style="border: 2px solid #dc3545; max-width: 700px;">
    <div style="background: #dc3545; color: #fff; padding: 1rem 1.25rem; margin: -1.25rem -1.25rem 1.25rem -1.25rem; border-radius: 4px 4px 0 0;">
        <h2 style="margin: 0; color: #fff;">DANGER: Purge All Data</h2>
    </div>

    <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 1rem; margin-bottom: 1.25rem;">
        <strong>WARNING:</strong> This action will <strong>permanently delete ALL data</strong> from the system, including:
        <ul style="margin: 0.5rem 0 0 1.25rem; padding: 0;">
            <li>All raw materials and their constituents</li>
            <li>All finished goods and formulas</li>
            <li>All SDS versions and generated PDFs</li>
            <li>All hazard data, exposure limits, and CAS master records</li>
            <li>All competent person determinations</li>
            <li>All regulatory lists (SARA 313, HAP, Exempt VOC)</li>
            <li>All uploaded supplier SDS files</li>
            <li>All backups, audit logs, and refresh logs</li>
        </ul>
    </div>

    <div style="background: #d1ecf1; border: 1px solid #0dcaf0; border-radius: 4px; padding: 1rem; margin-bottom: 1.25rem;">
        <strong>The following will be PRESERVED:</strong>
        <ul style="margin: 0.5rem 0 0 1.25rem; padding: 0;">
            <li>System settings (company info, logos, configuration)</li>
            <li>User accounts</li>
            <li>Pictogram images</li>
        </ul>
    </div>

    <div style="background: #f8d7da; border: 1px solid #dc3545; border-radius: 4px; padding: 1rem; margin-bottom: 1.5rem;">
        <strong>THIS ACTION CANNOT BE UNDONE.</strong> It is strongly recommended that you create a full backup before proceeding.
        <br><a href="/admin/backups" class="btn btn-sm btn-outline" style="margin-top: 0.5rem;">Go to Backup &amp; Restore</a>
    </div>

    <form method="POST" action="/admin/purge-data" id="purge-form">
        <?= csrf_field() ?>

        <div class="form-group" style="margin-bottom: 1rem;">
            <label for="admin_username"><strong>Admin Username</strong></label>
            <input type="text" name="admin_username" id="admin_username" autocomplete="off" required
                   placeholder="Enter your admin username" style="max-width: 400px;">
        </div>

        <div class="form-group" style="margin-bottom: 1rem;">
            <label for="admin_password"><strong>Admin Password</strong></label>
            <input type="password" name="admin_password" id="admin_password" autocomplete="off" required
                   placeholder="Enter your admin password" style="max-width: 400px;">
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
            <label for="confirm_delete"><strong>Type DELETE to confirm</strong></label>
            <input type="text" name="confirm_delete" id="confirm_delete" autocomplete="off" required
                   placeholder="Type DELETE here" style="max-width: 400px; font-family: monospace; font-size: 1.1rem; letter-spacing: 0.1em;">
            <p class="text-muted" style="margin-top: 0.25rem; font-size: 0.85rem;">
                You must type the word DELETE in all capital letters to enable the purge button.
            </p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-danger" id="purge-btn" disabled
                    style="font-weight: bold; padding: 0.6rem 2rem;">
                Purge All Data
            </button>
            <a href="/admin/settings" class="btn btn-outline" style="margin-left: 0.5rem;">Cancel</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var confirmInput = document.getElementById('confirm_delete');
    var purgeBtn = document.getElementById('purge-btn');
    var purgeForm = document.getElementById('purge-form');

    // Enable button only when "DELETE" is typed
    confirmInput.addEventListener('input', function () {
        purgeBtn.disabled = (this.value !== 'DELETE');
    });

    // Final confirmation dialog
    purgeForm.addEventListener('submit', function (e) {
        if (confirmInput.value !== 'DELETE') {
            e.preventDefault();
            return;
        }

        var msg = 'FINAL WARNING\n\n'
            + 'You are about to permanently delete ALL data from the system.\n'
            + 'Settings, users, and pictograms will be preserved.\n\n'
            + 'This action CANNOT be undone.\n\n'
            + 'Are you absolutely sure you want to proceed?';

        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
