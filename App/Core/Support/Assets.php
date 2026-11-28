<?php
declare(strict_types=1);
namespace TinyCat\Support;

/** Owned source paths are published only as content-addressed public outputs. */
final class Assets
{
    public static function asset(string $path): string
    {
        AssetGraph::validate($path);
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $minify = match ($type) {
            'css' => ClientAssets::option('performance.minify_css'),
            'js' => ClientAssets::option('performance.minify_js'),
            default => false,
        };
        $compiled = CompiledAssets::asset($path, $minify);
        if ($compiled !== null) { return $compiled; }
        $source = AssetGraph::read($path);
        if ($type === 'css') { $source = AssetGraph::stylesheet([$path]); }
        if ($type === 'js') { $source = AssetGraph::javascript(AssetGraph::order([$path])); }
        $name = substr((string) preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo($path, PATHINFO_FILENAME)), 0, 100);
        $url = Minifier::cachedAssetUrl($path, 'asset-' . strtolower($name), $source, $type, $minify);
        return $url ?? throw new \RuntimeException('Could not publish owned asset: ' . $path);
    }

    public static function directory(string $namespace = 'assets'): string
    {
        // Test workers explicitly inherit an isolated root; cache.directory never relocates public URLs.
        $root = getenv('TINYCAT_RUNTIME_ASSET_DIRECTORY') ?: Storage::path('cache');
        if ($namespace === '') { return $root; }
        if (!in_array($namespace, ['assets', 'prepared-assets', 'asset-publications', 'coordination'], true)) { throw new \InvalidArgumentException('Invalid runtime asset namespace.'); }
        return $root . DIRECTORY_SEPARATOR . $namespace;
    }

    public static function file(string $name, string $namespace = 'assets'): string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $name) !== 1 || $name === '.' || $name === '..') { throw new \InvalidArgumentException('Invalid runtime asset file name.'); }
        return self::directory($namespace) . DIRECTORY_SEPARATOR . $name;
    }

    public static function writeFile(string $name, string $content, string $namespace = 'assets'): bool
    {
        return Storage::write(self::file($name, $namespace), $content, 0664, true);
    }

    /** @template T
     * @param callable(): T $operation
     * @return T */
    public static function synchronized(callable $operation, bool $shared = false): mixed
    {
        return Cache::synchronized('runtime-assets', $operation, $shared, self::directory('coordination'));
    }

    private const string RUNTIME_NAME = '/^[a-z0-9_-]{1,120}\.[a-f0-9]{20}(?:\.min)?\.(?:css|js|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf)$/D';

    /** @return array{changed: int, has_more: bool} */
    public static function cleanup(int $batch): array
    {
        $directory = Assets::directory('assets');
        if (!is_dir($directory) || is_link($directory)) { return ['changed' => 0, 'has_more' => false]; }
        return FileSweep::run($directory, $batch, static function (array $files) use ($directory): array {
            $cutoff = time() - 30 * 86400;
            $candidates = array_filter($files, static fn (\SplFileInfo $file): bool => preg_match(self::RUNTIME_NAME, $file->getFilename()) === 1 && $file->getMTime() < $cutoff);
            // Most batches contain current outputs: do not enumerate roots or read their graph.
            if ($candidates === []) { return ['changed' => 0, 'has_more' => false]; }
            return self::expireRuntime($directory, $candidates, $cutoff);
        }, 'runtime-assets', false);
    }

    /** @param array<\SplFileInfo> $files
     * @return array{changed: int, has_more: bool} */
    private static function expireRuntime(string $directory, array $files, int $cutoff): array
    {
        $publication = Minifier::publicationRevision();
        if ($publication === false) { throw new \RuntimeException('Unable to inspect asset publication revision.'); }
        $inventories = self::runtimeInventories();
        $keep = []; $pending = [];
        foreach ($inventories as $inventory => $retainedAt) {
            if (is_link($inventory) || $retainedAt < $cutoff) { continue; }
            $text = file_get_contents($inventory);
            if ($text === false) { throw new \RuntimeException('Unable to inspect prepared asset roots.'); }
            preg_match_all('~/(?:storage/)?cache/assets/([a-z0-9_.-]+)~', str_replace('\\/', '/', $text), $references);
            foreach ($references[1] as $name) { if (preg_match(self::RUNTIME_NAME, $name) === 1) { $pending[] = $name; } }
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file instanceof \SplFileInfo) { throw new \RuntimeException('Invalid runtime asset entry.'); }
            if (!$file->isLink() && $file->isFile() && preg_match(self::RUNTIME_NAME, $file->getFilename()) === 1 && $file->getMTime() >= $cutoff) { $pending[] = $file->getFilename(); }
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
            foreach ($matches[1] as $dependency) { if (preg_match(self::RUNTIME_NAME, $dependency) === 1) { $pending[] = $dependency; } }
        }
        return Assets::synchronized(static function () use ($publication, $inventories, $files, $cutoff, $keep): array {
            if (Minifier::publicationRevision() !== $publication || self::runtimeInventories() !== $inventories) { return ['changed' => 0, 'has_more' => true]; }
            $changed = 0;
            foreach ($files as $file) {
                if (isset($keep[$file->getFilename()]) || preg_match(self::RUNTIME_NAME, $file->getFilename()) !== 1) { continue; }
                clearstatcache(true, $file->getPathname());
                $modified = @filemtime($file->getPathname());
                if ($modified !== false && $modified < $cutoff && FileSweep::remove($file->getPathname())) { $changed++; }
            }
            return ['changed' => $changed, 'has_more' => false];
        });
    }
    /** @return array<string, int|false> */
    private static function runtimeInventories(): array
    {
        $inventories = [];
        foreach (glob(Assets::directory('prepared-assets') . '/*.json') ?: [] as $file) {
            if (preg_match('/^[a-f0-9]{64}\.json$/D', basename($file)) !== 1 || is_link($file)) { continue; }
            clearstatcache(true, $file);
            $modified = filemtime($file);
            $inventories[$file] = $modified;
            if ($modified === false || $modified < time() - 30 * 86400) { continue; }
            $data = json_decode((string) file_get_contents($file), true);
            $manifest = $data['parts']['manifest'] ?? null;
            if (in_array($data['format'] ?? null, [2, 3], true) && is_string($manifest) && preg_match('/^[a-f0-9]{64}\.part\.json$/D', $manifest) === 1) {
                $part = Assets::file($manifest, 'prepared-assets');
                if (!is_file($part) || is_link($part)) { throw new \RuntimeException('Missing prepared asset references.'); }
                $inventories[$part] = max($modified, $inventories[$part] ?? 0);
            }
        }
        return $inventories;
    }
}
