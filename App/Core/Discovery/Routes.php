<?php
declare(strict_types=1);
namespace TinyCat\Core\Discovery;

use TinyCat\Core\Modules\Registrations;
use TinyCat\Http\{Api, Document, Router};

final class Routes
{
    public static function register(Api $api): void
    {
        Router::route(['GET', 'HEAD'], '/oembed', Oembed::respond(...), 'application/json');
        if (Registrations::current()->entries->sitemaps !== []) {
            foreach (['/sitemap.xml', '/sitemap'] as $path) { Router::route(['GET', 'HEAD'], $path, Sitemap::respond(...)); }
            Router::route(['GET', 'HEAD'], '/{sitemap_file:' . Sitemap::FILE_PATTERN . '}', static function (string $sitemap_file): void { $p = Sitemap::parseFile($sitemap_file); if ($p === null) { Document::notFound('Sitemap not found.'); return; } Sitemap::respond($p['section'], $p['page']); });
            foreach (['/robots.txt', '/robots'] as $path) { Router::route(['GET', 'HEAD'], $path, self::robots(...)); }
        }
        if (Registrations::current()->entries->search !== []) { Search::register($api); }
    }
    public static function robots(): void
    {
        $body = '';
        foreach (Registrations::current()->entries->crawlerHints as $hint) { $body .= '# ' . str_replace(["\r", "\n"], '', ($hint->render)()) . "\n"; }
        $body .= "User-agent: *\n";
        foreach (['admin', 'api', 'install', 'login', 'logout', 'notifications', 'recovery', 'register', 'search'] as $path) { $body .= 'Disallow: /' . $path . "\n"; }
        Document::respond($body . 'Sitemap: ' . \TinyCat\Support\LocalUrls::absolute('/sitemap.xml') . "\n", 'text/plain', 3600);
    }
}
