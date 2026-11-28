<?php
declare(strict_types=1);

namespace TinyCat\Support;

use TinyCat\Core\Modules\DeploymentIdentity;

/** Read the single installed build; HTTP never compiles or publishes assets. */
final class CompiledAssets
{
    /** @return array<mixed> */
    private static function manifest(?string $locale = null, ?string $path = null): array
    {
        if (class_exists(\TinyCat\Core\Modules\Resources::class, false) && \TinyCat\Core\Modules\Resources::isScoped()) { return []; }
        if (!in_array('inventory', get_class_methods(DeploymentIdentity::class), true)) { return []; }
        return DeploymentIdentity::inventory($locale ?? (class_exists(Config::class) ? Localization::locale() : 'en'), $path)['assets'] ?? [];
    }

    public static function asset(string $path, bool $minify): ?string
    {
        $manifest = self::manifest();
        $url = $manifest['assets'][$path][$minify ? 'min' : 'raw'] ?? null;
        if (!is_string($url)) { if ($manifest !== []) { DeploymentIdentity::reject(); } return null; }
        if (!DeploymentIdentity::availableUrl($url)) { DeploymentIdentity::reject(); return null; }
        DeploymentIdentity::delivered();
        return $url;
    }

    /** @return array<string, mixed>|null */
    public static function components(): ?array
    {
        $manifest = self::manifest();
        $name = $manifest['server_components'] ?? null;
        if ($name === null) { return null; }
        if (!is_string($name) || preg_match('/^components\.[a-f0-9]{24}\.php$/D', $name) !== 1) { throw new \RuntimeException('Invalid compiled component path.'); }
        $file = Paths::basePath('App/Core/Support/compiled/' . $name);
        if (!is_file($file)) { DeploymentIdentity::reject(); return null; }
        $components = require $file;
        if (!is_array($components)) { DeploymentIdentity::reject(); return null; }
        DeploymentIdentity::delivered();
        return $components;
    }

    /** @return array{scripts: list<string>, style: string, deferred: array<string, mixed>}|null */
    public static function client(string $path, string $locale, bool $minifyJs = false, bool $minifyCss = false): ?array
    {
        foreach (self::manifest($locale, $path)['clients'] ?? [] as $prefix => $client) {
            if ($prefix !== '/' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) { continue; }
            if (!isset($client['catalogs'][$locale])) { return null; }
            $js = $minifyJs ? 'min' : 'raw';
            $key = $js . '-' . ($minifyCss ? 'min' : 'raw');
            $catalog = $client['catalog_variants'][$locale][$key] ?? $client['catalogs'][$locale];
            DeploymentIdentity::delivered();
            return ['scripts' => [$client['runtime'][$js], $client['components'], $catalog, $client['app'][$js]], 'style' => $client['styles'][$minifyCss ? 'min' : 'raw'], 'deferred' => $client['deferred'][$locale][$key] ?? []];
        }
        return null;
    }
}
