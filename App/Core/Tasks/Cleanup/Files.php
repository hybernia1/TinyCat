<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks\Cleanup;

use TinyCat\Support\FileSweep;
use TinyCat\Support\Cache;

final class Files
{
    /** Only disposable temporary formats; avatar recovery backups are deliberately excluded.
     * @return array{changed: int, has_more: bool, inspected: int} */
    public static function temporary(int $limit): array
    {
        $directories = array_values(array_unique(array_map(static fn (\TinyCat\Core\Modules\TemporaryDirectory $entry): string => ($entry->directory)(), \TinyCat\Core\Modules\Registrations::current()->entries->temporaryDirectories)));
        $remaining = max(1, $limit);
        $rotationKey = 'temporary-cleanup-directory-' . hash('sha256', implode("\0", $directories));
        $rotate = $remaining < count($directories);
        $offset = $rotate ? max(0, (int) Cache::get($rotationKey, 86400)) % count($directories) : 0;
        $pending = [...array_slice($directories, $offset), ...array_slice($directories, 0, $offset)];
        $changed = 0;
        $inspected = 0;
        $blocked = false;
        while ($pending !== [] && $remaining > 0) {
            $round = $pending;
            $pending = [];
            foreach ($round as $index => $directory) {
                if ($remaining === 0) { $pending = [...$pending, ...array_slice($round, $index)]; break; }
                // Record the successor before work, including for single-entry budgets.
                if ($rotate) { Cache::put($rotationKey, ((int) array_search($directory, $directories, true) + 1) % count($directories), 86400, 0); }
                $budget = max(1, intdiv($remaining, count($round) - $index));
                $result = FileSweep::run($directory, $budget, static function (array $files): array {
                    $changed = 0;
                    foreach ($files as $file) {
                        if (preg_match('/^\.(?:image-|branding-|tinycat-write-|health-probe-|avatar-(?!backup-))[A-Za-z0-9]+$/D', $file->getFilename()) !== 1) { continue; }
                        clearstatcache(true, $file->getPathname());
                        $modified = @filemtime($file->getPathname());
                        if ($modified !== false && $modified < time() - 172800 && FileSweep::remove($file->getPathname())) { $changed++; }
                    }
                    return ['changed' => $changed, 'has_more' => false];
                }, 'temporary');
                $changed += $result['changed'];
                $inspected += $result['inspected'];
                $remaining -= $result['inspected'];
                if ($result['has_more']) {
                    // A busy cursor gets another invocation, never a spin in this one.
                    if ($result['inspected'] === 0) { $blocked = true; }
                    else { $pending[] = $directory; }
                }
            }
        }
        return ['changed' => $changed, 'has_more' => $blocked || $pending !== [], 'inspected' => $inspected];
    }
}
