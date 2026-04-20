<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

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
</div>

<style>
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
