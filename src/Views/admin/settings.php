<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <form method="POST" action="/admin/settings" enctype="multipart/form-data">
        <?= csrf_field() ?>

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
            <div class="form-group">
                <label>Publishing CPU Workers</label>
                <input type="number" name="sds__publish_workers" min="0" step="1" value="<?= e($settings['sds.publish_workers'] ?? '0') ?>">
                <small class="text-muted">0 = auto (minimum 8 workers). Set a specific number to override.</small>
            </div>
        </div>

        <h2>Default Product Use</h2>
        <p class="text-muted mb-1">Set default values for Recommended Use and Restrictions on Use. These will pre-fill when creating new finished goods but can be overridden per product.</p>
        <div class="form-grid-2col">
            <div class="form-group full-width">
                <label>Default Recommended Use</label>
                <input type="text" name="sds__default_recommended_use" value="<?= e($settings['sds.default_recommended_use'] ?? '') ?>" placeholder="e.g. Industrial ink for offset printing">
            </div>
            <div class="form-group full-width">
                <label>Default Restrictions on Use</label>
                <input type="text" name="sds__default_restrictions_on_use" value="<?= e($settings['sds.default_restrictions_on_use'] ?? '') ?>" placeholder="e.g. Not for food contact or consumer use">
            </div>
        </div>

        <h2>Product Families</h2>
        <p class="text-muted mb-1">Enter one product family per line. These appear as dropdown options when creating/editing a finished good.</p>
        <div class="form-group">
            <textarea name="sds__product_families" rows="5" style="font-size: 0.9rem;" placeholder="UV Offset&#10;Aqueous&#10;Solvent&#10;Flexo&#10;Digital"><?= e($settings['sds.product_families'] ?? '') ?></textarea>
        </div>

        <h2>Physical State Options</h2>
        <p class="text-muted mb-1">Enter one physical state per line. These appear as dropdown options on the finished good form and populate SDS Section 9.</p>
        <div class="form-group">
            <textarea name="sds__physical_states" rows="5" style="font-size: 0.9rem;" placeholder="Liquid&#10;Paste&#10;Solid&#10;Powder&#10;Gel"><?= e($settings['sds.physical_states'] ?? '') ?></textarea>
        </div>

        <h2>Color Options</h2>
        <p class="text-muted mb-1">Enter one color per line. These appear as dropdown options on the finished good form and populate SDS Section 9.</p>
        <div class="form-group">
            <textarea name="sds__color_options" rows="5" style="font-size: 0.9rem;" placeholder="Black&#10;White&#10;Yellow&#10;Cyan&#10;Magenta&#10;Transparent&#10;Various"><?= e($settings['sds.color_options'] ?? '') ?></textarea>
        </div>

        <h2>Net Weight Units</h2>
        <p class="text-muted mb-1">Enter one unit per line. These appear as dropdown options for the net weight field on GHS labels.</p>
        <div class="form-group">
            <textarea name="label__net_weight_units" rows="5" style="font-size: 0.9rem;" placeholder="LBS&#10;OZ&#10;KG&#10;G&#10;GAL&#10;L&#10;ML&#10;FL OZ&#10;QT&#10;PT"><?= e($settings['label.net_weight_units'] ?? '') ?></textarea>
        </div>

        <h2>Trade Secret Descriptions</h2>
        <p class="text-muted mb-1">Enter one trade secret description per line. These appear as dropdown options when marking a CAS constituent as a trade secret.</p>
        <div class="form-group">
            <textarea name="sds__trade_secret_descriptions" rows="5" style="font-size: 0.9rem;" placeholder="Proprietary Resin Blend&#10;Proprietary Pigment Dispersion&#10;Proprietary Additive"><?= e($settings['sds.trade_secret_descriptions'] ?? '') ?></textarea>
        </div>

        <h2>Legal / Disclaimer Statement</h2>
        <p class="text-muted mb-1">This statement will appear at the end of every SDS (after Section 16). Use this for legal disclaimers, liability limitations, or any language required by your legal counsel.</p>

        <div class="form-group">
            <textarea name="sds__legal_disclaimer" rows="6" style="font-size: 0.9rem;"><?= e($settings['sds.legal_disclaimer'] ?? '') ?></textarea>
        </div>

        <h2>Report Disclaimer</h2>
        <p class="text-muted mb-1">This statement will appear at the bottom of HAP/VOC shipping report PDFs. Leave blank to omit the disclaimer from reports.</p>

        <div class="form-group">
            <textarea name="sds__report_disclaimer" rows="4" style="font-size: 0.9rem;"><?= e($settings['sds.report_disclaimer'] ?? '') ?></textarea>
        </div>

        <h2>Maintenance</h2>
        <div class="form-grid-2col">
            <div class="form-group"><label>Audit Log Retention (days)</label><input type="number" name="cron__log_retention_days" value="<?= e($settings['cron.log_retention_days'] ?? '365') ?>"></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
