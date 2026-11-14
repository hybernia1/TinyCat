<?php
declare(strict_types=1);
namespace TinyCat\Core\Updates;

final class ServiceProvider implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions
{
    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(
            navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.updates.admin', static fn (): array => [
                'href' => '/admin/updates', 'icon' => 'download', 'label' => \TinyCat\Support\Localization::t('updates.title'),
            ], 70)],
            health: [new \TinyCat\Core\Modules\HealthProbe('updates', static fn (): string => Manager::maintenanceActive() ? 'critical' : 'ok', 80)],
            maintenance: [new \TinyCat\Core\Modules\MaintenanceTask('update_files', static fn (int $batch): array => Cleanup::run($batch), 70, initial: true)],
        );
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/updates', Pages::administration(...));
    }
}
