<?php
declare(strict_types=1);

namespace TinyCat\Support;

use RuntimeException;

/** Module-owned manifests select public client code, definitions and messages. */
final class ClientAssets
{
    /**
     * @return list<array<mixed>>
     */
    private static function features(string $path): array
    {
        $features = [];
        foreach (glob(Paths::basePath('assets/client/*.json')) ?: [] as $file) {
            foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $feature) {
                foreach ($feature['routes'] as $route) {
                    $matches = match ($route) {
                        '*' => true,
                        'public' => $path !== '/admin' && !str_starts_with($path, '/admin/') && $path !== '/install' && !str_starts_with($path, '/install/'),
                        'admin' => $path === '/admin' || str_starts_with($path, '/admin/'),
                        default => $path === $route || str_starts_with($path, $route . '/'),
                    };
                    if ($matches) { $features[] = $feature; break; }
                }
            }
        }
        return $features;
    }

    /** @return array<string, mixed> */
    public static function definitions(string $path): array
    {
        $roots = [];
        foreach (self::features($path) as $feature) {
            foreach ($feature['components'] ?? [] as $root) { $roots[] = (string) $root; }
        }
        return Components::select(array_values(array_unique($roots)));
    }

    /** @return array<string, string> */
    public static function catalog(string $path, string $locale): array
    {
        $dictionary = Localization::catalog($locale);
        $keys = array_fill_keys(Components::labels(self::definitions($path)), true);
        foreach (self::features($path) as $feature) {
            foreach ($feature['translations'] ?? [] as $key) { $keys[(string) $key] = true; }
            foreach ($feature['translation_sources'] ?? [] as $source) {
                $code = (string) file_get_contents(Paths::basePath((string) $source));
                preg_match_all('/["\']([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)["\']/i', $code, $matches);
                foreach ($matches[1] as $key) {
                    if (!array_key_exists($key, $dictionary)) { continue; }
                    $prefixes = $feature['translation_prefixes'] ?? [];
                    if ($prefixes !== [] && !array_any($prefixes, static fn (string $prefix): bool => str_starts_with($key, $prefix))) { continue; }
                    $keys[$key] = true;
                }
            }
        }
        $catalog = [];
        foreach ($keys as $key => $_) {
            if (!array_key_exists($key, $dictionary)) { throw new RuntimeException('Missing client translation: ' . $key); }
            $catalog[$key] = $dictionary[$key];
        }
        ksort($catalog);
        return $catalog;
    }

    /**
     * @param list<string> $extraScripts
     * @return list<string>
     */
    public static function scripts(string $path, array $extraScripts = ['js/tinycat.js']): array
    {
        $locale = Localization::locale();
        $core = ['api-client', 'dom', 'forms', 'i18n', 'modal', 'ui', 'components'];
        $urls = array_map(static fn (string $name): string => Assets::asset('js/core/' . $name . '.js'), $core);
        $encode = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $urls[] = self::bundle('components', 'window.TinyCat.components.register(' . $encode((object) self::definitions($path)) . ');');
        $urls[] = self::bundle('i18n-' . strtolower($locale), 'window.TinyCat.i18n.register(' . $encode($locale) . ',' . $encode((object) self::catalog($path, $locale)) . ');');
        $scripts = [];
        foreach (self::features($path) as $feature) {
            foreach ($feature['scripts'] ?? [] as $script) { $scripts[] = (string) $script; }
        }
        foreach (array_unique([...$scripts, ...$extraScripts]) as $script) { $urls[] = Assets::asset($script); }
        return $urls;
    }

    private static function bundle(string $kind, string $source): string
    {
        $file = 'client-' . $kind . '.' . substr(hash('sha256', $source), 0, 24) . '.js';
        $target = Cache::file($file, 'assets');
        if (!is_file($target) && !Cache::writeFile($file, $source, 'assets')) { throw new RuntimeException('Could not write client asset: ' . $file); }
        // Keep older hashes: an already-rendered page may still need its original assets.
        return '/cache/assets/' . $file;
    }
}
