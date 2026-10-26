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
        if (self::$manifest !== null) { return self::$manifest; }
        $file = Paths::basePath('assets/build/manifest.json');
        return self::$manifest = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public static function asset(string $path, bool $minify): ?string
    {
        return self::manifest()['assets'][$path][$minify ? 'min' : 'raw'] ?? null;
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
