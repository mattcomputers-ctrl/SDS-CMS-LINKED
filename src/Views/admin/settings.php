<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <form method="POST" action="/admin/settings">
        <?= csrf_field() ?>

        <h2>Company Information</h2>
        <div class="form-grid-2col">
            <div class="form-group"><label>Company Name</label><input type="text" name="company.name" value="<?= e($settings['company.name'] ?? '') ?>"></div>
            <div class="form-group"><label>Phone</label><input type="text" name="company.phone" value="<?= e($settings['company.phone'] ?? '') ?>"></div>
            <div class="form-group full-width"><label>Address</label><input type="text" name="company.address" value="<?= e($settings['company.address'] ?? '') ?>"></div>
            <div class="form-group"><label>Emergency Phone</label><input type="text" name="company.emergency_phone" value="<?= e($settings['company.emergency_phone'] ?? '') ?>"></div>
            <div class="form-group"><label>Email</label><input type="email" name="company.email" value="<?= e($settings['company.email'] ?? '') ?>"></div>
        </div>

        <h2>SDS Configuration</h2>
        <div class="form-grid-2col">
            <div class="form-group">
                <label>Default VOC Calc Mode</label>
                <select name="sds.voc_calc_mode">
                    <option value="method24_standard" <?= ($settings['sds.voc_calc_mode'] ?? '') === 'method24_standard' ? 'selected' : '' ?>>Method 24 Standard</option>
                    <option value="method24_less_water_exempt" <?= ($settings['sds.voc_calc_mode'] ?? '') === 'method24_less_water_exempt' ? 'selected' : '' ?>>Method 24 Less Water/Exempt</option>
                </select>
            </div>
            <div class="form-group">
                <label>Missing Data Threshold (%)</label>
                <input type="number" name="sds.missing_threshold_pct" step="0.1" value="<?= e($settings['sds.missing_threshold_pct'] ?? '1.0') ?>">
            </div>
        </div>

        <h2>Data Refresh</h2>
        <div class="form-grid-2col">
            <div class="form-group"><label>Federal Refresh Interval (hours)</label><input type="number" name="cron.federal_refresh_hours" value="<?= e($settings['cron.federal_refresh_hours'] ?? '168') ?>"></div>
            <div class="form-group"><label>Audit Log Retention (days)</label><input type="number" name="cron.log_retention_days" value="<?= e($settings['cron.log_retention_days'] ?? '365') ?>"></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
