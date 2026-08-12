<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;
use TinyCat\Extension\Assets;

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
$siteIdentity = SiteIdentity::metadata();
$searchQuery = trim((string) ($search_query ?? get('q', '')));
$title = (string) ($title ?? $appName);
$current = route_path((string) ($current ?? route_path()));
$bodyClass = (string) ($bodyClass ?? '');
$csrfToken = (string) ($csrfToken ?? csrf_token());
$styles = is_array($styles ?? null) ? $styles : [];
$styleGroups = is_array($style_groups ?? null) ? $style_groups : [];
$scripts = $scripts ?? ['js/tinycat.js'];
$extensionAssets = Assets::forPath($current);
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
    ...Registry::adminNavigation(),
    [
        'icon' => 'shield',
        'label' => t('moderation.title'),
        'children' => [
            ['href' => '/admin/moderation/reports', 'icon' => 'flag', 'label' => t('moderation.reports_title')],
            ['href' => '/admin/moderation/blocking', 'icon' => 'lock', 'label' => t('moderation.url_blocker_title')],
        ],
    ],
    ['href' => '/admin/cron', 'icon' => 'clock', 'label' => t('cron.title')],
    ['href' => '/admin/extensions', 'icon' => 'puzzle', 'label' => t('extensions.title')],
    ['href' => '/admin/updates', 'icon' => 'download', 'label' => t('updates.title')],
    ['href' => '/admin/settings', 'icon' => 'settings', 'label' => t('settings.title')],
];
$authUser = auth();
$isAdminShell = $authUser !== null && (bool) ($adminShell ?? str_starts_with($current, '/admin'));
$nav = $isAdminShell ? (array) ($nav ?? $defaultAdminNav) : (array) ($frontendNav ?? []);
$adminNavItemIsActive = static fn (string $href): bool => $current === $href
    || ($href !== '/admin' && str_starts_with($current, $href . '/'));

if (!$isAdminShell && $nav === []) {
    $nav = [];
}

$pageTitle = $title === $appName ? $title : $title . ' | ' . $appName;
$metaTitle = meta_text((string) ($meta['title'] ?? $pageTitle), 120);
$metaDescription = meta_text((string) ($meta['description'] ?? site_meta_description()), 180);
$metaUrl = absolute_url((string) ($meta['url'] ?? ($_SERVER['REQUEST_URI'] ?? $current)));
$metaImageRaw = trim((string) ($meta['image'] ?? site_meta_image_url()));
$metaImage = $metaImageRaw !== '' ? absolute_url($metaImageRaw) : '';
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
$metaLocale = str_replace('-', '_', locale());
$bodyClasses = trim($bodyClass . ($isAdminShell ? ' admin-shell-page' : ''));
$theme = user_theme($authUser);
$themeAttribute = $theme !== 'system' ? ' data-theme="' . e($theme) . '"' : '';

if ($styles === []) {
    $styleGroupFiles = [
        'feed' => 'css/tinycat-feed.css',
        'interaction' => 'css/tinycat-interaction.css',
        'avatar' => 'css/tinycat-avatar.css',
        'profile' => 'css/tinycat-profile.css',
        'admin' => 'css/tinycat-admin.css',
    ];
    $styleGroups = $styleGroups !== [] ? $styleGroups : ($isAdminShell ? ['admin'] : []);
    $styles = ['css/tinycat-core.css'];

    foreach ($styleGroups as $styleGroup) {
        $styleFile = $styleGroupFiles[(string) $styleGroup] ?? '';

        if ($styleFile !== '') {
            $styles[] = $styleFile;
        }
    }

    $styles[] = 'css/tinycat-modal.css';
    $styles[] = 'css/tinycat-responsive.css';
}

$styles = array_values(array_unique($styles));
$styleBundleUrl = asset_bundle($styles, 'css');
$styleUrls = array_values(array_unique([
    ...($styleBundleUrl !== null ? [$styleBundleUrl] : array_map(static fn (mixed $style): string => asset((string) $style), $styles)),
    ...$extensionAssets['styles'],
]));
$scriptUrls = array_values(array_unique([
    ...array_map(static fn (mixed $script): string => asset((string) $script), (array) $scripts),
    ...$extensionAssets['scripts'],
]));
?>
<!doctype html>
<html lang="<?= e(locale()) ?>"<?= $themeAttribute ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <?php if ($theme === 'light'): ?>
        <meta name="theme-color" content="<?= e((string) $siteIdentity['light_theme_color']) ?>">
    <?php elseif ($theme === 'dark'): ?>
        <meta name="theme-color" content="<?= e((string) $siteIdentity['dark_theme_color']) ?>">
    <?php else: ?>
        <meta name="theme-color" content="<?= e((string) $siteIdentity['light_theme_color']) ?>" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="<?= e((string) $siteIdentity['dark_theme_color']) ?>" media="(prefers-color-scheme: dark)">
    <?php endif; ?>
    <link rel="manifest" href="<?= e((string) $siteIdentity['manifest_url']) ?>">
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
    <?php if ($metaRss !== ''): ?>
        <link rel="alternate" type="application/rss+xml" title="<?= e($pageTitle) ?>" href="<?= e(absolute_url($metaRss)) ?>">
    <?php endif; ?>
    <?php if ($metaPrev !== ''): ?>
        <link rel="prev" href="<?= e(absolute_url($metaPrev)) ?>">
    <?php endif; ?>
    <?php if ($metaNext !== ''): ?>
        <link rel="next" href="<?= e(absolute_url($metaNext)) ?>">
    <?php endif; ?>
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
        <?php if ($metaImageType !== ''): ?><meta property="og:image:type" content="<?= e($metaImageType) ?>"><?php endif; ?>
        <?php if ($metaImageWidth > 0): ?><meta property="og:image:width" content="<?= e($metaImageWidth) ?>"><?php endif; ?>
        <?php if ($metaImageHeight > 0): ?><meta property="og:image:height" content="<?= e($metaImageHeight) ?>"><?php endif; ?>
        <meta property="og:image:alt" content="<?= e($metaImageAlt) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $metaImage !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta name="twitter:description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta name="twitter:image" content="<?= e($metaImage) ?>">
        <meta name="twitter:image:alt" content="<?= e($metaImageAlt) ?>">
    <?php endif; ?>
    <?php if ($siteFaviconUrl !== ''): ?>
        <link rel="icon" type="image/webp" href="<?= e($siteFaviconUrl) ?>">
    <?php endif; ?>
    <?php if ((string) $siteIdentity['favicon_url'] !== ''): ?>
        <link rel="icon" type="image/png" sizes="32x32" href="<?= e((string) $siteIdentity['favicon_url']) ?>">
    <?php endif; ?>
    <?php if ((string) $siteIdentity['apple_touch_icon_url'] !== ''): ?>
        <link rel="apple-touch-icon" sizes="180x180" href="<?= e((string) $siteIdentity['apple_touch_icon_url']) ?>">
    <?php endif; ?>
    <?php if (is_string($metaJsonLdJson) && $metaJsonLdJson !== ''): ?>
        <script type="application/ld+json"><?= $metaJsonLdJson ?></script>
    <?php endif; ?>
    <?php foreach ($styleUrls as $styleUrl): ?>
        <link rel="stylesheet" href="<?= e($styleUrl) ?>">
    <?php endforeach; ?>
    <?php foreach ($scriptUrls as $scriptUrl): ?>
        <script src="<?= e($scriptUrl) ?>" defer></script>
    <?php endforeach; ?>
    <?php $googleMeasurementId = trim((string) config('analytics.google_measurement_id', '')); ?>
    <?php $analyticsConsent = (string) ($_COOKIE['tinycat_analytics_consent'] ?? ''); ?>
    <?php $analyticsConfigured = site_google_analytics_configured(); ?>
    <?php if ($analyticsConfigured && $analyticsConsent === 'granted'): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($googleMeasurementId) ?>"></script>
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
            gtag('config','<?= e($googleMeasurementId) ?>');
        </script>
    <?php endif; ?>
    <?= part('layout/flashes', ['items' => $flashToasts]) ?>
</head>
<body<?= $bodyClasses !== '' ? ' class="' . e($bodyClasses) . '"' : '' ?> data-icon-sprite="<?= e(asset('icons.svg')) ?>" data-ui-close="<?= et('common.close') ?>" data-ui-cancel="<?= et('common.cancel') ?>" data-ui-confirm="<?= et('common.confirm') ?>" data-ui-confirm-title="<?= et('common.confirm_action') ?>" data-ui-request-failed="<?= et('common.request_failed') ?>">
    <a class="skip-link" href="#main-content"><?= et('common.skip_to_content') ?></a>
    <?php if ($isAdminShell): ?>
        <div class="admin-shell" data-admin-shell>
            <aside class="admin-sidebar" id="admin-sidebar" data-admin-sidebar>
                <div class="admin-sidebar-header">
                    <a class="admin-brand" href="/">
                        <?php if ($siteLogoUrl !== ''): ?>
                            <img class="brand-logo" src="<?= e($siteLogoUrl) ?>" alt="<?= et('common.site_logo_alt', ['site' => $appName]) ?>">
                        <?php else: ?>
                            <?= icon('dashboard', 'icon-lg') ?>
                        <?php endif; ?>
                        <span>
                            <strong<?= $siteLogoUrl !== '' ? ' aria-hidden="true"' : '' ?>><?= e($appName) ?></strong>
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
                        $children = array_values((array) ($item['children'] ?? []));
                        if ($children !== []):
                            $groupActive = false;
                            foreach ($children as $child) {
                                $childHref = route_path((string) ($child['href'] ?? '#'));
                                if ($adminNavItemIsActive($childHref)) {
                                    $groupActive = true;
                                    break;
                                }
                            }
                            ?>
                            <details class="admin-nav-group"<?= $groupActive ? ' open data-active="true"' : '' ?>>
                                <summary class="admin-nav-link admin-nav-summary">
                                    <?= icon((string) ($item['icon'] ?? 'folder')) ?>
                                    <span><?= e((string) ($item['label'] ?? '')) ?></span>
                                    <?= icon('chevron-down', 'icon admin-nav-chevron') ?>
                                </summary>
                                <div class="admin-nav-submenu">
                                    <?php foreach ($children as $child): ?>
                                        <?php
                                        $childHref = route_path((string) ($child['href'] ?? '#'));
                                        $childActive = $adminNavItemIsActive($childHref);
                                        ?>
                                        <a class="admin-nav-link" href="<?= e($childHref) ?>"<?= $childActive ? ' aria-current="page"' : '' ?>>
                                            <?= icon((string) ($child['icon'] ?? 'link')) ?>
                                            <span><?= e((string) ($child['label'] ?? $childHref)) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php
                        $href = route_path((string) ($item['href'] ?? '#'));
                        $active = $adminNavItemIsActive($href);
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
                            <small>TinyCat v<?= e(Core::VERSION) ?></small>
                        </span>
                    </div>
                    <form action="/logout" method="post" data-confirm="<?= et('auth.logout_confirm') ?>" data-confirm-title="<?= et('auth.logout_title') ?>" data-confirm-ok="<?= et('common.logout') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
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
                        <img class="brand-logo" src="<?= e($siteLogoUrl) ?>" alt="<?= et('common.site_logo_alt', ['site' => $appName]) ?>">
                    <?php endif; ?>
                    <strong<?= $siteLogoUrl !== '' ? ' aria-hidden="true"' : '' ?>><?= e($appName) ?></strong>
                </a>
                <form class="global-search" action="/search" method="get" role="search" data-global-search data-search-api="/api/search" data-search-tags="<?= et('public.search_tags') ?>" data-search-users="<?= et('public.search_users') ?>" data-search-content="<?= et('public.search_content') ?>" data-search-all="<?= et('public.search_all') ?>" data-search-empty="<?= et('public.search_empty') ?>" data-search-min="<?= et('public.search_min') ?>" data-search-captcha-title="<?= et('public.search_captcha_title') ?>" data-search-captcha-required="<?= et('public.search_captcha_required') ?>" data-search-captcha-submit="<?= et('common.confirm') ?>" autocomplete="off">
                    <label class="sr-only" for="global-search-input"><?= et('common.search') ?></label>
                    <div class="global-search-control">
                        <?= icon('search') ?>
                        <input class="global-search-input" id="global-search-input" type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="<?= et('public.search_suggest_placeholder') ?>" minlength="2" maxlength="80" data-global-search-input>
                    </div>
                    <div class="global-search-results" data-global-search-results hidden></div>
                </form>
                <nav class="nav-links" aria-label="<?= et('common.main_navigation') ?>">
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
                        <?php $profileNeedsEmail = trim((string) ($authUser['email'] ?? '')) === ''; ?>
                        <?php if (user_is_admin($authUser)): ?>
                            <a class="nav-link nav-link-icon" href="/admin" aria-label="<?= et('common.admin') ?>" title="<?= et('common.admin') ?>">
                                <?= icon('dashboard') ?>
                            </a>
                        <?php endif; ?>
                        <?php $notificationUserId = (int) ($authUser['id'] ?? 0); ?>
                        <?php $notificationState = Notifications::state($notificationUserId); ?>
                        <?php $notificationItems = Notifications::viewItems(Notifications::items($notificationUserId, Notifications::PREVIEW_LIMIT), 90); ?>
                        <?= part('notifications/menu', [
                            'current' => $current,
                            'state' => $notificationState,
                            'notifications' => $notificationItems,
                        ]) ?>
                        <a class="nav-link nav-link-icon profile-nav-link" href="<?= e($profileUrl) ?>"<?= $current === $profileUrl ? ' aria-current="page"' : '' ?> aria-label="<?= e($profileNeedsEmail ? t('account.email_missing_title') : t('account.public_profile')) ?>" title="<?= e($profileNeedsEmail ? t('account.email_missing_title') : t('account.public_profile')) ?>">
                            <?= icon('user') ?>
                            <?php if ($profileNeedsEmail): ?>
                                <span class="notification-badge profile-email-warning" aria-hidden="true">!</span>
                                <span class="sr-only"><?= et('account.email_missing_title') ?></span>
                            <?php endif; ?>
                        </a>
                        <form action="/logout" method="post" class="inline-flex" data-confirm="<?= et('auth.logout_confirm') ?>" data-confirm-title="<?= et('auth.logout_title') ?>" data-confirm-ok="<?= et('common.logout') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button class="nav-link nav-link-icon" type="submit" aria-label="<?= et('common.logout') ?>" title="<?= et('common.logout') ?>">
                                <?= icon('logout') ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="nav-link nav-link-icon" href="/login"<?= $current === '/login' ? ' aria-current="page"' : '' ?> data-modal-open="<?= e(auth_modal_id()) ?>" data-modal-url="<?= e(auth_modal_url()) ?>" aria-label="<?= et('common.login') ?>" title="<?= et('common.login') ?>">
                            <?= icon('user') ?>
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
        <aside class="cookie-consent" data-cookie-consent data-google-measurement-id="<?= e($googleMeasurementId) ?>" aria-labelledby="cookie-consent-title">
            <div class="cookie-consent-copy">
                <strong id="cookie-consent-title"><?= et('privacy.cookie_consent_title') ?></strong>
                <span><?= et(site_captcha_enabled() ? 'privacy.cookie_consent_text_captcha' : 'privacy.cookie_consent_text') ?></span>
            </div>
            <div class="cookie-consent-actions">
                <button class="btn btn-primary btn-sm" type="button" data-cookie-consent-choice="granted"><?= et('privacy.cookie_consent_accept') ?></button>
                <button class="btn btn-secondary btn-sm" type="button" data-cookie-consent-choice="denied"><?= et('privacy.cookie_consent_necessary') ?></button>
            </div>
        </aside>
    <?php endif; ?>

    <?php endif; ?>
</body>
</html>
