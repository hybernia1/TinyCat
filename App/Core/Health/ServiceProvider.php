<?php
declare(strict_types=1);
namespace TinyCat\Core\Health;
use TinyCat\Core\Health\Checks;
use TinyCat\Core\Health\Service;

final class ServiceProvider implements \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesContributions
{
    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        $probes = [];
        foreach (Checks::localDefinitions() as $id => $run) { $probes[] = new \TinyCat\Core\Modules\HealthProbe($id, $run, count($probes) * 10); }
        return new \TinyCat\Core\Modules\Contributions(temporaryDirectories: [
            new \TinyCat\Core\Modules\TemporaryDirectory('health-uploads', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/.health-probes'), 50),
            new \TinyCat\Core\Modules\TemporaryDirectory('health-storage', static fn (): string => \TinyCat\Support\Paths::basePath('storage/health-probes'), 60),
        ],
            tasks: [new \TinyCat\Core\Modules\ScheduledTask('site-health', static fn (array $context): array => Service::run($context),
                admin: ['icon' => 'shield', 'title' => 'health.title', 'help' => 'health.intro', 'schedule' => 'health.schedule'], order: 30)],
            navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.health.admin', static fn (): array => self::adminNavigation(), 60)],
            health: $probes,
            notificationTypes: \TinyCat\Core\Health\Alerts::types(),
        );
    }

    /** @return list<string> */
    public static function tables(): array { return []; }

    /** @return array<string, mixed> */
    public static function adminNavigation(): array
    {
        return ['href' => '/admin/site-health', 'icon' => 'shield', 'label' => \TinyCat\Support\Localization::t('health.title')];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/site-health', Pages::show(...));
    }
}
