<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'RM SDS Book') ?> — <?= e(\SDS\Core\App::config('app.name', 'SDS System')) ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <div class="container" style="max-width: 900px; margin: 2rem auto; padding: 0 1rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h1><?= e($pageTitle ?? 'RM SDS Book') ?></h1>
            <a href="/login" class="btn btn-outline">Sign In</a>
        </div>

        <div class="toolbar">
            <form method="GET" action="/rm-sds-book" class="search-form" style="flex: 1; display: flex; gap: 0.5rem; align-items: center;">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by RM code, supplier, or product name..." style="flex: 1;">
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($search): ?>
                    <a href="/rm-sds-book" class="btn btn-outline">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <p class="text-muted"><?= $total ?> raw material SDS document(s) found.</p>

        <?php if (empty($results)): ?>
            <div class="card" style="text-align: center; padding: 2rem;">
                <p class="text-muted">No supplier SDS documents found<?= $search ? ' for "' . e($search) . '"' : '' ?>.</p>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>RM Code</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><strong><?= e($r['product_name']) ?></strong></td>
                        <td>
                            <a href="<?= e($r['view_url']) ?>" target="_blank" class="btn btn-sm btn-primary">View SDS</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <?php
                            $params = ['q' => $search, 'page' => $i];
                            $url = '/rm-sds-book?' . http_build_query($params);
                        ?>
                        <?php if ($i === $page): ?>
                            <span class="pagination-current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= e($url) ?>" class="btn btn-sm btn-outline"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
