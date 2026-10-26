<?php
declare(strict_types=1);
namespace TinyCat\Bots;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array
    {
        return \TinyCat\Bots\Importer::requiredTables();
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/bots', static function (): void {
            $botId = max(0, (int) \TinyCat\Http\Web::get('bot', 0));
            \TinyCat\Http\Web::redirect($botId > 0
                ? '/admin/bots/list?' . http_build_query(['bot' => $botId])
                : '/admin/bots/accounts');
        });
        \TinyCat\Http\Router::route('GET', '/admin/bots/{bot_id:[0-9]+}', static function (string $bot_id): void {
            $_GET['id'] = (string) max(0, (int) $bot_id);
            require \TinyCat\Support\Paths::publicPath('admin/bots/detail.php');
        });
        \TinyCat\Http\Router::route('GET', '/admin/bots/accounts', static function (): void {
            require \TinyCat\Support\Paths::publicPath('admin/bots/accounts-page.php');
        });
        \TinyCat\Http\Router::route('GET', '/admin/bots/list', static function (): void {
            require \TinyCat\Support\Paths::publicPath('admin/bots/sources-page.php');
        });
    }
}
