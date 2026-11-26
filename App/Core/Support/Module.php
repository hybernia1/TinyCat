<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** Technical contributions; no HTTP routes or independent module discovery. */
final class Module implements \TinyCat\Core\Modules\ProvidesContributions
{
    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.core.modules', static fn (): array => ['href' => '/admin/modules', 'icon' => 'settings', 'label' => Localization::t('modules.title')], 65)], temporaryDirectories: [new \TinyCat\Core\Modules\TemporaryDirectory('cache', static fn (): string => Cache::directory(), 70), new \TinyCat\Core\Modules\TemporaryDirectory('module-package-staging', static fn (): string => Paths::basePath('storage/modules/staging'), 90)], maintenance: [
            new \TinyCat\Core\Modules\MaintenanceTask('expired_cache_files', static fn (int $batch): array => Cache::cleanupExpired($batch), 80),
            new \TinyCat\Core\Modules\MaintenanceTask('retired_build_files', BuildCleanup::run(...), 85),
            new \TinyCat\Core\Modules\MaintenanceTask('retired_runtime_assets', RuntimeAssetCleanup::run(...), 86),
            new \TinyCat\Core\Modules\MaintenanceTask('expired_prepared_assets', PreparedAssets::cleanup(...), 87),
            new \TinyCat\Core\Modules\MaintenanceTask('abandoned_module_packages', \TinyCat\Core\Modules\ModulePackage::cleanupAbandoned(...), 87),
            new \TinyCat\Core\Modules\MaintenanceTask('old_debug_logs', \TinyCat\Core\Runtime\Debug::cleanupLogs(...), 88),
        ]);
    }
}
