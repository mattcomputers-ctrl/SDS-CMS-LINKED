<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>California Prop 65 List</h2>
    <a href="/prop65/create" class="btn btn-primary">+ Add Entry</a>
</div>

<p class="text-muted">
    Chemicals whose CAS appears here drive the RM edit page's Auto-detected Prop 65 section and
    Section 15 warning text on generated SDSs. Entries tagged <strong>manual</strong> are preserved
    by the OEHHA importer; <strong>OEHHA</strong> entries are refreshed from the official CSV each
    time <code>scripts/import-prop65-list.php</code> runs.
</p>

<p class="text-muted" style="margin-top:-0.5rem;">
    <strong>Total:</strong> <?= (int) ($counts['total'] ?? 0) ?>
    &nbsp;·&nbsp; Manual: <?= (int) ($counts['manual'] ?? 0) ?>
    &nbsp;·&nbsp; OEHHA: <?= (int) ($counts['oehha'] ?? 0) ?>
    &nbsp;·&nbsp; Seed: <?= (int) ($counts['seed'] ?? 0) ?>
</p>

<form method="GET" action="/prop65" class="d-flex" style="gap: 0.5rem; margin-bottom: 1rem; align-items: flex-end;">
    <div class="form-group" style="flex: 1; margin-bottom: 0;">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= e($q ?? '') ?>" placeholder="CAS or chemical name">
    </div>
    <div class="form-group" style="margin-bottom: 0;">
        <label for="source">Source</label>
        <select id="source" name="source">
            <?php foreach (['all' => 'All', 'manual' => 'Manual', 'oehha' => 'OEHHA', 'seed' => 'Seed'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= ($source ?? 'all') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn">Filter</button>
    <?php if (!empty($q) || (!empty($source) && $source !== 'all')): ?>
        <a href="/prop65" class="btn btn-outline">Clear</a>
    <?php endif; ?>
</form>

<p class="text-muted">
    Showing <?= count($items) ?> entr<?= count($items) === 1 ? 'y' : 'ies' ?>.
</p>

<table class="table table-sm">
    <thead>
        <tr>
            <th style="width: 130px;">CAS</th>
            <th>Chemical Name</th>
            <th>Toxicity Type</th>
            <th style="width: 110px;">Date Listed</th>
            <th style="width: 90px;">Source</th>
            <th style="width: 130px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
        <tr><td colspan="6" class="text-muted" style="text-align:center;">No matching entries.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $item): ?>
        <?php
            $src      = $item['source_ref'] ?? null;
            $srcLabel = 'Seed';
            $srcClass = '';
            if ($src === 'manual') {
                $srcLabel = 'Manual';
                $srcClass = 'style="color:#1e40af; font-weight:600;"';
            } elseif ($src !== null && str_starts_with($src, 'OEHHA')) {
                $srcLabel = 'OEHHA';
                $srcClass = 'style="color:#6b7280;"';
            }
        ?>
        <tr>
            <td><strong><?= e($item['cas_number']) ?></strong></td>
            <td><?= e($item['chemical_name']) ?></td>
            <td><?= e($item['toxicity_type']) ?></td>
            <td><?= e($item['date_listed'] ?? '—') ?></td>
            <td <?= $srcClass ?> title="<?= e($src ?? '') ?>"><?= e($srcLabel) ?></td>
            <td>
                <a href="/prop65/<?= (int) $item['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <form method="POST" action="/prop65/<?= (int) $item['id'] ?>/delete" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger"
                            onclick="return confirm('Delete this Prop 65 entry (<?= e($item['cas_number']) ?>)?');">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
