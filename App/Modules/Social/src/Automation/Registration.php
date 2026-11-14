<?php
declare(strict_types=1);
namespace TinyCat\Social\Automation;

final class Registration implements \TinyCat\Core\Modules\ProvidesManagedSchema, \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Identity\ProvidesLifecycle
{
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema { return Schema::schema(); }

    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant
    {
        return new \TinyCat\Core\Identity\Participant(statusChanged: static function (array $user, bool $active): void {
            if (!$active && ($user['role'] ?? '') === 'bot') {
                \TinyCat\Database\Connection::update('bot_sources', ['enabled' => 0], ['bot_user_id' => (int) $user['id']]);
            }
        });
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(
            tasks: [Importer::scheduledTask()],
            navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.bots.admin', static fn (): array => self::adminNavigation(), 30)],
            maintenance: [
                new \TinyCat\Core\Modules\MaintenanceTask('bot_history', static fn (int $batch): array => Cleanup::run($batch), 130),
                new \TinyCat\Core\Modules\MaintenanceTask('duplicate_bot_posts', static fn (int $batch): array => DuplicateCleanup::run($batch), 140),
            ],
        );
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return \TinyCat\Social\Automation\Importer::requiredTables();
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
