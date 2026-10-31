<?php
declare(strict_types=1);
namespace TinyCat\Search;

final class Module implements \TinyCat\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/search/feed', static fn () => \TinyCat\Status\Syndication::respond('search'));
        \TinyCat\Http\Router::route('GET', '/search', static function (): void {
            Pages::search();
        });
    }
}
