<?php
declare(strict_types=1);
namespace TinyCat\Support;

/** Retain runtime outputs from recent publication roots and prepared selections. */
final class RuntimeAssetCleanup
{
    private const string NAME = '/^[a-z0-9_-]{1,120}\.[a-f0-9]{20}(?:\.min)?\.(?:css|js|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf)$/D';

    /** @return array{changed: int, has_more: bool} */
    public static function run(int $batch): array
    {
        $directory = Cache::directory('assets');
        if (!is_dir($directory) || is_link($directory)) { return ['changed' => 0, 'has_more' => false]; }
        $cutoff = time() - 30 * 86400;
        $publication = Minifier::publicationRevision();
        if ($publication === false) { throw new \RuntimeException('Unable to inspect asset publication revision.'); }
        $inventories = self::inventories();
        $keep = []; $pending = [];
        foreach (array_keys($inventories) as $inventory) {
            if (is_link($inventory) || filemtime($inventory) < $cutoff) { continue; }
            $text = file_get_contents($inventory);
            if ($text === false) { throw new \RuntimeException('Unable to inspect prepared asset roots.'); }
            preg_match_all('~/(?:storage/)?cache/assets/([a-z0-9_.-]+)~', str_replace('\\/', '/', $text), $references);
            foreach ($references[1] as $name) { if (preg_match(self::NAME, $name) === 1) { $pending[] = $name; } }
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file instanceof \SplFileInfo) { throw new \RuntimeException('Invalid runtime asset entry.'); }
            if (!$file->isLink() && $file->isFile() && preg_match(self::NAME, $file->getFilename()) === 1 && $file->getMTime() >= $cutoff) { $pending[] = $file->getFilename(); }
        }
        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($keep[$name])) { continue; }
            $keep[$name] = true;
            $path = $directory . '/' . $name;
            if (!in_array(pathinfo($path, PATHINFO_EXTENSION), ['js', 'css'], true) || !is_file($path) || is_link($path)) { continue; }
            if (filesize($path) > 16777216) { throw new \RuntimeException('Runtime asset exceeds cleanup inspection limit.'); }
            $text = @file_get_contents($path);
            if ($text === false) {
                clearstatcache(true, $path);
                if (!file_exists($path)) { continue; }
                throw new \RuntimeException('Unable to inspect runtime asset references.');
            }
            preg_match_all('~/(?:storage/)?cache/assets/([a-z0-9_.-]+)~', str_replace('\\/', '/', $text), $matches);
            foreach ($matches[1] as $dependency) { if (preg_match(self::NAME, $dependency) === 1) { $pending[] = $dependency; } }
        }
        return Cache::synchronized('runtime-assets', static function () use ($publication, $inventories, $directory, $batch, $cutoff, $keep): array {
            if (Minifier::publicationRevision() !== $publication || self::inventories() !== $inventories) { return ['changed' => 0, 'has_more' => true]; }
            return FileSweep::run($directory, $batch, static function (array $files) use ($cutoff, $keep): array {
                $changed = 0;
                foreach ($files as $file) {
                    if (isset($keep[$file->getFilename()]) || preg_match(self::NAME, $file->getFilename()) !== 1) { continue; }
                    clearstatcache(true, $file->getPathname());
                    $modified = @filemtime($file->getPathname());
                    if ($modified !== false && $modified < $cutoff && FileSweep::remove($file->getPathname())) { $changed++; }
                }
                return ['changed' => $changed, 'has_more' => false];
            }, 'runtime-assets', false);
        });
    }
    /** @return array<string, int|false> */
    private static function inventories(): array
    {
        $inventories = [];
        foreach (glob(Cache::directory('prepared-assets') . '/*.json') ?: [] as $file) {
            clearstatcache(true, $file);
            $inventories[$file] = filemtime($file);
        }
        return $inventories;
    }
}
