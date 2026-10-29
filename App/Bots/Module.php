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

    /**
     * @return array<string, mixed>
     */
    public static function adminNavigation(): array
    {
        return [
            'icon' => 'rss',
            'label' => \TinyCat\Support\Localization::t('bots.title'),
            'children' => [
                ['href' => '/admin/bots/accounts', 'icon' => 'users', 'label' => \TinyCat\Support\Localization::t('bots.accounts_title')],
                ['href' => '/admin/bots/list', 'icon' => 'list-unordered', 'label' => \TinyCat\Support\Localization::t('bots.sources_title')],
                ['href' => '/admin/bots/queue', 'icon' => 'clock', 'label' => \TinyCat\Support\Localization::t('bots.queue_title')],
            ],
        ];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/bots/queue', Pages::queue(...));
        \TinyCat\Http\Router::route('GET', '/admin/bots', static function (): void {
            $botId = max(0, (int) \TinyCat\Http\Web::get('bot', 0));
            \TinyCat\Http\Web::redirect($botId > 0
                ? '/admin/bots/list?' . http_build_query(['bot' => $botId])
                : '/admin/bots/accounts');
        });
        \TinyCat\Http\Router::route('GET', '/admin/bots/{bot_id:[0-9]+}', static function (string $bot_id): void {
            Pages::detail(max(0, (int) $bot_id));
        });
        \TinyCat\Http\Router::route('GET', '/admin/bots/accounts', Pages::accounts(...));
        \TinyCat\Http\Router::route('GET', '/admin/bots/list', Pages::sources(...));
    }
}
