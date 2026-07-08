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
$siteLogoUrl = site_logo_url();
$siteFaviconUrl = site_favicon_url();
$siteFooterHtml = site_footer_html();
$searchQuery = trim((string) ($search_query ?? get('q', '')));
$title = (string) ($title ?? $appName);
$current = route_path((string) ($current ?? route_path()));
$bodyClass = (string) ($bodyClass ?? '');
$csrfToken = (string) ($csrfToken ?? csrf_token());
$styles = $styles ?? ['css/tinycat.css'];
$scripts = $scripts ?? ['js/tinycat.js'];
$actions = (string) ($actions ?? '');
$meta = is_array($meta ?? null) ? $meta : [];
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
    ['href' => '/admin/users', 'icon' => 'users', 'label' => t('users.list_title')],
    ['href' => '/admin/moderation', 'icon' => 'shield', 'label' => t('moderation.title')],
    ['href' => '/admin/maintenance', 'icon' => 'database', 'label' => t('maintenance.title')],
    ['href' => '/admin/settings', 'icon' => 'settings', 'label' => t('settings.title')],
];
$authUser = auth();
$isAdminShell = $authUser !== null && (bool) ($adminShell ?? str_starts_with($current, '/admin'));
$nav = $isAdminShell ? (array) ($nav ?? $defaultAdminNav) : (array) ($frontendNav ?? []);

if (!$isAdminShell && $nav === []) {
    $nav = [];
}

$pageTitle = $title === $appName ? $title : $title . ' | ' . $appName;
$metaTitle = meta_text((string) ($meta['title'] ?? $pageTitle), 120);
$metaDescription = meta_text((string) ($meta['description'] ?? t('public.meta_description', ['site' => $appName])), 180);
$metaUrl = absolute_url((string) ($meta['url'] ?? ($_SERVER['REQUEST_URI'] ?? $current)));
$metaImageRaw = trim((string) ($meta['image'] ?? site_meta_image_url()));
$metaImage = $metaImageRaw !== '' ? absolute_url($metaImageRaw) : '';
$metaType = (string) ($meta['type'] ?? 'website');
$metaRobots = trim((string) ($meta['robots'] ?? ($isAdminShell ? 'noindex,nofollow' : '')));
$metaLocale = str_replace('-', '_', locale());
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
    <?php if ($metaDescription !== ''): ?>
        <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaRobots !== ''): ?>
        <meta name="robots" content="<?= e($metaRobots) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= e($metaUrl) ?>">
    <meta property="og:site_name" content="<?= e($appName) ?>">
    <meta property="og:title" content="<?= e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta property="og:description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <meta property="og:type" content="<?= e($metaType) ?>">
    <meta property="og:url" content="<?= e($metaUrl) ?>">
    <meta property="og:locale" content="<?= e($metaLocale) ?>">
    <?php if ($metaType === 'article' && !empty($meta['published_time'])): ?>
        <meta property="article:published_time" content="<?= e(date_iso((string) $meta['published_time'])) ?>">
    <?php endif; ?>
    <?php if ($metaType === 'article' && !empty($meta['author'])): ?>
        <meta property="article:author" content="<?= e((string) $meta['author']) ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta property="og:image" content="<?= e($metaImage) ?>">
        <meta property="og:image:alt" content="<?= e($metaTitle) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $metaImage !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta name="twitter:description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta name="twitter:image" content="<?= e($metaImage) ?>">
    <?php endif; ?>
    <?php if ($siteFaviconUrl !== ''): ?>
        <link rel="icon" type="image/webp" href="<?= e($siteFaviconUrl) ?>">
    <?php endif; ?>
    <?php foreach ((array) $styles as $style): ?>
        <link rel="stylesheet" href="<?= e(asset((string) $style)) ?>">
    <?php endforeach; ?>
    <?php foreach ((array) $scripts as $script): ?>
        <script src="<?= e(asset((string) $script)) ?>" defer></script>
    <?php endforeach; ?>
    <?php if ($flashToasts !== []): ?>
        <template data-tinycat-flashes><?= json_encode($flashToasts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></template>
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
                    <?php
                    $adminIdentity = user_display_name($authUser);
                    $adminIdentity = $adminIdentity !== '' ? '@' . $adminIdentity : $appName;
                    ?>
                    <div class="admin-user">
                        <?= icon('user') ?>
                        <span>
                            <strong><?= e($adminIdentity) ?></strong>
                            <small><?= e($adminIdentity) ?></small>
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
                    <div class="stack stack-gap-24">
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
                <form class="global-search" action="/search" method="get" role="search" data-global-search data-search-api="/api/search" data-search-tags="<?= et('public.search_tags') ?>" data-search-users="<?= et('public.search_users') ?>" data-search-content="<?= et('public.search_content') ?>" data-search-all="<?= et('public.search_all') ?>" data-search-empty="<?= et('public.search_empty') ?>" data-search-min="<?= et('public.search_min') ?>" data-search-captcha-title="<?= et('public.search_captcha_title') ?>" data-search-captcha-submit="<?= et('common.confirm') ?>" autocomplete="off">
                    <label class="sr-only" for="global-search-input"><?= et('common.search') ?></label>
                    <div class="global-search-control">
                        <?= icon('search') ?>
                        <input class="global-search-input" id="global-search-input" type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="<?= et('public.search_suggest_placeholder') ?>" minlength="2" maxlength="80" data-global-search-input>
                    </div>
                    <div class="global-search-results" data-global-search-results hidden></div>
                </form>
                <nav class="nav-links" aria-label="Main">
                    <?php foreach ((array) $nav as $item): ?>
                        <?php
                        $href = (string) ($item['href'] ?? '#');
                        $target = (string) ($item['target'] ?? '_self');
                        $newWindow = $target === '_blank';
                        $isLocal = str_starts_with($href, '/') && !str_starts_with($href, '//');
                        $active = $isLocal && route_path($href) === $current;
                        ?>
                        <a class="nav-link nav-link-icon" href="<?= e($href) ?>"<?= $newWindow ? ' target="_blank" rel="noopener"' : '' ?><?= $active ? ' aria-current="page"' : '' ?> aria-label="<?= e((string) ($item['label'] ?? $href)) ?>" title="<?= e((string) ($item['label'] ?? $href)) ?>">
                            <?= icon((string) ($item['icon'] ?? 'link')) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($authUser !== null): ?>
                        <?php $profileUrl = author_url((int) ($authUser['id'] ?? 0)); ?>
                        <?php if ((string) ($authUser['role'] ?? '') === 'admin'): ?>
                            <a class="nav-link nav-link-icon" href="/admin" aria-label="<?= et('common.admin') ?>" title="<?= et('common.admin') ?>">
                                <?= icon('dashboard') ?>
                            </a>
                        <?php endif; ?>
                        <?php
                        $notificationUserId = (int) ($authUser['id'] ?? 0);
                        $notificationUnread = notification_unread_count($notificationUserId);
                        $notificationLatestId = notification_latest_id($notificationUserId);
                        ?>
                        <div class="notification-menu" data-notification-menu>
                            <a class="nav-link nav-link-icon notification-nav-link" href="/notifications"<?= $current === '/notifications' ? ' aria-current="page"' : '' ?> aria-label="<?= et('notifications.title') ?>" title="<?= et('notifications.title') ?>" aria-haspopup="true" aria-expanded="false" aria-controls="notification-popover" data-notification-button data-notification-api="/api/notifications" data-notification-interval="5000" data-notification-unread="<?= e($notificationUnread) ?>" data-notification-latest-id="<?= e($notificationLatestId) ?>" data-notification-message="<?= et('notifications.new') ?>">
                                <?= icon('bell') ?>
                                <span class="notification-badge" data-notification-count<?= $notificationUnread < 1 ? ' hidden' : '' ?>><?= e(notification_badge_text($notificationUnread)) ?></span>
                            </a>
                            <div class="notification-popover" id="notification-popover" data-notification-popover hidden>
                                <div class="notification-popover-header">
                                    <strong><?= et('notifications.title') ?></strong>
                                    <span class="badge badge-primary" data-notification-menu-count<?= $notificationUnread < 1 ? ' hidden' : '' ?>><?= e(notification_badge_text($notificationUnread)) ?></span>
                                </div>
                                <div class="notification-popover-list" data-notification-list>
                                    <?= notification_preview_html($notificationUserId) ?>
                                </div>
                                <a class="notification-popover-more" href="/notifications">
                                    <?= icon('bell') ?> <span><?= et('notifications.more') ?></span>
                                </a>
                            </div>
                        </div>
                        <a class="nav-link nav-link-icon" href="<?= e($profileUrl) ?>"<?= $current === $profileUrl ? ' aria-current="page"' : '' ?> aria-label="<?= et('account.public_profile') ?>" title="<?= et('account.public_profile') ?>">
                            <?= icon('user') ?>
                        </a>
                        <form action="/logout" method="post" class="inline-flex">
                            <?= csrf_field() ?>
                            <button class="nav-link nav-link-icon" type="submit" aria-label="<?= et('common.logout') ?>" title="<?= et('common.logout') ?>">
                                <?= icon('logout') ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="nav-link nav-link-icon" href="/login"<?= $current === '/login' ? ' aria-current="page"' : '' ?> aria-label="<?= et('common.login') ?>" title="<?= et('common.login') ?>">
                            <?= icon('login') ?>
                        </a>
                        <?php if (registration_enabled()): ?>
                            <a class="nav-link nav-link-icon" href="/register"<?= $current === '/register' ? ' aria-current="page"' : '' ?> aria-label="<?= et('common.register') ?>" title="<?= et('common.register') ?>">
                                <?= icon('user-plus') ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <main class="section">
            <div class="container stack stack-gap-24">
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
