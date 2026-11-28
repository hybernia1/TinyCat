<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;
use TinyCat\Core\Tasks\Cleanup\Task as CleanupTask;
use TinyCat\Core\Tasks\Cleanup\Files;
use TinyCat\Core\Tasks\Health\Checks;
use TinyCat\Core\Tasks\Health\Inspection;
use TinyCat\Core\Tasks\Health\Alerts;

final class ServiceProvider implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions
{

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        $probes = [];
        foreach (Checks::localDefinitions() as $id => $run) { $probes[] = new \TinyCat\Core\Modules\HealthProbe($id, $run, count($probes) * 10); }
        $probes[] = new \TinyCat\Core\Modules\HealthProbe('scheduler', Checks::scheduler(...), 70);
        return new \TinyCat\Core\Modules\Contributions(
            componentLookups: [
                new \TinyCat\Core\Modules\ComponentLookup('scheduler.tasks', static fn (): array => Presentation::tasks()),
                new \TinyCat\Core\Modules\ComponentLookup('scheduler.options', static fn (): array => Presentation::options()),
            ],
            tasks: [CleanupTask::scheduledTask(), Inspection::scheduledTask()],
            temporaryDirectories: [
                new \TinyCat\Core\Modules\TemporaryDirectory('cache', static fn (): string => \TinyCat\Support\Cache::directory(), 70),
                new \TinyCat\Core\Modules\TemporaryDirectory('health-uploads', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/.health-probes'), 50),
                new \TinyCat\Core\Modules\TemporaryDirectory('health-storage', static fn (): string => \TinyCat\Support\Storage::path('health-probes'), 60),
            ],
            widgets: [new \TinyCat\Core\Modules\DashboardWidget('core.health', Inspection::widget(...), 10, type: 'status', title: static fn (): string => \TinyCat\Support\Localization::t('health.title'), icon: 'shield')],
            notificationTypes: Alerts::types(),
            navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.scheduler.admin', static fn (): array => self::adminNavigation(), 50)],
            health: $probes,
            maintenance: [
                new \TinyCat\Core\Modules\MaintenanceTask('expired_cache_files', static fn (int $batch): array => \TinyCat\Support\Cache::cleanupExpired($batch), 80),
                new \TinyCat\Core\Modules\MaintenanceTask('old_debug_logs', \TinyCat\Core\Runtime\Debug::cleanupLogs(...), 88),
                new \TinyCat\Core\Modules\MaintenanceTask('temporary_files', static fn (int $batch): array => Files::temporary($batch), 60),
                History::retention(),
            ],
        );
    }


    /** @return array<string, mixed> */
    public static function adminNavigation(): array
    {
        return ['href' => \TinyCat\Core\Ui\Admin\Definitions::get('core.scheduler-runs')['path'], 'icon' => 'clock', 'label' => \TinyCat\Support\Localization::t('cron.menu_title')];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        \TinyCat\Http\Middleware::register('scheduler', static function (): void { Tokens::requireBearer(); });
        \TinyCat\Http\Middleware::register('scheduler_trigger', static function (): void { Tokens::requireBasic(); });
        Api::register($api);
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('core.scheduler-runs'), static function (): void { Pages::show(); });
    }
}
