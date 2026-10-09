<?php include dirname(__DIR__) . '/layouts/main.php'; ?>
<?php
/**
 * Element-flag seed preview (AdminController@elementFlagsSeedPreview, audit #19).
 * Nothing has been written yet. $plan comes straight from
 * CasElementFlagSeeder::plan() — the same rows and counts
 * scripts/seed-cas-element-flags.php prints for a dry run.
 */
$rows      = $plan['rows'] ?? [];
$force     = !empty($force);
$fmt       = static fn($n): string => number_format((int) $n);
$flagCols  = ['has_nitrogen' => 'N', 'has_sulfur' => 'S', 'has_halogen' => 'Hal'];
$changedN  = (int) ($plan['changed'] ?? 0);
$manualN   = (int) ($plan['skippedManual'] ?? 0);
$rmN       = (int) ($plan['inUseRmCount'] ?? 0);
?>
<style>
    .ef { display: inline-block; min-width: 2.1em; padding: 0.05rem 0.35rem; margin-right: 2px; border-radius: 3px; font-size: 0.72rem; font-weight: 600; text-align: center; background: #eef0f2; color: #9aa3ad; }
    .ef.on { background: #27ae60; color: #fff; }
    .ef.chg { outline: 2px solid #f39c12; outline-offset: -1px; }
    #efTable td, #efTable th { font-size: 0.85rem; padding: 0.3rem 0.5rem; }
    #efTable td.basis { font-family: monospace; font-size: 0.78rem; color: #555; word-break: break-word; }
</style>

<p><a href="/determinations?tab=descriptions">&larr; Back to CAS Determinations (CAS Descriptions)</a></p>

<div class="d-flex justify-between align-center mb-1">
    <h2>Seed Element Flags &mdash; Preview</h2>
</div>

<div style="background:#eff6ff; border:1px solid #3b82f6; color:#1e3a8a; padding:0.6rem 0.9rem; border-radius:4px; margin-bottom:1rem;">
    <strong>Dry run &mdash; nothing has been written yet.</strong>
    This is the same result as <code>scripts/seed-cas-element-flags.php</code> without <code>--confirm</code>.
    For every CAS in the registry the nitrogen / sulfur / halogen flags are inferred from the molecular formula when one is
    parseable, otherwise from a conservative keyword scan of every name known for the CAS (preferred name, synonyms,
    constituent, Prop 65 and HAP list names). Review the rows whose basis is a name keyword for false positives, then click
    <strong>Apply seed</strong>. Wrong flags can be corrected afterwards with the <em>Flags</em> button on the CAS Descriptions tab.
</div>

<div class="card" style="margin-bottom: 1rem;">
    <h3 style="margin-top: 0;">Summary</h3>
    <table class="table table-sm" style="max-width: 720px;">
        <tr><th style="width: 240px;">Rows scanned</th><td><?= $fmt($plan['scanned'] ?? 0) ?> <span class="text-muted">cas_master rows</span></td></tr>
        <tr><th>Changed</th><td><strong><?= $fmt($changedN) ?></strong> <span class="text-muted">CAS whose flags would be rewritten (listed below)</span></td></tr>
        <tr><th>Unchanged</th><td><?= $fmt($plan['unchanged'] ?? 0) ?> <span class="text-muted">inferred flags already match</span></td></tr>
        <tr><th>Skipped (manual)</th>
            <td>
                <?= $fmt($manualN) ?>
                <span class="text-muted">
                    <?php if ($force): ?>
                        &mdash; force is on for this preview: manually set rows are included above and would be overwritten
                    <?php else: ?>
                        rows set by hand with the <em>Flags</em> button (<code>element_flags_source = manual</code>) are left alone;
                        <a href="/determinations/element-flags?force=1">preview with them included</a>
                    <?php endif; ?>
                </span>
            </td>
        </tr>
        <tr><th>Raw materials to bump</th><td><strong><?= $fmt($rmN) ?></strong> <span class="text-muted">distinct raw materials carrying a changed CAS; their <code>updated_at</code> is set so the next bulk publish picks them up</span></td></tr>
    </table>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <form method="POST" action="/determinations/element-flags/apply" style="display: flex; align-items: flex-end; gap: 1.25rem; flex-wrap: wrap;"
          onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.textContent='Applying…'; return true;">
        <?= csrf_field() ?>
        <div class="form-group" style="margin-bottom: 0;">
            <label style="display:inline-flex; align-items:center; gap:0.35rem; font-weight:normal;">
                <input type="checkbox" name="no_queue" value="1">
                Do not queue SDS-update rows (raw materials are still bumped)
            </label>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label style="display:inline-flex; align-items:center; gap:0.35rem; font-weight:normal;">
                <input type="checkbox" name="force" value="1" <?= $force ? 'checked' : '' ?>>
                Also overwrite manually set flags (<code>--force</code>)
            </label>
        </div>
        <button type="submit" class="btn btn-primary" id="efApply"
                data-changed="<?= (int) $changedN ?>" data-rms="<?= (int) $rmN ?>" data-manual="<?= (int) $manualN ?>" data-previewed-force="<?= $force ? '1' : '0' ?>"
                <?= ($changedN === 0 && ($force || $manualN === 0)) ? 'disabled' : '' ?>>Apply seed</button>
    </form>
    <p class="text-muted" style="font-size: 0.85rem; margin: 0.75rem 0 0;">
        The first run on an existing catalog bumps every raw material carrying a flagged CAS (Section 10 text changes for those
        products) &mdash; run it before a bulk publish, not during one. Tick <em>Do not queue</em> if the SDS Updates page would be
        flooded; bulk publish still picks the products up. A second run reports Changed: 0.
    </p>
</div>

<div class="card">
    <div class="d-flex justify-between align-center" style="flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="margin: 0;">CAS that would change <span class="text-muted" style="font-weight: normal; font-size: 0.9rem;">(<?= $fmt($changedN) ?>)</span></h3>
        <input type="text" id="efFilter" placeholder="Filter by CAS, name, basis or source..." style="max-width: 360px;" <?= $rows === [] ? 'disabled' : '' ?>>
    </div>
    <p class="text-muted" style="font-size: 0.85rem; margin: 0.5rem 0;">
        Current &rarr; proposed flags; a highlighted pill marks the flag that changes. Rows whose basis starts with
        <code>formula</code> come from the molecular formula; <code>names:</code> lists the keyword stems that fired, per flag.
        Manually set rows are <?= $force ? 'included (force)' : 'not listed' ?>.
    </p>
    <?php if ($rows === []): ?>
        <p class="text-muted"><em>Nothing to change &mdash; every registry row already carries its inferred flags<?= $manualN > 0 && !$force ? ' (apart from ' . $fmt($manualN) . ' manually set row' . ($manualN === 1 ? '' : 's') . ')' : '' ?>.</em></p>
    <?php else: ?>
    <table class="table" id="efTable">
        <thead>
            <tr>
                <th style="width: 120px;">CAS</th>
                <th>Name</th>
                <th style="width: 125px;" title="Flags stored now">Current</th>
                <th style="width: 125px;" title="Flags the seed would write">Proposed</th>
                <th>Basis</th>
                <th style="width: 70px;" title="element_flags_source stored now">Source</th>
                <th style="width: 60px; text-align: right;" title="Raw materials carrying this CAS">RMs</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php $cur = $r['current']; $pro = $r['proposed']; ?>
            <tr>
                <td><strong><?= e($r['cas']) ?></strong></td>
                <td><?= e($r['name']) ?></td>
                <td><?php foreach ($flagCols as $k => $lbl): ?><span class="ef<?= !empty($cur[$k]) ? ' on' : '' ?>"><?= $lbl ?></span><?php endforeach; ?></td>
                <td><?php foreach ($flagCols as $k => $lbl): ?><span class="ef<?= !empty($pro[$k]) ? ' on' : '' ?><?= (int) ($cur[$k] ?? 0) !== (int) ($pro[$k] ?? 0) ? ' chg' : '' ?>"><?= $lbl ?></span><?php endforeach; ?></td>
                <td class="basis"><?= e($r['basis']) ?></td>
                <td><?= $r['source'] !== null ? '<span class="badge' . ($r['source'] === 'manual' ? ' badge-draft' : ' badge-muted') . '">' . e($r['source']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                <td style="text-align: right;"><?= (int) $r['rmCount'] > 0 ? (int) $r['rmCount'] : '<span class="text-muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="text-muted" id="efFilterCount" style="font-size: 0.85rem; margin: 0.5rem 0 0;"></p>
    <?php endif; ?>
</div>

<script>
(function () {
    // Confirm dialog for Apply — states what will be written and bumped.
    var apply = document.getElementById('efApply');
    if (apply) {
        apply.addEventListener('click', function (ev) {
            var f = apply.form;
            var changed = parseInt(apply.dataset.changed, 10) || 0;
            var rms     = parseInt(apply.dataset.rms, 10) || 0;
            var manual  = parseInt(apply.dataset.manual, 10) || 0;
            var forceTicked    = f.force && f.force.checked;
            var forcePreviewed = apply.dataset.previewedForce === '1';
            var msg = 'Seed element flags for ' + changed + ' CAS? ' + rms + ' raw material(s) will be flagged for republish'
                + (f.no_queue && f.no_queue.checked ? '' : ' and the affected SDSs queued on SDS Updates') + '.';
            if (forceTicked && !forcePreviewed) {
                msg += '\n\nForce is ticked: the ' + manual + ' manually set row(s) will be overwritten too. '
                    + 'They are NOT in the table above; use the "preview with them included" link first if you want to see them.';
            }
            if (!window.confirm(msg)) {
                ev.preventDefault();
            }
        });
    }

    var input = document.getElementById('efFilter');
    var table = document.getElementById('efTable');
    if (!input || !table) return;
    var rows  = Array.prototype.slice.call(table.tBodies[0].rows);
    var texts = rows.map(function (tr) { return tr.textContent.toLowerCase(); });
    var count = document.getElementById('efFilterCount');
    var timer = null;
    function run() {
        var q = input.value.trim().toLowerCase();
        var shown = 0;
        for (var i = 0; i < rows.length; i++) {
            var hit = q === '' || texts[i].indexOf(q) !== -1;
            rows[i].style.display = hit ? '' : 'none';
            if (hit) shown++;
        }
        if (count) count.textContent = q === '' ? '' : shown + ' of ' + rows.length + ' rows match';
    }
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(run, 120);
    });
})();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
