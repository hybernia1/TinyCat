<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use TinyCat\Support\{Paths, Storage, Version};

/** One installed build identity for assets, components and translations. */
final class DeploymentIdentity
{
    /** @var array{revision: int, packages: string|false|null}|null */
    private static ?array $deployment = null;
    private static bool $snapshot = false;
    /** @var array<mixed>|null */
    private static ?array $inventory = null;
    private static int $inventoryRevision = -1;
    /** @var array{assets: array<mixed>, translations: array<mixed>}|null */
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
            if ($manifest['selection'] === Resources::fingerprint()) { return true; }
            if (Resources::isScoped()) { return false; }
            // Missing sources suspend PHP providers, but do not change saved activation.
            // Keep the installed build usable for core administration and recovery.
            $root = Paths::basePath();
            $selected = Deployment::selection($root, static fn (): array => require $root . '/App/modules.php');
            $available = Loader::availableSelection($root, $selected);
            if ($available === $selected) { return false; }
            $fingerprint = static function (array $selection): string {
                $ids = [...\TinyCat\Core\Runtime\Framework::CAPABILITIES, ...array_keys($selection)];
                sort($ids, SORT_STRING);
                return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
            };
            return $manifest['selection'] === $fingerprint($selected) && Resources::fingerprint() === $fingerprint($available);
        }
        return !is_file(Storage::path('deployment.json'));
    }

    /** One installed manifest commits public assets, server components and translations together.
     * @return array{assets: array<mixed>, translations: array<mixed>} */
    public static function release(bool $fresh = false): array
    {
        if (!$fresh && self::$release !== null) { return self::$release; }
        $file = Paths::basePath('assets/build/manifest.json');
        $assets = [];
        try {
            if (is_file($file) && !is_link($file)) { $assets = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }
        } catch (\JsonException $error) { \TinyCat\Core\Runtime\Failure::report($error); }
        $assets = is_array($assets) ? $assets : [];
        return self::$release = ['assets' => $assets, 'translations' => is_array($assets['translations'] ?? null) ? $assets['translations'] : []];
    }

    /** Pin one generation for both readers, including each used page's deferred variants.
     * Missing builds are reported by client delivery; readers never publish a fallback.
     * @return array<mixed> */
    public static function inventory(?string $locale = null, ?string $path = null): array
    {
        $resources = class_exists(Resources::class);
        if ($resources && Resources::isScoped()) { return []; }
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
        // Package metadata may be needed to resolve locale before a payload is requested.
        if ($candidate === [] && PHP_SAPI !== 'cli') { self::reject(); throw new \TinyCat\Http\HttpException('Asset build is unavailable. Run php App/Console.php assets.', 503, 'asset_inventory_unavailable'); }
        if ($locale === null) { return self::$inventory = $candidate; }
        if ($candidate !== [] && !self::available($candidate, $locale, $path)) {
            if (PHP_SAPI !== 'cli') { throw new \TinyCat\Http\HttpException('Asset build is incomplete. Run php App/Console.php assets.', 503, 'asset_inventory_unavailable'); }
            $candidate = [];
        }
        self::$checked[$context] = true;
        return self::$inventory = $candidate;
    }

    public static function delivered(): void { self::$delivered = true; }

    /** An existing payload cannot change generation; missing builds never trigger compilation. */
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
        foreach ([$inventory['assets']['server_components'] ?? null, $inventory['translations']['catalogs'][$locale] ?? null] as $name) {
            if (!is_string($name) || preg_match('/^(?:components|[a-z0-9-]+)\.[a-f0-9]{24}\.php$/D', $name) !== 1) { return false; }
            $file = Paths::basePath('App/Core/Support/compiled/' . $name);
            if (!is_file($file) || is_link($file)) { return false; }
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
