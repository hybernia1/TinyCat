<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

use TinyCat\Support\FileSweep;

final class FileCleanup
{
    /** Only disposable temporary formats; avatar recovery backups are deliberately excluded.
     * @return array{changed: int, has_more: bool} */
    public static function temporary(int $limit): array
    {
        $directories = array_values(array_unique(array_map(static fn (\TinyCat\Core\Modules\TemporaryDirectory $entry): string => ($entry->directory)(), \TinyCat\Core\Modules\Registrations::current()->entries->temporaryDirectories)));
        $changed = 0;
        $hasMore = false;
        foreach ($directories as $directory) {
            $result = FileSweep::run($directory, max(1, intdiv($limit, count($directories))), static function (array $files): array {
                $changed = 0;
                foreach ($files as $file) {
                    if (preg_match('/^\.(?:image-|tinycat-write-|health-probe-|avatar-(?!backup-))[A-Za-z0-9]+$/D', $file->getFilename()) !== 1) { continue; }
                    clearstatcache(true, $file->getPathname());
                    $modified = @filemtime($file->getPathname());
                    if ($modified !== false && $modified < time() - 172800 && FileSweep::remove($file->getPathname())) { $changed++; }
                }
                return ['changed' => $changed, 'has_more' => false];
            }, 'temporary');
            $changed += $result['changed'];
            $hasMore = $hasMore || $result['has_more'];
        }
        return ['changed' => $changed, 'has_more' => $hasMore];
    }
}
