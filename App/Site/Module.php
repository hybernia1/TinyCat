<?php
declare(strict_types=1);
namespace TinyCat\Site;

final class Module implements \TinyCat\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        foreach (['/sitemap.xml', '/sitemap'] as $path) {
            \TinyCat\Http\Router::route(['GET', 'HEAD'], $path, Discovery::sitemap(...));
        }
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/{sitemap_file:' . Sitemap::FILE_PATTERN . '}', static function (string $sitemap_file): void {
            $sitemap = Sitemap::parseFile($sitemap_file);
            if ($sitemap === null) { \TinyCat\Http\Document::notFound('Sitemap not found.'); return; }
            Discovery::sitemap($sitemap['section'], $sitemap['page']);
        });
        foreach (['/robots.txt', '/robots'] as $path) {
            \TinyCat\Http\Router::route(['GET', 'HEAD'], $path, Discovery::robots(...));
        }
        foreach (['/llms.txt', '/llms'] as $path) {
            \TinyCat\Http\Router::route(['GET', 'HEAD'], $path, Discovery::llms(...));
        }
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/llms-full.txt', static fn () => Discovery::llms(true));
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/site.webmanifest', static function (): void {
            \TinyCat\Site\Identity::respondManifest();
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/favicon-32x32.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('favicon');
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/apple-touch-icon.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('apple');
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/icon-192.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('pwa-192');
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/icon-512.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('pwa-512');
        });
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/icon-maskable-512.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('maskable-512');
        });
        \TinyCat\Http\Router::route('GET', '/privacy', Pages::privacy(...));
        foreach ([[['GET', 'POST'], '/install', 'install/index.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
        \TinyCat\Http\Router::route(['GET', 'POST'], '/admin', Pages::dashboard(...));
    }
}
