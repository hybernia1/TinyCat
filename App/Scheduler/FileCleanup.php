<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Support\FileSweep;
use TinyCat\Support\Paths;
use TinyCat\Support\Cache;

final class FileCleanup
{
    /** Only disposable temporary formats; avatar recovery backups are deliberately excluded.
     * @return array{changed: int, has_more: bool} */
    public static function temporary(int $limit): array
    {
        $directories = [Paths::basePath('uploads/avatars'), Paths::basePath('uploads/status-images'), Paths::basePath('uploads/links'), Paths::basePath('uploads/site'), Paths::basePath('uploads/.health-probes'), Paths::basePath('storage/health-probes'), Cache::directory()];
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
