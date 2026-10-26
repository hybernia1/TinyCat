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
        foreach ([['GET', '/rss', 'rss.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
        \TinyCat\Http\Router::route(['GET', 'POST'], '/status/{status_id:[0-9]+}', static function (string $status_id): void {
            $_GET['id'] = (string) max(0, (int) $status_id);

            require \TinyCat\Support\Paths::publicPath('status.php');
        });

        \TinyCat\Http\Router::route('GET', '/tag/{tag}/feed', static function (string $tag): void {
            $rssType = 'tag';
            $rssTag = $tag;

            require \TinyCat\Support\Paths::publicPath('rss.php');
        });

        \TinyCat\Http\Router::route(['GET', 'POST'], '/tag/{tag}', static function (string $tag): void {
            $_GET['tag'] = $tag;

            require \TinyCat\Support\Paths::publicPath('tag.php');
        });

        foreach ([
            ['GET', '/', 'index.php'],
            [['GET', 'POST'], '/status', 'status.php'],
            [['GET', 'POST'], '/tag', 'tag.php'],
        ] as [$methods, $path, $file]) {
            \TinyCat\Http\Router::route($methods, $path, static function () use ($file): void { require \TinyCat\Support\Paths::publicPath($file); });
        }
    }
}
