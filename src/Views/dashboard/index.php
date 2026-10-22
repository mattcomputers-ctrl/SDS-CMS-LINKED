<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php if (!empty($reviewCounts['unavailable'])): ?>
<p class="stats-asof text-muted">The CAS Determinations counts could not be loaded just now (details in the error log). <a href="/?refresh=1">Try again</a></p>
<?php elseif (!empty($reviewCounts)): // CAS Determinations access only (DashboardController) ?>
<div class="dashboard-stats">
    <?php foreach ([
        ['needs', '/determinations',          'CAS determinations pending',  'Constituent CAS numbers without a determination'],
        ['tsca',  '/determinations?tab=tsca', 'TSCA review items',           'On sheets Bulk SDS Publish is cleared to publish'],
        ['stot',  '/determinations?tab=stot', 'STOT target organs needed',   'Substances classified H370-H373 without organs'],
    ] as [$key, $href, $label, $detail]): ?>
        <?php $n = (int) ($reviewCounts[$key] ?? 0); ?>
        <a class="stat-tile<?= $n === 0 ? ' stat-clear' : '' ?>" href="<?= e($href) ?>">
            <span class="stat-number"><?= number_format($n) ?></span>
            <span class="stat-label"><?= e($label) ?></span>
            <span class="stat-detail"><?= e($detail) ?></span>
        </a>
    <?php endforeach; ?>
</div>
<p class="stats-asof text-muted">
    Counts as of <?= e(\SDS\Services\PublishClock::display($reviewCounts['at'], 'g:i a')) ?> · <a href="/?refresh=1">Refresh</a>
</p>
<?php endif; ?>

<div class="quick-find-grid">
    <div class="card quick-find-card">
        <h2 class="card-title">Find a Finished Good SDS</h2>
        <p class="text-muted">Search by product code, description, or customer alias.</p>
        <form method="GET" action="/lookup" class="quick-find-form">
            <input type="text" name="q" placeholder="Product code or description…" autofocus
                   autocomplete="off" spellcheck="false">
            <button type="submit" class="btn btn-primary">Find SDS</button>
        </form>
        <p class="text-muted" style="margin-top: 0.75rem;">
            <a href="/lookup">Browse all published SDSs</a>
        </p>
    </div>

    <div class="card quick-find-card">
        <h2 class="card-title">Find a Raw Material SDS</h2>
        <p class="text-muted">Search by internal code, supplier, or product name.</p>
        <form method="GET" action="/raw-materials" class="quick-find-form">
            <input type="text" name="search" placeholder="Internal code, supplier, or name…"
                   autocomplete="off" spellcheck="false">
            <button type="submit" class="btn btn-primary">Find RM</button>
        </form>
        <p class="text-muted" style="margin-top: 0.75rem;">
            <a href="/raw-materials">Browse all raw materials</a>
        </p>
    </div>

    <?php if (can_read('sds_review')): ?>
    <div class="card quick-find-card">
        <h2 class="card-title">SDS Creation Readiness Check</h2>
        <p class="text-muted">Which raw materials still need info?</p>
        <form method="GET" action="/sds-review" class="quick-find-form">
            <input type="text" name="q" placeholder="Product code, alias, or description…"
                   autocomplete="off" spellcheck="false">
            <button type="submit" class="btn btn-primary">Check Readiness</button>
        </form>
        <p class="text-muted" style="margin-top: 0.75rem;">
            <a href="/sds-review">Open readiness check</a>
        </p>
    </div>
    <?php endif; ?>
</div>

<style>
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
        gap: 1rem;
        max-width: 56rem;
        margin: 2rem auto 0;
    }
    .stat-tile {
        display: flex;
        flex-direction: column;
        align-items: center;
        background: var(--card-bg, #fff);
        border: 1px solid var(--border-color, #dee2e6);
        border-radius: 0.5rem;
        padding: 1.25rem 1.5rem;
        text-align: center;
        color: inherit;
        text-decoration: none;
    }
    .stat-tile:hover {
        border-color: var(--primary, #0d6efd);
    }
    .stat-clear .stat-number {
        color: #28a745;
    }
    .stats-asof {
        max-width: 56rem;
        margin: 0.5rem auto 0;
        text-align: right;
        font-size: 0.85rem;
    }
    .stat-number {
        font-size: 2.5rem;
        font-weight: 700;
        line-height: 1.1;
        color: var(--primary, #0d6efd);
    }
    .stat-label {
        font-size: 1.1rem;
        font-weight: 500;
        margin-top: 0.25rem;
    }
    .stat-detail {
        font-size: 0.85rem;
        color: var(--text-muted, #6c757d);
        margin-top: 0.25rem;
    }
    .quick-find-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));
        gap: 1.5rem;
        max-width: 56rem;
        margin: 2rem auto;
    }
    .quick-find-card {
        padding: 1.5rem;
    }
    .quick-find-card .card-title {
        margin-top: 0;
    }
    .quick-find-form {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }
    .quick-find-form input[type="text"] {
        flex: 1 1 auto;
        font-size: 1rem;
        padding: 0.5rem 0.75rem;
    }
    .quick-find-form button {
        flex: 0 0 auto;
    }
</style>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
