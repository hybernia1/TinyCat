<?php
declare(strict_types=1);
namespace TinyCat\Social\Search;

final class Registration implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions
{
    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(search: Sources::all());
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/search/feed', static fn () => \TinyCat\Social\Posts\Syndication::respond('search'), contentType: 'text/plain');
        \TinyCat\Http\Router::route('GET', '/search', static function (): void {
            Pages::search();
        });
    }
}
