<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>EPA RCRA Waste Codes <small class="text-muted" style="font-weight:400; font-size:0.85rem;">(40 CFR 261 — toxicity characteristic and listed wastes)</small></h2>
    <a href="/rcra/create" class="btn btn-primary">+ Add Entry</a>
</div>

<p class="text-muted">
    Chemicals whose CAS appears here drive the RCRA classification line in Section 13 of generated SDSs:
    <strong>D004–D043</strong> toxicity-characteristic constituents (with their TCLP regulatory level) and
    <strong>F / K / P / U</strong> listed wastes. One row per CAS + code — a solvent can carry several
    (e.g. methyl ethyl ketone: D035, U159, F005). D001–D003 are derived from the product's flash point and
    hazard classification and are not managed here. Saving an entry flags every raw material carrying the
    CAS for re-publish. Entries tagged <strong>manual</strong> were added via this page; <strong>seed</strong>
    entries came from migration 055 and carry their CFR citation.
</p>

<p class="text-muted" style="margin-top:-0.5rem;">
    <strong>Total:</strong> <?= (int) ($counts['total'] ?? 0) ?>
    &nbsp;·&nbsp; Manual: <?= (int) ($counts['manual'] ?? 0) ?>
    &nbsp;·&nbsp; Seed: <?= (int) ($counts['seed'] ?? 0) ?>
</p>

<form method="GET" action="/rcra" class="d-flex" style="gap: 0.5rem; margin-bottom: 1rem; align-items: flex-end;">
    <div class="form-group" style="flex: 1; margin-bottom: 0;">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= e($q ?? '') ?>" placeholder="CAS, waste code, or description">
    </div>
    <div class="form-group" style="margin-bottom: 0;">
        <label for="kind">Kind</label>
        <select id="kind" name="kind">
            <option value="">All</option>
            <?php foreach (['D' => 'D — toxicity characteristic', 'F' => 'F — non-specific source', 'K' => 'K — specific source', 'P' => 'P — acutely hazardous', 'U' => 'U — toxic commercial product'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= ($kind ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin-bottom: 0;">
        <label for="source">Source</label>
        <select id="source" name="source">
            <?php foreach (['all' => 'All', 'manual' => 'Manual', 'seed' => 'Seed'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= ($source ?? 'all') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn">Filter</button>
    <?php if (!empty($q) || !empty($kind) || (!empty($source) && $source !== 'all')): ?>
        <a href="/rcra" class="btn btn-outline">Clear</a>
    <?php endif; ?>
</form>

<p class="text-muted">
    Showing <?= count($items) ?> entr<?= count($items) === 1 ? 'y' : 'ies' ?>.
</p>

<table class="table table-sm">
    <thead>
        <tr>
            <th style="width: 90px;">Code</th>
            <th style="width: 60px;">Kind</th>
            <th style="width: 130px;">CAS</th>
            <th>Description</th>
            <th style="width: 130px;">TCLP limit (mg/L)</th>
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
            $srcLabel = $isManual ? 'Manual' : 'Seed';
            $srcStyle = $isManual ? 'color:#1e40af; font-weight:600;' : 'color:#6b7280;';
            $limit    = $item['limit_mg_l'] ?? null;
            $limitTxt = ($limit === null || $limit === '') ? '' : rtrim(rtrim(number_format((float) $limit, 3, '.', ''), '0'), '.');
        ?>
        <tr>
            <td><strong><?= e($item['waste_code']) ?></strong></td>
            <td><?= e($item['kind']) ?></td>
            <td><?= e($item['cas_number']) ?></td>
            <td><?= e($item['description']) ?></td>
            <td><?= $limitTxt !== '' ? e($limitTxt) : '<span class="text-muted">—</span>' ?></td>
            <td style="<?= $srcStyle ?>" title="<?= e($src ?? '') ?>"><?= e($srcLabel) ?></td>
            <td>
                <a href="/rcra/<?= (int) $item['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <form method="POST" action="/rcra/<?= (int) $item['id'] ?>/delete" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger"
                            onclick="return confirm('Delete <?= e($item['waste_code']) ?> for CAS <?= e($item['cas_number']) ?>?');">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
