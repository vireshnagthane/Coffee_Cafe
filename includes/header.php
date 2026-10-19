<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Dashboard';
$currentUser = $_SESSION['user'] ?? null;
$flashMessage = take_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/style.css?v=<?= e(APP_ASSET_VERSION) ?>">
    <script src="<?= e(APP_BASE_URL) ?>/assets/js/app.js?v=<?= e(APP_ASSET_VERSION) ?>" defer></script>
</head>
<body class="app-shell">
<aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(APP_BASE_URL) ?>/dashboard.php">
        <span class="brand-mark" aria-hidden="true">B</span>
        <span><strong>Brew &amp; Bean</strong><small>COFFEE MANAGEMENT</small></span>
    </a>
    <nav class="side-nav" aria-label="Main navigation">
        <span class="nav-label">WORKSPACE</span>
        <a class="nav-link <?= $pageTitle === 'Overview' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/dashboard.php">
            <span aria-hidden="true">▦</span> Overview
        </a>
        <span class="nav-label nav-label-spaced">MANAGEMENT</span>
        <a class="nav-link <?= $pageTitle === 'Products' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/products.php"><span aria-hidden="true">◈</span> Products</a>
        <a class="nav-link <?= $pageTitle === 'Categories' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/categories.php"><span aria-hidden="true">▤</span> Categories</a>
        <a class="nav-link <?= $pageTitle === 'Point of sale' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/pos.php"><span aria-hidden="true">▣</span> Point of sale</a>
        <a class="nav-link <?= $pageTitle === 'Orders' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/orders.php"><span aria-hidden="true">◷</span> Orders</a>
        <a class="nav-link <?= $pageTitle === 'Inventory' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/inventory.php"><span aria-hidden="true">▦</span> Inventory</a>
        <a class="nav-link <?= $pageTitle === 'Purchases' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/purchases.php"><span aria-hidden="true">↗</span> Purchases</a>
        <a class="nav-link <?= $pageTitle === 'Suppliers' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/suppliers.php"><span aria-hidden="true">♧</span> Suppliers</a>
        <a class="nav-link <?= $pageTitle === 'Customers' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/customers.php"><span aria-hidden="true">♙</span> Customers</a>
        <?php if (($currentUser['role'] ?? '') === 'administrator'): ?>
            <a class="nav-link <?= $pageTitle === 'Expenses' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/expenses.php"><span aria-hidden="true">₹</span> Expenses</a>
            <a class="nav-link <?= $pageTitle === 'Reports' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/reports.php"><span aria-hidden="true">▧</span> Reports</a>
            <a class="nav-link <?= $pageTitle === 'Settings' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/settings.php"><span aria-hidden="true">⚙</span> Settings</a>
            <a class="nav-link <?= $pageTitle === 'User accounts' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/users.php"><span aria-hidden="true">♙</span> Team accounts</a>
            <a class="nav-link <?= $pageTitle === 'Database' ? 'active' : '' ?>" href="<?= e(APP_BASE_URL) ?>/database.php"><span aria-hidden="true">▤</span> Database</a>
        <?php endif; ?>
        <div class="nav-coming">Your cafe workspace, available on this computer.</div>
    </nav>
    <div class="sidebar-footer">
        <span class="avatar"><?= e(strtoupper(substr((string) ($currentUser['full_name'] ?? 'U'), 0, 1))) ?></span>
        <span class="user-label"><strong><?= e($currentUser['full_name'] ?? 'User') ?></strong><small><?= e(ucfirst($currentUser['role'] ?? '')) ?></small></span>
    </div>
</aside>
<div class="main-column">
    <header class="topbar">
        <button class="menu-toggle" type="button" data-sidebar-toggle aria-label="Toggle navigation">☰</button>
        <div>
            <span class="eyebrow">COFFEE SHOP OPERATIONS</span>
            <h1><?= e($pageTitle) ?></h1>
        </div>
        <div class="topbar-actions">
            <span class="topbar-right"><span class="status-dot"></span> Local workspace</span>
            <form action="<?= e(APP_BASE_URL) ?>/logout.php" method="post">
                <?= csrf_field() ?>
                <button class="button button-light logout-button" type="submit"><span aria-hidden="true">↪</span> Sign out</button>
            </form>
        </div>
    </header>
    <main class="page-content">
        <?php if ($flashMessage !== null): ?>
            <div class="alert alert-<?= e($flashMessage['type'] ?? 'info') ?>" role="status">
                <?= e($flashMessage['message'] ?? '') ?>
            </div>
        <?php endif; ?>
