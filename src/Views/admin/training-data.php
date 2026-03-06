<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <h2>Generate Training Data</h2>
    <p class="text-muted">
        Populate the system with realistic sample data for training purposes.
        This creates approximately <strong>100 raw materials</strong> with CAS constituents
        and <strong>500 finished goods</strong> with formulas across all ink families
        (UV Offset, UV Flexo, Solvent, Aqueous, EB Cure).
    </p>

    <?php if ($rawMaterialCount > 0 || $finishedGoodCount > 0): ?>
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 1rem; margin-bottom: 1.25rem;">
            <strong>Data already exists:</strong> <?= $rawMaterialCount ?> raw material(s) and <?= $finishedGoodCount ?> finished good(s) are currently in the system.
            Training data can only be generated into an empty system. Use
            <a href="/admin/purge-data"><strong>Purge Data</strong></a> first if you want to start fresh.
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-primary" disabled>Generate Training Data</button>
        </div>
    <?php else: ?>
        <div style="background: #d1ecf1; border: 1px solid #0dcaf0; border-radius: 4px; padding: 1rem; margin-bottom: 1.25rem;">
            The system is empty and ready for training data. Click the button below to populate it.
            All generated data can be removed later using <a href="/admin/purge-data"><strong>Purge Data</strong></a>.
        </div>

        <form method="POST" action="/admin/training-data/generate" id="generate-form">
            <?= csrf_field() ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="generate-btn">Generate Training Data</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card" style="margin-top: 1.5rem;">
    <h2>Download Report Training CSVs</h2>
    <p class="text-muted">
        These CSV files are pre-built to work with the <a href="/reports">HAP / VOC Reporting</a> upload workflow.
        Upload the alias file as "Item Names" and the shipping file as "Shipping Detail" on the Reports page.
    </p>

    <?php if ($finishedGoodCount === 0): ?>
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 1rem; margin-bottom: 1rem;">
            Generate training data first before downloading CSVs. The CSVs are built from the finished goods currently in the system.
        </div>
    <?php endif; ?>

    <table class="table" style="max-width: 700px;">
        <thead>
            <tr>
                <th>File</th>
                <th>Description</th>
                <th style="width: 140px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Item Name Aliases</strong></td>
                <td>
                    Maps item names (product codes with pack extensions like <code>UVO0001-50</code>)
                    to descriptions. Upload this as the "Item Names CSV" on the Reports page.
                </td>
                <td>
                    <?php if ($finishedGoodCount > 0): ?>
                        <a href="/admin/training-data/download/alias" class="btn btn-sm btn-outline">Download CSV</a>
                    <?php else: ?>
                        <button class="btn btn-sm btn-outline" disabled>Download CSV</button>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>Shipping Detail</strong></td>
                <td>
                    Contains ~1,500 shipping records across 15 customers over the past 12 months.
                    Upload this as the "Shipping Detail CSV" on the Reports page.
                </td>
                <td>
                    <?php if ($finishedGoodCount > 0): ?>
                        <a href="/admin/training-data/download/shipping" class="btn btn-sm btn-outline">Download CSV</a>
                    <?php else: ?>
                        <button class="btn btn-sm btn-outline" disabled>Download CSV</button>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('generate-form');
    if (form) {
        form.addEventListener('submit', function () {
            var btn = document.getElementById('generate-btn');
            btn.disabled = true;
            btn.textContent = 'Generating... please wait';
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
