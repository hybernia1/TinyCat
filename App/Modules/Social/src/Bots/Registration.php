<?php
declare(strict_types=1);
namespace TinyCat\Social\Bots;

final class Registration
{
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

    /**
     * @return array<string, mixed>
     */
    public static function adminNavigation(): array
    {
        return [
            'icon' => 'rss',
            'label' => \TinyCat\Support\Localization::t('bots.title'),
            'children' => [
                \TinyCat\Core\Ui\Admin\Definitions::navigation('social.bot-creation'),
                \TinyCat\Core\Ui\Admin\Definitions::navigation('social.bot-sources'),
                \TinyCat\Core\Ui\Admin\Definitions::navigation('social.bot-queue'),
            ],
        ];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('social.bot-queue'), static function (): void { Pages::queue(); });
        \TinyCat\Http\Router::route('GET', '/admin/bots', static function (): void {
            $botId = max(0, (int) \TinyCat\Http\Web::get('bot', 0));
            \TinyCat\Http\Web::redirect($botId > 0
                ? '/admin/bots/list?' . http_build_query(['bot' => $botId])
                : '/admin/bots/accounts');
        });
        \TinyCat\Http\Router::route('GET', '/admin/bots/{bot_id:[0-9]+}', static function (string $bot_id): void {
            Pages::detail(max(0, (int) $bot_id));
        });
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('social.bot-creation'), static function (): void { Pages::accounts(); });
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('social.bot-sources'), static function (): void { Pages::sources(); });
    }
}
