<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/sds/<?= (int) $version['finished_good_id'] ?>">&larr; Back to SDS Versions</a></p>

<div class="card">
    <h2>Version <?= (int) $version['version'] ?> (<?= e($version['language']) ?>) — <?= e($version['product_code']) ?></h2>
    <p class="text-muted">Published <?= format_date($version['published_at'], 'm/d/Y H:i') ?></p>
</div>

<div class="card">
    <h2 class="card-title">Hazard Decision Trace</h2>
    <?php if (empty($trace)): ?>
        <p class="text-muted">No trace data available for this version.</p>
    <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr><th>#</th><th>Step</th><th>Description</th><th>Details</th></tr>
            </thead>
            <tbody>
            <?php foreach ($trace as $i => $step): ?>
                <tr class="trace-<?= e($step['step'] ?? '') ?>">
                    <td><?= $i + 1 ?></td>
                    <td><span class="badge"><?= e($step['step'] ?? '') ?></span></td>
                    <td><?= e($step['description'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($step['data'])): ?>
                            <details>
                                <summary>Data</summary>
                                <pre class="trace-data"><?= e(json_encode($step['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
