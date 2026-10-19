<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>Bulk Publish Queue</h2>
    <div>
        <a href="/bulk-publish" class="btn btn-outline">&larr; Bulk Publish</a>
        <a href="/bulk-publish/queue" class="btn btn-outline">Refresh</a>
    </div>
</div>

<p class="text-muted">
    Every bulk SDS publish trigger (hourly CMS sync, admin "Start Bulk Publish" click, CLI invocation)
    lands here as a queue row. One job runs at a time; concurrent triggers wait their turn. A job
    shown as <strong>running</strong> with <strong>0 active workers</strong> is stuck — use
    <em>Force fail</em> to clear it.
</p>
<p class="text-muted" style="margin-top:-0.25rem;">
    Each job publishes English first, then Spanish, German and French. <strong>Languages</strong>
    shows when each language was finished; English sheets are ready to use from that time, while
    the run carries on with the other languages.
</p>

<p class="text-muted" style="margin-top:-0.25rem;">
    <strong>Active worker processes right now:</strong> <?= (int) ($activeWorkers ?? 0) ?>
</p>

<?php if (empty($jobs)): ?>
    <div class="alert alert-info">No jobs in the queue yet.</div>
<?php else: ?>
<table class="table table-sm">
    <thead>
        <tr>
            <th style="width:50px;">#</th>
            <th style="width:90px;">Status</th>
            <th>Triggered</th>
            <th style="width:160px;">Queued</th>
            <th style="width:130px;">Started / Completed</th>
            <th style="width:120px;">Duration</th>
            <th style="width:220px;">Counts</th>
            <th style="width:190px;">Languages</th>
            <th>Error</th>
            <th style="width:140px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($jobs as $job): ?>
        <?php
            $status = $job['status'];
            $badgeColor = [
                'pending'   => '#6c757d',
                'running'   => '#0d6efd',
                'completed' => '#28a745',
                'failed'    => '#dc3545',
            ][$status] ?? '#6c757d';

            $trigger = $job['triggered_by'] ?? '';
            $user    = $job['user_name']    ?? null;
            $triggerLabel = $trigger === 'manual' && $user
                ? "manual · {$user}"
                : $trigger;

            $queuedAt    = $job['queued_at']    ?? '';
            $startedAt   = $job['started_at']   ?? '';
            $completedAt = $job['completed_at'] ?? '';

            // Audit #59 — job timestamps are stored in UTC.
            $duration = '';
            $startTs  = \SDS\Services\PublishClock::parseUtc((string) $startedAt);
            $doneTs   = \SDS\Services\PublishClock::parseUtc((string) $completedAt);
            if ($startTs !== null && $doneTs !== null) {
                $duration = gmdate('H:i:s', max(0, $doneTs - $startTs)) . ' (done)';
            } elseif ($startTs !== null) {
                $duration = gmdate('H:i:s', max(0, time() - $startTs)) . ' (running)';
            }

            $pub = $job['published_count']   ?? null;
            $fai = $job['failed_count']      ?? null;
            $wi  = $job['work_items_count']  ?? null;
            $countsText = '';
            if ($wi !== null) {
                $done = (int) $pub + (int) $fai;
                $pct  = $wi > 0 ? round(100 * $done / $wi, 1) : 0;
                $countsText = "{$done} / {$wi} ({$pct}%) · ";
            }
            $countsText .= "pub: " . ($pub ?? '—') . " · fail: " . ($fai ?? '—');

            $err = trim((string) ($job['error_message'] ?? ''));

            // Migration 065: language passes ("English done 10/20 14:05"); none for older jobs.
            $langRows = \SDS\Controllers\BulkPublishController::languageProgressRows($job['language_progress'] ?? null);
        ?>
        <tr>
            <td><strong>#<?= (int) $job['id'] ?></strong></td>
            <td>
                <span style="background:<?= $badgeColor ?>; color:#fff; border-radius:3px; padding:2px 8px; font-size:0.85rem; text-transform:uppercase;">
                    <?= e($status) ?>
                </span>
            </td>
            <td><?= e($triggerLabel) ?></td>
            <td><?= e(\SDS\Services\PublishClock::display((string) $queuedAt, 'Y-m-d H:i:s')) ?></td>
            <td>
                <?php if ($startedAt): ?>
                    <div style="font-size:0.85rem;">start <?= e(\SDS\Services\PublishClock::display((string) $startedAt, 'Y-m-d H:i:s')) ?></div>
                <?php endif; ?>
                <?php if ($completedAt): ?>
                    <div style="font-size:0.85rem;">done <?= e(\SDS\Services\PublishClock::display((string) $completedAt, 'Y-m-d H:i:s')) ?></div>
                <?php endif; ?>
                <?php if (!$startedAt && !$completedAt): ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= $duration !== '' ? e($duration) : '<span class="text-muted">—</span>' ?></td>
            <td style="font-size:0.85rem;"><?= e($countsText) ?></td>
            <td style="font-size:0.85rem;">
                <?php if ($langRows === []): ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
                <?php foreach ($langRows as $lr): ?>
                    <div<?= $lr['lang'] === 'en' ? ' title="Includes the private-label documents"' : '' ?>>
                        <?php if ($lr['done_at'] !== null): ?>
                            <span style="color:#28a745; font-weight:600;">&#10003; <?= e($lr['name']) ?> done</span>
                            <span class="text-muted"><?= e(\SDS\Services\PublishClock::display($lr['done_at'], 'm/d H:i')) ?></span>
                        <?php elseif ($status === 'running'): ?>
                            <?= e($lr['name']) ?>: <?= $lr['processed'] > 0 ? number_format($lr['processed']) . ' / ' . number_format($lr['items']) : '<span class="text-muted">waiting</span>' ?>
                        <?php else: ?>
                            <span class="text-muted"><?= e($lr['name']) ?>: not finished (<?= number_format($lr['processed']) ?> / <?= number_format($lr['items']) ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </td>
            <td style="font-size:0.8rem; color:#6b7280;"><?= $err !== '' ? e(mb_strimwidth($err, 0, 120, '…')) : '' ?></td>
            <td>
                <?php if ($status === 'pending'): ?>
                    <form method="POST" action="/bulk-publish/queue/<?= (int) $job['id'] ?>/dismiss" style="display:inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline"
                                onclick="return confirm('Dismiss pending job #<?= (int) $job['id'] ?>?');">Dismiss</button>
                    </form>
                <?php elseif ($status === 'running'): ?>
                    <form method="POST" action="/bulk-publish/queue/<?= (int) $job['id'] ?>/force-fail" style="display:inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('Force-fail running job #<?= (int) $job['id'] ?>?\n\nThis also SIGTERMs any surviving publish-worker processes. Use this if the runner appears dead or the job is hung.');">Force fail</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<script>
// Light auto-refresh while a job is running so the admin doesn't have to hit reload.
(function () {
    var hasActive = <?= !empty(array_filter($jobs, fn($j) => in_array($j['status'] ?? '', ['pending','running'], true))) ? 'true' : 'false' ?>;
    if (hasActive) {
        setTimeout(function () { window.location.reload(); }, 10000);
    }
})();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
