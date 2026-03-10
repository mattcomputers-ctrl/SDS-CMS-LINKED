<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'SDS System') ?> — <?= e(\SDS\Core\App::config('app.name', 'SDS System')) ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="<?= is_sds_book_only() ? '/sds-book' : '/' ?>"><?= e(\SDS\Core\App::config('app.name', 'SDS System')) ?></a>
        </div>
        <?php if (isset($_SESSION['_user'])): ?>
        <button class="navbar-toggle" id="navToggle" aria-label="Toggle navigation">
            <span></span><span></span><span></span>
        </button>
        <div class="navbar-collapse" id="navCollapse">
            <ul class="navbar-menu">
                <?php if (is_sds_book_only()): ?>
                    <li><a href="/sds-book" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/sds-book') ? 'active' : '' ?>">Raw Material SDS Book</a></li>
                <?php else: ?>
                    <?php if (can_read('dashboard')): ?>
                    <li><a href="/" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/' ? 'active' : '' ?>">Dashboard</a></li>
                    <?php endif; ?>
                    <?php if (can_read('raw_materials')): ?>
                    <li><a href="/raw-materials" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/raw-materials') ? 'active' : '' ?>">Raw Materials</a></li>
                    <?php endif; ?>
                    <?php if (can_read('finished_goods')): ?>
                    <li><a href="/finished-goods" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/finished-goods') ? 'active' : '' ?>">Finished Goods</a></li>
                    <?php endif; ?>
                    <?php if (can_read('fg_sds_lookup')): ?>
                    <li><a href="/lookup" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/lookup') ? 'active' : '' ?>">FG SDS Lookup</a></li>
                    <?php endif; ?>
                    <?php if (can_read('rm_sds_book')): ?>
                    <li><a href="/sds-book" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/sds-book') ? 'active' : '' ?>">RM SDS Book</a></li>
                    <?php endif; ?>
                    <?php if (can_read('reports')): ?>
                    <li><a href="/reports" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/reports') ? 'active' : '' ?>">Reports</a></li>
                    <?php endif; ?>
                    <?php if (can_edit('rm_mass_replace')): ?>
                    <li><a href="/formulas/mass-replace" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/formulas/mass-replace') ? 'active' : '' ?>">Mass Replacement</a></li>
                    <?php endif; ?>
                    <?php if (can_read('cas_determinations')): ?>
                    <li><a href="/determinations" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/determinations') ? 'active' : '' ?>">CAS Determinations</a></li>
                    <?php endif; ?>
                    <?php if (can_read('exempt_vocs')): ?>
                    <li><a href="/exempt-vocs" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/exempt-vocs') ? 'active' : '' ?>">Exempt VOC Library</a></li>
                    <?php endif; ?>
                    <?php if (can_read('bulk_publish')): ?>
                    <li><a href="/bulk-publish" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/bulk-publish') ? 'active' : '' ?>">Bulk SDS Publish</a></li>
                    <?php endif; ?>
                    <?php if (can_read('bulk_export')): ?>
                    <li><a href="/bulk-export" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/bulk-export') ? 'active' : '' ?>">Bulk SDS Export</a></li>
                    <?php endif; ?>
                    <?php if (can_manage_users()): ?>
                    <li class="dropdown">
                        <a href="/admin/users" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin') ? 'active' : '' ?>">Admin</a>
                        <ul class="dropdown-menu">
                            <li><a href="/admin/users">Users</a></li>
                            <li><a href="/admin/groups">Permission Groups</a></li>
                            <li><a href="/admin/settings">Settings</a></li>
                            <li><a href="/admin/pictograms">Pictograms</a></li>
                            <li><a href="/admin/federal-data">Federal Data</a></li>
                            <li><a href="/admin/audit-log">Audit Log</a></li>
                            <li><a href="/admin/sds-versions">SDS Versions</a></li>
                            <li><a href="/admin/backups">Backup &amp; Restore</a></li>
                            <li><a href="/admin/storage">Storage</a></li>
                            <li><a href="/admin/network-settings">Network Settings</a></li>
                            <li><a href="/admin/training-data">Training Data</a></li>
                            <li><a href="/admin/purge-data" style="color: #dc3545;">Purge Data</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
            <div class="navbar-user">
                <span class="navbar-user-name"><?= e($_SESSION['_user']['display_name'] ?: $_SESSION['_user']['username']) ?></span>
                <?php
                $navUserGroups = \SDS\Services\PermissionService::getUserGroups((int) $_SESSION['_user']['id']);
                if (!empty($navUserGroups)):
                    $navGroup = $navUserGroups[0];
                ?>
                <span class="badge badge-<?= (int) $navGroup['is_admin'] ? 'admin' : 'editor' ?>"><?= e($navGroup['name']) ?></span>
                <?php endif; ?>
                <a href="/logout" class="btn btn-sm btn-logout">Logout</a>
            </div>
        </div>
        <?php endif; ?>
    </nav>

    <main class="container">
        <?= flash_messages() ?>
        <?php if (isset($pageTitle)): ?>
            <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <?php endif; ?>
