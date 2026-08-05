<?php
declare(strict_types=1);

namespace TinyCat\Extension;

use Core;
use PDO;
use RuntimeException;
use Throwable;
use TinyCat\Update\MigrationRegistry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
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
