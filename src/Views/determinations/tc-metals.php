<?php include dirname(__DIR__) . '/layouts/main.php'; ?>
<?php
/**
 * RCRA metal-flag seed preview (AdminController@tcMetalsSeedPreview, audit #12).
 * Nothing has been written yet. $plan comes straight from
 * CasTcMetalSeeder::plan() — the same rows and counts
 * scripts/seed-cas-tc-metals.php prints for a dry run.
 */
$rows      = $plan['rows'] ?? [];
$force     = !empty($force);
$fmt       = static fn($n): string => number_format((int) $n);
$metals    = \SDS\Services\CasElementFlagger::TC_METALS;
$changedN  = (int) ($plan['changed'] ?? 0);
$manualN   = (int) ($plan['skippedManual'] ?? 0);
$rmN       = (int) ($plan['inUseRmCount'] ?? 0);
?>
<style>
    .tm { display: inline-block; min-width: 2.1em; padding: 0.05rem 0.35rem; margin-right: 2px; border-radius: 3px; font-size: 0.72rem; font-weight: 600; text-align: center; background: #eef0f2; color: #9aa3ad; }
    .tm.on { background: #27ae60; color: #fff; }
    .tm.chg { outline: 2px solid #f39c12; outline-offset: -1px; }
    #tmTable td, #tmTable th { font-size: 0.85rem; padding: 0.3rem 0.5rem; }
    #tmTable td.basis { font-family: monospace; font-size: 0.78rem; color: #555; word-break: break-word; }
</style>

<p><a href="/determinations?tab=descriptions">&larr; Back to CAS Determinations (CAS Descriptions)</a></p>

<div class="d-flex justify-between align-center mb-1">
    <h2>Seed RCRA Metal Flags &mdash; Preview</h2>
</div>

<div style="background:#eff6ff; border:1px solid #3b82f6; color:#1e3a8a; padding:0.6rem 0.9rem; border-radius:4px; margin-bottom:1rem;">
    <strong>Dry run &mdash; nothing has been written yet.</strong>
    This is the same result as <code>scripts/seed-cas-tc-metals.php</code> without <code>--confirm</code>.
    For every CAS in the registry the RCRA toxicity-characteristic metals (As, Ba, Cd, Cr, Pb, Hg, Se, Ag) are inferred from the
    molecular formula when one is parseable, otherwise from conservative name patterns (including Colour Index pigment names) over
    every name known for the CAS. A flagged compound gets the element's D004&ndash;D011 code and TCLP limit in SDS Section 13.
    Review the rows whose basis is a name pattern for false positives, then click <strong>Apply seed</strong>. Wrong metals can be
    corrected afterwards in the <em>RCRA metals</em> box on the CAS Descriptions tab.
</div>

<div class="card" style="margin-bottom: 1rem;">
    <h3 style="margin-top: 0;">Summary</h3>
    <table class="table table-sm" style="max-width: 720px;">
        <tr><th style="width: 240px;">Rows scanned</th><td><?= $fmt($plan['scanned'] ?? 0) ?> <span class="text-muted">cas_master rows</span></td></tr>
        <tr><th>Changed</th><td><strong><?= $fmt($changedN) ?></strong> <span class="text-muted">CAS whose RCRA metals would be rewritten (listed below)</span></td></tr>
        <tr><th>Unchanged</th><td><?= $fmt($plan['unchanged'] ?? 0) ?> <span class="text-muted">inferred metals already match</span></td></tr>
        <tr><th>Skipped (manual)</th>
            <td>
                <?= $fmt($manualN) ?>
                <span class="text-muted">
                    <?php if ($force): ?>
                        &mdash; force is on for this preview: manually set rows are included above and would be overwritten
                    <?php else: ?>
                        rows set by hand in the <em>RCRA metals</em> box (<code>tc_metals_source = manual</code>) are left alone;
                        <a href="/determinations/tc-metals?force=1">preview with them included</a>
                    <?php endif; ?>
                </span>
            </td>
        </tr>
        <tr><th>Raw materials to bump</th><td><strong><?= $fmt($rmN) ?></strong> <span class="text-muted">distinct raw materials carrying a changed CAS; their <code>updated_at</code> is set so the next bulk publish picks them up</span></td></tr>
    </table>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <form method="POST" action="/determinations/tc-metals/apply" style="display: flex; align-items: flex-end; gap: 1.25rem; flex-wrap: wrap;"
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
                Also overwrite manually set metals (<code>--force</code>)
            </label>
        </div>
        <button type="submit" class="btn btn-primary" id="tmApply"
                data-changed="<?= (int) $changedN ?>" data-rms="<?= (int) $rmN ?>" data-manual="<?= (int) $manualN ?>" data-previewed-force="<?= $force ? '1' : '0' ?>"
                <?= ($changedN === 0 && ($force || $manualN === 0)) ? 'disabled' : '' ?>>Apply seed</button>
    </form>
    <p class="text-muted" style="font-size: 0.85rem; margin: 0.75rem 0 0;">
        Only CAS that contain a TC metal are written, so the first run bumps just the raw materials carrying those compounds
        (Section 13 text changes for those products). Expect barium sulfate and barium-lake pigments to add a conditional D005
        line. A second run reports Changed: 0.
    </p>
</div>

<div class="card">
    <div class="d-flex justify-between align-center" style="flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="margin: 0;">CAS that would change <span class="text-muted" style="font-weight: normal; font-size: 0.9rem;">(<?= $fmt($changedN) ?>)</span></h3>
        <input type="text" id="tmFilter" placeholder="Filter by CAS, name, basis or source..." style="max-width: 360px;" <?= $rows === [] ? 'disabled' : '' ?>>
    </div>
    <p class="text-muted" style="font-size: 0.85rem; margin: 0.5rem 0;">
        Current &rarr; proposed metals; a highlighted pill marks a metal that changes. Rows whose basis starts with
        <code>formula</code> come from the molecular formula; <code>names:</code> lists the patterns that fired, per metal.
        Manually set rows are <?= $force ? 'included (force)' : 'not listed' ?>.
    </p>
    <?php if ($rows === []): ?>
        <p class="text-muted"><em>Nothing to change &mdash; every registry row already carries its inferred metals<?= $manualN > 0 && !$force ? ' (apart from ' . $fmt($manualN) . ' manually set row' . ($manualN === 1 ? '' : 's') . ')' : '' ?>.</em></p>
    <?php else: ?>
    <table class="table" id="tmTable">
        <thead>
            <tr>
                <th style="width: 120px;">CAS</th>
                <th>Name</th>
                <th style="width: 230px;" title="Metals stored now">Current</th>
                <th style="width: 230px;" title="Metals the seed would write">Proposed</th>
                <th>Basis</th>
                <th style="width: 70px;" title="tc_metals_source stored now">Source</th>
                <th style="width: 60px; text-align: right;" title="Raw materials carrying this CAS">RMs</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php $cur = $r['current']; $pro = $r['proposed']; ?>
            <tr>
                <td><strong><?= e($r['cas']) ?></strong></td>
                <td><?= e($r['name']) ?></td>
                <td><?php foreach ($metals as $m): ?><span class="tm<?= in_array($m, $cur, true) ? ' on' : '' ?>"><?= $m ?></span><?php endforeach; ?></td>
                <td><?php foreach ($metals as $m): ?><span class="tm<?= in_array($m, $pro, true) ? ' on' : '' ?><?= in_array($m, $cur, true) !== in_array($m, $pro, true) ? ' chg' : '' ?>"><?= $m ?></span><?php endforeach; ?></td>
                <td class="basis"><?= e($r['basis']) ?></td>
                <td><?= $r['source'] !== null ? '<span class="badge' . ($r['source'] === 'manual' ? ' badge-draft' : ' badge-muted') . '">' . e($r['source']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                <td style="text-align: right;"><?= (int) $r['rmCount'] > 0 ? (int) $r['rmCount'] : '<span class="text-muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="text-muted" id="tmFilterCount" style="font-size: 0.85rem; margin: 0.5rem 0 0;"></p>
    <?php endif; ?>
</div>

<script>
(function () {
    // Confirm dialog for Apply — states what will be written and bumped.
    var apply = document.getElementById('tmApply');
    if (apply) {
        apply.addEventListener('click', function (ev) {
            var f = apply.form;
            var changed = parseInt(apply.dataset.changed, 10) || 0;
            var rms     = parseInt(apply.dataset.rms, 10) || 0;
            var manual  = parseInt(apply.dataset.manual, 10) || 0;
            var forceTicked    = f.force && f.force.checked;
            var forcePreviewed = apply.dataset.previewedForce === '1';
            var msg = 'Seed RCRA metal flags for ' + changed + ' CAS? ' + rms + ' raw material(s) will be flagged for republish'
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

    var input = document.getElementById('tmFilter');
    var table = document.getElementById('tmTable');
    if (!input || !table) return;
    var rows  = Array.prototype.slice.call(table.tBodies[0].rows);
    var texts = rows.map(function (tr) { return tr.textContent.toLowerCase(); });
    var count = document.getElementById('tmFilterCount');
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
