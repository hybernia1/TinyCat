<?php
declare(strict_types=1);
namespace TinyCat\Status;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array
    {
        return ['content', 'content_images', 'terms', 'content_tags', 'links', 'content_links', 'content_likes', 'content_comments', 'comment_likes'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/oembed', Embed::oembed(...));
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed', static fn (string $id) => Embed::page((int) $id));
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed-code', static fn (string $id) => Embed::page((int) $id, true));
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/rss', static fn () => \TinyCat\Http\Document::notFound('Feed not found.'));
        \TinyCat\Http\Router::route('GET', '/status/{status_id:[0-9]+}', static function (string $status_id): void {
            Pages::detail(max(0, (int) $status_id));
        });

        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/tag/{tag}/feed', static fn (string $tag) => Syndication::respond('tag', $tag));

        \TinyCat\Http\Router::route('GET', '/tag/{tag}', static function (string $tag): void {
            Pages::tag($tag);
        });

        foreach ([
            ['GET', '/', Pages::home(...)],
            ['GET', '/status', Pages::detail(...)],
            ['GET', '/tag', Pages::tag(...)],
        ] as [$methods, $path, $handler]) {
            \TinyCat\Http\Router::route($methods, $path, $handler);
        }
    }
}
