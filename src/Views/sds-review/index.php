<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="lead" style="margin-top: -0.5rem; margin-bottom: 0.5rem;">
    Which raw materials still need info?
</p>
<p class="text-muted mb-1">
    Enter a finished good, alias, or description to see which raw materials
    in its formula still need information before an SDS can be generated. A
    raw material counts as ready once a user has saved it or one of its
    constituent CAS numbers has an active CAS determination — the same rule
    used by <a href="/bulk-publish">Bulk SDS Publish</a>.
</p>

<form method="GET" action="/sds-review" class="lookup-form" style="margin-bottom: 1.5rem;">
    <div class="lookup-search">
        <input type="text" name="q" value="<?= e($q) ?>"
               placeholder="Product code, alias, or description…"
               autofocus autocomplete="off" spellcheck="false">
        <button type="submit" class="btn btn-primary">Check Readiness</button>
        <?php if ($q !== '' || $review !== null): ?>
            <a href="/sds-review" class="btn btn-outline" style="margin-left: 0.25rem;">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($review !== null): ?>
    <?php $fg = $review['fg']; ?>
    <div class="card" style="margin-bottom: 1rem;">
        <h2 style="margin-top: 0;">
            <?= e($fg['product_code']) ?>
            <?php if (!(int) $fg['is_active']): ?>
                <span class="badge badge-muted">Inactive</span>
            <?php endif; ?>
        </h2>
        <p class="text-muted" style="margin: 0.25rem 0 1rem;">
            <?= e($fg['description']) ?>
            <?php if (!empty($fg['family'])): ?>
                &middot; <?= e($fg['family']) ?>
            <?php endif; ?>
            <?php if (!empty($review['is_resale'])): ?>
                &middot; Resale of raw material <strong><?= e($review['resale_rm']['internal_code']) ?></strong>
                &middot; <?= (int) $review['reviewed_count'] ?> of <?= (int) $review['total_rms'] ?> raw material ready
            <?php elseif (!empty($review['formula_version'])): ?>
                &middot; Formula v<?= (int) $review['formula_version'] ?>
                &middot; <?= (int) $review['reviewed_count'] ?> of <?= (int) $review['total_rms'] ?> raw material<?= $review['total_rms'] === 1 ? '' : 's' ?> ready
            <?php endif; ?>
        </p>

        <?php if (!empty($review['is_resale'])): ?>
            <div class="alert alert-muted" style="background:#eef; border-left:3px solid #99c;">
                <strong>Resale item.</strong> This product has no formula — it's sold
                as-is under the alias. The SDS will be derived 100 % from the source
                raw material <strong><?= e($review['resale_rm']['internal_code']) ?></strong>.
            </div>
        <?php endif; ?>

        <?php if (!$review['has_formula']): ?>
            <div class="alert alert-warning">
                <strong>No formula entered.</strong>
                Before this finished good can be published, it needs a formula.
                <a href="/formulas/<?= (int) $fg['id'] ?>/edit">Create formula &rarr;</a>
            </div>
        <?php elseif (empty($review['unreviewed'])): ?>
            <div class="alert alert-success">
                <strong>Ready to generate an SDS.</strong>
                Every raw material in this formula has the info it needs.
                Bulk SDS Publish will include this finished good the next
                time it runs (provided the current SDS is out of date).
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <strong>
                    <?= count($review['unreviewed']) ?>
                    raw material<?= count($review['unreviewed']) === 1 ? '' : 's' ?>
                    still need<?= count($review['unreviewed']) === 1 ? 's' : '' ?> info.
                </strong>
                Open each one below and either fill in the hazard data (any
                save counts as reviewed) or add a CAS determination for one
                of its constituents.
            </div>

            <table class="table table-sm" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th>Internal Code</th>
                        <th>Supplier</th>
                        <th>Product</th>
                        <th>Where Used</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($review['unreviewed'] as $rm): ?>
                    <tr>
                        <td><strong><?= e($rm['internal_code']) ?></strong></td>
                        <td><?= e($rm['supplier'] ?? '') ?: '<span class="text-muted">&mdash;</span>' ?></td>
                        <td><?= e($rm['supplier_product_name'] ?? '') ?: '<span class="text-muted">&mdash;</span>' ?></td>
                        <td>
                            <?php if ($rm['is_direct']): ?>
                                <span class="badge badge-muted">Direct</span>
                            <?php endif; ?>
                            <?php foreach ($rm['via_fg_codes'] as $code): ?>
                                <span class="badge badge-muted" title="Used via sub-assembly">via <?= e($code) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td style="white-space: nowrap;">
                            <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm">Edit RM</a>
                            <a href="/raw-materials/<?= (int) $rm['id'] ?>/constituents" class="btn btn-sm btn-outline">Constituents</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p class="text-muted" style="margin-top: 1rem; margin-bottom: 0; font-size: 0.85rem;">
            <?php if (!empty($review['is_resale'])): ?>
                <a href="/raw-materials/<?= (int) $review['resale_rm']['id'] ?>/edit">Open source raw material</a>
                <?php if (empty($review['unreviewed'])): ?>
                    &middot; <a href="/sds/resale/<?= (int) $review['resale_rm']['id'] ?>/preview">Preview SDS</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="/formulas/<?= (int) $fg['id'] ?>">View full formula</a>
                <?php if ($review['has_formula']): ?>
                    &middot; <a href="/sds/<?= (int) $fg['id'] ?>/preview">Preview SDS</a>
                <?php endif; ?>
            <?php endif; ?>
        </p>

        <?php if (!empty($review['is_resale']) && empty($review['unreviewed']) && can_edit('sds')): ?>
            <form method="POST" action="/sds/resale/<?= (int) $review['resale_rm']['id'] ?>/publish"
                  style="margin-top: 1rem;"
                  onsubmit="return confirm('Publish SDS for <?= e($review['resale_rm']['base_code']) ?> and all resale aliases pointing at it?');">
                <?= csrf_field() ?>
                <input type="hidden" name="change_summary" value="Published from SDS Creation Readiness Check">
                <button type="submit" class="btn btn-primary">Publish Resale SDS</button>
                <small class="text-muted" style="margin-left: 0.5rem;">
                    Generates the SDS for this raw material under code
                    <strong><?= e($review['resale_rm']['base_code']) ?></strong>, plus one alias-branded
                    variant per alias that resolves to this RM via the resale path.
                </small>
            </form>
        <?php endif; ?>
    </div>

<?php elseif ($q !== '' && !empty($matches)): ?>
    <p class="text-muted"><?= count($matches) ?> match<?= count($matches) === 1 ? '' : 'es' ?> for &ldquo;<?= e($q) ?>&rdquo; &mdash; pick one to review.</p>
    <table class="table">
        <thead>
            <tr>
                <th>Product Code</th>
                <th>Description</th>
                <th>Family</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($matches as $m): ?>
            <?php $kind = $m['_kind'] ?? 'fg'; ?>
            <tr>
                <td>
                    <strong><?= e($m['display_code'] ?? ($m['product_code'] ?? '')) ?></strong>
                    <?php if ($kind === 'rm'): ?>
                        <span class="badge badge-muted" title="Resale raw material (no formula)">Resale</span>
                    <?php endif; ?>
                </td>
                <td><?= e($m['description']) ?></td>
                <td><?= e($m['family'] ?? '—') ?></td>
                <td><?= (int) $m['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                <td>
                    <?php if ($kind === 'rm'): ?>
                        <a href="/sds-review?rm_id=<?= (int) $m['id'] ?>" class="btn btn-sm btn-primary">Check</a>
                    <?php else: ?>
                        <a href="/sds-review?fg_id=<?= (int) $m['id'] ?>" class="btn btn-sm btn-primary">Check</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($q !== ''): ?>
    <div class="alert alert-muted">
        No finished goods or aliases matched &ldquo;<?= e($q) ?>&rdquo;.
    </div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
