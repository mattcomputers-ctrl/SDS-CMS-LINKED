<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/sds/<?= (int) $finishedGood['id'] ?>">&larr; Back to SDS Versions</a></p>

<div class="sds-preview">
    <div class="sds-header">
        <?php if (!empty($sds['meta']['company_logo_path'])): ?>
            <img src="<?= e($sds['meta']['company_logo_path']) ?>" alt="Company Logo" style="max-height: 60px; max-width: 250px; margin-bottom: 0.5rem;">
        <?php endif; ?>
        <h2>SAFETY DATA SHEET</h2>
        <p class="text-muted">Preview — <?= e(strtoupper($language)) ?> — Generated <?= date('m/d/Y H:i') ?></p>
    </div>

    <?php foreach ($sds['sections'] as $num => $section): ?>
    <div class="sds-section" id="section-<?= $num ?>">
        <h3 class="sds-section-title">SECTION <?= $num ?>: <?= e(strtoupper($section['title'] ?? '')) ?></h3>

        <?php if ($num === 2): // Hazard Identification ?>
            <?php if (!empty($section['signal_word'])): ?>
                <p class="signal-word signal-<?= strtolower($section['signal_word']) ?>"><?= e($section['signal_word']) ?></p>
            <?php endif; ?>
            <?php if (!empty($section['pictograms'])): ?>
                <p><strong>Pictograms:</strong> <?= e(implode(', ', $section['pictograms'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($section['h_statements'])): ?>
                <p><strong>Hazard Statements:</strong></p>
                <ul>
                <?php foreach ($section['h_statements'] as $s): ?>
                    <li><?= e($s['code']) ?>: <?= e($s['text']) ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($section['p_statements'])): ?>
                <p><strong>Precautionary Statements:</strong></p>
                <ul>
                <?php foreach ($section['p_statements'] as $s): ?>
                    <li><?= e($s['code']) ?>: <?= e($s['text']) ?></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        <?php elseif ($num === 3): // Composition ?>
            <p><strong>Type:</strong> <?= e($section['substance_or_mixture'] ?? 'Mixture') ?></p>
            <?php if (!empty($section['components'])): ?>
            <table class="table table-sm">
                <thead><tr><th>CAS</th><th>Chemical Name</th><th>Concentration</th></tr></thead>
                <tbody>
                <?php foreach ($section['components'] as $c): ?>
                    <tr>
                        <td><?= e($c['cas_number']) ?></td>
                        <td><?= e($c['chemical_name']) ?></td>
                        <td><?= e($c['concentration_range'] ?? number_format((float) $c['concentration_pct'], 2) . '%') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

        <?php elseif ($num === 8 && !empty($section['exposure_limits'])): ?>
            <table class="table table-sm">
                <thead><tr><th>CAS</th><th>Chemical</th><th>Type</th><th>Value</th><th>Units</th></tr></thead>
                <tbody>
                <?php foreach ($section['exposure_limits'] as $el): ?>
                    <tr>
                        <td><?= e($el['cas_number']) ?></td>
                        <td><?= e($el['chemical_name']) ?></td>
                        <td><?= e($el['limit_type']) ?></td>
                        <td><?= e($el['value']) ?></td>
                        <td><?= e($el['units']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php foreach ($section as $key => $val): ?>
                <?php if (!is_string($val) || $key === 'title' || $val === '') continue; ?>
                <p><strong><?= e(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= e($val) ?></p>
            <?php endforeach; ?>

        <?php else: ?>
            <?php foreach ($section as $key => $val): ?>
                <?php if ($key === 'title') continue; ?>
                <?php if (is_string($val) && $val !== ''): ?>
                    <p><strong><?= e(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= e($val) ?></p>
                <?php elseif (is_numeric($val)): ?>
                    <p><strong><?= e(ucwords(str_replace('_', ' ', $key))) ?>:</strong> <?= $val ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($sds['legal_disclaimer'])): ?>
    <div class="sds-section" id="section-disclaimer">
        <h3 class="sds-section-title">DISCLAIMER</h3>
        <p style="white-space: pre-wrap;"><?= e($sds['legal_disclaimer']) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($sds['warnings'])): ?>
    <div class="alert alert-warning">
        <strong>Warnings:</strong>
        <ul>
        <?php foreach ($sds['warnings'] as $w): ?>
            <li><?= e($w) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
