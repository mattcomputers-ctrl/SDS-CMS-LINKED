<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'SDS System') ?> — <?= e(\SDS\Core\App::config('app.name', 'SDS System')) ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="<?= is_sds_book_only() ? '/sds-book' : '/' ?>" class="sidebar-brand"><?= e(\SDS\Core\App::config('app.name', 'SDS System')) ?></a>
        </div>

        <?php if (isset($_SESSION['_user'])): ?>
        <nav class="sidebar-nav">
            <ul class="sidebar-menu">
                <?php if (is_sds_book_only()): ?>
                    <li><a href="/sds-book" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/sds-book') ? 'active' : '' ?>"><span class="menu-icon">&#128214;</span> Raw Material SDS Book</a></li>
                <?php else: ?>
                    <?php if (can_read('dashboard')): ?>
                    <li><a href="/" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/' ? 'active' : '' ?>"><span class="menu-icon">&#9632;</span> Dashboard</a></li>
                    <?php endif; ?>

                    <li class="sidebar-section-label">Materials</li>
                    <?php if (can_read('raw_materials')): ?>
                    <li><a href="/raw-materials" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/raw-materials') ? 'active' : '' ?>"><span class="menu-icon">&#9830;</span> Raw Materials</a></li>
                    <?php endif; ?>
                    <?php if (can_read('finished_goods')): ?>
                    <li><a href="/finished-goods" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/finished-goods') ? 'active' : '' ?>"><span class="menu-icon">&#9733;</span> Finished Goods</a></li>
                    <?php endif; ?>

                    <li class="sidebar-section-label">SDS</li>
                    <?php if (can_read('fg_sds_lookup')): ?>
                    <li><a href="/lookup" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/lookup') ? 'active' : '' ?>"><span class="menu-icon">&#128269;</span> FG SDS Lookup</a></li>
                    <?php endif; ?>
                    <?php if (can_read('rm_sds_book')): ?>
                    <li><a href="/sds-book" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/sds-book') ? 'active' : '' ?>"><span class="menu-icon">&#128214;</span> RM SDS Book</a></li>
                    <?php endif; ?>
                    <?php if (can_read('bulk_publish')): ?>
                    <li><a href="/bulk-publish" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/bulk-publish') ? 'active' : '' ?>"><span class="menu-icon">&#128196;</span> Bulk SDS Publish</a></li>
                    <?php endif; ?>
                    <?php if (can_read('bulk_export')): ?>
                    <li><a href="/bulk-export" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/bulk-export') ? 'active' : '' ?>"><span class="menu-icon">&#128230;</span> Bulk SDS Export</a></li>
                    <?php endif; ?>

                    <li class="sidebar-section-label">Tools</li>
                    <?php if (can_read('aliases')): ?>
                    <li><a href="/aliases" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/aliases') ? 'active' : '' ?>"><span class="menu-icon">&#128279;</span> Aliases</a></li>
                    <?php endif; ?>
                    <?php if (can_read('reports')): ?>
                    <li><a href="/reports" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/reports') ? 'active' : '' ?>"><span class="menu-icon">&#128202;</span> Reports</a></li>
                    <?php endif; ?>
                    <?php if (can_edit('rm_mass_replace')): ?>
                    <li><a href="/formulas/mass-replace" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/formulas/mass-replace') ? 'active' : '' ?>"><span class="menu-icon">&#128260;</span> Mass Replacement</a></li>
                    <?php endif; ?>

                    <li class="sidebar-section-label">Data</li>
                    <?php if (can_read('cas_determinations')): ?>
                    <li><a href="/determinations" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/determinations') ? 'active' : '' ?>"><span class="menu-icon">&#128300;</span> CAS Determinations</a></li>
                    <?php endif; ?>
                    <?php if (can_read('exempt_vocs')): ?>
                    <li><a href="/exempt-vocs" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/exempt-vocs') ? 'active' : '' ?>"><span class="menu-icon">&#127811;</span> Exempt VOC Library</a></li>
                    <?php endif; ?>

                    <?php if (can_manage_users()): ?>
                    <li class="sidebar-section-label">Admin</li>
                    <li><a href="/admin/users" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/users' ? 'active' : '' ?>"><span class="menu-icon">&#128101;</span> Users</a></li>
                    <li><a href="/admin/groups" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin/groups') ? 'active' : '' ?>"><span class="menu-icon">&#128274;</span> Permission Groups</a></li>
                    <li><a href="/admin/settings" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/settings' ? 'active' : '' ?>"><span class="menu-icon">&#9881;</span> Settings</a></li>
                    <li><a href="/admin/pictograms" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/pictograms' ? 'active' : '' ?>"><span class="menu-icon">&#9888;</span> Pictograms</a></li>
                    <li><a href="/admin/snur-list" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin/snur-list') ? 'active' : '' ?>"><span class="menu-icon">&#128220;</span> SNUR List</a></li>
                    <li><a href="/admin/federal-data" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin/federal-data') ? 'active' : '' ?>"><span class="menu-icon">&#127963;</span> Federal Data</a></li>
                    <li><a href="/admin/audit-log" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/audit-log' ? 'active' : '' ?>"><span class="menu-icon">&#128203;</span> Audit Log</a></li>
                    <li><a href="/admin/sds-versions" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin/sds-versions') ? 'active' : '' ?>"><span class="menu-icon">&#128195;</span> SDS Versions</a></li>
                    <li><a href="/admin/backups" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/backups' ? 'active' : '' ?>"><span class="menu-icon">&#128190;</span> Backup &amp; Restore</a></li>
                    <li><a href="/admin/storage" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/storage' ? 'active' : '' ?>"><span class="menu-icon">&#128451;</span> Storage</a></li>
                    <li><a href="/admin/network-settings" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/network-settings' ? 'active' : '' ?>"><span class="menu-icon">&#127760;</span> Network Settings</a></li>
                    <li><a href="/admin/training-data" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/training-data' ? 'active' : '' ?>"><span class="menu-icon">&#128218;</span> Training Data</a></li>
                    <li><a href="/admin/purge-data" class="sidebar-link-danger <?= ($_SERVER['REQUEST_URI'] ?? '') === '/admin/purge-data' ? 'active' : '' ?>"><span class="menu-icon">&#128465;</span> Purge Data</a></li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-info">
                    <span class="sidebar-user-name"><?= e($_SESSION['_user']['display_name'] ?: $_SESSION['_user']['username']) ?></span>
                    <?php
                    $navUserGroups = \SDS\Services\PermissionService::getUserGroups((int) $_SESSION['_user']['id']);
                    if (!empty($navUserGroups)):
                        $navGroup = $navUserGroups[0];
                    ?>
                    <span class="badge badge-<?= (int) $navGroup['is_admin'] ? 'admin' : 'editor' ?>"><?= e($navGroup['name']) ?></span>
                    <?php endif; ?>
                </div>
                <a href="/logout" class="btn btn-sm btn-logout">Logout</a>
            </div>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content area -->
    <div class="main-wrapper" id="mainWrapper">
        <!-- Top bar (mobile) -->
        <header class="topbar" id="topbar">
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                <span></span><span></span><span></span>
            </button>
            <span class="topbar-title"><?= e($pageTitle ?? \SDS\Core\App::config('app.name', 'SDS System')) ?></span>
        </header>

        <main class="content">
            <?= flash_messages() ?>
            <?php if (isset($pageTitle)): ?>
                <h1 class="page-title"><?= e($pageTitle) ?></h1>
            <?php endif; ?>
