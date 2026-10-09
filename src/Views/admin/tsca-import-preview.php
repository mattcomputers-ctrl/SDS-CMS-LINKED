<?php include dirname(__DIR__) . '/layouts/main.php'; ?>
<?php
/**
 * Dry-run preview for the EPA TSCA inventory upload (AdminController@importTsca).
 * Nothing has been written yet. $parsed / $plan come straight from
 * TSCAInventoryImporter::parse() and ::plan() — the same numbers the CLI
 * prints for a --dry-run.
 */
$cols       = $parsed['columns'] ?? [];
$pruneOn    = !empty($meta['prune']);
$pruneEst   = (int) ($plan['pruneEstimate'] ?? 0);
$affected   = $plan['affected'] ?? [];
$affectedN  = (int) ($plan['affectedCount'] ?? 0);
$sizeMb     = isset($fileSize) ? number_format($fileSize / 1048576, 1) : null;
$fmt        = static fn($n): string => number_format((int) $n);
?>

<p><a href="/tsca">&larr; Back to TSCA Inventory</a></p>

<div class="d-flex justify-between align-center mb-1">
    <h2>TSCA Inventory Import &mdash; Preview</h2>
</div>

<div style="background:#eff6ff; border:1px solid #3b82f6; color:#1e3a8a; padding:0.6rem 0.9rem; border-radius:4px; margin-bottom:1rem;">
    <strong>Dry run &mdash; nothing has been written yet.</strong>
    Review the counts below, then click <strong>Apply import</strong> or <strong>Discard</strong>.
    The upload is kept for 2 hours.
</div>

<div class="card" style="margin-bottom: 1rem;">
    <h3 style="margin-top: 0;">File</h3>
    <table class="table table-sm" style="max-width: 720px;">
        <tr><th style="width: 220px;">Uploaded</th><td><?= e((string) ($meta['original_name'] ?? '')) ?><?php if ($sizeMb !== null): ?> <span class="text-muted">(CSV <?= e($sizeMb) ?> MB)</span><?php endif; ?></td></tr>
        <?php if (($meta['csv_name'] ?? '') !== ($meta['original_name'] ?? '')): ?>
        <tr><th>CSV inside ZIP</th><td><?= e((string) $meta['csv_name']) ?></td></tr>
        <?php endif; ?>
        <tr><th>Version label</th><td><code><?= e((string) ($meta['version'] ?? '')) ?></code> <span class="text-muted">(stamped as <code>source_version</code> on every EPA row this import touches)</span></td></tr>
        <tr><th>Detected columns</th>
            <td>
                CAS = <code><?= e((string) ($cols['cas'] ?? '')) ?></code>,
                name = <code><?= e((string) ($cols['name'] ?? '')) ?></code>,
                activity = <?= ($cols['activity'] ?? null) !== null ? '<code>' . e((string) $cols['activity']) . '</code>' : '<span class="text-muted">(none; all ACTIVE)</span>' ?>,
                flag = <?= ($cols['flag'] ?? null) !== null ? '<code>' . e((string) $cols['flag']) . '</code>' : '<span class="text-muted">(none)</span>' ?>
            </td>
        </tr>
        <tr><th>Header row</th><td class="text-muted" style="font-size: 0.85rem;"><?= e(implode(' | ', array_slice($parsed['headerFound'] ?? [], 0, 12))) ?></td></tr>
    </table>

    <?php if (!empty($parsed['warnings'])): ?>
        <div style="background:#fef3c7; border:1px solid #f59e0b; color:#78350f; padding:0.5rem 0.75rem; border-radius:4px; margin-top:0.5rem;">
            <strong>Warnings:</strong>
            <ul style="margin: 0.25rem 0 0 1.2rem;">
                <?php foreach ($parsed['warnings'] as $w): ?><li><?= e((string) $w) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <h3 style="margin-top: 0;">Parsed</h3>
    <table class="table table-sm" style="max-width: 720px;">
        <tr><th style="width: 220px;">Data rows</th><td><?= $fmt($parsed['rows'] ?? 0) ?></td></tr>
        <tr><th>Unique CAS</th><td><strong><?= $fmt($parsed['uniqueCas'] ?? 0) ?></strong></td></tr>
        <tr><th>Skipped (no / invalid CAS)</th><td><?= $fmt($parsed['skippedNoCas'] ?? 0) ?> <span class="text-muted">confidential accession numbers, blanks</span></td></tr>
        <tr><th>Duplicate CAS rows</th><td><?= $fmt($parsed['dupes'] ?? 0) ?> <span class="text-muted">first kept</span></td></tr>
        <tr><th>Existing table rows</th><td><?= $fmt($plan['existingCount'] ?? 0) ?></td></tr>
    </table>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <h3 style="margin-top: 0;">What would change</h3>
    <table class="table table-sm" style="max-width: 720px;">
        <tr><th style="width: 220px;">Insert</th><td><strong><?= $fmt($plan['inserted'] ?? 0) ?></strong> <span class="text-muted">CAS not on the table yet</span></td></tr>
        <tr><th>Update</th><td><strong><?= $fmt($plan['updated'] ?? 0) ?></strong> <span class="text-muted">name / activity / flags changed</span></td></tr>
        <tr><th>Unchanged</th><td><?= $fmt($plan['unchanged'] ?? 0) ?> <span class="text-muted">re-stamped with the version label only</span></td></tr>
        <tr><th>Manual rows preserved</th><td><?= $fmt($plan['skippedManual'] ?? 0) ?> <span class="text-muted">source = manual, never overwritten</span></td></tr>
        <tr><th>Prune</th>
            <td>
                <?php if ($pruneOn): ?>
                    <strong><?= $fmt($pruneEst) ?></strong> <span class="text-muted">EPA rows not in this file would be deleted</span>
                <?php else: ?>
                    <span class="text-muted">off &mdash; <?= $fmt($pruneEst) ?> EPA row<?= $pruneEst === 1 ? '' : 's' ?> not in this file would be deleted if you tick <em>Prune</em> below</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr><th>CAS in use that change status</th>
            <td>
                <strong><?= $fmt($affectedN) ?></strong>
                <span class="text-muted">constituent CAS whose Section 15 resolution flips (inserted<?= $pruneOn ? ' or pruned' : '' ?>); their raw materials are bumped and SDSs queued</span>
                <?php if ($affected !== []): ?>
                    <div style="font-size: 0.85rem; margin-top: 0.25rem;">
                        <?= e(implode(', ', array_slice($affected, 0, 40))) ?><?php if ($affectedN > 40): ?> <span class="text-muted">(+<?= $affectedN - 40 ?> more)</span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</div>

<div class="card">
    <form method="POST" action="/tsca/import/apply" style="display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap;"
          onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.textContent='Importing…'; return true;">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e((string) $token) ?>">
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label>Version label</label>
            <div><code><?= e((string) ($meta['version'] ?? '')) ?></code>
                <span class="text-muted" style="font-size: 0.85rem;">(fixed for this preview &mdash; discard and upload again to change it)</span></div>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label style="display:inline-flex; align-items:center; gap:0.35rem; font-weight:normal;">
                <input type="checkbox" name="prune" value="1" <?= $pruneOn ? 'checked' : '' ?>>
                Prune EPA rows missing from this file
            </label>
        </div>
        <button type="submit" class="btn btn-primary"
                onclick="return confirm('Apply this import to the TSCA inventory table? Affected raw materials will be flagged for republish.');">Apply import</button>
    </form>
    <form method="POST" action="/tsca/import/discard" style="display: inline-block; margin-top: 0.75rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e((string) $token) ?>">
        <button type="submit" class="btn btn-outline">Discard</button>
    </form>
    <p class="text-muted" style="font-size: 0.85rem; margin: 0.75rem 0 0;">
        Applying ~70k rows takes a few seconds to a minute. Leave the page open until it returns to the list.
    </p>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
