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
        \TinyCat\Support\LocalUrls::configure(LocaleRouting::url(...));
        Startup::$exception = Errors::exception(...);
        Startup::provideHttp('tinycat.core', new HttpPolicy(
            prepare: static function (string $path): ?string {
                $path = LocaleRouting::prepare($path);
                if (LocaleRouting::active() !== null && $path === '/' && parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/' . LocaleRouting::active()
                    && in_array(Request::current()->method(), ['GET', 'HEAD'], true)) {
                    \TinyCat\Http\Web::redirect(LocaleRouting::url('/') . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''), 301);
                }
                if (self::installation($path) && \TinyCat\Core\Installation\Installer::isComplete()) { Errors::respond(404); return null; }
                return $path;
            }, maintenanceExempt: Branding::handles(...),
            databaseExempt: static fn (string $path): bool => self::installation($path) || Branding::handles($path) || (bool) Startup::option('install.complete', false),
            databaseUnavailable: static function (string $path): void {
                if (Router::isApiPath($path) || Request::current()->wantsJson()) { Response::error(\TinyCat\Support\Localization::t('install.messages.db_not_ready'), 503, 'database_not_ready', ['redirect' => '/install']); }
                \TinyCat\Http\Web::redirect('/install');
            }, beforeDispatch: static function (string $path): void {
                if (LocaleRouting::supports($path) || Router::isApiPath($path)) {
                    \TinyCat\Core\Identity\Locale::apply(); LocaleRouting::apply();
                    header('Vary: Accept-Language, X-TinyCat-Locale', false);
                }
            }, notFound: static function (): void { Errors::respond(404); },
        ));
        \TinyCat\Core\Updates\Manager::configure(Startup::option(...), Errors::respond(...));
    }

    private static function installation(string $path): bool
    {
        return $path === '/install' || str_starts_with($path, '/install/') || $path === '/api/installation' || str_starts_with($path, '/api/installation/');
    }

    public static function contributions(): Contributions
    {
        return new Contributions(localizedRoutes: [
            new LocalizedRoute('core.home', '^/$'),
            new LocalizedRoute('core.identity', '^/(?:login|register|logout)$', ['next']),
            new LocalizedRoute('core.recovery', '^/recovery$', ['token']),
            new LocalizedRoute('core.notifications', '^/notifications$'),
            new LocalizedRoute('core.privacy', '^/privacy$', translatedDocument: true),
            new LocalizedRoute('core.search', '^/search/all$', ['q', 'collection']),
        ], navigation: [new NavigationItem('tinycat.core.dashboard', static fn (): array => ['href' => '/admin', 'icon' => 'dashboard', 'label' => \TinyCat\Support\Localization::t('common.dashboard')], 0)],
            widgets: [new DashboardWidget('core.diagnostics', Admin\Diagnostics::render(...), 100)]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Information::register();
        $api->post('/site/cache-resets', static fn (): array => ['reset' => \TinyCat\Support\Cache::resetOpcache(), 'redirect' => '/admin'], 'admin', 200);
    }
}
