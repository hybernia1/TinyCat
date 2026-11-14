<?php
declare(strict_types=1);

namespace TinyCat\RegistryFixture;

final class Module implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions
{
    public static function register(\TinyCat\Http\Api $api): void { throw new \RuntimeException('Route registration is not part of registry collection.'); }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        $never = static function (): never { throw new \RuntimeException('A metadata callback ran during collection.'); };
        return new \TinyCat\Core\Modules\Contributions(
            tasks: [new \TinyCat\Core\Modules\ScheduledTask('fixture.task', $never, options: ['batch' => 2], modeOptions: ['admin' => ['target']],
                normalize: static fn (array $options): array => array_replace($options, ['batch' => max(1, $options['batch'])]), healthAge: $never)],
            navigation: [new \TinyCat\Core\Modules\NavigationItem('fixture.menu', $never)],
            health: [new \TinyCat\Core\Modules\HealthProbe('fixture.probe', $never)],
            maintenance: [new \TinyCat\Core\Modules\MaintenanceTask('fixture.cleanup', $never)],
        );
    }
}
