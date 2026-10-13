<?php include dirname(__DIR__) . '/layouts/main.php'; ?>
<?php
// Migration 064: quick "Not a chemical" button. Posts to the flag endpoint,
// which checks the capability and CSRF and comes back to the given tab.
$canFlagNoSds = can_edit('rm_sds_not_required');
$noSdsButton  = static function (array $rm, string $returnTo): string {
    $code = (string) ($rm['internal_code'] ?? '');
    $msg  = 'Mark ' . $code . ' as not a chemical (No SDS required)? It stays in the system, but it is left off'
          . ' SDS publishing, GHS labels, the SDS send queue and these worklists.';
    return '<form method="POST" action="/raw-materials/' . (int) $rm['id'] . '/sds-requirement" style="display: inline;"'
         . ' onsubmit="return confirm(' . e((string) json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . ');">'
         . csrf_field()
         . '<input type="hidden" name="sds_not_required" value="1">'
         . '<input type="hidden" name="return_to" value="' . e($returnTo) . '">'
         . '<button type="submit" class="btn btn-sm btn-outline" title="Mark No SDS required: pails, lids, colour guides, tools">Not a chemical</button>'
         . '</form>';
};
$flaggedInFormulas = $flaggedInFormulas ?? [];
?>

<!-- Tab Navigation -->
<div class="tab-nav" style="display: flex; gap: 0; border-bottom: 2px solid #003366; margin-bottom: 1rem;">
    <button class="tab-btn active" data-tab="incomplete" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #003366; color: #fff; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-right: 2px;">
        Needs Details
        <?php if (!empty($incomplete)): ?>
            <span style="background: #dc3545; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($incomplete) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="unblockers" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold;">
        Single-RM Unblock
        <?php if (!empty($unblockers)): ?>
            <span style="background: #28a745; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($unblockers) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="trade-secret" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-left: 2px;">
        Trade Secret
        <?php if (!empty($tradeSecret)): ?>
            <span style="background: #f59e0b; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($tradeSecret) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" data-tab="inventory" style="padding: 0.5rem 1.2rem; border: 2px solid #003366; border-bottom: none; background: #e9ecef; color: #003366; cursor: pointer; border-radius: 4px 4px 0 0; font-weight: bold; margin-left: 2px;">
        In Inventory
        <?php if (!empty($inventoryGaps)): ?>
            <span style="background: #0d6efd; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 0.8rem; margin-left: 4px;"><?= count($inventoryGaps) ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- Tab: Needs Details (existing) -->
<div class="tab-panel" id="tab-incomplete">
    <?php if (!empty($flaggedInFormulas)): ?>
        <!-- Migration 064: marked No SDS required, but a current formula uses it -->
        <div class="alert alert-warning">
            <strong><?= count($flaggedInFormulas) ?> raw material(s) marked No SDS required are used in a current formula.</strong>
            Formulas are not changed by the mark, so their data (usually none) still feeds these finished goods' SDSs.
            Check that each one belongs in the formula.
            <table class="table table-sm" style="margin: 8px 0 0; background: transparent;">
                <thead>
                    <tr>
                        <th>Internal Code</th>
                        <th>Product Name</th>
                        <th title="Finished goods whose current formula uses this raw material directly">Used in</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($flaggedInFormulas as $frm): ?>
                    <tr>
                        <td><strong><a href="/raw-materials/<?= (int) $frm['id'] ?>/edit"><?= e($frm['internal_code']) ?></a></strong>
                            <span class="badge badge-muted">No SDS required</span></td>
                        <td><?= e($frm['supplier_product_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                        <td><?= e($frm['fg_codes']) ?> <small class="text-muted">(<?= (int) $frm['fg_direct_count'] ?>)</small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (empty($incomplete)): ?>
        <div class="alert alert-success">
            All imported raw materials have their details saved. Nothing to do here.
        </div>
    <?php else: ?>
        <p class="text-muted"><?= count($incomplete) ?> raw material(s) imported from CMS still need constituents and SDS details. Sorted by the number of finished goods that depend on them — highest impact first.
            Items marked No SDS required are left off unless a current formula uses them.</p>

        <table class="table">
            <thead>
                <tr>
                    <th>Internal Code</th>
                    <th>Product Name</th>
                    <th title="Finished goods whose formula tree transitively contains this raw material">FGs Using</th>
                    <th title="Finished goods that reference this raw material directly">Direct</th>
                    <th>CMS Code</th>
                    <th>Imported</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($incomplete as $rm): ?>
                <tr>
                    <td><strong><?= e($rm['internal_code']) ?></strong>
                        <?php if (!empty($rm['sds_not_required'])): ?>
                            <span class="badge badge-muted" title="Marked No SDS required, but a current formula uses it: its data still feeds that finished good's SDS">No SDS required</span>
                        <?php endif; ?></td>
                    <td><?= e($rm['supplier_product_name'] ?? '—') ?></td>
                    <td><strong><?= (int) ($rm['fg_total_count'] ?? 0) ?></strong></td>
                    <td><span class="text-muted"><?= (int) ($rm['fg_direct_count'] ?? 0) ?></span></td>
                    <td><?= e($rm['cms_item_code'] ?? '—') ?></td>
                    <td><?= e(\SDS\Services\PublishClock::display($rm['created_at'] ?? null, 'Y-m-d H:i:s')) ?></td>
                    <td style="white-space: nowrap;">
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm btn-primary">Add Details</a>
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/constituents" class="btn btn-sm btn-outline">Constituents</a>
                        <?php if ($canFlagNoSds && empty($rm['sds_not_required'])): ?>
                            <?= $noSdsButton($rm, '/cms-import/incomplete') ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Tab: Single-RM Unblock -->
<div class="tab-panel" id="tab-unblockers" style="display: none;">
    <?php if (empty($unblockers)): ?>
        <div class="alert alert-success">
            No raw material is a single-shot blocker right now. Every finished good with an unreviewed RM has at least two unreviewed RMs in its formula tree, so no one-RM review would fully unblock an FG on its own.
        </div>
    <?php else: ?>
        <p class="text-muted">Raw materials where reviewing <em>just this one</em> would qualify at least one finished good for bulk SDS publish. Sorted by leverage — the top row unblocks the most SDSs per unit of review effort.</p>

        <table class="table">
            <thead>
                <tr>
                    <th>Internal Code</th>
                    <th>Supplier</th>
                    <th>Product Name</th>
                    <th title="Finished goods that would become eligible for bulk publish if this single RM is reviewed">FGs Unblocked</th>
                    <th>Example FG codes</th>
                    <th>CMS Code</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($unblockers as $rm): ?>
                <?php
                    $all      = $rm['all_fgs']     ?? [];
                    $examples = $rm['example_fgs'] ?? [];
                    $extra    = count($all) - count($examples);
                ?>
                <tr>
                    <td><strong><?= e($rm['internal_code']) ?></strong>
                        <?php if (!empty($rm['sds_not_required'])): ?>
                            <span class="badge badge-muted" title="Marked No SDS required, but a current formula uses it, so its data still has to be reviewed for that finished good">No SDS required</span>
                        <?php endif; ?></td>
                    <td><?= e($rm['supplier'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td><?= e($rm['supplier_product_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td><strong><?= (int) $rm['unblock_count'] ?></strong></td>
                    <td>
                        <span title="<?= e(implode(', ', $all)) ?>"><?= e(implode(', ', $examples)) ?></span>
                        <?php if ($extra > 0): ?>
                            <small class="text-muted">(+<?= $extra ?> more)</small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($rm['cms_item_code'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm btn-primary">Review</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Tab: Trade Secret -->
<div class="tab-panel" id="tab-trade-secret" style="display: none;">
    <?php if (empty($tradeSecret)): ?>
        <div class="alert alert-success">
            No raw materials have trade-secret content. Every constituent has a disclosed CAS.
        </div>
    <?php else: ?>
        <p class="text-muted">
            Raw materials whose composition has trade-secret content — either the whole RM is in
            <code>hazardous_no_cas</code> mode (vendor refused all CAS disclosure) or specific
            constituents are flagged trade-secret. These are the vendors to chase: getting full
            disclosure unblocks SDS publishing under the federal hazard-data threshold rule.
            Sorted by FG impact, then trade-secret percentage.
        </p>

        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Internal Code</th>
                    <th>Supplier</th>
                    <th>Supplier Product</th>
                    <th title="Type of trade-secret content in this RM">Type</th>
                    <th title="Sum of trade-secret percentage in this RM (whole-RM = 100%)">TS %</th>
                    <th title="Number of trade-secret constituent rows">TS rows</th>
                    <th title="Distinct active finished goods whose formula tree contains this RM">FGs Using</th>
                    <th>CMS Code</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tradeSecret as $rm): ?>
                <?php
                    $type = $rm['ts_type'] ?? 'constituents';
                    $typeLabel = [
                        'whole_rm'     => '<span style="color:#dc3545; font-weight:600;" title="hazardous_no_cas — vendor refused all CAS disclosure">Whole RM</span>',
                        'constituents' => '<span style="color:#f59e0b; font-weight:600;" title="One or more constituents flagged is_trade_secret">Constituents</span>',
                        'both'         => '<span style="color:#dc3545; font-weight:600;" title="Whole-RM mode AND has constituent rows flagged trade-secret">Both</span>',
                    ][$type] ?? e($type);
                ?>
                <tr>
                    <td><strong><?= e($rm['internal_code']) ?></strong>
                        <?php if (!empty($rm['sds_not_required'])): ?>
                            <span class="badge badge-muted" title="Marked No SDS required, but a current formula uses it: its data still feeds that finished good's SDS">No SDS required</span>
                        <?php endif; ?></td>
                    <td><?= e($rm['supplier'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?= e($rm['supplier_product_name'] ?? '') ?: '<span class="text-muted">—</span>' ?>
                        <?php if (!empty($rm['supplier_product_code'])): ?>
                            <br><small class="text-muted"><?= e($rm['supplier_product_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= $typeLabel ?></td>
                    <td><strong><?= number_format((float) $rm['ts_pct'], 2) ?>%</strong></td>
                    <td><?= (int) $rm['ts_count'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td><strong><?= (int) ($rm['fg_total_count'] ?? 0) ?></strong></td>
                    <td><?= e($rm['cms_item_code'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm btn-primary">Edit RM</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Tab: In Inventory -->
<div class="tab-panel" id="tab-inventory" style="display: none;">
    <?php if (!empty($inventoryError)): ?>
        <div class="alert alert-warning"><?= e($inventoryError) ?></div>
    <?php elseif (empty($inventoryGaps)): ?>
        <div class="alert alert-success">
            Every raw material with stock on hand in CMS has constituent data and a vendor SDS uploaded.
        </div>
    <?php else: ?>
        <p class="text-muted">
            Raw materials with stock on hand in CMS that are missing constituent data
            and/or a vendor SDS upload (required to appear in the RM SDS Book).
            Sorted by quantity on hand — the biggest piles first.
            Items marked No SDS required (not chemicals) are not listed.
        </p>

        <table class="table">
            <thead>
                <tr>
                    <th>Internal Code</th>
                    <th>Supplier</th>
                    <th>Product Name</th>
                    <th>On Hand</th>
                    <th>Missing</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($inventoryGaps as $rm): ?>
                <tr>
                    <td><strong><?= e($rm['internal_code']) ?></strong></td>
                    <td><?= e($rm['supplier'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td><?= e($rm['supplier_product_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td><strong><?= number_format($rm['qty_on_hand'], 1) ?></strong> <?= e($rm['unit']) ?></td>
                    <td>
                        <?php if ($rm['missing_data']): ?>
                            <span class="badge badge-danger" title="No constituents entered">Data</span>
                        <?php endif; ?>
                        <?php if ($rm['missing_sds']): ?>
                            <span class="badge badge-warning" title="No vendor SDS PDF uploaded — cannot appear in RM SDS Book">Vendor SDS</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <a href="/raw-materials/<?= (int) $rm['id'] ?>/edit" class="btn btn-sm btn-primary">Edit RM</a>
                        <?php if ($rm['missing_data']): ?>
                            <a href="/raw-materials/<?= (int) $rm['id'] ?>/constituents" class="btn btn-sm btn-outline">Constituents</a>
                        <?php endif; ?>
                        <?php if ($canFlagNoSds): ?>
                            <?= $noSdsButton($rm, '/cms-import/incomplete#inventory') ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div style="margin-top: 20px;">
    <a href="/cms-import" class="btn btn-outline">Back to CMS Import</a>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn').forEach(function (b) {
            b.style.background = '#e9ecef';
            b.style.color = '#003366';
            b.classList.remove('active');
        });
        btn.style.background = '#003366';
        btn.style.color = '#fff';
        btn.classList.add('active');

        document.querySelectorAll('.tab-panel').forEach(function (p) {
            p.style.display = 'none';
        });
        document.getElementById('tab-' + btn.dataset.tab).style.display = 'block';
    });
});
// Open the tab named in the URL hash (e.g. #inventory after a "Not a chemical" click).
(function () {
    var want = (window.location.hash || '').replace('#', '');
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        if (want !== '' && btn.dataset.tab === want) {
            btn.click();
        }
    });
})();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
