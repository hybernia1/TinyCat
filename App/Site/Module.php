<?php
declare(strict_types=1);
namespace TinyCat\Site;

final class Module implements \TinyCat\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/sitemap.xml', static function (): void {
            $sitemapSection = 'index';
            require \TinyCat\Support\Paths::publicPath('sitemap.php');
        });
        \TinyCat\Http\Router::route('GET', '/{sitemap_file:' . \TinyCat\Site\Sitemap::FILE_PATTERN . '}', static function (string $sitemap_file): void {
            $sitemap = \TinyCat\Site\Sitemap::parseFile($sitemap_file);
            if ($sitemap === null) {
                http_response_code(404);
                return;
            }

            $sitemapSection = $sitemap['section'];
            $sitemapPage = $sitemap['page'];
            require \TinyCat\Support\Paths::publicPath('sitemap.php');
        });
        \TinyCat\Http\Router::route('GET', '/robots.txt', static function (): void {
            require \TinyCat\Support\Paths::publicPath('robots.php');
        });
        \TinyCat\Http\Router::route('GET', '/llms.txt', static function (): void {
            $llmsFull = false;
            require \TinyCat\Support\Paths::publicPath('llms.php');
        });
        \TinyCat\Http\Router::route('GET', '/llms-full.txt', static function (): void {
            $llmsFull = true;
            require \TinyCat\Support\Paths::publicPath('llms.php');
        });
        \TinyCat\Http\Router::route('GET', '/site.webmanifest', static function (): void {
            \TinyCat\Site\Identity::respondManifest();
        });
        \TinyCat\Http\Router::route('GET', '/favicon-32x32.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('favicon');
        });
        \TinyCat\Http\Router::route('GET', '/apple-touch-icon.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('apple');
        });
        \TinyCat\Http\Router::route('GET', '/icon-192.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('pwa-192');
        });
        \TinyCat\Http\Router::route('GET', '/icon-512.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('pwa-512');
        });
        \TinyCat\Http\Router::route('GET', '/icon-maskable-512.png', static function (): void {
            \TinyCat\Site\Identity::respondIcon('maskable-512');
        });
        \TinyCat\Http\Router::route('GET', '/privacy', Pages::privacy(...));
        foreach ([[['GET', 'POST'], '/install', 'install/index.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
        \TinyCat\Http\Router::route(['GET', 'POST'], '/admin', Pages::dashboard(...));
        foreach ([['GET', '/sitemap', 'sitemap.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
        foreach ([['GET', '/robots', 'robots.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
        foreach ([['GET', '/llms', 'llms.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
    }
}
