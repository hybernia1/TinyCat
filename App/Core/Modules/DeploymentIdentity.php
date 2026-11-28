<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use TinyCat\Support\{Paths, Storage, Version, PreparedAssets, Assets};

/** Shared identity for prepared assets, components and translations. */
final class DeploymentIdentity
{
    /** @var array{revision: int, packages: string|false|null}|null */
    private static ?array $deployment = null;
    private static bool $snapshot = false;
    private static bool $modulesLoaded = false;
    private static string|false|null $loadedPackages = null;

    /** Capture before executing any selected module PHP, including scoped catalogs. */
    public static function captureModules(): void
    {
        if (!self::$modulesLoaded) { self::$loadedPackages = self::current()['packages']; self::$modulesLoaded = true; }
    }

    public static function canPrepare(): bool
    {
        return in_array('guardsGeneration', get_class_methods(ModuleAutoload::class), true)
            && (!self::$modulesLoaded || self::$loadedPackages === self::current()['packages']);
    }

    /** @var array<mixed>|null */
    private static ?array $inventory = null;
    private static int $inventoryRevision = -1;
    /** @var array{assets: array<mixed>, translations: array<mixed>, anchor: string}|null */
    private static ?array $release = null;
    /** @var array<string, true> */
    private static array $checked = [];
    private static bool $delivered = false;
    private static bool $failed = false;

    /** Read-only HTTP requests share one deployment identity; builds and writes stay fresh.
     * @template T
     * @param callable(): T $read
     * @return T */
    public static function withDeploymentSnapshot(callable $read): mixed
    {
        $previous = self::$deployment; $snapshot = self::$snapshot;
        $inventory = self::$inventory; $revision = self::$inventoryRevision; $checked = self::$checked; $delivered = self::$delivered; $failed = self::$failed;
        self::$deployment = null; self::$snapshot = true;
        self::$inventory = null; self::$checked = []; self::$delivered = false; self::$failed = false;
        try { return $read(); }
        finally { self::$deployment = $previous; self::$snapshot = $snapshot; self::$inventory = $inventory; self::$inventoryRevision = $revision; self::$checked = $checked; self::$delivered = $delivered; self::$failed = $failed; }
    }

    /** Shared deployment identity check; readers reject scoped builds before loading.
     * @internal
     * @param array<mixed> $manifest */
    public static function matchesSelection(array $manifest): bool
    {
        if (($manifest['version'] ?? null) !== Version::CURRENT
            || !is_string($manifest['generation'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $manifest['generation']) !== 1
            || !is_string($manifest['selection'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $manifest['selection']) !== 1) { return false; }
        $deployment = self::current();
        if ($deployment['packages'] === false || ($manifest['server_components_packages'] ?? null) !== $deployment['packages']) { return false; }
        if (class_exists(\TinyCat\Core\Modules\Resources::class)) {
            return $manifest['selection'] === \TinyCat\Core\Modules\Resources::fingerprint();
        }
        return !is_file(Storage::path('deployment.json'));
    }

    /** One small, immutable release pair; no source traversal or network operations.
     * The anchor binds a local overlay even to an absent or damaged release pair.
     * @return array{assets: array<mixed>, translations: array<mixed>, anchor: string} */
    public static function release(bool $fresh = false): array
    {
        if (!$fresh && self::$release !== null) { return self::$release; }
        $assetFile = Paths::basePath('assets/build/manifest.json');
        $translationFile = Paths::basePath('App/Core/Support/compiled/translations.php');
        $assetText = is_file($assetFile) ? @file_get_contents($assetFile) : false;
        $translationText = is_file($translationFile) ? @file_get_contents($translationFile) : false;
        if (($assetText === false && is_file($assetFile)) || ($translationText === false && is_file($translationFile))) {
            \TinyCat\Core\Runtime\Failure::report(new \RuntimeException('Cannot read the release asset inventory.'));
        }
        $assets = []; $translations = [];
        try {
            if ($assetText !== false) { $assets = json_decode($assetText, true, 512, JSON_THROW_ON_ERROR); }
            if ($translationText !== false) { $translations = require $translationFile; }
        } catch (\JsonException | \ParseError $error) {
            \TinyCat\Core\Runtime\Failure::report($error);
        }
        return self::$release = ['assets' => is_array($assets) ? $assets : [], 'translations' => is_array($translations) ? $translations : [], 'anchor' => hash('sha256', serialize([$assetText, $translationText]))];
    }

    /** Pin one generation for both readers, including each used page's deferred variants.
     * A rejected inventory uses the existing source fallback for every channel.
     * @return array<mixed> */
    public static function inventory(?string $locale = null, ?string $path = null): array
    {
        $resources = class_exists(Resources::class);
        if (($resources && Resources::isScoped()) || (class_exists(\TinyCat\Support\Config::class) && (bool) \TinyCat\Support\Config::get('assets.source', false))) { return []; }
        $revision = $resources ? Resources::revision() : 0;
        if (self::$inventoryRevision !== $revision) { self::$inventory = null; self::$checked = []; self::$delivered = false; self::$failed = false; self::$inventoryRevision = $revision; }
        if (self::$failed) { self::reject(); }
        $path ??= (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $context = $path . ':' . ($locale ?? '');
        if (self::$inventory !== null) {
            if ($locale !== null && self::$inventory !== [] && !isset(self::$checked[$context])) {
                if (!self::available(self::$inventory, $locale, $path)) {
                    if (self::$delivered) { self::reject(); }
                    self::$inventory = null;
                } else { self::$checked[$context] = true; }
            }
            if (self::$inventory !== null) { return self::$inventory; }
        }
        $release = self::release();
        $a = $release['assets']; $t = $release['translations'];
        $candidate = [];
        if (self::matchesSelection($a) && self::matchesSelection($t) && $a['generation'] === $t['generation']
            && is_array($a['assets'] ?? null) && is_array($a['clients'] ?? null)
            && is_array($t['packages'] ?? null) && is_array($t['catalogs'] ?? null)) {
            $candidate = ['assets' => $a, 'translations' => $t];
        }
        // Older classes retained by an updater must defer optional preparation to fresh code.
        if ($resources && in_array('index', get_class_methods(PreparedAssets::class), true)) {
            $index = PreparedAssets::index();
            if ($index !== [] && ($candidate === [] || $candidate['assets']['generation'] !== $index['generation']
                || ($locale !== null && !self::available($candidate, $locale, $path)))) {
                $manifest = PreparedAssets::read('manifest', null, $index);
                $overlay = ($manifest['generation'] ?? null) === $index['generation'] && ($manifest['version'] ?? null) === $index['version']
                    ? ['assets' => $manifest, 'translations' => $index, 'prepared' => $index] : [];
                if ($overlay !== []) { $candidate = $overlay; }
            }
        }
        // Package metadata may be needed to resolve locale before a payload is requested.
        if ($locale === null) { return self::$inventory = $candidate; }
        if ($candidate !== [] && !self::available($candidate, $locale, $path)) { $candidate = []; }
        self::$checked[$context] = true;
        return self::$inventory = $candidate;
    }

    public static function delivered(): void { self::$delivered = true; }

    /** A fresh request can choose sources; an existing payload cannot change generation. */
    public static function reject(): void
    {
        if (self::$delivered || self::$failed) {
            self::$failed = true;
            throw new \TinyCat\Http\HttpException('Asset inventory is incomplete. Reload the page or repair the installation.', 503, 'asset_inventory_unavailable');
        }
        self::$inventory = [];
    }

    /** @param array<mixed> $inventory */
    private static function available(array $inventory, string $locale, ?string $path): bool
    {
        // Unknown/regional locales retain Localization's existing parent/en fallback.
        if (!array_key_exists($locale, $inventory['translations']['packages'])) { $locale = 'en'; }
        if (isset($inventory['prepared'])) {
            $index = $inventory['prepared'];
            foreach ([$index['parts']['components'] ?? null, $index['parts']['catalogs'][$locale]['app'] ?? null, $index['parts']['catalogs'][$locale]['emails'] ?? null] as $name) {
                if (!is_string($name) || preg_match('/^[a-f0-9]{64}\.part\.json$/D', $name) !== 1 || !is_file(Assets::file($name, 'prepared-assets'))) { return false; }
            }
        } else {
            foreach ([$inventory['assets']['server_components'] ?? null, $inventory['translations']['catalogs'][$locale] ?? null] as $name) {
                if (!is_string($name) || preg_match('/^(?:components|[a-z0-9-]+)\.[a-f0-9]{24}\.php$/D', $name) !== 1) { return false; }
                $file = Paths::basePath('App/Core/Support/compiled/' . $name);
                if (!is_file($file) || is_link($file)) { return false; }
            }
        }
        $path ??= (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        foreach ($inventory['assets']['clients'] ?? [] as $prefix => $client) {
            if (!is_string($prefix) || !is_array($client)) { return false; }
            if ($prefix !== '/' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) { continue; }
            if (!isset($client['catalogs'][$locale])) { return false; }
            foreach (['runtime', 'app', 'styles'] as $field) {
                if (!is_string($client[$field]['raw'] ?? null) || !is_string($client[$field]['min'] ?? null)) { return false; }
            }
            if (!is_string($client['components'] ?? null)) { return false; }
            $deferred = [];
            foreach ($client['deferred'][$locale] ?? [] as $variant) {
                if (!is_array($variant)) { return false; }
                $deferred[] = array_intersect_key($variant, ['script' => true, 'style' => true]);
            }
            // Only this delivery scope and locale; includes raw/min catalog and deferred variants.
            $parts = [$client['runtime'], $client['components'], $client['app'], $client['styles'], $client['catalogs'][$locale], $client['catalog_variants'][$locale] ?? [], $deferred, $client['dependencies'] ?? []];
            $urls = []; $valid = true;
            array_walk_recursive($parts, static function (mixed $url) use (&$urls, &$valid): void {
                if (is_string($url) && str_starts_with($url, '/')) { $urls[$url] = true; }
                elseif ($url !== null) { $valid = false; }
            });
            foreach (array_keys($urls) as $url) { if (!self::availableUrl($url)) { return false; } }
            return $valid;
        }
        return false;
    }

    public static function availableUrl(string $url): bool
    {
        if (preg_match('~^/assets/build/([A-Za-z0-9_-][A-Za-z0-9._-]*\.[a-f0-9]{24}\.(?:js|css|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf))$~D', $url, $match) === 1) { $file = Paths::basePath('assets/build/' . $match[1]); }
        elseif (preg_match('~^/cache/assets/([a-z0-9_-]{1,120}\.[a-f0-9]{20}(?:\.min)?\.(?:js|css|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf))$~D', $url, $match) === 1) { $file = Assets::file($match[1], 'assets'); }
        else { return false; }
        return is_file($file) && !is_link($file);
    }

    public static function key(?string $version = null): string
    {
        return hash('sha256', json_encode([$version ?? Version::CURRENT, \TinyCat\Core\Modules\Resources::fingerprint(), self::current()['packages']], JSON_THROW_ON_ERROR));
    }

    /** @return array{revision: int, packages: string|false|null} */
    private static function current(): array
    {
        $selectionRevision = class_exists(\TinyCat\Core\Modules\Resources::class) ? \TinyCat\Core\Modules\Resources::revision() : 0;
        $deployment = self::$snapshot ? self::$deployment : null;
        if ($deployment === null || $deployment['revision'] !== $selectionRevision) {
            $packages = Paths::basePath('App/module-packages.json');
            $deployment = ['revision' => $selectionRevision, 'packages' => is_file($packages) ? hash_file('sha256', $packages) : null];
            if (self::$snapshot) { self::$deployment = $deployment; }
        }
        return $deployment;
    }

}
