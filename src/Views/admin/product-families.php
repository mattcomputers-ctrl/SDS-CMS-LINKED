<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2>Product Families</h2>
    <div>
        <a href="/admin/product-families/recompute" class="btn">Recompute now</a>
        <a href="/admin/product-families/create" class="btn btn-primary">+ Add Family</a>
    </div>
</div>

<p class="text-muted">
    A family supplies the Section 1 Recommended Use / Restrictions on Use defaults (per language) and the UV/LED flag.
    Items join a family by manual choice on the item form, by a rule (code prefix, description phrase, exact code — aliases are matched too),
    or, for products, by the family carrying the largest weight share of their formula. Rule and family edits are applied by
    <strong>Recompute now</strong> (with a preview). Reassigned items are flagged for SDS republish.
</p>

<?php if (!empty($pending)): $n = (int) $pending['rm_changed'] + (int) $pending['fg_changed']; ?>
    <?php if ($n > 0): ?>
        <div class="alert alert-warning"><strong><?= $n ?></strong> item(s) would change family (<?= (int) $pending['reassigned'] ?> reassignment(s)). <a href="/admin/product-families/recompute">Review and apply</a>.</div>
    <?php else: ?>
        <p class="text-muted">All items are up to date with the current rules.</p>
    <?php endif; ?>
<?php endif; ?>

<table class="table table-sm">
    <thead>
        <tr>
            <th>Name</th>
            <th style="width: 80px;">UV/LED</th>
            <th style="width: 70px;">Rules</th>
            <th style="width: 120px;">Raw materials</th>
            <th style="width: 110px;">Products</th>
            <th style="width: 80px;">Active</th>
            <th style="width: 150px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($families)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;">No product families yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($families as $f): $u = $usage[(int) $f['id']] ?? ['raw_materials' => 0, 'finished_goods' => 0, 'manual_rm' => 0, 'manual_fg' => 0, 'rules' => 0]; ?>
        <tr>
            <td><strong><?= e($f['name']) ?></strong></td>
            <td><?= !empty($f['is_uv']) ? 'Yes' : '—' ?></td>
            <td><?= (int) $u['rules'] ?></td>
            <td><?= (int) $u['raw_materials'] ?><?= $u['manual_rm'] > 0 ? ' <small class="text-muted">(' . (int) $u['manual_rm'] . ' manual)</small>' : '' ?></td>
            <td><?= (int) $u['finished_goods'] ?><?= $u['manual_fg'] > 0 ? ' <small class="text-muted">(' . (int) $u['manual_fg'] . ' manual)</small>' : '' ?></td>
            <td><?= !empty($f['is_active']) ? 'Yes' : 'No' ?></td>
            <td>
                <a href="/admin/product-families/<?= (int) $f['id'] ?>/edit" class="btn btn-sm">Edit</a>
                <?php if ($u['manual_rm'] + $u['manual_fg'] === 0): ?>
                <form method="POST" action="/admin/product-families/<?= (int) $f['id'] ?>/delete" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger"
                            onclick="return confirm('Delete family <?= e($f['name']) ?>? Its rules are removed and its items are re-resolved.');">Delete</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
