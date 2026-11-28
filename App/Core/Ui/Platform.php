<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Core\Modules\{Contributions, DashboardWidget, LocalizedRoute, NavigationItem};
use TinyCat\Core\Runtime\{Errors, HttpPolicy, Startup};
use TinyCat\Http\{LocaleRouting, Request, Response, Router};

/** Mandatory web surface. Functional modules contribute content, never the HTTP pipeline. */
final class Platform
{
    public static function initialize(): void
    {
        Router::configurePublicWeb(PublicWeb::enabled(...));
        Startup::$exception = static function (\Throwable $error): void { Errors::exception($error); };
        Startup::provideHttp('tinycat.core', new HttpPolicy(
            prepare: static function (string $path): ?string {
                $path = LocaleRouting::prepare($path);
                if (LocaleRouting::active() !== null && $path === '/' && parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/' . LocaleRouting::active()
                    && in_array(Request::current()->method(), ['GET', 'HEAD'], true)) {
                    \TinyCat\Http\Web::redirect(LocaleRouting::url('/') . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''), 301);
                }
                if (self::installation($path) && !\TinyCat\Core\Updates\Manager::installationPending() && \TinyCat\Core\Installation\Installer::isComplete()) { Errors::respond(404); return null; }
                return $path;
            }, maintenanceExempt: static fn (string $path): bool => Branding::handles($path),
            databaseExempt: static fn (string $path): bool => self::installation($path) || Branding::handles($path) || (bool) Startup::option('install.complete', false),
            databaseUnavailable: static function (string $path): void {
                if (Router::isApiPath($path) || Request::current()->wantsJson()) { Response::error(\TinyCat\Support\Localization::t('install.messages.db_not_ready'), 503, 'database_not_ready', ['redirect' => '/install']); }
                \TinyCat\Http\Web::redirect('/install');
            }, beforeDispatch: static function (string $path): void {
                if (LocaleRouting::supports($path) || Router::isApiPath($path)) {
                    \TinyCat\Core\Identity\Authentication::applyLocale(); LocaleRouting::apply();
                    header('Vary: Accept-Language, X-TinyCat-Locale', false);
                }
            }, notFound: static function (): void { Errors::respond(404); },
        ));
        \TinyCat\Core\Updates\Manager::configure(Startup::option(...), static function (int $status): void { Errors::respond($status); });
    }

    private static function installation(string $path): bool
    {
        return $path === '/install' || str_starts_with($path, '/install/') || $path === '/api/installation' || str_starts_with($path, '/api/installation/');
    }

    public static function contributions(): Contributions
    {
        return new Contributions(
            maintenance: [
                new \TinyCat\Core\Modules\MaintenanceTask('retired_runtime_assets', \TinyCat\Support\Assets::cleanup(...), 86),
                new \TinyCat\Core\Modules\MaintenanceTask('expired_prepared_assets', \TinyCat\Support\Assets::cleanupPrepared(...), 87),
            ], sitemaps: [new \TinyCat\Core\Modules\SitemapSection('pages', static fn (): int => count(Information::pages()), static fn (int $limit, int $offset): array => array_slice(Information::pages(), $offset, $limit), 10)], crawlerHints: [new \TinyCat\Core\Modules\CrawlerHint('tinycat.core.llms', static fn (): string => 'AI and LLM context: ' . \TinyCat\Http\LocalUrls::absolute('/llms.txt'))], componentLookups: [new \TinyCat\Core\Modules\ComponentLookup('dashboard.statistics', Admin\Statistics::fieldLookup(...))], localizedRoutes: [
            new LocalizedRoute('core.home', '^/$'),
            new LocalizedRoute('core.identity', '^/(?:login|register|logout)$', ['next']),
            new LocalizedRoute('core.recovery', '^/recovery$', ['token', 'next']),
            new LocalizedRoute('core.account', '^/account$'),
            new LocalizedRoute('core.notifications', '^/notifications$'),
            new LocalizedRoute('core.privacy', '^/privacy$', translatedDocument: true),
            new LocalizedRoute('core.search', '^/search/all$', ['q', 'collection']),
        ], widgets: [new DashboardWidget('core.statistics', Admin\Statistics::widget(...), -10, type: 'metrics', title: static fn (): string => \TinyCat\Support\Localization::t('admin.widgets.stats'), icon: 'chart', visible: static fn (): bool => Admin\Statistics::groups() !== [])], navigation: [new NavigationItem('tinycat.core.modules', static fn (): array => ['href' => '/admin/modules', 'icon' => 'settings', 'label' => \TinyCat\Support\Localization::t('modules.title')], 65), new NavigationItem('tinycat.core.dashboard', static fn (): array => ['href' => '/admin', 'icon' => 'dashboard', 'label' => \TinyCat\Support\Localization::t('common.dashboard')], 0),
            new NavigationItem('tinycat.core.cache', static fn (): array => ['href' => '/admin/cache', 'icon' => 'database', 'label' => \TinyCat\Support\Localization::t('admin.cache.title')], 65)]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Information::register();
        $api->get('/site/dashboard-stats', static function (Request $request): array {
            $request->validated(['stats' => 'required|string|max:80']);
            return Admin\Statistics::resource((string) $request->input('stats'));
        }, 'admin');
        Router::route('GET', '/admin/cache', Admin\CachePage::respond(...));
        $api->post('/site/asset-builds', static function (): array {
            $result = \TinyCat\Support\AssetCompiler::regenerate();
            return $result + ['redirect' => '/admin/cache'];
        }, 'admin', 200);
        $api->post('/site/cache-resets', static fn (): array => ['reset' => \TinyCat\Core\Runtime\Debug::resetOpcache(), 'redirect' => '/admin/cache'], 'admin', 200);
    }
}
