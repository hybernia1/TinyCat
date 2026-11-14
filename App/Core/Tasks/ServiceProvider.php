<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;
use TinyCat\Core\Tasks\Cleanup;
use TinyCat\Core\Tasks\FileCleanup;
use TinyCat\Core\Tasks\HealthCheck;

final class ServiceProvider implements \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesContributions
{

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(
            tasks: [Cleanup::scheduledTask()],
            navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.scheduler.admin', static fn (): array => self::adminNavigation(), 50)],
            health: [new \TinyCat\Core\Modules\HealthProbe('scheduler', static fn (): array => HealthCheck::run(), 70)],
            maintenance: [
                new \TinyCat\Core\Modules\MaintenanceTask('temporary_files', static fn (int $batch): array => FileCleanup::temporary($batch), 60),
                \TinyCat\Database\Retention::task('old_scheduler_runs', 'scheduler_runs', 'started_at < ?', static fn (): array => [\TinyCat\Support\Dates::dateDb('-30 days')], 120),
            ],
        );
    }

    /** @return list<string> */
    public static function tables(): array { return []; }

    /** @return array<string, mixed> */
    public static function adminNavigation(): array
    {
        return [
            'icon' => 'clock',
            'label' => \TinyCat\Support\Localization::t('cron.menu_title'),
            'children' => [
                ['href' => '/admin/cron', 'icon' => 'settings', 'label' => \TinyCat\Support\Localization::t('cron.title'), 'exact' => true],
                ['href' => '/admin/cron/list', 'icon' => 'list-unordered', 'label' => \TinyCat\Support\Localization::t('cron.list_title')],
            ],
        ];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        \TinyCat\Http\Middleware::register('scheduler', Tokens::requireBearer(...));
        \TinyCat\Http\Middleware::register('scheduler_trigger', Tokens::requireBasic(...));
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/cron/list', Pages::listing(...));
        \TinyCat\Http\Router::route('GET', '/admin/cron', Pages::administration(...));
    }
}
