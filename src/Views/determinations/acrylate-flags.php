<?php include dirname(__DIR__) . '/layouts/main.php'; ?>
<?php
/**
 * Acrylate-flag seed preview (AdminController@acrylateFlagsSeedPreview, A12a).
 * Nothing has been written yet. $plan comes straight from
 * CasAcrylateFlagSeeder::plan(), the same rows and counts
 * scripts/seed-cas-acrylate-flags.php prints for a dry run.
 */
$rows     = $plan['rows'] ?? [];
$force    = !empty($force);
$fmt      = static fn($n): string => number_format((int) $n);
$flagTxt  = static fn(?int $v): string => $v === null ? 'not set' : ($v === 1 ? 'acrylate' : 'not acrylate');
$changedN = (int) ($plan['changed'] ?? 0);
$textN    = (int) ($plan['textChanges'] ?? 0);
$manualN  = (int) ($plan['skippedManual'] ?? 0);
$rmN      = (int) ($plan['inUseRmCount'] ?? 0);
$missing  = $plan['legacyNotInRegistry'] ?? [];
?>
<style>
    #acTable td, #acTable th { font-size: 0.85rem; padding: 0.3rem 0.5rem; }
    #acTable td.basis { font-family: monospace; font-size: 0.78rem; color: #555; word-break: break-word; }
    .acchk { display: inline-block; padding: 0.05rem 0.35rem; border-radius: 3px; font-size: 0.72rem; font-weight: 600; background: #f39c12; color: #fff; }
</style>

<p><a href="/determinations?tab=descriptions">&larr; Back to CAS Determinations (CAS Descriptions)</a></p>

<div class="d-flex justify-between align-center mb-1">
    <h2>Seed Acrylate Flags &mdash; Preview</h2>
</div>

<div style="background:#eff6ff; border:1px solid #3b82f6; color:#1e3a8a; padding:0.6rem 0.9rem; border-radius:4px; margin-bottom:1rem;">
    <p style="margin: 0;">
        <strong>Dry run &mdash; nothing has been written yet.</strong>
        This is the same result as <code>scripts/seed-cas-acrylate-flags.php</code> without <code>--confirm</code>.
        For every CAS in the registry the acrylate flag is set when the CAS is on the old 14-entry list or any name known for it
        (registry description, synonyms, constituent / Prop 65 / HAP names) looks like a reactive acrylate or methacrylate monomer
        or oligomer (&ldquo;&hellip;acrylate&rdquo;, (meth)acrylic acid; acrylic polymers and resins never count). UV sheets name the
        flagged components in the Section 4 skin sentence, using the CAS description from this registry. The seed never marks a CAS
        &ldquo;not an acrylate&rdquo;; untick it on the CAS Descriptions tab instead.
    </p>
    <p style="margin: 0.5rem 0 0;">
        The old hard-coded list labelled 2223-82-7, 28961-43-5, 52408-84-1 and 55818-57-0 wrongly. Those labels are gone, so check the
        CAS descriptions of these four, and of every row marked <span class="acchk">check name</span>, on the CAS Descriptions tab
        before the next publish.
    </p>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <h3 style="margin-top: 0;">Summary</h3>
    <table class="table table-sm" style="max-width: 720px;">
        <tr><th style="width: 240px;">Rows scanned</th><td><?= $fmt($plan['scanned'] ?? 0) ?> <span class="text-muted">cas_master rows</span></td></tr>
        <tr><th>Changed</th><td><strong><?= $fmt($changedN) ?></strong> <span class="text-muted">CAS whose stored flag would be written (listed below)</span></td></tr>
        <tr><th>Change sheet text</th><td><strong><?= $fmt($textN) ?></strong> <span class="text-muted">of those change what UV sheets detect; only these bump raw materials</span></td></tr>
        <tr><th>Unchanged</th><td><?= $fmt($plan['unchanged'] ?? 0) ?> <span class="text-muted">stored flag already matches</span></td></tr>
        <tr><th>Skipped (manual)</th>
            <td>
                <?= $fmt($manualN) ?>
                <span class="text-muted">
                    <?php if ($force): ?>
                        &mdash; force is on for this preview: manually set rows are included above and would be overwritten
                    <?php else: ?>
                        rows set by hand in the <em>Acrylate</em> box (<code>acrylate_source = manual</code>) are left alone;
                        <a href="/determinations/acrylate-flags?force=1">preview with them included</a>
                    <?php endif; ?>
                </span>
            </td>
        </tr>
        <tr><th>Raw materials to bump</th><td><strong><?= $fmt($rmN) ?></strong> <span class="text-muted">distinct raw materials carrying a CAS whose detection changes; their <code>updated_at</code> is set so the next bulk publish picks them up</span></td></tr>
        <tr><th>Legacy CAS not in registry</th>
            <td>
                <?= $missing === [] ? '<span class="text-muted">none</span>' : e(implode(', ', $missing)) ?>
                <span class="text-muted">still detected through the old list for one more release; add them to the registry (CAS Descriptions) before the list is removed</span>
            </td>
        </tr>
    </table>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <form method="POST" action="/determinations/acrylate-flags/apply" style="display: flex; align-items: flex-end; gap: 1.25rem; flex-wrap: wrap;"
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
        <button type="submit" class="btn btn-primary" id="acApply"
                data-changed="<?= (int) $changedN ?>" data-rms="<?= (int) $rmN ?>" data-manual="<?= (int) $manualN ?>" data-previewed-force="<?= $force ? '1' : '0' ?>" data-text="<?= $textN ?>"
                <?= ($changedN === 0 && ($force || $manualN === 0)) ? 'disabled' : '' ?>>Apply seed</button>
    </form>
    <p class="text-muted" style="font-size: 0.85rem; margin: 0.75rem 0 0;">
        Rows marked "no" under Sheet text only record what the sheets already detect: they are written without bumping any raw
        material. A second run reports Changed: 0.
    </p>
</div>

<div class="card">
    <div class="d-flex justify-between align-center" style="flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="margin: 0;">CAS that would change <span class="text-muted" style="font-weight: normal; font-size: 0.9rem;">(<?= $fmt($changedN) ?>)</span></h3>
        <input type="text" id="acFilter" placeholder="Filter by CAS, name, basis or source..." style="max-width: 360px;" <?= $rows === [] ? 'disabled' : '' ?>>
    </div>
    <p class="text-muted" style="font-size: 0.85rem; margin: 0.5rem 0;">
        Current &rarr; proposed flag. <code>legacy list</code> means the CAS is on the old 14-entry list; <code>names:</code> lists the
        names that look like an acrylate. Manually set rows are <?= $force ? 'included (force)' : 'not listed' ?>.
    </p>
    <?php if ($rows === []): ?>
        <p class="text-muted"><em>Nothing to change &mdash; every registry row already carries its proposed acrylate flag<?= $manualN > 0 && !$force ? ' (apart from ' . $fmt($manualN) . ' manually set row' . ($manualN === 1 ? '' : 's') . ')' : '' ?>.</em></p>
    <?php else: ?>
    <table class="table" id="acTable">
        <thead>
            <tr>
                <th style="width: 120px;">CAS</th>
                <th>Name</th>
                <th style="width: 110px;" title="Flag stored now">Current</th>
                <th style="width: 110px;" title="Flag the seed would write">Proposed</th>
                <th>Basis</th>
                <th style="width: 80px;" title="Does the acrylate detection on UV sheets change (bumps raw materials)?">Sheet text</th>
                <th style="width: 70px;" title="acrylate_source stored now">Source</th>
                <th style="width: 60px; text-align: right;" title="Raw materials carrying this CAS">RMs</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['cas']) ?></strong></td>
                <td><?= e($r['name']) ?><?= !empty($r['nameCheck']) ? ' <span class="acchk">check name</span>' : '' ?></td>
                <td><?= e($flagTxt($r['current'])) ?></td>
                <td><?= $r['proposed'] !== $r['current'] ? '<strong>' . e($flagTxt($r['proposed'])) . '</strong>' : e($flagTxt($r['proposed'])) ?></td>
                <td class="basis"><?= e($r['basis']) ?></td>
                <td><?= $r['bump'] ? '<strong>yes</strong>' : '<span class="text-muted">no</span>' ?></td>
                <td><?= $r['source'] !== null ? '<span class="badge' . ($r['source'] === 'manual' ? ' badge-draft' : ' badge-muted') . '">' . e($r['source']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                <td style="text-align: right;"><?= (int) $r['rmCount'] > 0 ? (int) $r['rmCount'] : '<span class="text-muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="text-muted" id="acFilterCount" style="font-size: 0.85rem; margin: 0.5rem 0 0;"></p>
    <?php endif; ?>
</div>

<script>
(function () {
    // Confirm dialog for Apply — states what will be written and bumped.
    var apply = document.getElementById('acApply');
    if (apply) {
        apply.addEventListener('click', function (ev) {
            var f = apply.form;
            var changed = parseInt(apply.dataset.changed, 10) || 0;
            var text    = parseInt(apply.dataset.text, 10) || 0;
            var rms     = parseInt(apply.dataset.rms, 10) || 0;
            var manual  = parseInt(apply.dataset.manual, 10) || 0;
            var forceTicked    = f.force && f.force.checked;
            var forcePreviewed = apply.dataset.previewedForce === '1';
            var msg = 'Seed acrylate flags for ' + changed + ' CAS? ' + text + ' change sheet text; ' + rms + ' raw material(s) will be flagged for republish'
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

    var input = document.getElementById('acFilter');
    var table = document.getElementById('acTable');
    if (!input || !table) return;
    var rows  = Array.prototype.slice.call(table.tBodies[0].rows);
    var texts = rows.map(function (tr) { return tr.textContent.toLowerCase(); });
    var count = document.getElementById('acFilterCount');
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
