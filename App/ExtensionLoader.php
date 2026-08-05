<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ExtensionLoader
{
    private static bool $booted = false;
    private static array $available = [];
    private static array $loaded = [];
    private static array $stateOverrides = [];

    private function __construct()
    {
    }

    public static function boot(string $directory, array $stateOverrides = []): void
    {
        if (self::$booted) {
            return;
        }

        $available = self::discover($directory);
        self::$stateOverrides = self::normalizeStateOverrides($stateOverrides);
        $resolved = [];

        foreach ($available as $slug => $manifest) {
            $shouldLoad = self::$stateOverrides[$slug] ?? $manifest['autoload'];
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

            if (ExtensionRegistry::has($slug)) {
                throw new LogicException('Extension was registered before its manifest was loaded: ' . $slug);
            }

            $registeredBefore = ExtensionRegistry::slugs();
            require $manifest['entry_path'];
            $registered = array_values(array_diff(ExtensionRegistry::slugs(), $registeredBefore));

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

            $manifest = self::readManifest($manifestPath, $extensionRoot, $entry);
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

    private static function readManifest(string $path, string $root, string $directoryName): array
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
        $legacyVersion = isset($manifest['legacy_version'])
            ? trim((string) $manifest['legacy_version'])
            : '';

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
        if ($legacyVersion !== '' && (strlen($legacyVersion) > 32 || !self::validVersion($legacyVersion))) {
            throw new RuntimeException('Invalid extension legacy version: ' . $slug);
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
            $migrations[] = [
                'id' => 'extension:' . $slug . ':' . $migrationName,
                'file' => $migrationFile,
                'path' => $migrationPath,
                'checksum' => MigrationRegistry::checksum($migrationPath),
            ];
            $migrationNames[$migrationName] = true;
        }

        $sortedMigrationFiles = array_column($migrations, 'file');
        sort($sortedMigrationFiles, SORT_STRING);
        if ($sortedMigrationFiles !== array_column($migrations, 'file')) {
            throw new RuntimeException('Extension migrations must be sorted: ' . $slug);
        }

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
            'legacy_version' => $legacyVersion,
            'migrations' => $migrations,
            'root' => $root,
            'entry_path' => self::resolvePhpFile($root, $entry, $slug, 'entry'),
        ];
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

    private static function validVersion(string $version): bool
    {
        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private static function publicManifest(array $manifest): array
    {
        unset($manifest['entry_path']);
        return $manifest;
    }
}
