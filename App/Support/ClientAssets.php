<?php
declare(strict_types=1);

namespace TinyCat\Support;

use RuntimeException;

/** Module-owned manifests select public client code, definitions and messages. */
final class ClientAssets
{
    /** @var array<string, list<array<mixed>>> */
    private static array $features = [];
    /**
     * @return list<array<mixed>>
     */
    private static function features(string $path): array
    {
        if (isset(self::$features[$path])) { return self::$features[$path]; }
        $features = [];
        foreach (self::manifests() as $file) {
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
        return self::$features[$path] = $features;
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

    /**
     * @param array<string, string>|null $dictionary Validated source data during a build.
     * @return array<string, string>
     */
    public static function catalog(string $path, string $locale, ?array $dictionary = null): array
    {
        $dictionary ??= Localization::catalog($locale);
        $keys = array_fill_keys(Components::labels(self::definitions($path)), true);
        foreach (self::features($path) as $feature) {
            foreach ($feature['translations'] ?? [] as $key) { $keys[(string) $key] = true; }
            foreach ($feature['messages'] ?? [] as $key) { $keys[(string) $key] = true; }
        }
        $catalog = [];
        foreach ($keys as $key => $_) {
            if (!array_key_exists($key, $dictionary)) { throw new RuntimeException('Missing client translation: ' . $key); }
            $catalog[$key] = $dictionary[$key];
        }
        ksort($catalog);
        return $catalog;
    }

    /** @return array<string, string> Stable API code => module-owned translation identifier. */
    public static function messages(string $path): array
    {
        $messages = [];
        foreach (self::features($path) as $feature) {
            foreach ($feature['messages'] ?? [] as $code => $key) {
                if (isset($messages[$code]) && $messages[$code] !== $key) { throw new RuntimeException('Conflicting API message code: ' . $code); }
                $messages[$code] = $key;
            }
        }
        ksort($messages);
        return $messages;
    }

    public static function componentScript(string $path): string
    {
        return 'window.TinyCat.components.register(' . self::encode((object) self::definitions($path)) . ');'
            . 'window.TinyCat.api.registerMessages(' . self::encode((object) self::messages($path)) . ');';
    }

    /** @return list<string> */
    public static function manifests(): array
    {
        return [Paths::basePath('assets/ui/module.json'), ...(glob(Paths::basePath('assets/modules/*/module.json')) ?: [])];
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        $paths = ['/', '/admin', '/install'];
        foreach (self::manifests() as $file) {
            foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $feature) {
                foreach ($feature['routes'] as $route) { if (str_starts_with($route, '/')) { $paths[] = $route; } }
            }
        }
        $paths = array_values(array_unique($paths));
        usort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
        return $paths;
    }

    /** @return list<string> */
    public static function runtime(): array
    {
        return AssetGraph::order(['assets/core/components.js']);
    }

    /** @return list<string> */
    public static function entries(string $path): array
    {
        $entries = [];
        foreach (self::features($path) as $feature) {
            foreach ($feature['entries'] ?? [] as $entry) { $entries[] = $entry; }
        }
        return array_values(array_unique([...$entries, 'assets/bootstrap.js']));
    }

    /** @return list<string> */
    public static function sourceStyles(string $path): array
    {
        $styles = [];
        foreach (self::features($path) as $feature) {
            foreach ($feature['styles'] ?? [] as $style) { $styles[] = $style; }
        }
        return array_values(array_unique($styles));
    }

    /** @param list<string> $extraScripts
     * @return list<string>
     */
    public static function scripts(string $path, array $extraScripts = []): array
    {
        $locale = Localization::locale();
        $minify = (bool) \TinyCat\Settings\Store::setting('performance.minify_js', false);
        $compiled = CompiledAssets::client($path, $locale, $minify);
        $urls = $compiled !== null ? $compiled['scripts'] : [
            self::bundle('runtime', AssetGraph::javascript(self::runtime()), 'js', $minify),
            self::bundle('components', self::componentScript($path)),
            self::bundle('i18n-' . strtolower($locale), 'window.TinyCat.i18n.register(' . self::encode($locale) . ',' . self::encode((object) self::catalog($path, $locale)) . ');'),
            self::bundle('app', AssetGraph::javascript(AssetGraph::order(self::entries($path), self::runtime())), 'js', $minify),
        ];
        foreach ($extraScripts as $script) { $urls[] = Assets::asset($script); }
        return $urls;
    }

    /** @return list<string> */
    public static function styles(string $path): array
    {
        $minify = (bool) \TinyCat\Settings\Store::setting('performance.minify_css', false);
        $compiled = CompiledAssets::client($path, Localization::locale(), false, $minify);
        return [$compiled !== null ? $compiled['style'] : self::bundle('styles', AssetGraph::stylesheet(self::sourceStyles($path)), 'css', $minify)];
    }

    /** @return list<string> */
    public static function standalone(): array
    {
        $sources = [];
        foreach (self::manifests() as $file) {
            foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $feature) {
                foreach ($feature['standalone'] ?? [] as $source) {
                    AssetGraph::read($source);
                    if (!in_array(pathinfo($source, PATHINFO_EXTENSION), ['js', 'css'], true)) { throw new RuntimeException('Invalid standalone asset: ' . $source); }
                    $sources[] = $source;
                }
            }
        }
        return array_values(array_unique($sources));
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private static function bundle(string $kind, string $source, string $type = 'js', bool $minify = false): string
    {
        $url = Minifier::cachedAssetUrl('client-' . $kind, 'client-' . $kind, $source, $type, $minify);
        if ($url === null) { throw new RuntimeException('Could not write client asset: ' . $kind); }
        return $url;
    }
}
