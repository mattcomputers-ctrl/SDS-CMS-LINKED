<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/formulas/<?= (int) $finishedGood['id'] ?>">&larr; Back to Formula</a></p>

<?php $voc = $result['voc']; ?>

<div class="grid-2col">
    <div class="card">
        <h2 class="card-title">VOC Results (EPA Method 24)</h2>
        <table class="table table-sm table-props">
            <tr><td>VOC (wt%)</td><td><strong><?= number_format((float) $voc['total_voc_wt_pct'], 2) ?>%</strong></td></tr>
            <tr><td>Exempt VOC (wt%)</td><td><?= number_format((float) $voc['total_exempt_voc_wt_pct'], 2) ?>%</td></tr>
            <tr><td>Water (wt%)</td><td><?= number_format((float) $voc['total_water_wt_pct'], 2) ?>%</td></tr>
            <tr><td>Mixture SG</td><td><?= number_format((float) $voc['mixture_sg'], 4) ?></td></tr>
            <tr><td>VOC (lb/gal) per EPA Method 24</td><td><strong><?= number_format((float) $voc['voc_lb_per_gal'], 2) ?></strong></td></tr>
            <tr><td>VOC less W&E (lb/gal)</td><td><strong><?= number_format((float) $voc['voc_lb_per_gal_less_water_exempt'], 2) ?></strong></td></tr>
            <tr><td>Solids (wt%)</td><td><?= number_format((float) $voc['solids_wt_pct'], 1) ?>%</td></tr>
            <tr><td>Solids (vol%)</td><td><?= $voc['solids_vol_pct'] !== null ? number_format((float) $voc['solids_vol_pct'], 1) . '%' : 'N/A' ?></td></tr>
        </table>
    </div>

    <div class="card">
        <h2 class="card-title">Warnings & Assumptions</h2>
        <?php if (!empty($result['warnings'])): ?>
            <div class="alert alert-warning">
                <ul>
                <?php foreach ($result['warnings'] as $w): ?>
                    <li><?= e($w) ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($voc['assumptions'])): ?>
            <h3>Assumptions Made</h3>
            <ul class="assumption-list">
            <?php foreach ($voc['assumptions'] as $a): ?>
                <li><?= e($a['message']) ?></li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-muted">No assumptions needed.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Expanded Composition (CAS Level)</h2>
    <table class="table table-sm">
        <thead>
            <tr><th>CAS</th><th>Chemical Name</th><th>Concentration</th></tr>
        </thead>
        <tbody>
        <?php foreach ($result['composition'] as $c): ?>
            <tr>
                <td><?= e($c['cas_number']) ?></td>
                <td><?= e($c['chemical_name']) ?></td>
                <td><?= number_format((float) $c['concentration_pct'], 4) ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
