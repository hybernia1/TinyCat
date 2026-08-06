<?php
declare(strict_types=1);

namespace TinyCat\Extension;

use Cache;
use Core;
use FilesystemIterator;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Minifier;
use PDO;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use TinyCat\Update\MigrationRegistry;
use TinyCat\Sitemap;
use ZipArchive;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}
final class Assets
{
    private const int MAX_SOURCE_BYTES = 2 * 1024 * 1024;
    private const int MAX_ASSETS_PER_EXTENSION = 20;
    private const string CACHE_VERSION = '1';
    private const string CACHE_NAMESPACE = 'assets';
    private const string CACHE_URL = '/cache/assets';

    private static array $providers = [];

    private function __construct()
    {
    }

    public static function register(string $slug, mixed $provider): void
    {
        if ($provider === null) {
            return;
        }

        if (!is_callable($provider)) {
            throw new InvalidArgumentException('Invalid extension asset provider.');
        }

        if (isset(self::$providers[$slug])) {
            throw new RuntimeException('Extension asset provider is already registered: ' . $slug);
        }

        self::$providers[$slug] = $provider;
    }

    /**
     * @return array{styles: list<string>, scripts: list<string>}
     */
    public static function forPath(string $path): array
    {
        $published = ['styles' => [], 'scripts' => []];

        foreach (self::$providers as $slug => $provider) {
            $definition = $provider(Core::path($path));
            if ($definition === []) {
                continue;
            }

            if (!is_array($definition) || array_is_list($definition)) {
                throw new RuntimeException('Extension asset provider must return an object: ' . $slug);
            }

            $unknown = array_diff(array_keys($definition), ['styles', 'scripts']);
            if ($unknown !== []) {
                throw new RuntimeException('Extension asset provider returned an unknown asset group: ' . $slug);
            }

            $count = 0;
            foreach (['styles' => 'css', 'scripts' => 'js'] as $group => $type) {
                $paths = $definition[$group] ?? [];
                if (!is_array($paths) || !array_is_list($paths)) {
                    throw new RuntimeException('Extension asset group must be a list: ' . $slug . '/' . $group);
                }

                $count += count($paths);
                if ($count > self::MAX_ASSETS_PER_EXTENSION) {
                    throw new RuntimeException('Extension asset provider returned too many files: ' . $slug);
                }

                foreach ($paths as $relativePath) {
                    if (!is_string($relativePath) || trim($relativePath) === '') {
                        throw new RuntimeException('Extension asset path must be a non-empty string: ' . $slug);
                    }

                    $published[$group][] = self::url($slug, $relativePath, $type);
                }
            }
        }

        $published['styles'] = array_values(array_unique($published['styles']));
        $published['scripts'] = array_values(array_unique($published['scripts']));

        return $published;
    }

    public static function url(string $slug, string $relativePath, string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['css', 'js'], true)) {
            throw new InvalidArgumentException('Invalid extension asset type.');
        }

        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/')) {
            throw new InvalidArgumentException('Extension asset path must be relative.');
        }

        if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) !== $type) {
            throw new InvalidArgumentException('Extension asset has an invalid file type.');
        }

        $sourceFile = Registry::file($slug, $relativePath);
        $size = filesize($sourceFile);
        if (!is_int($size) || $size > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Extension asset exceeds the maximum size of 2 MiB.');
        }

        $source = file_get_contents($sourceFile);
        if (!is_string($source)) {
            throw new RuntimeException('Could not read extension asset: ' . $slug . '/' . $relativePath);
        }

        $minified = (bool) Core::setting('performance.minify_' . $type, false);
        $content = $minified
            ? ($type === 'css' ? Minifier::minifyCss($source) : Minifier::minifyJavaScript($source))
            : $source;

        $baseName = strtolower((string) pathinfo($relativePath, PATHINFO_FILENAME));
        $baseName = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $baseName), '-');
        $baseName = substr($baseName !== '' ? $baseName : 'asset', 0, 40);
        $identity = substr(hash('sha256', $slug . "\0" . $relativePath), 0, 10);
        $prefix = 'ext-' . $slug . '-' . $baseName . '-' . $identity;
        $hash = substr(hash('sha256', self::CACHE_VERSION . "\0" . ($minified ? 'min' : 'raw') . "\0" . $content), 0, 20);
        $fileName = $prefix . '.' . $hash . ($minified ? '.min' : '') . '.' . $type;
        $target = Cache::file($fileName, self::CACHE_NAMESPACE);

        if (!is_file($target)) {
            if (!Cache::writeFile($fileName, $content, self::CACHE_NAMESPACE)) {
                throw new RuntimeException('Could not publish extension asset: ' . $slug . '/' . $relativePath);
            }

            $pattern = '/^' . preg_quote($prefix, '/') . '\\.[a-f0-9]{20}(?:\\.min)?\\.' . preg_quote($type, '/') . '$/';
            Cache::prune(
                self::CACHE_NAMESPACE,
                static fn (string $candidate): bool => $candidate !== $fileName
                    && preg_match($pattern, $candidate) === 1
            );
        }

        return self::CACHE_URL . '/' . rawurlencode($fileName);
    }
}

final class Lifecycle
{
    private const string VERSIONS_SETTING = 'extensions.installed_versions';

    private function __construct()
    {
    }

    public static function all(): array
    {
        $versions = self::installedVersions();
        $extensions = [];

        foreach (Loader::available() as $slug => $manifest) {
            $installedVersion = (string) ($versions[$slug] ?? '');
            $pending = 0;
            $migrationError = '';

            try {
                foreach ((array) ($manifest['migrations'] ?? []) as $migration) {
                    $checksum = (string) ($migration['checksum'] ?? '');
                    if (!hash_equals($checksum, MigrationRegistry::checksum((string) ($migration['path'] ?? '')))) {
                        throw new RuntimeException(
                            'Migration file checksum mismatch: ' . (string) ($migration['id'] ?? '')
                        );
                    }
                    if (!MigrationRegistry::applied(
                        (string) ($migration['id'] ?? ''),
                        $checksum
                    )) {
                        $pending++;
                    }
                }
            } catch (Throwable $exception) {
                $migrationError = $exception->getMessage();
            }

            $codeVersion = (string) ($manifest['version'] ?? '');
            $extensions[$slug] = [
                ...$manifest,
                'installed_version' => $installedVersion,
                'installed' => $installedVersion !== '',
                'update_available' => $installedVersion !== ''
                    && version_compare($codeVersion, $installedVersion, '>'),
                'downgrade_detected' => $installedVersion !== ''
                    && version_compare($codeVersion, $installedVersion, '<'),
                'pending_migrations' => $pending,
                'migration_error' => $migrationError,
            ];
        }

        return $extensions;
    }

    public static function migrate(string $slug): array
    {
        $slug = strtolower(trim($slug));
        $extension = Loader::available()[$slug] ?? null;

        if (!is_array($extension)) {
            throw new RuntimeException('Extension was not found: ' . $slug);
        }

        return self::migrateDefinition($slug, $extension);
    }

    public static function migrateDiscovered(string $slug, string $directory): array
    {
        $slug = strtolower(trim($slug));
        $extension = Loader::discover($directory)[$slug] ?? null;

        if (!is_array($extension)) {
            throw new RuntimeException('Extension was not found after installation: ' . $slug);
        }

        return self::migrateDefinition($slug, $extension);
    }

    private static function migrateDefinition(string $slug, array $extension): array
    {
        if (empty($extension['compatible'])) {
            throw new RuntimeException(
                'Extension ' . $slug . ' requires TinyCat ' . (string) ($extension['minimum_tinycat'] ?? '') . ' or newer.'
            );
        }

        $versions = self::installedVersions();
        $installedVersion = (string) ($versions[$slug] ?? '');
        $codeVersion = (string) ($extension['version'] ?? '');

        if ($installedVersion !== '' && version_compare($installedVersion, $codeVersion, '>')) {
            throw new RuntimeException('Extension downgrade is not supported: ' . $slug);
        }

        $lockName = 'tinycat_extension_migration_' . substr(hash('sha256', $slug), 0, 32);
        $mysqlLock = false;

        if ((string) db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $mysqlLock = (int) val('SELECT GET_LOCK(?, 0)', [$lockName]) === 1;
            if (!$mysqlLock) {
                throw new RuntimeException('Another extension migration is already running: ' . $slug);
            }
        }

        try {
            $applied = [];

            foreach ((array) ($extension['migrations'] ?? []) as $migration) {
                $id = (string) ($migration['id'] ?? '');
                if (MigrationRegistry::apply(
                    $id,
                    $codeVersion,
                    (string) ($migration['path'] ?? ''),
                    (string) ($migration['checksum'] ?? '')
                )) {
                    $applied[] = $id;
                }
            }

            $versions[$slug] = $codeVersion;
            ksort($versions, SORT_STRING);
            Core::setSetting(self::VERSIONS_SETTING, $versions, 'json', 'extensions');
        } finally {
            if ($mysqlLock) {
                try {
                    val('SELECT RELEASE_LOCK(?)', [$lockName]);
                } catch (Throwable) {
                    // The database releases connection locks automatically.
                }
            }
        }

        return [
            'slug' => $slug,
            'version' => $codeVersion,
            'migrations' => $applied,
        ];
    }

    public static function installedVersions(): array
    {
        $stored = Core::setting(self::VERSIONS_SETTING, []);
        if (!is_array($stored)) {
            return [];
        }

        $versions = [];

        foreach ($stored as $slug => $version) {
            $slug = strtolower(trim((string) $slug));
            $version = trim((string) $version);

            if (
                preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) === 1
                && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1
            ) {
                $versions[$slug] = $version;
            }
        }

        return $versions;
    }

    public static function freshInstallVersions(): array
    {
        $versions = [];

        foreach (Loader::loaded() as $slug => $extension) {
            $version = trim((string) ($extension['version'] ?? ''));
            if ($version !== '') {
                $versions[$slug] = $version;
            }
        }

        ksort($versions, SORT_STRING);
        return $versions;
    }
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

final class Registry
{
    private static array $extensions = [];

    private function __construct()
    {
    }

    public static function register(string $slug, array $definition): void
    {
        $slug = strtolower(trim($slug));

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1) {
            throw new InvalidArgumentException('Invalid extension slug.');
        }

        if (isset(self::$extensions[$slug])) {
            throw new LogicException('Extension is already registered: ' . $slug);
        }

        $tables = [];
        foreach ((array) ($definition['required_tables'] ?? []) as $table) {
            $table = trim((string) $table);

            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $table) !== 1) {
                throw new InvalidArgumentException('Invalid extension table name.');
            }

            $tables[] = $table;
        }

        $claimedTables = self::requiredTables();
        $duplicates = array_intersect($tables, $claimedTables);

        if ($duplicates !== []) {
            throw new LogicException('Extension table is already registered: ' . reset($duplicates));
        }

        $scheduledTasks = self::validateScheduledTasks((array) ($definition['scheduled_tasks'] ?? []));
        $taskDuplicates = array_intersect(array_keys($scheduledTasks), array_keys(self::scheduledTasks()));

        if ($taskDuplicates !== []) {
            throw new LogicException('Scheduled task is already registered: ' . reset($taskDuplicates));
        }

        $root = self::optionalDirectory($definition['root'] ?? null, 'root');
        $assetProvider = self::optionalCallable($definition['assets'] ?? null, 'asset provider');

        if ($assetProvider !== null && $root === null) {
            throw new InvalidArgumentException('Extension assets require a registered root directory: ' . $slug);
        }

        $extension = [
            'root' => $root,
            'views' => self::optionalDirectory($definition['views'] ?? null, 'views'),
            'translations' => self::optionalDirectory($definition['translations'] ?? null, 'translations'),
            'tables' => array_values(array_unique($tables)),
            'install_schema' => self::optionalCallable($definition['install_schema'] ?? null, 'install schema'),
            'routes' => self::optionalCallable($definition['routes'] ?? null, 'route registrar'),
            'api_routes' => self::optionalCallable($definition['api_routes'] ?? null, 'API route registrar'),
            'admin_navigation' => self::optionalCallable($definition['admin_navigation'] ?? null, 'admin navigation provider'),
            'scheduled_tasks' => $scheduledTasks,
        ];

        Sitemap::registerExtension($slug, $definition['sitemap'] ?? null);
        Assets::register($slug, $assetProvider);
        self::$extensions[$slug] = $extension;
    }

    public static function has(string $slug): bool
    {
        return isset(self::$extensions[strtolower(trim($slug))]);
    }

    public static function slugs(): array
    {
        return array_keys(self::$extensions);
    }

    public static function file(string $slug, string $relative): string
    {
        $root = (string) (self::$extensions[$slug]['root'] ?? '');

        if ($root === '') {
            throw new RuntimeException('Extension root is not registered: ' . $slug);
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new InvalidArgumentException('Invalid extension file path.');
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new InvalidArgumentException('Invalid extension file path.');
            }
        }

        $file = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        $prefix = strtolower(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

        if ($file === false || !is_file($file) || !str_starts_with(strtolower($file), $prefix)) {
            throw new RuntimeException('Extension file was not found: ' . $slug . '/' . $relative);
        }

        return $file;
    }

    public static function render(string $slug, string $template, array $data = []): string
    {
        $views = (string) (self::$extensions[$slug]['views'] ?? '');

        if ($views === '') {
            throw new RuntimeException('Extension views are not registered: ' . $slug);
        }

        return Core::render($template, $data, $views);
    }

    public static function translations(string $locale): array
    {
        if (preg_match('/^[A-Za-z]{2}(?:[-_][A-Za-z]{2})?$/', $locale) !== 1) {
            throw new InvalidArgumentException('Invalid extension translation locale.');
        }

        $translations = [];

        foreach (self::$extensions as $slug => $extension) {
            $directory = (string) ($extension['translations'] ?? '');
            if ($directory === '') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $locale . '.json';
            if (!is_file($path)) {
                continue;
            }

            $json = file_get_contents($path);
            if ($json === false) {
                throw new RuntimeException('Could not read extension translation file: ' . $path);
            }

            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Extension translation file contains invalid JSON: ' . $path, 0, $exception);
            }

            if (!is_array($data)) {
                throw new RuntimeException('Extension translation file must contain a JSON object: ' . $path);
            }

            $translations = array_replace_recursive($translations, $data);
        }

        return $translations;
    }

    public static function registerRoutes(): void
    {
        self::invokeRegistrars('routes');
    }

    public static function registerApiRoutes(): void
    {
        self::invokeRegistrars('api_routes');
    }

    public static function adminNavigation(): array
    {
        $items = [];

        foreach (self::$extensions as $extension) {
            $provider = $extension['admin_navigation'] ?? null;

            if (is_callable($provider)) {
                $item = $provider();

                if (is_array($item) && $item !== []) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    public static function scheduledTasks(): array
    {
        $tasks = [];

        foreach (self::$extensions as $extension) {
            foreach ((array) ($extension['scheduled_tasks'] ?? []) as $name => $definition) {
                $tasks[$name] = $definition;
            }
        }

        return $tasks;
    }

    public static function requiredTables(): array
    {
        return array_values(array_merge(...array_map(
            static fn (array $extension): array => (array) ($extension['tables'] ?? []),
            array_values(self::$extensions)
        )));
    }

    public static function installSchemas(): void
    {
        foreach (self::$extensions as $extension) {
            $installer = $extension['install_schema'] ?? null;

            if (is_callable($installer)) {
                $installer();
            }
        }
    }

    private static function invokeRegistrars(string $key): void
    {
        foreach (self::$extensions as $extension) {
            $registrar = $extension[$key] ?? null;

            if (is_callable($registrar)) {
                $registrar();
            }
        }
    }

    private static function optionalCallable(mixed $value, string $label): mixed
    {
        if ($value !== null && !is_callable($value)) {
            throw new InvalidArgumentException('Invalid extension ' . $label . '.');
        }

        return $value;
    }

    private static function optionalDirectory(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $directory = realpath((string) $value);
        if ($directory === false || !is_dir($directory)) {
            throw new InvalidArgumentException('Invalid extension ' . $label . ' directory.');
        }

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    private static function validateScheduledTasks(array $tasks): array
    {
        $validated = [];

        foreach ($tasks as $name => $definition) {
            $name = strtolower(trim((string) $name));

            if (preg_match('/^[a-z][a-z0-9-]{0,63}$/', $name) !== 1 || !is_array($definition)) {
                throw new InvalidArgumentException('Invalid scheduled task definition.');
            }

            $runner = $definition['runner'] ?? null;
            if (!is_callable($runner)) {
                throw new InvalidArgumentException('Invalid scheduled task runner: ' . $name);
            }

            $options = [];
            foreach ((array) ($definition['options'] ?? []) as $option => $default) {
                $option = strtolower(trim((string) $option));

                if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $option) !== 1 || !is_int($default)) {
                    throw new InvalidArgumentException('Invalid scheduled task option: ' . $option);
                }

                $options[$option] = $default;
            }

            $admin = $definition['admin'] ?? null;
            if ($admin !== null) {
                if (!is_array($admin) || array_is_list($admin)) {
                    throw new InvalidArgumentException('Invalid scheduled task admin metadata: ' . $name);
                }

                $icon = strtolower(trim((string) ($admin['icon'] ?? '')));
                $title = trim((string) ($admin['title'] ?? ''));
                $help = trim((string) ($admin['help'] ?? ''));
                $schedule = trim((string) ($admin['schedule'] ?? ''));

                if (
                    preg_match('/^[a-z][a-z0-9-]{0,63}$/', $icon) !== 1
                    || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $title) !== 1
                    || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $help) !== 1
                    || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $schedule) !== 1
                ) {
                    throw new InvalidArgumentException('Invalid scheduled task admin metadata: ' . $name);
                }

                $admin = [
                    'icon' => $icon,
                    'title' => $title,
                    'help' => $help,
                    'schedule' => $schedule,
                ];
            }

            $validated[$name] = [
                'runner' => $runner,
                'options' => $options,
                'admin' => $admin,
            ];
        }

        return $validated;
    }
}
