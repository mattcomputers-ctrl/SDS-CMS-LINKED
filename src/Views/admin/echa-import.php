<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h1>ECHA CLP Annex VI M-Factor Import</h1>
    <p class="text-muted">
        Import harmonised aquatic M-factor values from ECHA's CLP Annex VI
        Table 3.1 to drive Phase 4 aquatic-summation classifications.
        Required CSV columns:
        <code>cas_number, m_factor_acute, m_factor_chronic, acute_category, chronic_category</code>.
        Upload idempotently overwrites existing data — re-run any time
        ECHA publishes an update.
    </p>

    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>"
             style="margin-top: 1rem;">
            <strong><?= e($flash['msg']) ?></strong>
            <?php if (!empty($flash['output'])): ?>
                <pre style="margin-top: 0.5rem; font-size: 0.75rem; max-height: 18rem; overflow: auto; background: #fff; padding: 0.5rem; border: 1px solid #ddd;"><?= e($flash['output']) ?></pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2 style="margin-top: 1.5rem;">Current State</h2>
    <table class="table" style="max-width: 40rem;">
        <tr>
            <th>Rows with acute M-factor set</th>
            <td><?= (int) $acuteCount ?></td>
        </tr>
        <tr>
            <th>Rows with chronic M-factor set</th>
            <td><?= (int) $chronicCount ?></td>
        </tr>
        <tr>
            <th>seeds/echa_m_factors.csv</th>
            <td>
                <?php if ($csvExists): ?>
                    Present — <?= number_format($csvSize) ?> bytes, modified <?= e($csvMtime) ?>
                <?php else: ?>
                    <span class="text-muted">Not uploaded yet</span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <h2 style="margin-top: 1.5rem;">CSV Requirements</h2>
    <p class="text-muted">
        The first row must be a header row. Column names are matched
        case-insensitively. Any columns not listed below are ignored,
        so you can leave the raw ECHA export columns in place as long
        as the five required ones are present.
    </p>
    <table class="table" style="max-width: 60rem;">
        <thead>
            <tr>
                <th style="width: 12rem;">Column</th>
                <th style="width: 6rem;">Required?</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>cas_number</code></td>
                <td>Yes</td>
                <td>CAS Registry Number with hyphens (e.g. <code>1317-38-0</code>). Rows without a CAS number are skipped.</td>
            </tr>
            <tr>
                <td><code>m_factor_acute</code></td>
                <td>Either this or <code>m_factor_chronic</code></td>
                <td>Acute aquatic M-factor — typically 1, 10, 100, or 1000. Leave blank if the substance has no acute M-factor.</td>
            </tr>
            <tr>
                <td><code>m_factor_chronic</code></td>
                <td>Either this or <code>m_factor_acute</code></td>
                <td>Chronic aquatic M-factor. Leave blank if the substance has no chronic M-factor.</td>
            </tr>
            <tr>
                <td><code>acute_category</code></td>
                <td>Optional</td>
                <td>GHS category for the acute classification (e.g. <code>Category 1</code>). Only Category 1 carries an acute M-factor per GHS. Defaults to <code>Cat 1</code> if blank and an acute M-factor is provided.</td>
            </tr>
            <tr>
                <td><code>chronic_category</code></td>
                <td>Optional</td>
                <td>GHS category for the chronic classification. Defaults to <code>Cat 1</code> if blank and a chronic M-factor is provided.</td>
            </tr>
            <tr>
                <td><code>source_note</code></td>
                <td>Optional</td>
                <td>Free-text provenance for the audit trail (e.g. <code>ECHA Annex VI 2024-11</code>).</td>
            </tr>
        </tbody>
    </table>

    <h3 style="margin-top: 1rem;">Example rows</h3>
    <pre style="background: #f7f7f7; padding: 0.75rem; border-left: 3px solid #003366; font-size: 0.85rem; overflow-x: auto;">cas_number,m_factor_acute,m_factor_chronic,acute_category,chronic_category,source_note
1317-38-0,10,10,Category 1,Category 1,ECHA Annex VI 2024-11
13463-41-7,100,100,Category 1,Category 1,ECHA Annex VI 2024-11
56-35-9,1000,1000,Category 1,Category 1,ECHA Annex VI 2024-11</pre>

    <h3 style="margin-top: 1rem;">Notes</h3>
    <ul class="text-muted" style="font-size: 0.9rem;">
        <li>Lines beginning with <code>#</code> are treated as comments and skipped — useful for inline documentation.</li>
        <li>Empty M-factor cells are treated as "not specified" — the engine uses the GHS default M = 1.0 for those substances at runtime.</li>
        <li>Re-uploading is idempotent. Existing rows with the same CAS + category are updated in place; new synthetic rows are created when no aquatic classification exists yet.</li>
        <li>Cat 2 / Cat 3 aquatic categories don't carry M-factors per GHS — those rows should still be left to the engine's summation math rather than imported here.</li>
    </ul>

    <h2 style="margin-top: 1.5rem;">Upload Annex VI CSV</h2>
    <form method="POST" action="/admin/echa-import/upload" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="echa_csv">CSV file</label>
            <input type="file" id="echa_csv" name="echa_csv" accept=".csv,.txt" required>
            <small class="text-muted">
                Got an .xls from ECHA? Open it in Excel, then File → Save As → CSV UTF-8.
            </small>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Upload &amp; Import</button>
            <a href="/admin/settings" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
