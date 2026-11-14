<?php
declare(strict_types=1);
namespace TinyCat\Social\Profiles;

/** Public author profiles and social relationships. */
final class Registration implements \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Modules\ProvidesManagedSchema, \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Runtime\InitializesModule
{
    public static function contributions(): \TinyCat\Core\Modules\Contributions { return new \TinyCat\Core\Modules\Contributions(sitemaps: [new \TinyCat\Core\Modules\SitemapSection('authors', SitemapData::count(...), SitemapData::entries(...), 20)]); }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema { return Schema::schema(); }

    public static function initialize(): void
    {
        Integration::configure([]);
    }

    /** @return list<string> */
    public static function tables(): array { return ['user_followers']; }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/author/{author_id:[0-9]+}/feed', static fn (string $author_id) => Integration::feed((int) $author_id), contentType: 'text/plain');
        \TinyCat\Http\Router::route('GET', '/author/{author_id:[0-9]+}', static function (string $author_id): void { Pages::author(max(0, (int) $author_id)); });
        \TinyCat\Http\Router::route('GET', '/author', Pages::author(...));
    }
}
