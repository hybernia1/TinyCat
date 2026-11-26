<?php
declare(strict_types=1);

namespace TinyCat\Support;

use TinyCat\Core\Modules\DeploymentIdentity;

/** Signed release inventory; development checkouts can use the source fallback. */
final class CompiledAssets
{
    /** @var array<mixed>|null */
    private static ?array $manifest = null;

    /** @return array<mixed> */
    private static function manifest(): array
    {
        if (class_exists(\TinyCat\Core\Modules\Resources::class, false) && \TinyCat\Core\Modules\Resources::isScoped()) { return []; }
        if (class_exists(Config::class, false) && (bool) Config::get('assets.source', false)) { return []; }
        $file = Paths::basePath('assets/build/manifest.json');
        self::$manifest ??= is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        $manifest = self::$manifest ?? [];
        if ($manifest !== [] && DeploymentIdentity::matchesSelection($manifest)) { return $manifest; }
        $prepared = PreparedAssets::read('manifest');
        return isset($prepared['identity']) ? ($prepared['manifest'] ?? []) : $prepared;
    }

    public static function asset(string $path, bool $minify): ?string
    {
        return self::manifest()['assets'][$path][$minify ? 'min' : 'raw'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function components(): ?array
    {
        $manifest = self::manifest();
        if ($manifest['_prepared'] ?? false) {
            $prepared = PreparedAssets::read('components');
            return isset($prepared['identity']) ? ($prepared['components'] ?? null) : ($prepared ?: null);
        }
        $name = $manifest['server_components'] ?? null;
        if ($name === null) { return null; }
        if (!is_string($name) || preg_match('/^components\.[a-f0-9]{24}\.php$/D', $name) !== 1) { throw new \RuntimeException('Invalid compiled component path.'); }
        return require Paths::basePath('App/Core/Support/compiled/' . $name);
    }

    /** @return array{scripts: list<string>, style: string, deferred: array<string, mixed>}|null */
    public static function client(string $path, string $locale, bool $minifyJs = false, bool $minifyCss = false): ?array
    {
        foreach (self::manifest()['clients'] ?? [] as $prefix => $client) {
            if ($prefix !== '/' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) { continue; }
            if (!isset($client['catalogs'][$locale])) { return null; }
            $js = $minifyJs ? 'min' : 'raw';
            $key = $js . '-' . ($minifyCss ? 'min' : 'raw');
            $catalog = $client['catalog_variants'][$locale][$key] ?? $client['catalogs'][$locale];
            return ['scripts' => [$client['runtime'][$js], $client['components'], $catalog, $client['app'][$js]], 'style' => $client['styles'][$minifyCss ? 'min' : 'raw'], 'deferred' => $client['deferred'][$locale][$key] ?? []];
        }
        return null;
    }
}
