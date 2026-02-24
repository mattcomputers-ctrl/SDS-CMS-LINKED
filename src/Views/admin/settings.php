<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <form method="POST" action="/admin/settings" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <h2>Server / Network</h2>
        <p class="text-muted mb-1">The URL or IP address used to access this system. Change this if the server IP changes or you set up a domain name.</p>

        <div class="form-grid-2col">
            <div class="form-group full-width">
                <label>Server URL / IP Address</label>
                <input type="text" name="app__server_url"
                       value="<?= e($settings['app.server_url'] ?? \SDS\Core\App::config('app.url', 'http://' . ($_SERVER['SERVER_ADDR'] ?? 'localhost'))) ?>"
                       placeholder="http://192.168.1.100 or https://sds.yourcompany.com">
                <small class="text-muted">Include http:// or https:// prefix. Example: http://192.168.1.100</small>
            </div>
        </div>

        <h2>Manufacturer Information</h2>
        <p class="text-muted mb-1">This information appears in Section 1 of every SDS and on the PDF header.</p>

        <div class="form-grid-2col">
            <div class="form-group full-width"><label>Company Name</label><input type="text" name="company__name" value="<?= e($settings['company.name'] ?? '') ?>"></div>
            <div class="form-group full-width"><label>Street Address</label><input type="text" name="company__address" value="<?= e($settings['company.address'] ?? '') ?>"></div>
            <div class="form-group"><label>City</label><input type="text" name="company__city" value="<?= e($settings['company.city'] ?? '') ?>"></div>
            <div class="form-group"><label>State / Province</label><input type="text" name="company__state" value="<?= e($settings['company.state'] ?? '') ?>"></div>
            <div class="form-group"><label>ZIP / Postal Code</label><input type="text" name="company__zip" value="<?= e($settings['company.zip'] ?? '') ?>"></div>
            <div class="form-group"><label>Country</label><input type="text" name="company__country" value="<?= e($settings['company.country'] ?? '') ?>"></div>
            <div class="form-group"><label>Phone</label><input type="text" name="company__phone" value="<?= e($settings['company.phone'] ?? '') ?>"></div>
            <div class="form-group"><label>Fax</label><input type="text" name="company__fax" value="<?= e($settings['company.fax'] ?? '') ?>"></div>
            <div class="form-group"><label>Email</label><input type="email" name="company__email" value="<?= e($settings['company.email'] ?? '') ?>"></div>
            <div class="form-group"><label>Website</label><input type="url" name="company__website" value="<?= e($settings['company.website'] ?? '') ?>" placeholder="https://"></div>
            <div class="form-group full-width"><label>Emergency Phone (e.g. CHEMTREC)</label><input type="text" name="company__emergency_phone" value="<?= e($settings['company.emergency_phone'] ?? '') ?>"></div>
        </div>

        <h2>Company Logo</h2>
        <p class="text-muted mb-1">Upload your company logo to appear on SDS documents. Accepted formats: PNG, JPG, GIF. Max 2 MB.</p>

        <?php if (!empty($settings['company.logo_path'])): ?>
            <div class="logo-preview" style="margin-bottom: 1rem;">
                <img src="<?= e($settings['company.logo_path']) ?>" alt="Company Logo" style="max-height: 80px; max-width: 300px; border: 1px solid #e0e0e0; padding: 4px; border-radius: 4px; background: #fff;">
                <div style="margin-top: 0.25rem;">
                    <label style="font-weight: normal; font-size: 0.85rem; color: #888;">
                        <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                    </label>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-group" style="max-width: 400px;">
            <input type="file" name="company_logo" accept="image/png,image/jpeg,image/gif">
        </div>

        <h2>Login Page Logo</h2>
        <p class="text-muted mb-1">Upload a logo to display on the login page. Accepted formats: PNG, JPG, GIF. Max 2 MB.</p>

        <?php if (!empty($settings['login.logo_path'])): ?>
            <div class="logo-preview" style="margin-bottom: 1rem;">
                <img src="<?= e($settings['login.logo_path']) ?>" alt="Login Logo" style="max-height: 100px; max-width: 300px; border: 1px solid #e0e0e0; padding: 4px; border-radius: 4px; background: #fff;">
                <div style="margin-top: 0.25rem;">
                    <label style="font-weight: normal; font-size: 0.85rem; color: #888;">
                        <input type="checkbox" name="remove_login_logo" value="1"> Remove login logo
                    </label>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-group" style="max-width: 400px;">
            <input type="file" name="login_logo" accept="image/png,image/jpeg,image/gif">
        </div>

        <h2>SDS Configuration</h2>
        <div class="form-grid-2col">
            <div class="form-group">
                <label>Default VOC Calc Mode</label>
                <select name="sds__voc_calc_mode">
                    <option value="method24_standard" <?= ($settings['sds.voc_calc_mode'] ?? '') === 'method24_standard' ? 'selected' : '' ?>>Method 24 Standard</option>
                    <option value="method24_less_water_exempt" <?= ($settings['sds.voc_calc_mode'] ?? '') === 'method24_less_water_exempt' ? 'selected' : '' ?>>Method 24 Less Water/Exempt</option>
                </select>
            </div>
            <div class="form-group">
                <label>Missing Data Threshold (%)</label>
                <input type="number" name="sds__missing_threshold_pct" step="0.1" value="<?= e($settings['sds.missing_threshold_pct'] ?? '1.0') ?>">
            </div>
        </div>

        <h2>Legal / Disclaimer Statement</h2>
        <p class="text-muted mb-1">This statement will appear at the end of every SDS (after Section 16). Use this for legal disclaimers, liability limitations, or any language required by your legal counsel.</p>

        <div class="form-group">
            <textarea name="sds__legal_disclaimer" rows="6" style="font-size: 0.9rem;"><?= e($settings['sds.legal_disclaimer'] ?? '') ?></textarea>
        </div>

        <h2>Data Refresh</h2>
        <div class="form-grid-2col">
            <div class="form-group"><label>Federal Refresh Interval (hours)</label><input type="number" name="cron__federal_refresh_hours" value="<?= e($settings['cron.federal_refresh_hours'] ?? '168') ?>"></div>
            <div class="form-group"><label>Audit Log Retention (days)</label><input type="number" name="cron__log_retention_days" value="<?= e($settings['cron.log_retention_days'] ?? '365') ?>"></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
