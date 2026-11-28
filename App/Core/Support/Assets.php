<?php
declare(strict_types=1);
namespace TinyCat\Support;

/** Owned source paths are resolved exclusively through the installed build. */
final class Assets
{
    public static function asset(string $path): string
    {
        AssetGraph::validate($path);
        $minify = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'css' => ClientAssets::option('performance.minify_css'),
            'js' => ClientAssets::option('performance.minify_js'),
            default => false,
        };
        return CompiledAssets::asset($path, $minify)
            ?? throw new \TinyCat\Http\HttpException('Asset build is unavailable. Run php App/Console.php assets.', 503, 'asset_inventory_unavailable');
    }

    /** Retire only recognized legacy outputs after the last old generation's grace period.
     * No current reader or publisher uses these directories.
     * @return array{changed: int, has_more: bool} */
    public static function cleanup(int $batch): array { return self::legacy($batch, 'assets', '/^[a-z0-9_-]{1,120}\.[a-f0-9]{20}(?:\.min)?\.(?:css|js|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf)$/D'); }

    /** @return array{changed: int, has_more: bool} */
    public static function cleanupPrepared(int $batch): array { return self::legacy($batch, 'prepared-assets', '/^[a-f0-9]{64}(?:\.part)?\.json$/D'); }

    /** @return array{changed: int, has_more: bool} */
    private static function legacy(int $batch, string $namespace, string $pattern): array
    {
        $manifest = Paths::basePath('assets/build/manifest.json');
        if (!is_file($manifest)) { return ['changed' => 0, 'has_more' => false]; }
        $root = getenv('TINYCAT_RUNTIME_ASSET_DIRECTORY') ?: Storage::path('cache');
        $directory = $root . '/' . $namespace;
        if (!is_dir($directory) || is_link($directory)) { return ['changed' => 0, 'has_more' => false]; }
        $retired = $directory . '/.retired-at';
        if (is_link($retired)) { throw new \RuntimeException('Unsafe legacy asset retirement marker.'); }
        if (!is_file($retired) && !Storage::write($retired, (string) time())) { throw new \RuntimeException('Cannot mark legacy asset retirement.'); }
        $started = trim((string) file_get_contents($retired));
        if (!ctype_digit($started)) { throw new \RuntimeException('Invalid legacy asset retirement marker.'); }
        if ((int) $started > time() - 30 * 86400) { return ['changed' => 0, 'has_more' => false]; }
        $result = FileSweep::run($directory, $batch, static function (array $files) use ($pattern): array {
            $changed = 0;
            foreach ($files as $file) {
                if (preg_match($pattern, $file->getFilename()) === 1 && $file->getMTime() < time() - 30 * 86400 && FileSweep::remove($file->getPathname())) { $changed++; }
            }
            return ['changed' => $changed, 'has_more' => false];
        }, 'legacy-assets', false);
        if (!$result['has_more']) {
            $empty = true;
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if (!$entry instanceof \SplFileInfo) { throw new \RuntimeException('Invalid legacy asset directory entry.'); }
                if ($entry->getFilename() !== '.retired-at') { $empty = false; break; }
            }
            if ($empty && FileSweep::remove($retired)) { @rmdir($directory); }
        }
        return $result;
    }
}
