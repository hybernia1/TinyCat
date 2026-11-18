<?php
declare(strict_types=1);
namespace TinyCat\Social\Profiles;

/** Public author profiles and social relationships. */
final class Registration
{
    public static function contributions(): \TinyCat\Core\Modules\Contributions { return new \TinyCat\Core\Modules\Contributions(sitemaps: [new \TinyCat\Core\Modules\SitemapSection('authors', static fn (): int => SitemapData::count(), static fn (int $limit, int $offset): array => SitemapData::entries($limit, $offset), 20)]); }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/author/{author_id:[0-9]+}/feed', static fn (string $author_id) => \TinyCat\Social\Posts\Syndication::respond('author', '', (int) $author_id), contentType: 'text/plain');
    }
}
