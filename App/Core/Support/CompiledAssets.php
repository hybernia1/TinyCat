<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** Signed release inventory; development checkouts can use the source fallback. */
final class CompiledAssets
{
    /** @var array<mixed>|null */
    private static ?array $manifest = null;

    /** @return array<mixed> */
    private static function manifest(): array
    {
        if (class_exists(\TinyCat\Core\Modules\Resources::class, false) && \TinyCat\Core\Modules\Resources::isScoped()) { return []; }
        $file = Paths::basePath('assets/build/manifest.json');
        self::$manifest ??= is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        if (isset(self::$manifest['selection'])) {
            if (class_exists(\TinyCat\Core\Modules\Resources::class)) {
                if (self::$manifest['selection'] !== \TinyCat\Core\Modules\Resources::fingerprint()) { return []; }
            } elseif (is_file(Paths::basePath('storage/deployment.json'))) { return []; }
        }
        if (!isset(self::$manifest['selection']) && is_file(Paths::basePath('storage/deployment.json'))) { return []; }
        return self::$manifest ?? [];
    }

    public static function asset(string $path, bool $minify): ?string
    {
        return self::manifest()['assets'][$path][$minify ? 'min' : 'raw'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function components(): ?array
    {
        $manifest = self::manifest();
        $name = $manifest['server_components'] ?? null;
        if ($name === null) { return null; }
        // Package upgrades can change definitions without changing selected module IDs.
        $packages = Paths::basePath('App/module-packages.json');
        $revision = is_file($packages) ? hash_file('sha256', $packages) : null;
        if ($revision === false || ($manifest['server_components_packages'] ?? null) !== $revision) { return null; }
        if (!is_string($name) || preg_match('/^components\.[a-f0-9]{24}\.php$/D', $name) !== 1) { throw new \RuntimeException('Invalid compiled component path.'); }
        return require Paths::basePath('App/Core/Support/compiled/' . $name);
    }

    /** @return array{scripts: list<string>, style: string}|null */
    public static function client(string $path, string $locale, bool $minifyJs = false, bool $minifyCss = false): ?array
    {
        foreach (self::manifest()['clients'] ?? [] as $prefix => $client) {
            if ($prefix !== '/' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) { continue; }
            if (!isset($client['catalogs'][$locale])) { return null; }
            $js = $minifyJs ? 'min' : 'raw';
            return ['scripts' => [$client['runtime'][$js], $client['components'], $client['catalogs'][$locale], $client['app'][$js]], 'style' => $client['styles'][$minifyCss ? 'min' : 'raw']];
        }
        return null;
    }
}
