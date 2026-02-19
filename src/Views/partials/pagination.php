<?php if (isset($pages) && $pages > 1): ?>
<nav class="pagination">
    <?php
    $currentPage = (int) ($filters['page'] ?? 1);
    $baseUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $queryParams = $_GET;
    ?>

    <?php if ($currentPage > 1): ?>
        <?php $queryParams['page'] = $currentPage - 1; ?>
        <a href="<?= $baseUrl ?>?<?= http_build_query($queryParams) ?>" class="page-link">&laquo; Prev</a>
    <?php endif; ?>

    <?php
    $start = max(1, $currentPage - 2);
    $end   = min($pages, $currentPage + 2);
    ?>

    <?php if ($start > 1): ?>
        <?php $queryParams['page'] = 1; ?>
        <a href="<?= $baseUrl ?>?<?= http_build_query($queryParams) ?>" class="page-link">1</a>
        <?php if ($start > 2): ?><span class="page-ellipsis">...</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($p = $start; $p <= $end; $p++): ?>
        <?php $queryParams['page'] = $p; ?>
        <a href="<?= $baseUrl ?>?<?= http_build_query($queryParams) ?>"
           class="page-link <?= $p === $currentPage ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>

    <?php if ($end < $pages): ?>
        <?php if ($end < $pages - 1): ?><span class="page-ellipsis">...</span><?php endif; ?>
        <?php $queryParams['page'] = $pages; ?>
        <a href="<?= $baseUrl ?>?<?= http_build_query($queryParams) ?>" class="page-link"><?= $pages ?></a>
    <?php endif; ?>

    <?php if ($currentPage < $pages): ?>
        <?php $queryParams['page'] = $currentPage + 1; ?>
        <a href="<?= $baseUrl ?>?<?= http_build_query($queryParams) ?>" class="page-link">Next &raquo;</a>
    <?php endif; ?>
</nav>
<?php endif; ?>
