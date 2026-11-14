<?php
declare(strict_types=1);
namespace TinyCat\Core\Settings;
use TinyCat\Core\Settings\Store;

final class ServiceProvider implements \TinyCat\Core\Modules\ProvidesManagedSchema, \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Runtime\InitializesModule
{
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema { return Schema::schema(); }

    public static function initialize(): void
    {
        \TinyCat\Core\Runtime\Startup::$options = Store::config(...);
        \TinyCat\Core\Runtime\Startup::$dates = Store::config(...);
        \TinyCat\Core\Runtime\Startup::$locale = static fn (): mixed => Store::config('i18n.locale', \TinyCat\Support\Config::get('install.locale', 'en'));
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(settings: Settings::sections(), temporaryDirectories: [new \TinyCat\Core\Modules\TemporaryDirectory('branding', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/site'), 40)], navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.settings.admin', static fn (): array => [
            'modal' => 'modal-settings-editor', 'modal_url' => '/api/settings/editor', 'icon' => 'settings', 'label' => \TinyCat\Support\Localization::t('settings.title'),
        ], 80)]);
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return ['settings'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/site.webmanifest', static function (): void {
            \TinyCat\Core\Ui\Branding::respondManifest();
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/favicon-32x32.png', static function (): void {
            \TinyCat\Core\Ui\Branding::respondIcon('favicon');
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/apple-touch-icon.png', static function (): void {
            \TinyCat\Core\Ui\Branding::respondIcon('apple');
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/icon-192.png', static function (): void {
            \TinyCat\Core\Ui\Branding::respondIcon('pwa-192');
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/icon-512.png', static function (): void {
            \TinyCat\Core\Ui\Branding::respondIcon('pwa-512');
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/icon-maskable-512.png', static function (): void {
            \TinyCat\Core\Ui\Branding::respondIcon('maskable-512');
        });

    }
}
