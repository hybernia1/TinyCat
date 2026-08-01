<?php
declare(strict_types=1);

define('TINYCAT', true);

require_once __DIR__ . '/App/functions.php';
require_once __DIR__ . '/App/Api.php';

Core::securityHeaders();

$path = route_path();
$installPath = $path === '/install' || str_starts_with($path, '/install/');
$siteIdentityPath = in_array($path, [
    '/site.webmanifest',
    '/favicon-32x32.png',
    '/apple-touch-icon.png',
    '/icon-192.png',
    '/icon-512.png',
    '/icon-maskable-512.png',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
], true);

route(['GET', 'POST'], '/admin/bots/{bot_id:[0-9]+}', static function (string $bot_id): void {
    $_GET['id'] = (string) max(0, (int) $bot_id);
    require public_path('admin/bot.php');
});

route('GET', '/author/{author_id:[0-9]+}/feed', static function (string $author_id): void {
    $rssType = 'author';
    $rssAuthorId = max(0, (int) $author_id);

    require public_path('rss.php');
});

route('GET', '/sitemap.xml', static function (): void {
    $sitemapSection = 'index';
    require public_path('sitemap.php');
});

route('GET', '/sitemap-authors.xml', static function (): void {
    $sitemapSection = 'authors';
    require public_path('sitemap.php');
});

route('GET', '/sitemap-pages.xml', static function (): void {
    $sitemapSection = 'pages';
    require public_path('sitemap.php');
});

route('GET', '/sitemap-status.xml', static function (): void {
    $sitemapSection = 'status';
    require public_path('sitemap.php');
});

route('GET', '/sitemap-tags.xml', static function (): void {
    $sitemapSection = 'tags';
    require public_path('sitemap.php');
});

route('GET', '/robots.txt', static function (): void {
    require public_path('robots.php');
});

route('GET', '/llms.txt', static function (): void {
    $llmsFull = false;
    require public_path('llms.php');
});

route('GET', '/llms-full.txt', static function (): void {
    $llmsFull = true;
    require public_path('llms.php');
});

route('GET', '/site.webmanifest', static function (): void {
    SiteIdentity::respondManifest();
});

route('GET', '/favicon-32x32.png', static function (): void {
    SiteIdentity::respondIcon('favicon');
});

route('GET', '/apple-touch-icon.png', static function (): void {
    SiteIdentity::respondIcon('apple');
});

route('GET', '/icon-192.png', static function (): void {
    SiteIdentity::respondIcon('pwa-192');
});

route('GET', '/icon-512.png', static function (): void {
    SiteIdentity::respondIcon('pwa-512');
});

route('GET', '/icon-maskable-512.png', static function (): void {
    SiteIdentity::respondIcon('maskable-512');
});

// Backward-compatible aliases for installations where /icons is routed through PHP.
route('GET', '/icons/icon-192.png', static function (): void {
    SiteIdentity::respondIcon('pwa-192');
});

route('GET', '/icons/icon-512.png', static function (): void {
    SiteIdentity::respondIcon('pwa-512');
});

route('GET', '/icons/icon-maskable-512.png', static function (): void {
    SiteIdentity::respondIcon('maskable-512');
});

route('GET', '/author/{author_id:[0-9]+}', static function (string $author_id): void {
    $_GET['id'] = (string) max(0, (int) $author_id);

    require public_path('author.php');
});

route(['GET', 'POST'], '/status/{status_id:[0-9]+}', static function (string $status_id): void {
    $_GET['id'] = (string) max(0, (int) $status_id);

    require public_path('status.php');
});

route(['GET', 'POST'], '/search', static function (): void {
    require public_path('search.php');
});

route('GET', '/notifications/open', static function (): void {
    $user = require_auth('/login');
    redirect(notification_open((int) get('id', 0), (int) $user['id']));
});

route(['GET', 'POST'], '/notifications', static function (): void {
    require public_path('notifications.php');
});

route('GET', '/tag/{tag}/feed', static function (string $tag): void {
    $rssType = 'tag';
    $rssTag = $tag;

    require public_path('rss.php');
});

route(['GET', 'POST'], '/tag/{tag}', static function (string $tag): void {
    $_GET['tag'] = $tag;

    require public_path('tag.php');
});

route('GET', '/avatar/{username:[a-z][a-z0-9_]{2,31}}', static function (string $username): void {
    Avatar::respond($username);
});

Api::register();

if (!$installPath && !(bool) config('install.complete', false) && !app_db_ready()) {
    if (str_starts_with($path, '/api') || wants_json()) {
        api_error(t('install.messages.db_not_ready'), 503, 'database_not_ready', ['redirect' => '/install']);
    }

    redirect('/install');
}

if (!$installPath && !$siteIdentityPath) {
    app_apply_user_locale();
    app_touch_user_activity();
}

$handled = dispatch_routes($path);

if (!$handled) {
    $handled = autoroute($path);
}

if (!$handled && str_starts_with($path, '/api')) {
    api_error('API endpoint not found.', 404, 'not_found');
}

if (!$handled) {
    http_response_code(404);
    echo 'Not found.';
}
