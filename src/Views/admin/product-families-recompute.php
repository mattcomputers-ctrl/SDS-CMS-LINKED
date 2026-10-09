<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$c       = $result['counts'];
$total   = (int) $c['rm_changed'] + (int) $c['fg_changed'];
$limit   = 500;
$srcName = static fn(?string $s): string => $s === null ? '—' : $s;
?>

<p><a href="/admin/product-families">&larr; Back to Product Families</a></p>

<div class="d-flex justify-between align-center mb-1">
    <h2>Recompute Product Families</h2>
</div>

<?php if ($total === 0): ?>
    <div class="alert alert-success">Every raw material and product already carries the family the current rules and formulas resolve to. Nothing to apply.</div>
<?php else: ?>
    <div class="alert alert-warning">
        <strong><?= $total ?></strong> item(s) would change: <strong><?= (int) $c['reassigned'] ?></strong> reassignment(s) (family changes) and <?= (int) $c['metadata'] ?> source/name sync(s).
        Reassigned raw materials are bumped for bulk publish; reassigned products that already have a published SDS get one raw material bumped and an SDS Update entry.
    </div>
    <form method="POST" action="/admin/product-families/recompute" style="margin-bottom: 1rem;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary" onclick="return confirm('Apply <?= $total ?> change(s) and flag the affected SDSs for republish?');">Apply <?= $total ?> change(s)</button>
        <a href="/admin/product-families" class="btn btn-outline">Cancel</a>
    </form>

    <?php foreach (['raw_materials' => 'Raw materials', 'finished_goods' => 'Products (finished goods and intermediates)'] as $key => $title): $rows = $result['changes'][$key]; ?>
        <h3><?= e($title) ?> — <?= count($rows) ?></h3>
        <?php if (empty($rows)): ?>
            <p class="text-muted">No changes.</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead><tr><th style="width: 160px;">Code</th><th>Description</th><th style="width: 180px;">Current family</th><th style="width: 180px;">New family</th><th style="width: 110px;">Source</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($rows, 0, $limit) as $r): ?>
                <tr<?= $r['reassigned'] ? '' : ' class="text-muted"' ?>>
                    <td><strong><?= e((string) $r['code']) ?></strong></td>
                    <td><?= e((string) $r['description']) ?></td>
                    <td><?= e($srcName($r['old_family'])) ?><?= $r['old_source'] ? ' <small>(' . e($r['old_source']) . ')</small>' : '' ?></td>
                    <td><?= e($srcName($r['new_family'])) ?></td>
                    <td><?= e($srcName($r['new_source'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($rows) > $limit): ?>
                <tr><td colspan="5" class="text-muted">… and <?= count($rows) - $limit ?> more.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
