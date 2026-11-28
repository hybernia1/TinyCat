<?php
declare(strict_types=1);

namespace TinyCat\Support;

use TinyCat\Core\Modules\DeploymentIdentity;

/** Disposable local generations; HTTP only reads them, never compiles a selection. */
final class PreparedAssets
{
    /** @var array<string, array<mixed>> Immutable sections loaded in this request. */
    private static array $parts = [];

    /** Read only a requested section; no arguments preserve the previous complete API.
     * @param array<mixed>|null $snapshot
     * @return array<mixed> */
    public static function read(?string $section = null, ?string $locale = null, ?array $snapshot = null): array
    {
        if (!class_exists(\TinyCat\Core\Modules\Resources::class) || \TinyCat\Core\Modules\Resources::isScoped()) { return []; }
        if ((bool) Config::get('assets.source', false)) { return []; }
        $key = DeploymentIdentity::key();
        return Assets::synchronized(static function () use ($key, $section, $locale, $snapshot): array {
            $file = Assets::file($key . '.json', 'prepared-assets');
            clearstatcache(true, $file);
            if (!is_file($file) || is_link($file) || filemtime($file) < time() - 30 * 86400) { return []; }
            // Re-read the small index under the lock: a same-identity publication may
            // replace its references before cleanup, even within one timestamp tick.
            $index = $snapshot ?? self::index();
            if ($index === []) { return []; }
            if (filemtime($file) < time() - 3600 && !touch($file)) { throw new \RuntimeException('Cannot renew prepared asset retention.'); }
            if ($section === 'packages') { return $index['packages']; }
            if ($section !== null) {
                $name = $locale === null ? ($index['parts'][$section] ?? null) : ($index['parts']['catalogs'][$locale][$section] ?? null);
                return self::part($name);
            }
            $complete = $index;
            foreach (['manifest', 'components'] as $field) { $complete[$field] = self::part($index['parts'][$field] ?? null); }
            foreach ($index['parts']['catalogs'] ?? [] as $language => $channels) {
                foreach ($channels as $channel => $name) { $complete['catalogs'][$language][$channel] = self::part($name); }
            }
            return $complete;
        }, shared: true);
    }

    /** Admission metadata only; the readers pin this immutable index for one request.
     * @return array<mixed> */
    public static function index(): array
    {
        $key = DeploymentIdentity::key();
        $file = Assets::file($key . '.json', 'prepared-assets');
        clearstatcache(true, $file);
        if (!is_file($file) || is_link($file) || filemtime($file) < time() - 30 * 86400) { return []; }
        $index = self::document($key . '.json');
        if (($index['format'] ?? null) !== 3 || ($index['identity'] ?? null) !== $key
            || ($index['release'] ?? null) !== DeploymentIdentity::release()['anchor']
            || !DeploymentIdentity::matchesSelection($index)
            || !is_array($index['packages'] ?? null) || !is_array($index['parts'] ?? null)) { return []; }
        return $index;
    }

    /** @return array<mixed> */
    private static function part(mixed $name): array
    {
        if (!is_string($name) || preg_match('/^[a-f0-9]{64}\.part\.json$/D', $name) !== 1) { return []; }
        return self::$parts[$name] ??= self::document($name);
    }

    /** Non-executable private data, bounded and never followed through a symlink.
     * @return array<mixed> */
    private static function document(string $name): array
    {
        $file = Assets::file($name, 'prepared-assets');
        if (!is_file($file) || is_link($file) || filesize($file) > 33554432) { return []; }
        $text = file_get_contents($file);
        if ($text === false) { return []; }
        if (str_ends_with($name, '.part.json') && !hash_equals(substr($name, 0, 64), hash('sha256', $text))) { return []; }
        try { $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return []; }
        return is_array($data) ? $data : [];
    }

    /** Prepare a complete selection outside HTTP rendering and publish metadata last. */
    public static function prepare(?string $version = null): void
    {
        // An updater may retain the previous public asset owner after replacing sources.
        // Preparation is optional; a fresh worker uses the release inventory/source fallback.
        // @phpstan-ignore function.alreadyNarrowedType (Supported updates can retain the previous class definition.)
        if (!method_exists(Assets::class, 'file')) {
            if ($version === null) { throw new \RuntimeException('Asset preparation requires a fresh PHP process.'); }
            return;
        }
        if (!in_array('release', get_class_methods(DeploymentIdentity::class), true)) {
            if ($version === null) { throw new \RuntimeException('Asset preparation requires a fresh PHP process.'); }
            return;
        }
        if (!in_array('canPrepare', get_class_methods(DeploymentIdentity::class), true)
            || !DeploymentIdentity::canPrepare() || ($version !== null && $version !== Version::CURRENT)) {
            if ($version === null) { throw new \RuntimeException('Asset preparation requires a fresh PHP process. Run the assets command again.'); }
            return;
        }
        // An updater can still have the previous Version class loaded in PHP.
        $key = DeploymentIdentity::key($version);
        $release = DeploymentIdentity::release(true)['anchor'];
        $compiled = AssetCompiler::compile(emit: static function (string $prefix, string $source, string $type): string {
            return Minifier::cachedAssetUrl('prepared-' . $prefix, 'prepared-' . $prefix, $source, $type, false)
                ?? throw new \RuntimeException('Cannot publish prepared asset.');
        });
        if (!isset($compiled['server']['generation'])) {
            if ($version === null) { throw new \RuntimeException('Asset preparation requires a fresh PHP process.'); }
            return;
        }
        $compiled['manifest']['version'] = $compiled['server']['version'] = $version ?? Version::CURRENT;
        if ($release !== DeploymentIdentity::release(true)['anchor']) { throw new \RuntimeException('Release inventory changed during asset preparation.'); }
        if ($key !== DeploymentIdentity::key($version)) { throw new \RuntimeException('Asset selection changed during preparation.'); }
        // Immutable parts first, small selection index last, under the cleanup lock.
        Assets::synchronized(static function () use ($key, $compiled, $release): void {
            $publish = static function (array $data): string {
                $text = json_encode($data, JSON_THROW_ON_ERROR);
                $name = hash('sha256', $text) . '.part.json';
                if (!Assets::writeFile($name, $text, 'prepared-assets')) { throw new \RuntimeException('Cannot publish prepared asset section.'); }
                return $name;
            };
            $parts = ['manifest' => $publish($compiled['manifest'] + ['_prepared' => true]), 'components' => $publish($compiled['components']), 'catalogs' => []];
            foreach ($compiled['catalogs'] as $locale => $catalog) {
                foreach ($catalog as $channel => $messages) { $parts['catalogs'][$locale][$channel] = $publish($messages); }
            }
            $index = ['format' => 3, 'release' => $release, 'generation' => $compiled['server']['generation'], 'version' => $compiled['server']['version'], 'identity' => $key, 'selection' => $compiled['server']['selection'], 'server_components_packages' => $compiled['server']['server_components_packages'], 'packages' => $compiled['server']['packages'], 'parts' => $parts];
            if (!Assets::writeFile($key . '.json', json_encode($index, JSON_THROW_ON_ERROR), 'prepared-assets')) { throw new \RuntimeException('Cannot publish prepared asset inventory.'); }
        });
    }

    /** @return array{changed: int, has_more: bool, inspected: int} */
    public static function cleanup(int $batch): array
    {
        return Assets::synchronized(static fn (): array => FileSweep::run(Assets::directory('prepared-assets'), $batch, static function (array $files): array {
            $keep = [];
            foreach (glob(Assets::directory('prepared-assets') . '/*.json') ?: [] as $index) {
                if (preg_match('/^[a-f0-9]{64}\.json$/D', basename($index)) !== 1 || is_link($index) || filemtime($index) < time() - 30 * 86400) { continue; }
                $data = self::document(basename($index));
                $parts = $data['parts'] ?? [];
                array_walk_recursive($parts, static function (mixed $name) use (&$keep): void { if (is_string($name)) { $keep[$name] = true; } });
            }
            $changed = 0;
            foreach ($files as $file) {
                if (preg_match('/^[a-f0-9]{64}(?:\.part)?\.json$/D', $file->getFilename()) !== 1) { continue; }
                if (isset($keep[$file->getFilename()])) { continue; }
                clearstatcache(true, $file->getPathname());
                $modified = @filemtime($file->getPathname());
                if ($modified !== false && $modified < time() - 30 * 86400 && FileSweep::remove($file->getPathname())) { $changed++; }
            }
            return ['changed' => $changed, 'has_more' => false];
        }, 'prepared-assets', false));
    }
}
