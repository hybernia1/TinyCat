<?php
declare(strict_types=1);

namespace TinyCat\Support;

use RuntimeException;

/** Module-owned manifests select public client code, definitions and messages. */
final class ClientAssets
{
    private static ?\Closure $option = null;

    /** @param \Closure(string): bool $option */
    public static function configure(\Closure $option): void { self::$option = $option; }

    public static function option(string $key): bool
    {
        return self::$option !== null ? (self::$option)($key) : (bool) Config::get($key, false);
    }

    private static int $selection = -1;

    private static function synchronize(): void
    {
        $revision = \TinyCat\Core\Modules\Resources::revision();
        if (self::$selection === $revision) { return; }
        self::$features = [];
        if (self::$buildDefinitions !== null) { self::$buildDefinitions = []; }
        if (self::$buildKeys !== null) { self::$buildKeys = []; }
        self::$selection = $revision;
    }
    /** @var array<string, list<array<mixed>>> */
    private static array $features = [];
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $buildDefinitions = null;
    /** @var array<string, array<string, bool>>|null */
    private static ?array $buildKeys = null;

    /**
     * @template T
     * @param callable(): T $build
     * @return T
     */
    public static function withBuildCache(callable $build): mixed
    {
        $features = self::$features; $definitions = self::$buildDefinitions; $keys = self::$buildKeys; $selection = self::$selection;
        self::$features = []; self::$buildDefinitions = []; self::$buildKeys = [];
        try { return Components::withFreshTemplates($build); }
        finally { self::$features = $features; self::$buildDefinitions = $definitions; self::$buildKeys = $keys; self::$selection = $selection; }
    }
    /**
     * @return list<array<mixed>>
     */
    private static function features(string $path): array
    {
        self::synchronize();
        if (isset(self::$features[$path])) { return self::$features[$path]; }
        $selected = []; $definitions = [];
        foreach (self::manifests() as $file) {
            foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $feature) {
                if (isset($feature['id'])) {
                    $id = $feature['id'];
                    if (!is_string($id) || preg_match('/^[a-z][a-z0-9_.-]*$/D', $id) !== 1 || isset($definitions[$id])) { throw new RuntimeException('Invalid or duplicate client feature: ' . $file); }
                    $definitions[$id] = $feature;
                }
                foreach ($feature['routes'] ?? [] as $route) {
                    $matches = match ($route) {
                        '*' => true,
                        'public' => $path !== '/admin' && !str_starts_with($path, '/admin/') && $path !== '/install' && !str_starts_with($path, '/install/'),
                        'admin' => $path === '/admin' || str_starts_with($path, '/admin/'),
                        default => $path === $route || str_starts_with($path, $route . '/'),
                    };
                    if ($matches) { $selected[] = $feature; break; }
                }
            }
        }
        $features = []; $loaded = []; $visiting = [];
        $visit = static function (array $feature) use (&$visit, &$features, &$loaded, &$visiting, $definitions): void {
            $id = $feature['id'] ?? null;
            if ($id !== null && isset($loaded[$id])) { return; }
            if ($id !== null && isset($visiting[$id])) { throw new RuntimeException('Cyclic client feature: ' . $id); }
            if ($id !== null) { $visiting[$id] = true; }
            foreach ($feature['uses'] ?? [] as $dependency) {
                if (!is_string($dependency) || !isset($definitions[$dependency])) { throw new RuntimeException('Unknown client feature dependency.'); }
                $visit($definitions[$dependency]);
            }
            $features[] = $feature;
            if ($id !== null) { unset($visiting[$id]); $loaded[$id] = true; }
        };
        foreach ($selected as $feature) { $visit($feature); }
        return self::$features[$path] = $features;
    }

    /** @return array<string, mixed> */
    public static function definitions(string $path): array
    {
        self::synchronize();
        if (self::$buildDefinitions !== null && isset(self::$buildDefinitions[$path])) { return self::$buildDefinitions[$path]; }
        $roots = [];
        foreach (self::features($path) as $feature) {
            foreach ($feature['components'] ?? [] as $root) { $roots[] = (string) $root; }
        }
        $definitions = Components::select(array_values(array_unique($roots)));
        if (self::$buildDefinitions !== null) { self::$buildDefinitions[$path] = $definitions; }
        return $definitions;
    }

    /**
     * @param array<string, string>|null $dictionary Validated source data during a build.
     * @return array<string, string>
     */
    public static function catalog(string $path, string $locale, ?array $dictionary = null): array
    {
        self::synchronize();
        $dictionary ??= Localization::catalog($locale);
        $keys = self::$buildKeys[$path] ?? null;
        if ($keys === null) {
            $keys = array_fill_keys(Components::labels(self::definitions($path)), true);
            foreach (self::features($path) as $feature) {
                foreach ($feature['translations'] ?? [] as $key) { $keys[(string) $key] = true; }
                foreach ($feature['messages'] ?? [] as $key) { $keys[(string) $key] = true; }
            }
            if (self::$buildKeys !== null) { self::$buildKeys[$path] = $keys; }
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
        return \TinyCat\Core\Modules\Resources::files('assets');
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        $paths = ['/', '/admin', '/install'];
        foreach (self::manifests() as $file) {
            foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $feature) {
                foreach ($feature['routes'] ?? [] as $route) { if (str_starts_with($route, '/')) { $paths[] = $route; } }
            }
        }
        $paths = array_values(array_unique($paths));
        usort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
        return $paths;
    }

    /** @return list<string> */
    public static function runtime(): array
    {
        return AssetGraph::order(['assets/core/component-renderer.js']);
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
        $minify = self::option('performance.minify_js');
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
        $minify = self::option('performance.minify_css');
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
