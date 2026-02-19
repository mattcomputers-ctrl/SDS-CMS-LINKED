<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h2 class="card-title">Data Sources</h2>
    <table class="table">
        <thead>
            <tr><th>Source</th><th>Records</th><th>Last Refresh</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($sources)): ?>
            <tr><td colspan="4" class="text-muted">No federal data loaded yet.</td></tr>
        <?php else: ?>
            <?php foreach ($sources as $src): ?>
            <tr>
                <td><strong><?= e($src['source_name']) ?></strong></td>
                <td><?= number_format((int) $src['record_count']) ?></td>
                <td><?= $src['last_refresh'] ? format_date($src['last_refresh'], 'm/d/Y H:i') : 'Never' ?></td>
                <td>
                    <form method="POST" action="/admin/federal-data/refresh" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="source" value="<?= e(strtolower($src['source_name'])) ?>">
                        <button type="submit" class="btn btn-sm" onclick="return confirm('Refresh <?= e($src['source_name']) ?> data? This may take several minutes.')">Refresh</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <form method="POST" action="/admin/federal-data/refresh" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="source" value="">
        <button type="submit" class="btn btn-primary" onclick="return confirm('Refresh ALL sources? This may take a long time.')">Refresh All Sources</button>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Refresh Log</h2>
    <?php if (empty($refreshLog)): ?>
        <p class="text-muted">No refresh history.</p>
    <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr><th>Source</th><th>Started</th><th>Finished</th><th>Status</th><th>Processed</th><th>Updated</th></tr>
            </thead>
            <tbody>
            <?php foreach ($refreshLog as $log): ?>
                <tr>
                    <td><?= e($log['source_name']) ?></td>
                    <td><?= format_date($log['started_at'], 'm/d/Y H:i') ?></td>
                    <td><?= $log['finished_at'] ? format_date($log['finished_at'], 'H:i:s') : '—' ?></td>
                    <td><span class="badge badge-<?= $log['status'] ?>"><?= e($log['status']) ?></span></td>
                    <td><?= (int) $log['records_processed'] ?></td>
                    <td><?= (int) $log['records_updated'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
