<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<!-- Last Sync & Shipment Stats -->
<div class="card" style="margin-bottom: 16px;">
    <h3>Last Sync</h3>
    <table class="table" style="max-width: 600px;">
        <tbody>
            <tr>
                <td>Completed</td>
                <td>
                    <?php if (!empty($syncInfo['last_completed_at'])): ?>
                        <strong><?= e($syncInfo['last_completed_at']) ?></strong>
                        <?php if (!empty($syncInfo['last_trigger'])): ?>
                            <span class="text-muted">
                                (<?= e($syncInfo['last_trigger']) ?><?php if (($syncInfo['last_trigger'] ?? '') === 'manual' && !empty($syncInfo['last_triggered_by'])): ?> by <?= e($syncInfo['last_triggered_by']) ?><?php endif; ?>)
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">Never</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>Total shipments in system</td>
                <td><strong><?= number_format((int) ($syncInfo['shipment_count'] ?? 0)) ?></strong></td>
            </tr>
            <tr>
                <td>Most recent shipment</td>
                <td>
                    <?php if (!empty($syncInfo['recent_date'])): ?>
                        <strong><?= e($syncInfo['recent_date']) ?></strong>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>Most recent Ship To</td>
                <td>
                    <?php if (!empty($syncInfo['recent_ship_to'])): ?>
                        <strong><?= e($syncInfo['recent_ship_to']) ?></strong>
                        <?php if (!empty($syncInfo['recent_ship_name'])): ?>
                            <span class="text-muted"> — <?= e($syncInfo['recent_ship_name']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<?php if (!$configured): ?>
    <div class="alert alert-warning">
        <strong>CMS database not configured.</strong>
        Add a <code>cms_db</code> section to <code>config/config.php</code> with your MSSQL connection details.
    </div>
<?php else: ?>

    <?php if (!empty($incomplete)): ?>
    <div class="alert alert-info">
        <strong><?= count($incomplete) ?> raw material(s)</strong> imported from CMS still need details (constituents, SDS, etc.).
        <a href="/cms-import/incomplete" class="btn btn-sm" style="margin-left: 8px;">View Checklist</a>
    </div>
    <?php endif; ?>

    <?php
        $newCount     = count(array_filter($items, fn($i) => $i['import_status'] === 'new'));
        $revisedCount = count(array_filter($items, fn($i) => $i['import_status'] === 'revised'));
        $currentCount = count(array_filter($items, fn($i) => $i['import_status'] === 'current'));
    ?>

    <div class="card" style="margin-bottom: 16px;">
        <h3>CMS Sync Summary</h3>
        <table class="table" style="max-width: 400px;">
            <tbody>
                <tr>
                    <td>Total CMS items with formulas</td>
                    <td><strong><?= number_format(count($items)) ?></strong></td>
                </tr>
                <tr>
                    <td>New (will be created)</td>
                    <td><span class="badge badge-success"><?= $newCount ?></span></td>
                </tr>
                <tr>
                    <td>Revised (formula updated in CMS)</td>
                    <td><span class="badge badge-warning"><?= $revisedCount ?></span></td>
                </tr>
                <tr>
                    <td>Up to date</td>
                    <td><span class="badge badge-muted"><?= $currentCount ?></span></td>
                </tr>
            </tbody>
        </table>

        <?php if ($newCount > 0 || $revisedCount > 0): ?>
        <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
            <form method="POST" action="/cms-import/preview">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline">Preview Import</button>
            </form>
            <form method="POST" action="/cms-import/import" onsubmit="return confirm('Import and sync all CMS items? This will create new items, update revised formulas, sync aliases and shipments.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Run Import</button>
            </form>
            <form method="POST" action="/cms-import/full-sync"
                  onsubmit="return confirm('Run full sync? This imports from CMS, publishes eligible SDSs, and sends pending customer emails. This runs in the background.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Run Full Sync (Import + Publish + Auto-Send)</button>
            </form>
        </div>
        <?php else: ?>
        <p class="text-muted" style="margin-top: 12px;">All CMS items are up to date.</p>
        <form method="POST" action="/cms-import/import" style="margin-top: 8px;" onsubmit="return confirm('Re-sync aliases and shipments from CMS?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline">Re-sync Aliases &amp; Shipments</button>
        </form>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
