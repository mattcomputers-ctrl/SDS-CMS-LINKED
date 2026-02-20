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
            <a href="/"><?= e(\SDS\Core\App::config('app.name', 'SDS System')) ?></a>
        </div>
        <?php if (isset($_SESSION['_user'])): ?>
        <ul class="navbar-menu">
            <li><a href="/" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/' ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="/raw-materials" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/raw-materials') ? 'active' : '' ?>">Raw Materials</a></li>
            <li><a href="/finished-goods" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/finished-goods') ? 'active' : '' ?>">Finished Goods</a></li>
            <li><a href="/lookup" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/lookup') ? 'active' : '' ?>">SDS Lookup</a></li>
            <li><a href="/sds-book" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/sds-book') ? 'active' : '' ?>">SDS Book</a></li>
            <?php if (is_admin()): ?>
            <li class="dropdown">
                <a href="/admin/users" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin') ? 'active' : '' ?>">Admin</a>
                <ul class="dropdown-menu">
                    <li><a href="/admin/users">Users</a></li>
                    <li><a href="/admin/settings">Settings</a></li>
                    <li><a href="/admin/exempt-vocs">Exempt VOC Library</a></li>
                    <li><a href="/admin/determinations">Determinations</a></li>
                    <li><a href="/admin/federal-data">Federal Data</a></li>
                    <li><a href="/admin/audit-log">Audit Log</a></li>
                    <li><a href="/admin/sds-versions">SDS Versions</a></li>
                </ul>
            </li>
            <?php endif; ?>
        </ul>
        <div class="navbar-user">
            <span><?= e($_SESSION['_user']['display_name'] ?: $_SESSION['_user']['username']) ?></span>
            <span class="badge badge-<?= $_SESSION['_user']['role'] ?>"><?= e($_SESSION['_user']['role']) ?></span>
            <a href="/logout" class="btn btn-sm btn-outline">Logout</a>
        </div>
        <?php endif; ?>
    </nav>

    <main class="container">
        <?= flash_messages() ?>
        <?php if (isset($pageTitle)): ?>
            <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <?php endif; ?>
