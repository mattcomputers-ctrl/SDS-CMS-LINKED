<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>TSCA Inventory</h2>
    <a href="/tsca/create" class="btn btn-primary">+ Add Entry</a>
</div>

<p class="text-muted">
    CAS numbers on this list resolve as <strong>listed</strong> for the Section 15 TSCA sentence; a per-CAS
    override (listed / exempt / not listed) lives on
    <a href="/determinations?tab=tsca">CAS Determinations &rsaquo; TSCA Review</a>.
    <strong>Manual</strong> entries survive imports; <strong>EPA</strong> entries are refreshed by the
    import below (or <code>scripts/import-tsca-inventory.php</code> on the server — same importer).
</p>

<p class="text-muted" style="margin-top:-0.5rem;">
    <strong>Total:</strong> <?= (int) ($counts['total'] ?? 0) ?>
    &nbsp;·&nbsp; Active: <?= (int) ($counts['active'] ?? 0) ?>
    &nbsp;·&nbsp; Manual: <?= (int) ($counts['manual'] ?? 0) ?>
    &nbsp;·&nbsp; Latest import:
    <?php if (!empty($counts['latest_version']) || !empty($counts['last_import'])): ?>
        <?= e((string) ($counts['latest_version'] ?? '')) ?>
        <?php if (!empty($counts['last_import'])): ?>(<?= e((string) $counts['last_import']) ?>)<?php endif; ?>
    <?php else: ?>
        never
    <?php endif; ?>
</p>

<?php if (can_edit('tsca_list')): ?>
<div class="card" style="margin-bottom: 1rem;">
    <h3 style="margin-top: 0;">Import EPA TSCA inventory</h3>
    <p class="text-muted" style="margin-top: 0;">
        Download the current <strong>non-confidential</strong> inventory from
        <a href="https://www.epa.gov/tsca-inventory/how-access-tsca-inventory" target="_blank" rel="noopener">epa.gov › How to Access the TSCA Inventory</a>
        and upload the CSV (e.g. <code>TSCAINV_022025.csv</code>) or the ZIP that contains it. The file has roughly 70,000 rows.
        Nothing is written yet &mdash; the next page is a dry-run preview with an <strong>Apply import</strong> button.
        Manual entries are never touched; raw materials whose CAS changes status are flagged for republish and their SDSs queued.
    </p>
    <form method="POST" action="/tsca/import" enctype="multipart/form-data"
          style="display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap;">
        <?= csrf_field() ?>
        <div class="form-group" style="margin-bottom: 0;">
            <label for="tsca_file">EPA CSV or ZIP</label>
            <input type="file" id="tsca_file" name="tsca_file" accept=".csv,.zip" required>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 220px;">
            <label for="tsca_version">Version label</label>
            <input type="text" id="tsca_version" name="version" maxlength="100" placeholder="defaults to the CSV name"
                   value="<?= e(old('version')) ?>">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label style="display:inline-flex; align-items:center; gap:0.35rem; font-weight:normal;">
                <input type="checkbox" name="prune" value="1">
                Prune EPA rows missing from this file
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Upload &amp; preview</button>
    </form>
    <script>
    (function () {
        var f = document.getElementById('tsca_file'), v = document.getElementById('tsca_version');
        if (!f || !v) return;
        f.addEventListener('change', function () {
            if (v.value.trim() !== '' && v.dataset.auto !== '1') return;
            var n = (f.files && f.files[0]) ? f.files[0].name : '';
            v.value = n.replace(/\.(csv|zip)$/i, '');
            v.dataset.auto = '1';
        });
        v.addEventListener('input', function () { v.dataset.auto = ''; });
    })();
    </script>
</div>
<?php endif; ?>

<?php $anyFilter = ($q ?? '') !== '' || (($source ?? 'all') !== 'all') || (($active ?? 'all') !== 'all') || !empty($inUse); ?>
<form method="GET" action="/tsca" class="d-flex" style="gap: 0.5rem; margin-bottom: 1rem; align-items: flex-end; flex-wrap: wrap;">
    <div class="form-group" style="flex: 1; margin-bottom: 0; min-width: 220px;">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= e($q ?? '') ?>" placeholder="CAS prefix or chemical name">
    </div>
    <div class="form-group" style="margin-bottom: 0;">
        <label for="source">Source</label>
        <select id="source" name="source">
            <?php foreach (['all' => 'All', 'manual' => 'Manual', 'epa' => 'EPA'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= ($source ?? 'all') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin-bottom: 0;">
        <label for="active">Activity</label>
        <select id="active" name="active">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= ($active ?? 'all') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin-bottom: 0;">
        <label style="display:inline-flex; align-items:center; gap:0.35rem; font-weight:normal;">
            <input type="checkbox" name="in_use" value="1" <?= !empty($inUse) ? 'checked' : '' ?>>
            Only CAS used by raw materials
        </label>
    </div>
    <button type="submit" class="btn">Filter</button>
    <?php if ($anyFilter): ?>
        <a href="/tsca" class="btn btn-outline">Clear</a>
    <?php endif; ?>
</form>

<?php if (!empty($truncated)): ?>
    <p class="text-muted">Showing the first <?= (int) $cap ?> entries — narrow with a search or filter.</p>
<?php else: ?>
    <p class="text-muted">Showing <?= count($items) ?> entr<?= count($items) === 1 ? 'y' : 'ies' ?>.</p>
<?php endif; ?>

<table class="table table-sm">
    <thead>
        <tr>
            <th style="width: 130px;">CAS</th>
            <th>Chemical Name</th>
            <th style="width: 80px;">Active</th>
            <th style="width: 90px;">Flags</th>
            <th style="width: 100px;">Used in RMs</th>
            <th style="width: 90px;">Source</th>
            <th style="width: 130px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;">No matching entries.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $item): ?>
        <?php
            $src      = $item['source_ref'] ?? null;
            $isManual = $src === 'manual';
            $srcLabel = $isManual ? 'Manual' : 'EPA';
            $srcStyle = $isManual ? 'style="color:#1e40af; font-weight:600;"' : 'style="color:#6b7280;"';
            $srcTitle = $isManual ? 'manual' : trim('EPA ' . (string) ($item['source_version'] ?? ''));
            $rmCount  = (int) ($item['rm_count'] ?? 0);
        ?>
        <tr>
            <td><strong><?= e($item['cas_number']) ?></strong></td>
            <td><?= e($item['chemical_name']) ?></td>
            <td><?= (int) ($item['is_active_inventory'] ?? 1) === 1 ? 'Yes' : '<span class="text-muted">Inactive</span>' ?></td>
            <td><?= e((string) ($item['flags'] ?? '')) ?></td>
            <td><?= $rmCount > 0 ? $rmCount : '<span class="text-muted">—</span>' ?></td>
            <td <?= $srcStyle ?> title="<?= e($srcTitle) ?>"><?= e($srcLabel) ?></td>
            <td>
                <a href="/tsca/<?= rawurlencode($item['cas_number']) ?>/edit" class="btn btn-sm">Edit</a>
                <form method="POST" action="/tsca/<?= rawurlencode($item['cas_number']) ?>/delete" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger"
                            onclick="return confirm('Delete this TSCA inventory entry (<?= e($item['cas_number']) ?>)? Products containing it will print the not-verified sentence.');">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
