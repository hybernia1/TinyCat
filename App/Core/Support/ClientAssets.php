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
        self::$manifests = [];
        if (self::$buildDefinitions !== null) { self::$buildDefinitions = []; }
        if (self::$buildKeys !== null) { self::$buildKeys = []; }
        self::$selection = $revision;
    }
    /** @var array<string, list<array<mixed>>> */
    private static array $features = [];
    /** @var array<string, list<array<mixed>>> */
    private static array $manifests = [];
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
        $features = self::$features; $manifests = self::$manifests; $definitions = self::$buildDefinitions; $keys = self::$buildKeys; $selection = self::$selection;
        self::$features = []; self::$manifests = []; self::$buildDefinitions = []; self::$buildKeys = [];
        try { return Components::withFreshTemplates($build); }
        finally { self::$features = $features; self::$manifests = $manifests; self::$buildDefinitions = $definitions; self::$buildKeys = $keys; self::$selection = $selection; }
    }

    /** One source snapshot per selection; explicit builds always read fresh manifests.
     * @return list<array<mixed>> */
    private static function manifest(string $file): array
    {
        self::synchronize();
        return self::$manifests[$file] ??= json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
    /**
     * @return list<array<mixed>>
     */
    private static function features(string $path, bool $complete = false): array
    {
        self::synchronize();
        $cacheKey = $path . ($complete ? '@complete' : '');
        if (isset(self::$features[$cacheKey])) { return self::$features[$cacheKey]; }
        $selected = []; $definitions = [];
        foreach (self::manifests() as $file) {
            foreach (self::manifest($file) as $feature) {
                if (isset($feature['id'])) {
                    $id = $feature['id'];
                    if (!is_string($id) || preg_match('/^[a-z][a-z0-9_.-]*$/D', $id) !== 1 || isset($definitions[$id])) { throw new RuntimeException('Invalid or duplicate client feature: ' . $file); }
                    $definitions[$id] = $feature;
                }
                foreach ($feature['routes'] ?? [] as $route) {
                    $matches = match ($route) {
                        '*' => true,
                        'access' => \TinyCat\Core\Ui\Surface::forPath($path) === \TinyCat\Core\Ui\Surface::Access,
                        'public' => \TinyCat\Core\Ui\Surface::forPath($path) === \TinyCat\Core\Ui\Surface::PublicWeb,
                        'admin' => \TinyCat\Core\Ui\Surface::forPath($path) === \TinyCat\Core\Ui\Surface::Administration,
                        default => $path === $route || str_starts_with($path, $route . '/'),
                    };
                    if ($matches) { if ($complete || !isset($feature['defer'])) { $selected[] = $feature; } break; }
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
        return self::$features[$cacheKey] = $features;
    }

    /** @return array<string, mixed> */
    public static function definitions(string $path, bool $complete = false): array
    {
        self::synchronize();
        if (self::$buildDefinitions !== null && isset(self::$buildDefinitions[$path . ($complete ? '@complete' : '')])) { return self::$buildDefinitions[$path . ($complete ? '@complete' : '')]; }
        $roots = [];
        foreach (self::features($path, $complete) as $feature) {
            foreach ($feature['components'] ?? [] as $root) { $roots[] = (string) $root; }
            foreach ($feature['admin'] ?? [] as $definition) {
                foreach ([$definition['component'] ?? null, $definition['filter'] ?? null, $definition['editor']['fields'] ?? null] as $component) {
                    if (is_string($component) && $component !== '') { $roots[] = $component; }
                }
            }
        }
        $definitions = Components::select(array_values(array_unique($roots)));
        if (self::$buildDefinitions !== null) { self::$buildDefinitions[$path . ($complete ? '@complete' : '')] = $definitions; }
        return $definitions;
    }

    /**
     * @param array<string, string>|null $dictionary Validated source data during a build.
     * @return array<string, string>
     */
    public static function catalog(string $path, string $locale, ?array $dictionary = null, bool $complete = false): array
    {
        self::synchronize();
        $dictionary ??= Localization::catalog($locale);
        $keys = self::$buildKeys[$path . ($complete ? '@complete' : '')] ?? null;
        if ($keys === null) {
            $keys = array_fill_keys(Components::labels(self::definitions($path, $complete)), true);
            foreach (self::administration($path) as $definition) {
                foreach ([$definition['title'] ?? null, $definition['editor']['title'] ?? null, isset($definition['editor']) ? ($definition['editor']['submit'] ?? 'common.save') : null, isset($definition['editor']['management']) ? 'common.edit' : null] as $key) {
                    if (is_string($key)) { $keys[$key] = true; }
                }
            }
            foreach (self::features($path, $complete) as $feature) {
                foreach ($feature['translations'] ?? [] as $key) { $keys[(string) $key] = true; }
                foreach ($feature['messages'] ?? [] as $key) { $keys[(string) $key] = true; }
            }
            if (self::$buildKeys !== null) { self::$buildKeys[$path . ($complete ? '@complete' : '')] = $keys; }
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
    public static function messages(string $path, bool $complete = false): array
    {
        $messages = [];
        foreach (self::features($path, $complete) as $feature) {
            foreach ($feature['messages'] ?? [] as $code => $key) {
                if (isset($messages[$code]) && $messages[$code] !== $key) { throw new RuntimeException('Conflicting API message code: ' . $code); }
                $messages[$code] = $key;
            }
        }
        ksort($messages);
        return $messages;
    }

    /** @return array<string, array<string, mixed>> */
    public static function administration(?string $path = null): array
    {
        self::synchronize();
        $features = $path !== null ? self::features($path) : (self::$features['@administration'] ??= array_merge(...array_map(self::manifest(...), self::manifests())));
        $result = [];
        foreach ($features as $feature) {
            foreach ($feature['admin'] ?? [] as $id => $definition) {
                if (!is_string($id) || isset($result[$id]) || !is_array($definition)) { throw new RuntimeException('Invalid or duplicate admin definition: ' . $id); }
                $result[$id] = $definition;
            }
        }
        return $result;
    }

    public static function componentScript(string $path): string
    {
        $administration = \TinyCat\Core\Ui\Admin\Definitions::all($path);
        return 'window.TinyCat.components.register(' . self::encode((object) self::definitions($path)) . ');'
            . ($administration !== [] ? 'window.TinyCat.adminDefinitions=' . self::encode((object) $administration) . ';' : '')
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
        $paths = ['/', '/admin', '/install', ...\TinyCat\Core\Ui\Surface::ACCESS_PATHS];
        foreach (self::manifests() as $file) {
            foreach (self::manifest($file) as $feature) {
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
        return AssetGraph::order(['App/Core/Ui/assets/runtime/component-renderer.js']);
    }

    /** @return list<string> */
    public static function entries(string $path, bool $complete = false): array
    {
        $entries = [];
        foreach (self::features($path, $complete) as $feature) {
            foreach ($feature['entries'] ?? [] as $entry) { $entries[] = $entry; }
        }
        foreach (self::features($path, true) as $feature) {
            if (isset($feature['defer'])) { $entries[] = 'App/Core/Ui/assets/runtime/deferred.js'; break; }
        }
        return array_values(array_unique([...$entries, 'App/Core/Ui/assets/bootstrap.js']));
    }

    /** @return list<string> */
    public static function sourceStyles(string $path, bool $complete = false): array
    {
        $styles = [];
        foreach (self::features($path, $complete) as $feature) {
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
        $compiled = CompiledAssets::client($path, $locale, $minify, self::option('performance.minify_css'));
        $urls = $compiled !== null ? $compiled['scripts'] : AssetGraph::withSnapshot(static fn (): array => [
            self::bundle('runtime', AssetGraph::javascript(self::runtime()), 'js', $minify),
            self::bundle('components', self::componentScript($path)),
            self::bundle('i18n-' . strtolower($locale), self::catalogScript($path, $locale)),
            self::bundle('app', AssetGraph::javascript(AssetGraph::order(self::entries($path), self::runtime())), 'js', $minify),
        ]);
        foreach ($extraScripts as $script) { $urls[] = Assets::asset($script); }
        return $urls;
    }

    /** @return list<string> */
    public static function styles(string $path): array
    {
        $minify = self::option('performance.minify_css');
        $compiled = CompiledAssets::client($path, Localization::locale(), false, $minify);
        return [$compiled !== null ? $compiled['style'] : AssetGraph::withSnapshot(static fn (): string => self::bundle('styles', AssetGraph::stylesheet(self::sourceStyles($path)), 'css', $minify))];
    }

    /** @return list<string> */
    public static function standalone(): array
    {
        $sources = [];
        foreach (self::manifests() as $file) {
            foreach (self::manifest($file) as $feature) {
                foreach ($feature['standalone'] ?? [] as $source) {
                    AssetGraph::read($source);
                    if (!in_array(pathinfo($source, PATHINFO_EXTENSION), Minifier::ASSET_TYPES, true)) { throw new RuntimeException('Invalid standalone asset: ' . $source); }
                    $sources[] = $source;
                }
            }
        }
        return array_values(array_unique($sources));
    }

    /** @param array<string, string>|null $dictionary
     * @param array<string, mixed>|null $deferred */
    public static function catalogScript(string $path, string $locale, ?array $dictionary = null, ?array $deferred = null): string
    {
        $deferred ??= self::deferred($path, $locale, null, $dictionary);
        return 'window.TinyCat.i18n.register(' . self::encode($locale) . ',' . self::encode((object) self::catalog($path, $locale, $dictionary)) . ');'
            . ($deferred !== [] ? 'window.TinyCat.deferredAssets=' . self::encode($deferred) . ';' : '');
    }

    /** Build one additive editor payload per delivery scope, excluding the initial graph.
     * @param array<string, string>|null $dictionary
     * @param (callable(string, string, string): (string|array{raw: string, min: string}))|null $emit
     * @param (callable(string): string)|null $asset
     * @return array<string, mixed>
     */
    public static function deferred(string $path, string $locale, ?callable $emit = null, ?array $dictionary = null, ?callable $asset = null): array
    {
        $resources = []; $selectors = [];
        foreach (self::features($path, true) as $feature) {
            if (!isset($feature['defer'])) { continue; }
            foreach (['resources', 'selectors'] as $kind) {
                $values = $feature['defer'][$kind] ?? [];
                if (!is_array($values) || !array_is_list($values)) { throw new RuntimeException('Invalid deferred asset triggers.'); }
                foreach ($values as $value) {
                    if (!is_string($value) || $value === '') { throw new RuntimeException('Invalid deferred asset trigger.'); }
                    if ($kind === 'resources') {
                        if (!str_starts_with($value, '/api/') || str_contains($value, '?') || str_contains($value, '#')) { throw new RuntimeException('Deferred resources require API path prefixes.'); }
                        $resources[] = $value;
                    } else { $selectors[] = $value; }
                }
            }
        }
        if ($resources === [] && $selectors === []) { return []; }
        $emit ??= static fn (string $kind, string $source, string $type): string => self::bundle($kind, $source, $type, self::option($type === 'css' ? 'performance.minify_css' : 'performance.minify_js'));
        $definitions = array_diff_key(self::definitions($path, true), self::definitions($path));
        $catalog = array_diff_key(self::catalog($path, $locale, $dictionary, true), self::catalog($path, $locale, $dictionary));
        $messages = array_diff_key(self::messages($path, true), self::messages($path));
        $source = 'window.TinyCat.components.register(' . self::encode((object) $definitions) . ');'
            . 'window.TinyCat.i18n.register(' . self::encode($locale) . ',' . self::encode((object) $catalog) . ');'
            . 'window.TinyCat.api.registerMessages(' . self::encode((object) $messages) . ');'
            . AssetGraph::javascript(AssetGraph::order(self::entries($path, true), AssetGraph::order(self::entries($path))));
        $styles = array_values(array_diff(self::sourceStyles($path, true), self::sourceStyles($path)));
        return ['resources' => array_values(array_unique($resources)), 'selectors' => array_values(array_unique($selectors)),
            'script' => $emit('editors-' . $locale, $source, 'js'),
            'style' => $styles === [] ? null : $emit('editor-styles', AssetGraph::stylesheet($styles, $asset), 'css')];
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
