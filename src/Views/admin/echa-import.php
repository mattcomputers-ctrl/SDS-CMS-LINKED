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

    <h2 style="margin-top: 1.5rem;">Upload Annex VI CSV</h2>
    <form method="POST" action="/admin/echa-import/upload" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="echa_csv">CSV file</label>
            <input type="file" id="echa_csv" name="echa_csv" accept=".csv,.txt" required>
            <small class="text-muted">
                Got an .xls from ECHA? Open it in Excel, then File → Save As → CSV UTF-8.
                Keep column headers <code>cas_number</code>, <code>m_factor_acute</code>,
                <code>m_factor_chronic</code>, <code>acute_category</code>,
                <code>chronic_category</code>. Extra columns are ignored.
            </small>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Upload &amp; Import</button>
            <a href="/admin/settings" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
