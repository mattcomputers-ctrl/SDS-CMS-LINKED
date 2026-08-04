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

        <h2>SMTP Email Configuration</h2>
        <p class="text-muted mb-1">Configure SMTP for automated SDS email delivery to customer regulatory contacts.</p>
        <div class="form-grid-2col">
            <div class="form-group"><label>SMTP Host</label><input type="text" name="mail__smtp_host" value="<?= e($settings['mail.smtp_host'] ?? '') ?>" placeholder="smtp.company.com"></div>
            <div class="form-group"><label>SMTP Port</label><input type="number" name="mail__smtp_port" value="<?= e($settings['mail.smtp_port'] ?? '587') ?>"></div>
            <div class="form-group"><label>SMTP Username</label><input type="text" name="mail__smtp_user" value="<?= e($settings['mail.smtp_user'] ?? '') ?>"></div>
            <div class="form-group"><label>SMTP Password</label><input type="password" name="mail__smtp_password" value="<?= e($settings['mail.smtp_password'] ?? '') ?>"></div>
            <div class="form-group">
                <label>Encryption</label>
                <select name="mail__smtp_secure">
                    <option value="tls" <?= ($settings['mail.smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (port 587)</option>
                    <option value="ssl" <?= ($settings['mail.smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                    <option value="" <?= ($settings['mail.smtp_secure'] ?? 'tls') === '' ? 'selected' : '' ?>>None</option>
                </select>
            </div>
            <div class="form-group"><label>From Address</label><input type="email" name="mail__from_address" value="<?= e($settings['mail.from_address'] ?? '') ?>" placeholder="sds@company.com"></div>
            <div class="form-group"><label>From Name</label><input type="text" name="mail__from_name" value="<?= e($settings['mail.from_name'] ?? 'SDS System') ?>"></div>
            <div class="form-group full-width">
                <label>Email Subject</label>
                <input type="text" name="mail__sds_subject" value="<?= e($settings['mail.sds_subject'] ?? 'Safety Data Sheets') ?>">
            </div>
            <div class="form-group full-width">
                <label>Email Body</label>
                <textarea name="mail__sds_body" rows="5" style="font-family: monospace;"><?= e($settings['mail.sds_body'] ?? "Hello,\nPlease see attached for Safety Data Sheets from \"{company_name}\".\n\nBest regards,\nRegulatory Team\n\"{company_name}\"") ?></textarea>
                <small class="text-muted">Use <code>{company_name}</code> to insert the manufacturer name. Line breaks are preserved.</small>
            </div>
        </div>

        <h2>CMS Sync Schedule</h2>
        <p class="text-muted mb-1">Controls when and how often the CMS sync runs. The system crontab triggers the script every hour; these settings control whether it actually executes.</p>

        <div class="form-group">
            <input type="hidden" name="cms_sync__enabled" value="0">
            <label style="font-weight: normal;">
                <input type="checkbox" name="cms_sync__enabled" value="1"
                    <?= ((string) ($settings['cms_sync.enabled'] ?? '1')) !== '0' ? 'checked' : '' ?>>
                Enable CMS sync
            </label>
            <small class="text-muted">Uncheck to stop the cron from doing anything. Leave checked in normal operation.</small>
        </div>

        <?php $syncFreq = $settings['cms_sync.frequency'] ?? 'hourly'; ?>
        <div class="form-grid-2col">
            <div class="form-group">
                <label>Frequency</label>
                <select name="cms_sync__frequency" id="cmsSyncFrequency">
                    <option value="hourly" <?= $syncFreq === 'hourly' ? 'selected' : '' ?>>Hourly</option>
                    <option value="daily" <?= $syncFreq === 'daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="weekly" <?= $syncFreq === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                </select>
            </div>
            <div class="form-group" id="cmsSyncMinuteGroup">
                <label>Minute Past the Hour</label>
                <input type="number" name="cms_sync__run_minute" min="0" max="59" value="<?= e($settings['cms_sync.run_minute'] ?? '7') ?>">
                <small class="text-muted">0–59. Saving updates the system crontab so the hourly sync fires at this minute.</small>
            </div>
            <div class="form-group" id="cmsSyncTimeGroup" style="display: none;">
                <label>Time of Day</label>
                <input type="time" name="cms_sync__run_time" value="<?= e($settings['cms_sync.run_time'] ?? '06:00') ?>">
                <small class="text-muted">24-hour time. The sync will run within a 30-minute window of this time.</small>
            </div>
            <div class="form-group" id="cmsSyncDayGroup" style="display: none;">
                <label>Day of Week</label>
                <select name="cms_sync__run_day">
                    <?php
                    $days = ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday'];
                    $currentDay = $settings['cms_sync.run_day'] ?? '1';
                    foreach ($days as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $currentDay === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Shipment History (days)</label><input type="number" name="cms_sync__shipment_days" min="1" step="1" value="<?= e($settings['cms_sync.shipment_days'] ?? '1095') ?>"><small class="text-muted">How many days of shipment history to import from CMS</small></div>
        </div>

        <div class="form-group">
            <label>Active Hours</label>
            <p class="text-muted" style="margin: 0 0 0.5rem; font-size: 0.85rem;">
                Select which hours the sync is allowed to run. Uncheck hours when backups or other heavy I/O operations are scheduled.
                Only applies to Hourly frequency.
            </p>
            <?php
            $activeHoursRaw = $settings['cms_sync.active_hours'] ?? '';
            $activeHours = $activeHoursRaw !== '' ? array_map('intval', explode(',', $activeHoursRaw)) : range(0, 23);
            ?>
            <div class="hour-grid">
                <?php for ($h = 0; $h < 24; $h++):
                    $checked = in_array($h, $activeHours) ? 'checked' : '';
                    $label = sprintf('%02d:00', $h);
                ?>
                    <label class="hour-toggle <?= $checked ? 'active' : '' ?>">
                        <input type="checkbox" name="cms_sync__active_hours[]" value="<?= $h ?>" <?= $checked ?>>
                        <?= $label ?>
                    </label>
                <?php endfor; ?>
            </div>
            <div style="margin-top: 0.5rem;">
                <button type="button" class="btn btn-sm btn-outline" onclick="toggleAllHours(true)">Select All</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="toggleAllHours(false)">Deselect All</button>
            </div>
        </div>

        <div class="form-group">
            <input type="hidden" name="cms_sync__auto_bulk_publish" value="0">
            <label style="font-weight: normal;">
                <input type="checkbox" name="cms_sync__auto_bulk_publish" value="1"
                    <?= ((string) ($settings['cms_sync.auto_bulk_publish'] ?? '1')) !== '0' ? 'checked' : '' ?>>
                Auto-run Bulk SDS Publish after every CMS sync
            </label>
            <small class="text-muted">Uses the same eligibility rules as the <a href="/bulk-publish">Bulk SDS Publish</a> page.</small>
        </div>

        <?php if (!empty($settings['cms_sync.last_run_at'])): ?>
        <div class="alert alert-muted" style="margin-top: 0.5rem; font-size: 0.85rem;">
            Last sync ran at <strong><?= e($settings['cms_sync.last_run_at']) ?></strong>
        </div>
        <?php endif; ?>

        <h2>Timezone</h2>
        <p class="text-muted mb-1">All timestamps, schedules, and cron windows use this timezone.</p>
        <div class="form-grid-2col">
            <div class="form-group">
                <label>Timezone</label>
                <select name="app__timezone">
                    <?php
                    $currentTz = $settings['app.timezone'] ?? date_default_timezone_get();
                    foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $tz === $currentTz ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h2>Blackout Windows</h2>
        <p class="text-muted mb-1">
            All cron jobs (CMS sync, bulk publish, housekeeping, scheduled backups) will skip execution if they start during a blackout window.
            Use this to avoid conflicts with PVE/Proxmox backups or other heavy I/O operations.
            Timezone: <strong><?= e(date_default_timezone_get()) ?></strong>
        </p>
        <?php
        $blackoutWindows = json_decode($settings['cron.blackout_windows'] ?? '[]', true);
        if (!is_array($blackoutWindows)) $blackoutWindows = [];
        while (count($blackoutWindows) < 3) $blackoutWindows[] = ['start' => '', 'end' => ''];
        ?>
        <?php foreach ($blackoutWindows as $i => $win): ?>
        <div class="form-grid-2col" style="max-width: 500px; margin-bottom: 0.25rem;">
            <div class="form-group">
                <label>Window <?= $i + 1 ?> Start</label>
                <input type="time" name="blackout_start[]" value="<?= e($win['start'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Window <?= $i + 1 ?> End</label>
                <input type="time" name="blackout_end[]" value="<?= e($win['end'] ?? '') ?>">
            </div>
        </div>
        <?php endforeach; ?>
        <small class="text-muted">Leave both fields blank to disable a window. Wraps around midnight (e.g. 23:00–01:00 covers 23:00–23:59 and 00:00–00:59).</small>
        <input type="hidden" name="cron__blackout_windows" id="blackout-windows-json" value="<?= e($settings['cron.blackout_windows'] ?? '[]') ?>">

        <h2>Raw Material SDS Staleness</h2>
        <p class="text-muted mb-1">Controls the threshold used by the <a href="/stale-rm-sds">Stale RM SDS</a> page. Raw materials whose supplier SDS hasn't been confirmed current within this window are surfaced for vendor follow-up.</p>
        <div class="form-grid-2col">
            <div class="form-group"><label>Stale Threshold (days)</label><input type="number" name="sds__rm_stale_days" min="1" step="1" value="<?= e($settings['sds.rm_stale_days'] ?? '1095') ?>"><small class="text-muted">Default 1095 (3 years). RMs exceeding this are flagged as stale.</small></div>
        </div>

        <h2>California Proposition 65</h2>
        <p class="text-muted mb-1">Threshold below which a CAS-matched Prop 65 chemical is treated as trace and gets the "(trace)" suffix in the generated warning text. Applies to chemicals pulled automatically from <code>prop65_list</code> via composition CAS numbers. Manual entries on the raw-material page still have their own Trace checkbox.</p>
        <div class="form-grid-2col">
            <div class="form-group"><label>Auto-Trace Threshold (%)</label><input type="number" name="prop65__auto_trace_threshold_pct" min="0" step="0.01" value="<?= e($settings['prop65.auto_trace_threshold_pct'] ?? '0.1') ?>"><small class="text-muted">Default 0.1 % — matches the OSHA HazCom Section 3 disclosure threshold for CMR chemicals.</small></div>
        </div>

        <h2>Airborne/Unbound Particles Override (Inhalation-Only Hazards)</h2>
        <p class="text-muted mb-1">
            CAS numbers listed here are treated as inhalation-only hazards. When the finished product
            contains any non-solid/non-powder ingredient (liquid, paste, gel, etc.), the particulate is
            bound and no longer airborne, so the following are <strong>suppressed</strong> for these CAS:
            Prop 65 warnings (on SDSs, labels, and the Prop 65 report), carcinogen classifications (H351),
            Section 8 exposure limits, and Section 11 findings. All-powder products keep everything.
            Enter one CAS number per line. Chemical names are resolved automatically from the Prop 65 list.
        </p>
        <div class="form-group">
            <textarea name="sds__inhalation_only_cas" id="inhalationOnlyCas" rows="6" style="font-family: monospace; font-size: 0.9rem;"
                      placeholder="1333-86-4&#10;13463-67-7"><?= e($settings['sds.inhalation_only_cas'] ?? "1333-86-4\n13463-67-7") ?></textarea>
        </div>
        <?php if (!empty($inhalationCasNames)): ?>
        <table class="table" style="max-width: 500px; margin-bottom: 1rem;">
            <thead><tr><th>CAS Number</th><th>Chemical Name</th></tr></thead>
            <tbody>
            <?php foreach ($inhalationCasNames as $cas => $name): ?>
                <tr>
                    <td style="font-family: monospace;"><?= e($cas) ?></td>
                    <td><?= e($name) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <div class="form-group" style="margin-top: 0.5rem;">
            <button type="button" class="btn btn-secondary" id="bumpInhalationSds" style="font-size: 0.85rem;">
                Bump Affected SDSs
            </button>
            <small class="text-muted" style="display: block; margin-top: 0.25rem;">
                Marks all SDSs containing these CAS numbers as stale so they get regenerated on next bulk publish.
            </small>
            <div id="bumpResult" style="margin-top: 0.5rem;"></div>
        </div>

        <h2>Maintenance</h2>
        <div class="form-grid-2col">
            <div class="form-group"><label>Audit Log Retention (days)</label><input type="number" name="cron__log_retention_days" value="<?= e($settings['cron.log_retention_days'] ?? '365') ?>"></div>
        </div>

        <div class="form-group" style="margin-top: 1rem;">
            <label>Regenerate every SDS</label>
            <button type="button" class="btn btn-secondary" id="bumpAllSds" style="font-size: 0.85rem;">
                Bump ALL unblocked SDSs
            </button>
            <div class="alert alert-warning" style="margin-top: 0.5rem; font-size: 0.85rem;">
                <strong>&#9888; Warning:</strong> This marks every <em>unblocked</em> finished good (all raw
                materials reviewed) as needing regeneration. The next <strong>Bulk SDS Publish</strong> will then
                regenerate all of them, and <strong>will take a long time to complete &mdash; likely several
                hours</strong>. Blocked products (with an unreviewed raw material) are not affected.
            </div>
            <div id="bumpAllResult" style="margin-top: 0.5rem;"></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
</div>

<script>
(function() {
    var freq = document.getElementById('cmsSyncFrequency');
    var minuteGroup = document.getElementById('cmsSyncMinuteGroup');
    var timeGroup = document.getElementById('cmsSyncTimeGroup');
    var dayGroup = document.getElementById('cmsSyncDayGroup');
    var hourGrid = document.querySelector('.hour-grid');
    var hourGridParent = hourGrid ? hourGrid.closest('.form-group') : null;

    function updateFrequencyUI() {
        var v = freq.value;
        minuteGroup.style.display = v === 'hourly' ? '' : 'none';
        timeGroup.style.display = v !== 'hourly' ? '' : 'none';
        dayGroup.style.display = v === 'weekly' ? '' : 'none';
        if (hourGridParent) hourGridParent.style.display = v === 'hourly' ? '' : 'none';
    }

    freq.addEventListener('change', updateFrequencyUI);
    updateFrequencyUI();

    // Hour toggle visual state
    document.querySelectorAll('.hour-toggle input').forEach(function(cb) {
        cb.addEventListener('change', function() {
            this.closest('.hour-toggle').classList.toggle('active', this.checked);
        });
    });

    // Serialize blackout windows into hidden field on submit
    document.querySelector('form').addEventListener('submit', function() {
        var starts = document.querySelectorAll('input[name="blackout_start[]"]');
        var ends = document.querySelectorAll('input[name="blackout_end[]"]');
        var windows = [];
        for (var i = 0; i < starts.length; i++) {
            if (starts[i].value && ends[i].value) {
                windows.push({start: starts[i].value, end: ends[i].value});
            }
        }
        document.getElementById('blackout-windows-json').value = JSON.stringify(windows);
    });
})();

function toggleAllHours(state) {
    document.querySelectorAll('.hour-toggle input').forEach(function(cb) {
        cb.checked = state;
        cb.closest('.hour-toggle').classList.toggle('active', state);
    });
}

document.getElementById('bumpInhalationSds').addEventListener('click', function() {
    var btn = this;
    var resultDiv = document.getElementById('bumpResult');
    var cas = document.getElementById('inhalationOnlyCas').value.trim();
    if (!cas) {
        resultDiv.innerHTML = '<span style="color:#c00;">No CAS numbers entered.</span>';
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Bumping...';
    resultDiv.innerHTML = '';

    fetch('/admin/settings/bump-inhalation-cas', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('input[name="_csrf_token"]').value},
        body: JSON.stringify({cas_list: cas})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Bump Affected SDSs';
        if (data.success) {
            resultDiv.innerHTML = '<span style="color:#080;">' + data.message + '</span>';
        } else {
            resultDiv.innerHTML = '<span style="color:#c00;">' + (data.message || 'Error') + '</span>';
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = 'Bump Affected SDSs';
        resultDiv.innerHTML = '<span style="color:#c00;">Request failed.</span>';
    });
});

document.getElementById('bumpAllSds').addEventListener('click', function() {
    if (!confirm('Bump ALL unblocked SDSs?\n\nThe next Bulk SDS Publish will regenerate every unblocked finished good and may take several hours to complete. Continue?')) {
        return;
    }
    var btn = this;
    var resultDiv = document.getElementById('bumpAllResult');
    btn.disabled = true;
    btn.textContent = 'Bumping...';
    resultDiv.innerHTML = '';

    fetch('/admin/settings/bump-all-sds', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('input[name="_csrf_token"]').value},
        body: JSON.stringify({})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Bump ALL unblocked SDSs';
        resultDiv.innerHTML = '<span style="color:' + (data.success ? '#080' : '#c00') + ';">' + (data.message || 'Error') + '</span>';
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = 'Bump ALL unblocked SDSs';
        resultDiv.innerHTML = '<span style="color:#c00;">Request failed.</span>';
    });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
