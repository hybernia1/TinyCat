<?php
declare(strict_types=1);

namespace TinyCat\Extension;

use Cache;
use Core;
use FilesystemIterator;
use JsonException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use TinyCat\Update\MigrationRegistry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Loader
{
    private static bool $booted = false;
    private static array $available = [];
    private static array $loaded = [];
    private static array $stateOverrides = [];
    private static array $installedVersions = [];
    private const string MIGRATION_CHECKSUM_CACHE_PREFIX = 'extension_migration_checksums_';
    private const int MIGRATION_CHECKSUM_CACHE_TTL = 86400;

    private function __construct()
    {
    }

    public static function boot(string $directory, array $stateOverrides = [], array $installedVersions = []): void
    {
        if (self::$booted) {
            return;
        }

        $cachedChecksums = self::cachedMigrationChecksums($directory);
        $available = self::discoverWithCachedMigrationChecksums($directory, $cachedChecksums['checksums']);
        self::cacheMigrationChecksums($directory, $available, $cachedChecksums['fingerprint']);
        self::$stateOverrides = self::normalizeStateOverrides($stateOverrides);
        self::$installedVersions = self::normalizeInstalledVersions($installedVersions);
        $resolved = [];

        foreach ($available as $slug => $manifest) {
            $installedVersion = self::$installedVersions[$slug] ?? '';
            $isInstalled = $installedVersion !== '' && hash_equals($installedVersion, (string) $manifest['version']);
            $shouldLoad = $isInstalled && (self::$stateOverrides[$slug] ?? $manifest['autoload']);
            $publicManifest = [
                ...self::publicManifest($manifest),
                'enabled' => false,
                'requested_enabled' => $shouldLoad,
            ];

            if (!$shouldLoad) {
                $resolved[$slug] = $publicManifest;
                continue;
            }

            if (!$manifest['compatible']) {
                $resolved[$slug] = $publicManifest;
                continue;
            }

            if (Registry::has($slug)) {
                throw new LogicException('Extension was registered before its manifest was loaded: ' . $slug);
            }

            $registeredBefore = Registry::slugs();
            require $manifest['entry_path'];
            $registered = array_values(array_diff(Registry::slugs(), $registeredBefore));

            if ($registered !== [$slug]) {
                throw new RuntimeException('Extension entry must register exactly its manifest slug: ' . $slug);
            }

            $publicManifest['enabled'] = true;
            self::$loaded[$slug] = $publicManifest;
            $resolved[$slug] = $publicManifest;
        }

        self::$available = $resolved;
        self::$booted = true;
    }

    public static function discover(string $directory): array
    {
        return self::discoverWithCachedMigrationChecksums($directory);
    }

    private static function discoverWithCachedMigrationChecksums(
        string $directory,
        array $cachedMigrationChecksums = []
    ): array
    {
        if (!file_exists($directory)) {
            return [];
        }

        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Extensions path is not a readable directory.');
        }

        $extensions = [];
        $entries = scandir($root);
        if ($entries === false) {
            throw new RuntimeException('Extensions directory could not be read.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $extensionRoot = realpath($root . DIRECTORY_SEPARATOR . $entry);
            if ($extensionRoot === false || !is_dir($extensionRoot)) {
                continue;
            }
            if (!self::pathIsInside($extensionRoot, $root)) {
                throw new RuntimeException('Extension directory resolves outside the extensions root: ' . $entry);
            }

            $manifestPath = $extensionRoot . DIRECTORY_SEPARATOR . 'extension.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = self::readManifest($manifestPath, $extensionRoot, $entry, $cachedMigrationChecksums);
            $slug = $manifest['slug'];

            if (isset($extensions[$slug])) {
                throw new LogicException('Extension manifest slug is duplicated: ' . $slug);
            }

            $extensions[$slug] = $manifest;
        }

        ksort($extensions, SORT_STRING);
        return $extensions;
    }

    public static function available(): array
    {
        return self::$available;
    }

    public static function loaded(): array
    {
        return self::$loaded;
    }

    public static function stateOverrides(): array
    {
        return self::$stateOverrides;
    }

    private static function readManifest(
        string $path,
        string $root,
        string $directoryName,
        array $cachedMigrationChecksums = []
    ): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Extension manifest could not be read: ' . $path);
        }

        try {
            $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Extension manifest contains invalid JSON: ' . $path, 0, $exception);
        }

        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('Extension manifest must contain a JSON object: ' . $path);
        }

        $schema = $manifest['schema'] ?? null;
        $slug = strtolower(trim((string) ($manifest['slug'] ?? '')));
        $name = trim((string) ($manifest['name'] ?? ''));
        $version = trim((string) ($manifest['version'] ?? ''));
        $requires = $manifest['requires'] ?? null;
        $minimumTinycat = is_array($requires) ? trim((string) ($requires['tinycat'] ?? '')) : '';
        $minimumPhp = is_array($requires) ? trim((string) ($requires['php'] ?? '8.4.0')) : '8.4.0';
        $entry = trim(str_replace('\\', '/', (string) ($manifest['entry'] ?? '')), '/');
        $autoload = $manifest['autoload'] ?? null;
        $migrationFiles = $manifest['migrations'] ?? [];
        $uninstall = $manifest['uninstall'] ?? null;

        if ($schema !== 1) {
            throw new RuntimeException('Unsupported extension manifest schema: ' . $path);
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1) {
            throw new RuntimeException('Invalid extension manifest slug: ' . $path);
        }
        if (strtolower($directoryName) !== $slug) {
            throw new RuntimeException('Extension directory must match its manifest slug: ' . $slug);
        }
        if ($name === '' || strlen($name) > 120) {
            throw new RuntimeException('Invalid extension manifest name: ' . $slug);
        }
        if (
            strlen($version) > 32
            || strlen($minimumTinycat) > 32
            || strlen($minimumPhp) > 32
            || !self::validVersion($version)
            || !self::validVersion($minimumTinycat)
            || !self::validVersion($minimumPhp)
        ) {
            throw new RuntimeException('Invalid extension version requirement: ' . $slug);
        }
        if (!is_bool($autoload)) {
            throw new RuntimeException('Extension manifest autoload must be boolean: ' . $slug);
        }
        if (!is_array($migrationFiles) || !array_is_list($migrationFiles)) {
            throw new RuntimeException('Extension manifest migrations must be a list: ' . $slug);
        }

        $migrations = [];
        $migrationNames = [];

        foreach ($migrationFiles as $migrationFile) {
            $migrationFile = trim(str_replace('\\', '/', (string) $migrationFile), '/');
            if (preg_match('~^migrations/([0-9]{8}_[0-9]{3}_[a-z][a-z0-9_-]{0,79}\.php)$~', $migrationFile, $match) !== 1) {
                throw new RuntimeException('Invalid extension migration path: ' . $slug);
            }

            $migrationName = pathinfo((string) $match[1], PATHINFO_FILENAME);
            if (isset($migrationNames[$migrationName])) {
                throw new RuntimeException('Duplicate extension migration: ' . $slug . '/' . $migrationName);
            }

            $migrationPath = self::resolvePhpFile($root, $migrationFile, $slug, 'migration');
            $checksum = (string) ($cachedMigrationChecksums[$slug . '/' . $migrationFile] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                $checksum = MigrationRegistry::checksum($migrationPath);
            }
            $migrations[] = [
                'id' => 'extension:' . $slug . ':' . $migrationName,
                'file' => $migrationFile,
                'path' => $migrationPath,
                'checksum' => $checksum,
            ];
            $migrationNames[$migrationName] = true;
        }

        $sortedMigrationFiles = array_column($migrations, 'file');
        sort($sortedMigrationFiles, SORT_STRING);
        if ($sortedMigrationFiles !== array_column($migrations, 'file')) {
            throw new RuntimeException('Extension migrations must be sorted: ' . $slug);
        }

        $uninstall = self::uninstallDefinition($uninstall, $root, $slug);

        return [
            'schema' => 1,
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'minimum_tinycat' => $minimumTinycat,
            'minimum_php' => $minimumPhp,
            'compatible' => version_compare(Core::VERSION, $minimumTinycat, '>=')
                && version_compare(PHP_VERSION, $minimumPhp, '>='),
            'entry' => $entry,
            'autoload' => $autoload,
            'migrations' => $migrations,
            'uninstall' => $uninstall,
            'root' => $root,
            'entry_path' => self::resolvePhpFile($root, $entry, $slug, 'entry'),
        ];
    }

    private static function uninstallDefinition(mixed $definition, string $root, string $slug): ?array
    {
        if ($definition === null) {
            return null;
        }
        if (!is_array($definition) || array_is_list($definition)) {
            throw new RuntimeException('Invalid extension uninstall definition: ' . $slug);
        }

        $handler = trim(str_replace('\\', '/', (string) ($definition['handler'] ?? '')), '/');
        $options = $definition['options'] ?? null;
        if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 10) {
            throw new RuntimeException('Invalid extension uninstall options: ' . $slug);
        }

        $normalized = [];
        $recommended = false;

        foreach ($options as $option) {
            if (!is_array($option) || array_is_list($option)) {
                throw new RuntimeException('Invalid extension uninstall option: ' . $slug);
            }

            $id = strtolower(trim((string) ($option['id'] ?? '')));
            $danger = $option['danger'] ?? false;
            $isRecommended = $option['recommended'] ?? false;

            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id) !== 1
                || isset($normalized[$id])
                || !is_bool($danger)
                || !is_bool($isRecommended)
                || ($isRecommended && $recommended)
            ) {
                throw new RuntimeException('Invalid extension uninstall option: ' . $slug);
            }

            $labels = self::localizedManifestText($option['labels'] ?? null, 120, $slug, 'uninstall label');
            $descriptions = self::localizedManifestText(
                $option['descriptions'] ?? null,
                500,
                $slug,
                'uninstall description'
            );

            $normalized[$id] = [
                'id' => $id,
                'labels' => $labels,
                'descriptions' => $descriptions,
                'danger' => $danger,
                'recommended' => $isRecommended,
            ];
            $recommended = $recommended || $isRecommended;
        }

        return [
            'handler' => $handler,
            'handler_path' => self::resolvePhpFile($root, $handler, $slug, 'uninstall handler'),
            'options' => array_values($normalized),
        ];
    }

    private static function localizedManifestText(mixed $value, int $limit, string $slug, string $label): array
    {
        if (!is_array($value) || array_is_list($value) || $value === []) {
            throw new RuntimeException('Invalid extension ' . $label . ': ' . $slug);
        }

        $localized = [];
        foreach ($value as $locale => $text) {
            $locale = strtolower(str_replace('_', '-', trim((string) $locale)));
            $text = trim((string) $text);
            if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) !== 1
                || $text === ''
                || strlen($text) > $limit
            ) {
                throw new RuntimeException('Invalid extension ' . $label . ': ' . $slug);
            }
            $localized[$locale] = $text;
        }

        ksort($localized, SORT_STRING);
        return $localized;
    }

    private static function resolvePhpFile(string $root, string $relative, string $slug, string $label): string
    {
        if ($relative === '' || !str_ends_with(strtolower($relative), '.php') || str_contains($relative, "\0")) {
            throw new RuntimeException('Invalid extension ' . $label . ' path: ' . $slug);
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new RuntimeException('Invalid extension ' . $label . ' path: ' . $slug);
            }
        }

        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($path === false || !is_file($path) || !self::pathIsInside($path, $root)) {
            throw new RuntimeException('Extension ' . $label . ' was not found inside its directory: ' . $slug);
        }

        return $path;
    }

    private static function pathIsInside(string $path, string $root): bool
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (PHP_OS_FAMILY === 'Windows') {
            return str_starts_with(strtolower($path), strtolower($prefix));
        }

        return str_starts_with($path, $prefix);
    }

    private static function cachedMigrationChecksums(string $directory): array
    {
        $fingerprint = self::extensionDirectoryFingerprint($directory);

        if ($fingerprint === '' || !self::isManagedExtensionsDirectory($directory) || !class_exists(Cache::class)) {
            return ['fingerprint' => $fingerprint, 'checksums' => []];
        }

        $cached = Cache::get(self::migrationChecksumCacheKey($directory), self::MIGRATION_CHECKSUM_CACHE_TTL);
        if (!is_array($cached) || !hash_equals($fingerprint, (string) ($cached['fingerprint'] ?? ''))) {
            return ['fingerprint' => $fingerprint, 'checksums' => []];
        }

        $checksums = [];
        foreach ((array) ($cached['checksums'] ?? []) as $key => $checksum) {
            $key = trim((string) $key);
            $checksum = strtolower(trim((string) $checksum));

            if (
                preg_match('~^[a-z][a-z0-9_-]{0,63}/migrations/[0-9]{8}_[0-9]{3}_[a-z][a-z0-9_-]{0,79}\.php$~', $key) === 1
                && preg_match('/^[a-f0-9]{64}$/', $checksum) === 1
            ) {
                $checksums[$key] = $checksum;
            }
        }

        return ['fingerprint' => $fingerprint, 'checksums' => $checksums];
    }

    private static function cacheMigrationChecksums(string $directory, array $extensions, string $fingerprint): void
    {
        if ($fingerprint === '' || !self::isManagedExtensionsDirectory($directory) || !class_exists(Cache::class)) {
            return;
        }

        $checksums = [];
        foreach ($extensions as $slug => $extension) {
            foreach ((array) ($extension['migrations'] ?? []) as $migration) {
                $file = (string) ($migration['file'] ?? '');
                $checksum = strtolower((string) ($migration['checksum'] ?? ''));

                if (
                    preg_match('~^migrations/[0-9]{8}_[0-9]{3}_[a-z][a-z0-9_-]{0,79}\.php$~', $file) === 1
                    && preg_match('/^[a-f0-9]{64}$/', $checksum) === 1
                ) {
                    $checksums[(string) $slug . '/' . $file] = $checksum;
                }
            }
        }

        ksort($checksums, SORT_STRING);
        Cache::put(self::migrationChecksumCacheKey($directory), [
            'fingerprint' => $fingerprint,
            'checksums' => $checksums,
        ]);
    }

    private static function extensionDirectoryFingerprint(string $directory): string
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            return '';
        }

        try {
            $entries = ['.' => [filemtime($root), filesize($root)]];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    continue;
                }

                $path = $entry->getPathname();
                $relative = str_replace('\\', '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
                $entries[$relative] = [$entry->getType(), $entry->getMTime(), $entry->isFile() ? $entry->getSize() : 0];
            }

            ksort($entries, SORT_STRING);
            return hash('sha256', serialize($entries));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isManagedExtensionsDirectory(string $directory): bool
    {
        $root = realpath($directory);
        $managedDirectory = function_exists('base_path')
            ? \base_path('Extensions')
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Extensions';
        $managedRoot = realpath($managedDirectory);

        return $root !== false && $managedRoot !== false
            && strtolower(rtrim($root, DIRECTORY_SEPARATOR)) === strtolower(rtrim($managedRoot, DIRECTORY_SEPARATOR));
    }

    private static function migrationChecksumCacheKey(string $directory): string
    {
        $root = realpath($directory);
        return self::MIGRATION_CHECKSUM_CACHE_PREFIX . hash('sha256', $root === false ? $directory : $root);
    }

    private static function normalizeStateOverrides(array $states): array
    {
        $normalized = [];

        foreach ($states as $slug => $enabled) {
            $slug = strtolower(trim((string) $slug));
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1 || !is_bool($enabled)) {
                continue;
            }

            $normalized[$slug] = $enabled;
        }

        return $normalized;
    }

    private static function normalizeInstalledVersions(array $versions): array
    {
        $normalized = [];

        foreach ($versions as $slug => $version) {
            $slug = strtolower(trim((string) $slug));
            $version = trim((string) $version);

            if (
                preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1
                || !self::validVersion($version)
            ) {
                continue;
            }

            $normalized[$slug] = $version;
        }

        return $normalized;
    }

    private static function validVersion(string $version): bool
    {
        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private static function publicManifest(array $manifest): array
    {
        unset($manifest['entry_path']);
        if (is_array($manifest['uninstall'] ?? null)) {
            unset($manifest['uninstall']['handler_path']);
        }
        return $manifest;
    }
}
