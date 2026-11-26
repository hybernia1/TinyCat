<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** Disposable local generations; HTTP only reads them, never compiles a selection. */
final class PreparedAssets
{
    /** @var array<string, array<mixed>> */
    private static array $snapshots = [];

    /** @return array<mixed> */
    public static function read(): array
    {
        if (!class_exists(\TinyCat\Core\Modules\Resources::class) || \TinyCat\Core\Modules\Resources::isScoped()) { return []; }
        if ((bool) Config::get('assets.source', false)) { return []; }
        $key = DeploymentIdentity::key();
        if (isset(self::$snapshots[$key])) { return self::$snapshots[$key]; }
        $file = Cache::file($key . '.json', 'prepared-assets');
        $snapshot = Cache::synchronized('runtime-assets', static function () use ($file, $key): array {
            if (!is_file($file) || is_link($file)) { return []; }
            clearstatcache(true, $file);
            // An expired generation must not resurrect assets already eligible for deletion.
            if (filemtime($file) < time() - 30 * 86400 || filesize($file) > 33554432) { return []; }
            try { $snapshot = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return []; }
            if (!is_array($snapshot) || ($snapshot['format'] ?? null) !== 1 || ($snapshot['identity'] ?? null) !== $key) { return []; }
            foreach (['manifest', 'packages', 'components', 'catalogs'] as $field) { if (!is_array($snapshot[$field] ?? null)) { return []; } }
            if (($snapshot['manifest']['_prepared'] ?? null) !== true || !DeploymentIdentity::matchesSelection($snapshot['manifest'])) { return []; }
            if (filemtime($file) < time() - 3600 && !touch($file)) { throw new \RuntimeException('Cannot renew prepared asset retention.'); }
            return $snapshot;
        }, shared: true);
        if ($snapshot === []) { return []; }
        return self::$snapshots[$key] = $snapshot;
    }

    /** Prepare a complete selection outside HTTP rendering and publish metadata last. */
    public static function prepare(?string $version = null): void
    {
        // An updater can still have the previous Version class loaded in PHP.
        $key = DeploymentIdentity::key($version);
        $compiled = AssetCompiler::compile(emit: static function (string $prefix, string $source, string $type): string {
            return Minifier::cachedAssetUrl('prepared-' . $prefix, 'prepared-' . $prefix, $source, $type, false)
                ?? throw new \RuntimeException('Cannot publish prepared asset.');
        });
        if ($key !== DeploymentIdentity::key($version)) { throw new \RuntimeException('Asset selection changed during preparation.'); }
        $snapshot = ['format' => 1, 'identity' => $key, 'manifest' => $compiled['manifest'] + ['_prepared' => true], 'packages' => $compiled['server']['packages'], 'components' => $compiled['components'], 'catalogs' => $compiled['catalogs']];
        // This is private data, never executable cache PHP or a replacement signed inventory.
        Cache::synchronized('runtime-assets', static function () use ($key, $snapshot): void {
            if (!Cache::writeFile($key . '.json', json_encode($snapshot, JSON_THROW_ON_ERROR), 'prepared-assets')) { throw new \RuntimeException('Cannot publish prepared asset inventory.'); }
        });
        unset(self::$snapshots[$key]);
    }

    /** @return array{changed: int, has_more: bool, inspected: int} */
    public static function cleanup(int $batch): array
    {
        return Cache::synchronized('runtime-assets', static fn (): array => FileSweep::run(Cache::directory('prepared-assets'), $batch, static function (array $files): array {
            $changed = 0;
            foreach ($files as $file) {
                if (preg_match('/^[a-f0-9]{64}\.json$/D', $file->getFilename()) !== 1) { continue; }
                clearstatcache(true, $file->getPathname());
                $modified = @filemtime($file->getPathname());
                if ($modified !== false && $modified < time() - 30 * 86400 && FileSweep::remove($file->getPathname())) { $changed++; }
            }
            return ['changed' => $changed, 'has_more' => false];
        }, 'prepared-assets', false));
    }
}
