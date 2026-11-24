<?php
declare(strict_types=1);
namespace TinyCat\Fixture\Files;
use TinyCat\Core\Modules\{Contributions, MaintenanceTask};
final class Module implements \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Modules\ProvidesFileReferences, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Modules\Module
{
    public static function initialize(): void { throw new \RuntimeException('Inactive initialization must never execute.'); }
    public static function register(\TinyCat\Http\Api $api): void { throw new \RuntimeException('Inactive routes must never register.'); }
    public static function contributions(): Contributions {
        return new Contributions(maintenance: [
            new MaintenanceTask('fixture_file_orphans', static function (int $batch): array {
                $GLOBALS['fixture_file_runs'] = ($GLOBALS['fixture_file_runs'] ?? 0) + 1;
                return \TinyCat\Support\FileSweep::run(\TinyCat\Support\Paths::basePath('uploads/fixture'), $batch, static function (array $files): array {
                    $candidates = [];
                    foreach ($files as $file) { if ($file->getMTime() < time() - 3600) { $candidates[$file->getFilename()] = $file->getPathname(); } }
                    $retained = \TinyCat\Core\Files\References::retained('fixture.files', array_keys($candidates));
                    $changed = 0;
                    foreach (array_diff(array_keys($candidates), $retained) as $name) { if (\TinyCat\Support\FileSweep::remove($candidates[$name])) { $changed++; } }
                    return ['changed' => $changed, 'has_more' => false];
                });
            }, whenInactive: true),
            new MaintenanceTask('fixture_active_only', static function (int $batch): array { $GLOBALS['fixture_regular_runs'] = ($GLOBALS['fixture_regular_runs'] ?? 0) + 1; return ['changed' => 0, 'has_more' => false]; }),
        ]);
    }
    public static function fileReferences(string $group, array $candidates): array {
        if ($group !== 'fixture.files') { return []; }
        return array_values(array_intersect($candidates, array_column(\TinyCat\Database\Connection::all('SELECT path FROM fixture_files'), 'path')));
    }
}
