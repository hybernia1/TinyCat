<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!isset($content)) {
    http_response_code(404);
    return;
}

$appName = site_name();
$siteLogo = site_logo();
$siteFavicon = site_favicon();
$siteLogoUrl = $siteLogo === null ? '' : (string) ($siteLogo['url'] ?? '');
$siteFaviconUrl = $siteFavicon === null ? '' : (string) ($siteFavicon['url'] ?? '');
$siteFooterHtml = site_footer_html();
$title = (string) ($title ?? $appName);
$current = route_path((string) ($current ?? route_path()));
$bodyClass = (string) ($bodyClass ?? '');
$csrfToken = (string) ($csrfToken ?? csrf_token());
$styles = $styles ?? ['css/tinycat.css'];
$scripts = $scripts ?? ['js/tinycat.js'];
$actions = (string) ($actions ?? '');
$flashToasts = [];

foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $flashKey => $flashType) {
    $flashMessage = flash($flashKey);

    if ($flashMessage !== null && $flashMessage !== '') {
        $flashToasts[] = [
            'message' => (string) $flashMessage,
            'type' => $flashType,
        ];
    }
}

$defaultAdminNav = [
    ['href' => '/admin', 'icon' => 'dashboard', 'label' => t('common.dashboard')],
    ['href' => '/admin/content', 'icon' => 'file', 'label' => t('content.list_title')],
    ['href' => '/admin/menu', 'icon' => 'menu', 'label' => t('menu.list_title')],
    ['href' => '/admin/media', 'icon' => 'image', 'label' => t('media.list_title')],
    ['href' => '/admin/terms', 'icon' => 'folder', 'label' => t('terms.list_title')],
    ['href' => '/admin/users', 'icon' => 'users', 'label' => t('users.list_title')],
    ['href' => '/admin/settings', 'icon' => 'settings', 'label' => t('settings.title')],
];
$authUser = auth();
$isAdminShell = $authUser !== null && (bool) ($adminShell ?? str_starts_with($current, '/admin'));
$nav = $isAdminShell ? (array) ($nav ?? $defaultAdminNav) : (array) ($frontendNav ?? frontend_menu_items());

if (!$isAdminShell && $nav === []) {
    $nav = [
        ['href' => '/', 'icon' => 'home', 'label' => t('common.home')],
    ];
}

$pageTitle = $title === $appName ? $title : $title . ' | ' . $appName;
$bodyClasses = trim($bodyClass . ($isAdminShell ? ' admin-shell-page' : ''));
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($csrfToken !== ''): ?>
        <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <?php endif; ?>
    <title><?= e($pageTitle) ?></title>
    <?php if ($siteFaviconUrl !== ''): ?>
        <link rel="icon" href="<?= e($siteFaviconUrl) ?>">
    <?php endif; ?>
    <?php foreach ((array) $styles as $style): ?>
        <link rel="stylesheet" href="<?= e(asset((string) $style)) ?>">
    <?php endforeach; ?>
    <?php foreach ((array) $scripts as $script): ?>
        <script src="<?= e(asset((string) $script)) ?>" defer></script>
    <?php endforeach; ?>
    <?php if ($flashToasts !== []): ?>
        <script type="application/json" data-tinycat-flashes><?= json_encode($flashToasts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
</head>
<body<?= $bodyClasses !== '' ? ' class="' . e($bodyClasses) . '"' : '' ?>>
    <?php if ($isAdminShell): ?>
        <div class="admin-shell" data-admin-shell>
            <aside class="admin-sidebar" id="admin-sidebar" data-admin-sidebar>
                <div class="admin-sidebar-header">
                    <a class="admin-brand" href="/admin">
                        <?php if ($siteLogoUrl !== ''): ?>
                            <img class="brand-logo" src="<?= e($siteLogoUrl) ?>" alt="<?= e($appName) ?>" loading="lazy">
                        <?php else: ?>
                            <?= icon('dashboard', 'icon-lg') ?>
                        <?php endif; ?>
                        <span>
                            <strong><?= e($appName) ?></strong>
                            <small><?= et('common.admin') ?></small>
                        </span>
                    </a>
                    <button class="btn btn-icon admin-sidebar-close" type="button" data-admin-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= et('common.close') ?>">
                        <?= icon('close') ?>
                    </button>
                </div>

                <nav class="admin-nav" aria-label="<?= et('common.admin') ?>">
                    <?php foreach ((array) $nav as $item): ?>
                        <?php
                        $href = route_path((string) ($item['href'] ?? '#'));
                        $active = $current === $href || ($href !== '/admin' && str_starts_with($current, $href . '/'));
                        ?>
                        <a class="admin-nav-link" href="<?= e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                            <?= icon((string) ($item['icon'] ?? 'link')) ?>
                            <span><?= e((string) ($item['label'] ?? $href)) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="admin-sidebar-footer">
                    <div class="admin-user">
                        <?= icon('user') ?>
                        <span>
                            <strong><?= e((string) ($authUser['name'] ?? $appName)) ?></strong>
                            <small><?= e((string) ($authUser['email'] ?? '')) ?></small>
                        </span>
                    </div>
                    <form action="/logout" method="post">
                        <?= csrf_field() ?>
                        <button class="btn btn-secondary w-full" type="submit">
                            <?= icon('logout') ?> <span><?= et('common.logout') ?></span>
                        </button>
                    </form>
                </div>
            </aside>

            <button class="admin-backdrop" type="button" data-admin-nav-close aria-label="<?= et('common.close') ?>"></button>

            <div class="admin-main">
                <header class="admin-topbar">
                    <button class="btn btn-icon admin-menu-btn" type="button" data-admin-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= et('common.menu') ?>">
                        <?= icon('menu') ?>
                    </button>
                    <div class="admin-topbar-title">
                        <strong><?= e($title) ?></strong>
                    </div>
                    <?php if ($actions !== ''): ?>
                        <div class="admin-topbar-actions">
                            <?= $actions ?>
                        </div>
                    <?php endif; ?>
                </header>

                <main class="admin-content">
                    <div class="stack" style="--stack-gap: 24px;">
                        <?= $content ?>
                    </div>
                </main>
            </div>
        </div>
    <?php else: ?>
        <header class="navbar">
            <div class="container navbar-inner">
                <a class="navbar-brand" href="/">
                    <?php if ($siteLogoUrl !== ''): ?>
                        <img class="brand-logo" src="<?= e($siteLogoUrl) ?>" alt="<?= e($appName) ?>" loading="lazy">
                    <?php endif; ?>
                    <strong><?= e($appName) ?></strong>
                </a>
                <nav class="nav-links" aria-label="Main">
                    <?php foreach ((array) $nav as $item): ?>
                        <?php
                        $href = (string) ($item['href'] ?? '#');
                        $target = (string) ($item['target'] ?? '_self');
                        $newWindow = $target === '_blank';
                        $isLocal = str_starts_with($href, '/') && !str_starts_with($href, '//');
                        $active = $isLocal && route_path($href) === $current;
                        ?>
                        <a class="nav-link inline-flex items-center gap-2" href="<?= e($href) ?>"<?= $newWindow ? ' target="_blank" rel="noopener"' : '' ?><?= $active ? ' aria-current="page"' : '' ?>>
                            <?= icon((string) ($item['icon'] ?? 'link')) ?>
                            <span><?= e((string) ($item['label'] ?? $href)) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($authUser !== null): ?>
                        <form action="/logout" method="post" class="inline-flex">
                            <?= csrf_field() ?>
                            <button class="nav-link inline-flex items-center gap-2" type="submit" title="<?= e((string) ($authUser['email'] ?? '')) ?>">
                                <?= icon('logout') ?>
                                <span><?= et('common.logout') ?></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="nav-link inline-flex items-center gap-2" href="/login"<?= $current === '/login' ? ' aria-current="page"' : '' ?>>
                            <?= icon('login') ?>
                            <span><?= et('common.login') ?></span>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <main class="section">
            <div class="container stack" style="--stack-gap: 24px;">
                <?= $content ?>
            </div>
        </main>

        <?php if ($siteFooterHtml !== ''): ?>
            <footer class="site-footer">
                <div class="container site-footer-inner">
                    <?= $siteFooterHtml ?>
                </div>
            </footer>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
