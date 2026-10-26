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

$appName = \TinyCat\Site\Presentation::site_name();
$siteLogoUrl = \TinyCat\Site\Presentation::site_logo_url();
$siteFaviconUrl = \TinyCat\Site\Presentation::site_favicon_url();
$siteIdentity = \TinyCat\Site\Identity::metadata();
$searchQuery = trim((string) ($search_query ?? \TinyCat\Http\Web::get('q', '')));
$title = (string) ($title ?? $appName);
$current = \TinyCat\Http\Request::current()->path((string) ($current ?? \TinyCat\Http\Request::current()->path()));
$bodyClass = (string) ($bodyClass ?? '');
$csrfToken = (string) ($csrfToken ?? \TinyCat\Security\Csrf::token());
$styles = $styles ?? ['css/tinycat.css'];
$scriptUrls = \TinyCat\Support\ClientAssets::scripts($current, array_values(array_map(static fn (mixed $script): string => (string) $script, (array) ($scripts ?? ['js/tinycat.js']))));
$styleUrls = array_values(array_unique([
    ...array_map(static fn (mixed $style): string => \TinyCat\Support\Assets::asset((string) $style), (array) $styles),
]));
$actions = (string) ($actions ?? '');
$meta = is_array($meta ?? null) ? $meta : [];
$flashToasts = [];

foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $flashKey => $flashType) {
    $flashMessage = \TinyCat\Http\Session::flash($flashKey);

    if ($flashMessage !== null && $flashMessage !== '') {
        $flashToasts[] = [
            'message' => (string) $flashMessage,
            'type' => $flashType,
        ];
    }
}

$defaultAdminNav = [
    ['href' => '/admin', 'icon' => 'dashboard', 'label' => \TinyCat\Support\Localization::t('common.dashboard')],
    ['href' => '/admin/users', 'icon' => 'users', 'label' => \TinyCat\Support\Localization::t('users.list_title')],
    \TinyCat\Bots\Importer::adminNavigation(),
    [
        'icon' => 'shield',
        'label' => \TinyCat\Support\Localization::t('moderation.title'),
        'children' => [
            ['href' => '/admin/moderation/reports', 'icon' => 'flag', 'label' => \TinyCat\Support\Localization::t('moderation.reports_title')],
            ['href' => '/admin/moderation/blocking', 'icon' => 'lock', 'label' => \TinyCat\Support\Localization::t('moderation.url_blocker_title')],
        ],
    ],
    ['href' => '/admin/cron', 'icon' => 'clock', 'label' => \TinyCat\Support\Localization::t('cron.title')],
    ['href' => '/admin/updates', 'icon' => 'download', 'label' => \TinyCat\Support\Localization::t('updates.title')],
    ['href' => '/admin/settings', 'icon' => 'settings', 'label' => \TinyCat\Support\Localization::t('settings.title')],
];
$authUser = \TinyCat\Users\Authentication::auth();
$isAdminShell = $authUser !== null && (bool) ($adminShell ?? str_starts_with($current, '/admin'));
$nav = $isAdminShell ? (array) ($nav ?? $defaultAdminNav) : (array) ($frontendNav ?? []);
$adminNavItemIsActive = static fn (string $href): bool => $current === $href
    || ($href !== '/admin' && str_starts_with($current, $href . '/'));

if (!$isAdminShell && $nav === []) {
    $nav = [];
}

$pageTitle = $title === $appName ? $title : $title . ' | ' . $appName;
$metaTitle = \TinyCat\Site\Presentation::meta_text((string) ($meta['title'] ?? $pageTitle), 120);
$metaDescription = \TinyCat\Site\Presentation::meta_text((string) ($meta['description'] ?? \TinyCat\Site\Presentation::site_meta_description()), 180);
$metaUrl = \TinyCat\Site\Presentation::absolute_url((string) ($meta['url'] ?? ($_SERVER['REQUEST_URI'] ?? $current)));
$metaImageRaw = trim((string) ($meta['image'] ?? \TinyCat\Site\Presentation::site_meta_image_url()));
$metaImage = $metaImageRaw !== '' ? \TinyCat\Site\Presentation::absolute_url($metaImageRaw) : '';
$metaImageType = trim((string) ($meta['image_type'] ?? ''));
$metaImageWidth = max(0, (int) ($meta['image_width'] ?? 0));
$metaImageHeight = max(0, (int) ($meta['image_height'] ?? 0));
$metaImageAlt = trim((string) ($meta['image_alt'] ?? $metaTitle));
$metaType = (string) ($meta['type'] ?? 'website');
$metaRss = trim((string) ($meta['rss'] ?? ''));
$metaPrev = trim((string) ($meta['prev'] ?? ''));
$metaNext = trim((string) ($meta['next'] ?? ''));
$metaJsonLd = $meta['jsonld'] ?? null;
$metaJsonLdJson = is_array($metaJsonLd)
    ? json_encode($metaJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    : false;
$metaRobots = trim((string) ($meta['robots'] ?? ($isAdminShell ? 'noindex,nofollow' : '')));
$metaLocale = str_replace('-', '_', \TinyCat\Support\Localization::locale());
$bodyClasses = trim($bodyClass . ($isAdminShell ? ' admin-shell-page' : ''));
$theme = \TinyCat\Users\Profiles::user_theme($authUser);
$themeAttribute = $theme !== 'system' ? ' data-theme="' . \TinyCat\Support\Html::e($theme) . '"' : '';
?>
<!doctype html>
<html lang="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::locale()) ?>"<?= $themeAttribute ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <?php if ($theme === 'light'): ?>
        <meta name="theme-color" content="<?= \TinyCat\Support\Html::e((string) $siteIdentity['light_theme_color']) ?>">
    <?php elseif ($theme === 'dark'): ?>
        <meta name="theme-color" content="<?= \TinyCat\Support\Html::e((string) $siteIdentity['dark_theme_color']) ?>">
    <?php else: ?>
        <meta name="theme-color" content="<?= \TinyCat\Support\Html::e((string) $siteIdentity['light_theme_color']) ?>" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="<?= \TinyCat\Support\Html::e((string) $siteIdentity['dark_theme_color']) ?>" media="(prefers-color-scheme: dark)">
    <?php endif; ?>
    <link rel="manifest" href="<?= \TinyCat\Support\Html::e((string) $siteIdentity['manifest_url']) ?>">
    <?php if ($csrfToken !== ''): ?>
        <meta name="csrf-token" content="<?= \TinyCat\Support\Html::e($csrfToken) ?>">
    <?php endif; ?>
    <title><?= \TinyCat\Support\Html::e($pageTitle) ?></title>
    <?php if ($metaDescription !== ''): ?>
        <meta name="description" content="<?= \TinyCat\Support\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaRobots !== ''): ?>
        <meta name="robots" content="<?= \TinyCat\Support\Html::e($metaRobots) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= \TinyCat\Support\Html::e($metaUrl) ?>">
    <?php if ($metaRss !== ''): ?>
        <link rel="alternate" type="application/rss+xml" title="<?= \TinyCat\Support\Html::e($pageTitle) ?>" href="<?= \TinyCat\Support\Html::e(\TinyCat\Site\Presentation::absolute_url($metaRss)) ?>">
    <?php endif; ?>
    <?php if ($metaPrev !== ''): ?>
        <link rel="prev" href="<?= \TinyCat\Support\Html::e(\TinyCat\Site\Presentation::absolute_url($metaPrev)) ?>">
    <?php endif; ?>
    <?php if ($metaNext !== ''): ?>
        <link rel="next" href="<?= \TinyCat\Support\Html::e(\TinyCat\Site\Presentation::absolute_url($metaNext)) ?>">
    <?php endif; ?>
    <meta property="og:site_name" content="<?= \TinyCat\Support\Html::e($appName) ?>">
    <meta property="og:title" content="<?= \TinyCat\Support\Html::e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta property="og:description" content="<?= \TinyCat\Support\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <meta property="og:type" content="<?= \TinyCat\Support\Html::e($metaType) ?>">
    <meta property="og:url" content="<?= \TinyCat\Support\Html::e($metaUrl) ?>">
    <meta property="og:locale" content="<?= \TinyCat\Support\Html::e($metaLocale) ?>">
    <?php if ($metaType === 'article' && !empty($meta['published_time'])): ?>
        <meta property="article:published_time" content="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::dateIso((string) $meta['published_time'])) ?>">
    <?php endif; ?>
    <?php if ($metaType === 'article' && !empty($meta['author'])): ?>
        <meta property="article:author" content="<?= \TinyCat\Support\Html::e((string) $meta['author']) ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta property="og:image" content="<?= \TinyCat\Support\Html::e($metaImage) ?>">
        <?php if ($metaImageType !== ''): ?><meta property="og:image:type" content="<?= \TinyCat\Support\Html::e($metaImageType) ?>"><?php endif; ?>
        <?php if ($metaImageWidth > 0): ?><meta property="og:image:width" content="<?= \TinyCat\Support\Html::e($metaImageWidth) ?>"><?php endif; ?>
        <?php if ($metaImageHeight > 0): ?><meta property="og:image:height" content="<?= \TinyCat\Support\Html::e($metaImageHeight) ?>"><?php endif; ?>
        <meta property="og:image:alt" content="<?= \TinyCat\Support\Html::e($metaImageAlt) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $metaImage !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= \TinyCat\Support\Html::e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta name="twitter:description" content="<?= \TinyCat\Support\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta name="twitter:image" content="<?= \TinyCat\Support\Html::e($metaImage) ?>">
        <meta name="twitter:image:alt" content="<?= \TinyCat\Support\Html::e($metaImageAlt) ?>">
    <?php endif; ?>
    <?php if ($siteFaviconUrl !== ''): ?>
        <link rel="icon" type="image/webp" href="<?= \TinyCat\Support\Html::e($siteFaviconUrl) ?>">
    <?php endif; ?>
    <?php if ((string) $siteIdentity['favicon_url'] !== ''): ?>
        <link rel="icon" type="image/png" sizes="32x32" href="<?= \TinyCat\Support\Html::e((string) $siteIdentity['favicon_url']) ?>">
    <?php endif; ?>
    <?php if ((string) $siteIdentity['apple_touch_icon_url'] !== ''): ?>
        <link rel="apple-touch-icon" sizes="180x180" href="<?= \TinyCat\Support\Html::e((string) $siteIdentity['apple_touch_icon_url']) ?>">
    <?php endif; ?>
    <?php if (is_string($metaJsonLdJson) && $metaJsonLdJson !== ''): ?>
        <script type="application/ld+json"><?= $metaJsonLdJson ?></script>
    <?php endif; ?>
    <?php foreach ($styleUrls as $styleUrl): ?>
        <link rel="stylesheet" href="<?= \TinyCat\Support\Html::e($styleUrl) ?>">
    <?php endforeach; ?>
    <?php foreach ($scriptUrls as $scriptUrl): ?>
        <script src="<?= \TinyCat\Support\Html::e($scriptUrl) ?>" defer></script>
    <?php endforeach; ?>
    <?php $googleMeasurementId = trim((string) \TinyCat\Settings\Store::config('analytics.google_measurement_id', '')); ?>
    <?php $analyticsConsent = (string) ($_COOKIE['tinycat_analytics_consent'] ?? ''); ?>
    <?php $analyticsConfigured = \TinyCat\Site\Presentation::site_google_analytics_configured(); ?>
    <?php if ($analyticsConfigured && $analyticsConsent === 'granted'): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= \TinyCat\Support\Html::e($googleMeasurementId) ?>"></script>
        <script>
            window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
            gtag('consent','default',{
                analytics_storage:'granted',
                ad_storage:'denied',
                ad_user_data:'denied',
                ad_personalization:'denied',
                wait_for_update:500
            });
            gtag('js',new Date());
            gtag('config','<?= \TinyCat\Support\Html::e($googleMeasurementId) ?>');
        </script>
    <?php endif; ?>
    <?= \TinyCat\Support\View::part('layout/flashes', ['items' => $flashToasts]) ?>
</head>
<body<?= $bodyClasses !== '' ? ' class="' . \TinyCat\Support\Html::e($bodyClasses) . '"' : '' ?> data-current-user-id="<?= \TinyCat\Support\Html::e((int) ($authUser['id'] ?? 0)) ?>" data-icon-sprite="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Assets::asset('icons.svg')) ?>">
    <a class="skip-link" href="#main-content"><?= \TinyCat\Support\Html::et('common.skip_to_content') ?></a>
    <?php if ($isAdminShell): ?>
        <div class="admin-shell" data-admin-shell>
            <aside class="admin-sidebar" id="admin-sidebar" data-admin-sidebar>
                <div class="admin-sidebar-header">
                    <a class="admin-brand" href="/">
                        <?php if ($siteLogoUrl !== ''): ?>
                            <img class="brand-logo" src="<?= \TinyCat\Support\Html::e($siteLogoUrl) ?>" alt="<?= \TinyCat\Support\Html::et('common.site_logo_alt', ['site' => $appName]) ?>">
                        <?php else: ?>
                            <?= \TinyCat\Support\Html::icon('dashboard', 'icon-lg') ?>
                        <?php endif; ?>
                        <span>
                            <strong<?= $siteLogoUrl !== '' ? ' aria-hidden="true"' : '' ?>><?= \TinyCat\Support\Html::e($appName) ?></strong>
                            <small><?= \TinyCat\Support\Html::et('common.admin') ?></small>
                        </span>
                    </a>
                    <button class="btn btn-icon admin-sidebar-close" type="button" data-admin-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= \TinyCat\Support\Html::et('common.close') ?>">
                        <?= \TinyCat\Support\Html::icon('close') ?>
                    </button>
                </div>

                <nav class="admin-nav" aria-label="<?= \TinyCat\Support\Html::et('common.admin') ?>">
                    <?php foreach ((array) $nav as $item): ?>
                        <?php
                        $children = array_values((array) ($item['children'] ?? []));
                        if ($children !== []):
                            $groupActive = false;
                            foreach ($children as $child) {
                                $childHref = \TinyCat\Http\Request::current()->path((string) ($child['href'] ?? '#'));
                                if ($adminNavItemIsActive($childHref)) {
                                    $groupActive = true;
                                    break;
                                }
                            }
                            ?>
                            <details class="admin-nav-group"<?= $groupActive ? ' open data-active="true"' : '' ?>>
                                <summary class="admin-nav-link admin-nav-summary">
                                    <?= \TinyCat\Support\Html::icon((string) ($item['icon'] ?? 'folder')) ?>
                                    <span><?= \TinyCat\Support\Html::e((string) ($item['label'] ?? '')) ?></span>
                                    <?= \TinyCat\Support\Html::icon('chevron-down', 'icon admin-nav-chevron') ?>
                                </summary>
                                <div class="admin-nav-submenu">
                                    <?php foreach ($children as $child): ?>
                                        <?php
                                        $childHref = \TinyCat\Http\Request::current()->path((string) ($child['href'] ?? '#'));
                                        $childActive = $adminNavItemIsActive($childHref);
                                        ?>
                                        <a class="admin-nav-link" href="<?= \TinyCat\Support\Html::e($childHref) ?>"<?= $childActive ? ' aria-current="page"' : '' ?>>
                                            <?= \TinyCat\Support\Html::icon((string) ($child['icon'] ?? 'link')) ?>
                                            <span><?= \TinyCat\Support\Html::e((string) ($child['label'] ?? $childHref)) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php
                        $href = \TinyCat\Http\Request::current()->path((string) ($item['href'] ?? '#'));
                        $active = $adminNavItemIsActive($href);
                        ?>
                        <a class="admin-nav-link" href="<?= \TinyCat\Support\Html::e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                            <?= \TinyCat\Support\Html::icon((string) ($item['icon'] ?? 'link')) ?>
                            <span><?= \TinyCat\Support\Html::e((string) ($item['label'] ?? $href)) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="admin-sidebar-footer">
                    <?php
                    $adminIdentity = \TinyCat\Users\Profiles::user_display_name($authUser);
                    $adminIdentity = $adminIdentity !== '' ? '@' . $adminIdentity : $appName;
                    ?>
                    <div class="admin-user">
                        <?= \TinyCat\Support\Html::icon('user') ?>
                        <span>
                            <strong><?= \TinyCat\Support\Html::e($adminIdentity) ?></strong>
                            <small>TinyCat v<?= \TinyCat\Support\Html::e(\TinyCat\Support\Version::CURRENT) ?></small>
                        </span>
                    </div>
                    <form action="/logout" method="post" data-confirm="<?= \TinyCat\Support\Html::et('auth.logout_confirm') ?>" data-confirm-title="<?= \TinyCat\Support\Html::et('auth.logout_title') ?>" data-confirm-ok="<?= \TinyCat\Support\Html::et('common.logout') ?>" data-confirm-cancel="<?= \TinyCat\Support\Html::et('common.cancel') ?>" data-confirm-variant="danger">
                        <?= \TinyCat\Security\Csrf::field() ?>
                        <button class="btn btn-secondary w-full" type="submit">
                            <?= \TinyCat\Support\Html::icon('logout') ?> <span><?= \TinyCat\Support\Html::et('common.logout') ?></span>
                        </button>
                    </form>
                </div>
            </aside>

            <button class="admin-backdrop" type="button" data-admin-nav-close aria-label="<?= \TinyCat\Support\Html::et('common.close') ?>"></button>

            <div class="admin-main">
                <header class="admin-topbar">
                    <button class="btn btn-icon admin-menu-btn" type="button" data-admin-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= \TinyCat\Support\Html::et('common.menu') ?>">
                        <?= \TinyCat\Support\Html::icon('menu') ?>
                    </button>
                    <div class="admin-topbar-title">
                        <strong><?= \TinyCat\Support\Html::e($title) ?></strong>
                    </div>
                    <?php if ($actions !== ''): ?>
                        <div class="admin-topbar-actions">
                            <?= $actions ?>
                        </div>
                    <?php endif; ?>
                </header>

                <main class="admin-content" id="main-content" tabindex="-1">
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
                        <img class="brand-logo" src="<?= \TinyCat\Support\Html::e($siteLogoUrl) ?>" alt="<?= \TinyCat\Support\Html::et('common.site_logo_alt', ['site' => $appName]) ?>">
                    <?php endif; ?>
                    <strong<?= $siteLogoUrl !== '' ? ' aria-hidden="true"' : '' ?>><?= \TinyCat\Support\Html::e($appName) ?></strong>
                </a>
                <form class="global-search" action="/search" method="get" role="search" data-global-search data-search-api="/api/search" data-search-tags="<?= \TinyCat\Support\Html::et('public.search_tags') ?>" data-search-users="<?= \TinyCat\Support\Html::et('public.search_users') ?>" data-search-content="<?= \TinyCat\Support\Html::et('public.search_content') ?>" data-search-all="<?= \TinyCat\Support\Html::et('public.search_all') ?>" data-search-empty="<?= \TinyCat\Support\Html::et('public.search_empty') ?>" data-search-min="<?= \TinyCat\Support\Html::et('public.search_min') ?>" data-search-captcha-title="<?= \TinyCat\Support\Html::et('public.search_captcha_title') ?>" data-search-captcha-required="<?= \TinyCat\Support\Html::et('public.search_captcha_required') ?>" data-search-captcha-submit="<?= \TinyCat\Support\Html::et('common.confirm') ?>" autocomplete="off">
                    <label class="sr-only" for="global-search-input"><?= \TinyCat\Support\Html::et('common.search') ?></label>
                    <div class="global-search-control">
                        <?= \TinyCat\Support\Html::icon('search') ?>
                        <input class="global-search-input" id="global-search-input" type="search" name="q" value="<?= \TinyCat\Support\Html::e($searchQuery) ?>" placeholder="<?= \TinyCat\Support\Html::et('public.search_suggest_placeholder') ?>" minlength="2" maxlength="80" data-global-search-input>
                    </div>
                    <div class="global-search-results" data-global-search-results hidden></div>
                </form>
                <nav class="nav-links" aria-label="<?= \TinyCat\Support\Html::et('common.main_navigation') ?>">
                    <?php foreach ((array) $nav as $item): ?>
                        <?php
                        $href = (string) ($item['href'] ?? '#');
                        $target = (string) ($item['target'] ?? '_self');
                        $newWindow = $target === '_blank';
                        $isLocal = str_starts_with($href, '/') && !str_starts_with($href, '//');
                        $active = $isLocal && \TinyCat\Http\Request::current()->path($href) === $current;
                        ?>
                        <a class="nav-link nav-link-icon" href="<?= \TinyCat\Support\Html::e($href) ?>"<?= $newWindow ? ' target="_blank" rel="noopener"' : '' ?><?= $active ? ' aria-current="page"' : '' ?> aria-label="<?= \TinyCat\Support\Html::e((string) ($item['label'] ?? $href)) ?>" title="<?= \TinyCat\Support\Html::e((string) ($item['label'] ?? $href)) ?>">
                            <?= \TinyCat\Support\Html::icon((string) ($item['icon'] ?? 'link')) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($authUser !== null): ?>
                        <?php $profileUrl = \TinyCat\Users\Profiles::author_url((int) ($authUser['id'] ?? 0)); ?>
                        <?php $profileNeedsEmail = trim((string) ($authUser['email'] ?? '')) === ''; ?>
                        <?php if (\TinyCat\Users\Profiles::user_is_admin($authUser)): ?>
                            <a class="nav-link nav-link-icon" href="/admin" aria-label="<?= \TinyCat\Support\Html::et('common.admin') ?>" title="<?= \TinyCat\Support\Html::et('common.admin') ?>">
                                <?= \TinyCat\Support\Html::icon('dashboard') ?>
                            </a>
                        <?php endif; ?>
                        <?php $notificationUserId = (int) ($authUser['id'] ?? 0); ?>
                        <?php $notificationState = \TinyCat\Notifications\Inbox::state($notificationUserId); ?>
                        <?php $notificationItems = \TinyCat\Notifications\Inbox::viewItems(\TinyCat\Notifications\Inbox::items($notificationUserId, \TinyCat\Notifications\Inbox::PREVIEW_LIMIT), 90); ?>
                        <?= \TinyCat\Support\View::part('notifications/menu', [
                            'current' => $current,
                            'state' => $notificationState,
                            'notifications' => $notificationItems,
                        ]) ?>
                        <a class="nav-link nav-link-icon profile-nav-link" href="<?= \TinyCat\Support\Html::e($profileUrl) ?>"<?= $current === $profileUrl ? ' aria-current="page"' : '' ?> aria-label="<?= \TinyCat\Support\Html::e($profileNeedsEmail ? \TinyCat\Support\Localization::t('account.email_missing_title') : \TinyCat\Support\Localization::t('account.public_profile')) ?>" title="<?= \TinyCat\Support\Html::e($profileNeedsEmail ? \TinyCat\Support\Localization::t('account.email_missing_title') : \TinyCat\Support\Localization::t('account.public_profile')) ?>">
                            <?= \TinyCat\Support\Html::icon('user') ?>
                            <?php if ($profileNeedsEmail): ?>
                                <span class="notification-badge profile-email-warning" aria-hidden="true">!</span>
                                <span class="sr-only"><?= \TinyCat\Support\Html::et('account.email_missing_title') ?></span>
                            <?php endif; ?>
                        </a>
                        <form action="/logout" method="post" class="inline-flex" data-confirm="<?= \TinyCat\Support\Html::et('auth.logout_confirm') ?>" data-confirm-title="<?= \TinyCat\Support\Html::et('auth.logout_title') ?>" data-confirm-ok="<?= \TinyCat\Support\Html::et('common.logout') ?>" data-confirm-cancel="<?= \TinyCat\Support\Html::et('common.cancel') ?>" data-confirm-variant="danger">
                            <?= \TinyCat\Security\Csrf::field() ?>
                            <button class="nav-link nav-link-icon" type="submit" aria-label="<?= \TinyCat\Support\Html::et('common.logout') ?>" title="<?= \TinyCat\Support\Html::et('common.logout') ?>">
                                <?= \TinyCat\Support\Html::icon('logout') ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="nav-link nav-link-icon" href="/login"<?= $current === '/login' ? ' aria-current="page"' : '' ?> data-modal-open="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Login::auth_modal_id()) ?>" data-modal-url="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Login::auth_modal_url()) ?>" aria-label="<?= \TinyCat\Support\Html::et('common.login') ?>" title="<?= \TinyCat\Support\Html::et('common.login') ?>">
                            <?= \TinyCat\Support\Html::icon('user') ?>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <main class="section" id="main-content" tabindex="-1">
            <div class="container stack stack-gap-24">
                <?= $content ?>
            </div>
        </main>

        <?php if ($analyticsConfigured && !in_array($analyticsConsent, ['granted', 'denied'], true)): ?>
        <aside class="cookie-consent" data-cookie-consent data-google-measurement-id="<?= \TinyCat\Support\Html::e($googleMeasurementId) ?>" aria-labelledby="cookie-consent-title">
            <div class="cookie-consent-copy">
                <strong id="cookie-consent-title"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_title') ?></strong>
                <span><?= \TinyCat\Support\Html::et(\TinyCat\Site\Presentation::site_captcha_enabled() ? 'privacy.cookie_consent_text_captcha' : 'privacy.cookie_consent_text') ?></span>
            </div>
            <div class="cookie-consent-actions">
                <button class="btn btn-primary btn-sm" type="button" data-cookie-consent-choice="granted"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_accept') ?></button>
                <button class="btn btn-secondary btn-sm" type="button" data-cookie-consent-choice="denied"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_necessary') ?></button>
            </div>
        </aside>
    <?php endif; ?>

    <?php endif; ?>
</body>
</html>
