<?php
declare(strict_types=1);

namespace TinyCat\Core\Updates;

use FilesystemIterator;
use JsonException;
use PDO;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use \TinyCat\Support\Cache;

class Manager extends Package
{
    /** @var \Closure(string, mixed): mixed|null */
    private static ?\Closure $configuration = null;
    /** @var \Closure(int): void|null */
    private static ?\Closure $unavailable = null;

    /** @param \Closure(string, mixed): mixed $configuration
     * @param \Closure(int): void|null $unavailable */
    public static function configure(\Closure $configuration, ?\Closure $unavailable = null): void
    {
        self::$configuration = $configuration; self::$unavailable = $unavailable;
    }
    private const string DEFAULT_REPOSITORY = 'hybernia1/TinyCat';
    private const string CACHE_KEY = 'updater_latest_release';
    private const int CACHE_TTL = 900;
    private const int MAX_MANIFEST_BYTES = 1048576;
    private const int MAX_PACKAGE_BYTES = 104857600;
    private const int MAX_PACKAGE_FILES = 5000;

    // Release verification key. May be overridden by updates.public_key.
    private const string DEFAULT_PUBLIC_KEY = 'wyxVDrFHXjYsEY/xCfGQAw8G+wSR9+uLKz7fGcCf/Xs=';

    private function __construct()
    {
    }

    private static function option(string $key, mixed $default = null): mixed
    {
        return self::$configuration !== null ? (self::$configuration)($key, $default) : \TinyCat\Support\Config::get($key, $default);
    }

    /** @return resource */
    private static function operationLock(bool $shared = false)
    {
        self::ensureDirectory(self::updateRoot());
        $lock = fopen(self::updateRoot() . '/update.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, ($shared ? LOCK_SH : LOCK_EX) | LOCK_NB)) { if (is_resource($lock)) { fclose($lock); } throw new RuntimeException('Another deployment operation is already running.'); }
        return $lock;
    }

    private static function assertIdle(): void
    {
        $file = self::updateRoot() . '/operation.json';
        $state = is_file($file) ? self::decodeJson((string) file_get_contents($file), 'deployment operation') : [];
        if (self::maintenanceActive() || in_array($state['phase'] ?? '', ['applying', 'failed', 'restoring'], true)) { throw new RuntimeException('Restore the interrupted deployment backup before continuing.'); }
    }

    /** @param callable(): array<string, mixed> $run
     * @return array<string, mixed> */
    public static function runTask(callable $run): array
    {
        $lock = self::operationLock(true);
        try { self::assertIdle(); return $run(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** @param callable(): array<string, mixed> $apply
     * @param callable(): void|null $preflight
     * @param array{stage: string, files: array<string, string>, delete: list<string>}|null $changes
     * @return array<string, mixed> */
    public static function runLifecycle(callable $apply, bool $databaseBackup, ?callable $preflight = null, ?array $changes = null): array
    {
        $lock = self::operationLock(); $started = false; $ownedMaintenance = false;
        try {
            self::assertIdle();
            if ($preflight !== null) { $preflight(); }
            if ($changes !== null) { self::preflightManagedTargets($changes); }
            self::enableMaintenance(\TinyCat\Support\Version::CURRENT);
            $ownedMaintenance = true;
            $backup = self::createBackup($changes ?? ['files' => [], 'delete' => []], \TinyCat\Support\Version::CURRENT, $databaseBackup);
            if ($databaseBackup) { self::backupDatabase($backup); }
            self::writeJsonFile(self::updateRoot() . '/operation.json', ['phase' => 'applying', 'backup' => basename($backup), 'kind' => 'modules']);
            $started = true;
            if ($changes !== null) { self::applyFiles($changes['stage'], $changes); }
            $result = $apply();
            if ($changes !== null) { self::deleteLegacyFiles($changes); }
            self::completeOperation($backup, \TinyCat\Support\Version::CURRENT);
            return $result + ['backup' => basename($backup)];
        } catch (Throwable $error) {
            if ($started) { self::writeJsonFile(self::updateRoot() . '/operation.json', ['phase' => 'failed', 'backup' => basename($backup ?? ''), 'kind' => 'modules']); }
            elseif ($ownedMaintenance) { self::disableMaintenance(); }
            throw $error;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** @return array<mixed> */
    public static function installLocalPackage(string $manifestPath, string $signaturePath, string $packagePath): array
    {
        $manifest = self::verifyLocalPackage($manifestPath, $signaturePath, $packagePath);
        $lock = self::operationLock();
        $stage = self::updateRoot() . '/staging/local-' . bin2hex(random_bytes(8));
        try {
            self::assertIdle(); self::assertCompatibility($manifest); self::assertFreeSpace((int) $manifest['size']);
            self::verifyFileHash($packagePath, (string) $manifest['sha256']);
            self::extractPackage($packagePath, $stage, $manifest);
            return self::applyStaged($stage, $manifest);
        } finally { self::removeDirectory($stage); flock($lock, LOCK_UN); fclose($lock); }
    }

    /** @param array<mixed> $manifest
     * @return array<mixed> */
    private static function applyStaged(string $stage, array $manifest): array
    {
        self::assertIdle(); self::assertCompatibility($manifest);
        $plan = $manifest['distribution'] ?? null;
        if (is_array($plan)) {
            \TinyCat\Core\Modules\Distribution::verifyArtifact($stage, $plan);
        }
        \TinyCat\Core\Modules\PackageRegistry::protect(\TinyCat\Support\Paths::basePath(), array_values(array_filter([...array_keys($manifest['files']), ...$manifest['delete']], is_string(...))));
        self::preflightManagedTargets($manifest);
        $pending = self::hasPendingMigrations($manifest, $stage);
        $changed = false;
        try {
            self::enableMaintenance((string) $manifest['version']);
            $backup = self::createBackup($manifest, (string) $manifest['version'], $pending);
            if ($pending) { self::backupDatabase($backup); }
            self::writeJsonFile(self::updateRoot() . '/operation.json', ['phase' => 'applying', 'backup' => basename($backup), 'kind' => 'package']);
            $changed = true;
            self::applyFiles($stage, $manifest);
            $migrations = self::applyMigrations($stage, $manifest);
            if (is_array($plan)) { \TinyCat\Core\Modules\Lifecycle::applyPackage($plan, $stage, (string) $manifest['version']); }
            self::deleteLegacyFiles($manifest);
            self::completeOperation($backup, (string) $manifest['version']);
            return ['updated' => true, 'version' => $manifest['version'], 'backup' => self::relativeStoragePath($backup), 'migrations' => $migrations, 'message' => 'TinyCat was updated successfully.'];
        } catch (Throwable $error) {
            if ($changed) { self::writeJsonFile(self::updateRoot() . '/operation.json', ['phase' => 'failed', 'backup' => basename($backup ?? ''), 'kind' => 'package']); }
            else { self::disableMaintenance(); }
            throw new RuntimeException($changed ? 'Deployment stopped after changes. Restore the recorded backup. ' . $error->getMessage() : $error->getMessage(), 0, $error);
        }
    }

    private static function completeOperation(string $backup, string $version): void
    {
        self::clearRuntimeCache(); \TinyCat\Support\Cache::resetOpcache();
        self::writeJsonFile($backup . '/completed.json', ['version' => $version, 'completed_at' => date(DATE_ATOM)]);
        self::writeJsonFile(self::updateRoot() . '/operation.json', ['phase' => 'complete', 'backup' => basename($backup)]);
        self::disableMaintenance(); \TinyCat\Support\Cache::forget(self::CACHE_KEY);
    }

    /** Restore exactly the managed changes, database and activation state recorded by this runtime.
     * @return array<string, mixed> */
    public static function rollback(string $id): array
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $id) !== 1) { throw new RuntimeException('Invalid backup ID.'); }
        $lock = self::operationLock();
        try {
            $operation = self::decodeJson((string) @file_get_contents(self::updateRoot() . '/operation.json'), 'deployment operation');
            if (($operation['backup'] ?? null) !== $id || !in_array($operation['phase'] ?? '', ['applying', 'failed', 'complete', 'restoring'], true)) { throw new RuntimeException('Only the latest deployment operation can be restored.'); }
            $base = realpath(self::updateRoot() . '/backups'); $backup = realpath(self::updateRoot() . '/backups/' . $id);
            if ($base === false || $backup === false || !\TinyCat\Core\Modules\ModuleAutoload::inside($backup, $base)) { throw new RuntimeException('Unknown deployment backup.'); }
            $metadata = self::decodeJson((string) file_get_contents($backup . '/backup.json'), 'backup');
            if (($metadata['format'] ?? null) !== 2 || !is_array($metadata['files'] ?? null) || !is_array($metadata['new_files'] ?? null) || !array_key_exists('deployment', $metadata)) { throw new RuntimeException('This backup requires the documented manual restoration procedure.'); }
            foreach ($metadata['files'] as $path => $hash) {
                $source = self::pathBelow($backup . '/files', (string) $path);
                self::assertNoManagedSymlink($source, (string) $path);
                self::verifyFileHash($source, (string) $hash);
                self::assertNoManagedSymlink(self::pathBelow(\TinyCat\Support\Paths::basePath(), (string) $path), (string) $path);
                self::assertEntryPointReplaceable(self::pathBelow(\TinyCat\Support\Paths::basePath(), (string) $path), (string) $hash);
            }
            foreach ($metadata['new_files'] as $path) { $target = self::pathBelow(\TinyCat\Support\Paths::basePath(), (string) $path); self::assertNoManagedSymlink($target, (string) $path); self::assertEntryPointReplaceable($target, ''); }
            $database = $metadata['database'] ?? [];
            if (!empty($metadata['database_backup_required']) && (!is_array($database) || $database === [])) { throw new RuntimeException('Required database snapshot is incomplete.'); }
            foreach ($database as $name => $hash) {
                if (!in_array($name, ['database.sqlite', 'database.sql', 'database.index.jsonl'], true)) { throw new RuntimeException('Invalid database snapshot path.'); }
                self::verifyFileHash($backup . '/' . $name, (string) $hash);
            }
            if (!empty($metadata['database_backup_required']) && ($metadata['database_identity'] ?? null) !== \TinyCat\Database\Snapshots::identity(\TinyCat\Database\Connection::get())) { throw new RuntimeException('Database configuration no longer points to the snapshot destination.'); }
            self::enableMaintenance((string) $metadata['from_version']);
            self::writeJsonFile(self::updateRoot() . '/operation.json', ['phase' => 'restoring', 'backup' => $id]);
            if (!empty($metadata['database_backup_required'])) { \TinyCat\Database\Snapshots::restore(\TinyCat\Database\Connection::get(), $backup); }
            foreach ($metadata['files'] as $path => $hash) {
                $source = self::pathBelow($backup . '/files', (string) $path); $target = self::pathBelow(\TinyCat\Support\Paths::basePath(), (string) $path);
                $content = file_get_contents($source);
                if (!is_string($content) || !\TinyCat\Support\Storage::write($target, $content, 0644)) { throw new RuntimeException('Cannot restore managed file: ' . $path); }
            }
            foreach ($metadata['new_files'] as $path) { $target = self::pathBelow(\TinyCat\Support\Paths::basePath(), (string) $path); if (is_file($target) && !unlink($target)) { throw new RuntimeException('Cannot remove a newly introduced managed file.'); } }
            $stateFile = \TinyCat\Support\Paths::basePath('storage/deployment.json');
            if ($metadata['deployment'] === null) { if (is_file($stateFile) && !unlink($stateFile)) { throw new RuntimeException('Cannot restore absent activation state.'); } }
            elseif (!is_array($metadata['deployment']) || !\TinyCat\Support\Storage::write($stateFile, is_string($metadata['deployment_source'] ?? null) ? $metadata['deployment_source'] : json_encode($metadata['deployment'], JSON_THROW_ON_ERROR) . "\n")) { throw new RuntimeException('Cannot restore activation state.'); }
            self::clearRuntimeCache(); \TinyCat\Support\Cache::resetOpcache();
            self::writeJsonFile(self::updateRoot() . '/operation.json', ['phase' => 'rolled_back', 'backup' => $id]);
            self::disableMaintenance();
            return ['restored' => true, 'version' => $metadata['from_version'], 'backup' => $id];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function repository(): string
    {
        $repository = trim((string) self::option('updates.repository', self::DEFAULT_REPOSITORY));

        return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) === 1
            ? $repository
            : self::DEFAULT_REPOSITORY;
    }

    /**
     * @return array<mixed>
     */
    public static function cachedRelease(): ?array
    {
        $release = \TinyCat\Support\Cache::get(self::CACHE_KEY, self::CACHE_TTL);

        return is_array($release) ? $release : null;
    }

    /**
     * @return array<mixed>
     */
    public static function check(bool $force = false): array
    {
        if (!$force && ($cached = self::cachedRelease()) !== null) {
            return $cached;
        }

        self::requireRuntimeExtensions(false);

        $apiUrl = 'https://api.github.com/repos/' . self::repository() . '/releases/latest';
        $releaseJson = self::requestText($apiUrl, self::MAX_MANIFEST_BYTES, 'application/vnd.github+json');
        $release = self::decodeJson($releaseJson, 'GitHub release response');
        $assets = self::releaseAssets($release);
        $profiles = \TinyCat\Core\Modules\Distribution::profiles(\TinyCat\Core\Modules\Loader::load(\TinyCat\Support\Paths::basePath()));
        $product = \TinyCat\Core\Modules\Distribution::read(\TinyCat\Support\Paths::basePath())['product'] ?? array_key_first($profiles);
        if (!is_string($product)) { throw new RuntimeException('No distribution profile is configured.'); }
        $stem = \TinyCat\Core\Modules\Distribution::stem($product);
        $manifestUrl = $assets[$stem . '-update.json'] ?? '';
        $signatureUrl = $assets[$stem . '-update.sig'] ?? '';

        if ($manifestUrl === '' || $signatureUrl === '') {
            throw new RuntimeException('The latest release does not contain a TinyCat update manifest and signature.');
        }

        $manifestJson = self::requestText($manifestUrl, self::MAX_MANIFEST_BYTES, 'application/octet-stream');
        $signature = self::requestText($signatureUrl, 4096, 'application/octet-stream');
        self::verifyManifestSignature($manifestJson, $signature);

        $manifest = self::validateManifest(self::decodeJson($manifestJson, 'update manifest'));
        $packageName = (string) $manifest['package'];
        $packageUrl = $assets[$packageName] ?? '';

        if ($packageUrl === '') {
            throw new RuntimeException('The package declared by the update manifest is missing from the release.');
        }

        $version = (string) $manifest['version'];
        $compatible = version_compare(\TinyCat\Support\Version::CURRENT, (string) $manifest['minimum_version'], '>=')
            && version_compare(PHP_VERSION, (string) $manifest['minimum_php'], '>=');
        if (is_array($manifest['distribution'] ?? null)) {
            try { \TinyCat\Core\Modules\Deployment::transition(\TinyCat\Support\Paths::basePath(), $manifest['distribution']); }
            catch (RuntimeException) { $compatible = false; }
        }
        $result = [
            'current_version' => \TinyCat\Support\Version::CURRENT,
            'version' => $version,
            'available' => version_compare($version, \TinyCat\Support\Version::CURRENT, '>'),
            'compatible' => $compatible,
            'published_at' => trim((string) ($release['published_at'] ?? '')),
            'release_url' => self::httpsUrl((string) ($release['html_url'] ?? '')),
            'notes' => trim((string) ($release['body'] ?? '')),
            'manifest' => $manifest,
            'package_url' => $packageUrl,
        ];

        \TinyCat\Support\Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * @return array<mixed>
     */
    public static function installLatest(): array
    {
        self::requireRuntimeExtensions(true);
        @set_time_limit(600);
        ignore_user_abort(true);
        $release = self::check(true);

        if (empty($release['available'])) {
            return [
                'updated' => false,
                'version' => \TinyCat\Support\Version::CURRENT,
                'message' => 'TinyCat is already up to date.',
            ];
        }

        $manifest = (array) ($release['manifest'] ?? []);
        self::assertCompatibility($manifest);
        self::assertFreeSpace((int) ($manifest['size'] ?? 0));

        $updateRoot = self::updateRoot();
        $lock = self::operationLock();

        $version = (string) $manifest['version'];
        $workId = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $workDirectory = $updateRoot . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $workId;
        $packagePath = $workDirectory . DIRECTORY_SEPARATOR . (string) $manifest['package'];

        try {
            self::ensureDirectory($workDirectory);
            self::downloadGitHubFile(
                (string) $release['package_url'],
                $packagePath,
                self::MAX_PACKAGE_BYTES,
                'application/octet-stream',
                'updater',
                'update'
            );
            self::verifyFileHash($packagePath, (string) $manifest['sha256']);

            $stageDirectory = $workDirectory . DIRECTORY_SEPARATOR . 'package';
            self::extractPackage($packagePath, $stageDirectory, $manifest);
            self::preflightManagedTargets($manifest);
            return self::applyStaged($stageDirectory, $manifest);
        } finally {
            self::removeDirectory($workDirectory);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<mixed>
     */
    public static function verifyLocalPackage(string $manifestPath, string $signaturePath, string $packagePath): array
    {
        foreach ([$manifestPath, $signaturePath, $packagePath] as $path) {
            if (!is_file($path)) {
                throw new RuntimeException('Update verification input is missing: ' . basename($path));
            }
        }

        $manifestJson = (string) file_get_contents($manifestPath);
        $signature = (string) file_get_contents($signaturePath);
        self::verifyManifestSignature($manifestJson, $signature);
        $manifest = self::validateManifest(self::decodeJson($manifestJson, 'update manifest'));

        if (basename($packagePath) !== (string) $manifest['package']) {
            throw new RuntimeException('The local package name does not match the update manifest.');
        }

        self::verifyFileHash($packagePath, (string) $manifest['sha256']);
        $stage = self::updateRoot() . DIRECTORY_SEPARATOR . 'verification' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));

        try {
            self::extractPackage($packagePath, $stage, $manifest);
        } finally {
            self::removeDirectory($stage);
        }

        return $manifest;
    }

    /**
     * @return array<mixed>
     */
    public static function migrationHistory(): array
    {
        try {
            return MigrationRegistry::history();
        } catch (Throwable) {
            return [];
        }
    }

    public static function maintenanceActive(): bool
    {
        return is_file(self::maintenanceFile());
    }

    /**
     * @return array<mixed>
     */
    public static function maintenanceState(): array
    {
        $json = @file_get_contents(self::maintenanceFile());

        if (!is_string($json) || $json === '') {
            return [];
        }

        try {
            $state = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            return is_array($state) ? $state : [];
        } catch (JsonException) {
            return [];
        }
    }

    public static function enforceMaintenance(string $path): void
    {
        if (!self::maintenanceActive() || self::maintenancePathAllowed($path)) {
            return;
        }

        header('Retry-After: 300');
        if (self::$unavailable !== null) { (self::$unavailable)(503); }
        else { http_response_code(503); echo 'Maintenance in progress.'; }
        exit;
    }

    public static function disableMaintenance(): void
    {
        $file = self::maintenanceFile();

        if (is_file($file) && !@unlink($file)) {
            throw new RuntimeException('Unable to disable maintenance mode.');
        }
    }

    /**
     * @param array<mixed> $release
     * @return array<mixed>
     */
    private static function releaseAssets(array $release): array
    {
        $assets = [];

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = trim((string) ($asset['name'] ?? ''));
            $url = self::httpsUrl((string) ($asset['browser_download_url'] ?? ''));

            if ($name !== '' && $url !== '') {
                $assets[$name] = $url;
            }
        }

        return $assets;
    }

    /**
     * @param array<mixed> $manifest
     * @return array<mixed>
     */
    private static function validateManifest(array $manifest): array
    {
        $version = trim((string) ($manifest['version'] ?? ''));
        $minimumVersion = trim((string) ($manifest['minimum_version'] ?? ''));
        $minimumPhp = trim((string) ($manifest['minimum_php'] ?? ''));
        $package = trim((string) ($manifest['package'] ?? ''));
        $sha256 = strtolower(trim((string) ($manifest['sha256'] ?? '')));
        $size = (int) ($manifest['size'] ?? 0);
        $files = $manifest['files'] ?? null;
        $delete = $manifest['delete'] ?? [];
        $migrations = $manifest['migrations'] ?? [];

        if (
            !self::validVersion($version)
            || !self::validVersion($minimumVersion)
            || !self::validVersion($minimumPhp)
        ) {
            throw new RuntimeException('The update manifest contains an invalid version.');
        }

        if (
            preg_match('/^[A-Za-z0-9._-]+\.zip$/', $package) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
            || $size < 1
            || $size > self::MAX_PACKAGE_BYTES
        ) {
            throw new RuntimeException('The update manifest contains invalid package metadata.');
        }

        if (!is_array($files) || $files === [] || !is_array($delete) || !is_array($migrations)) {
            throw new RuntimeException('The update manifest has an invalid file list.');
        }

        $normalizedFiles = [];
        $normalizedFileKeys = [];

        foreach ($files as $path => $hash) {
            $path = self::managedPath((string) $path);
            $hash = strtolower(trim((string) $hash));
            $pathKey = strtolower($path);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new RuntimeException('Invalid file hash in the update manifest: ' . $path);
            }

            if (isset($normalizedFileKeys[$pathKey])) {
                throw new RuntimeException('Colliding paths in the update manifest: ' . $path);
            }

            $normalizedFiles[$path] = $hash;
            $normalizedFileKeys[$pathKey] = true;
        }

        self::assertDistinctPackagePaths(
            array_keys($normalizedFiles),
            'Colliding paths in the update manifest'
        );

        $normalizedDelete = [];

        foreach ($delete as $path) {
            $path = self::managedPath((string) $path);

            if (isset($normalizedFileKeys[strtolower($path)])) {
                throw new RuntimeException('A managed file cannot also be deleted by the same update: ' . $path);
            }

            $normalizedDelete[] = $path;
        }

        self::assertDistinctPackagePaths(
            $normalizedDelete,
            'Colliding deletion paths in the update manifest'
        );

        $normalizedMigrations = [];

        foreach ($migrations as $path) {
            $path = self::managedPath((string) $path);

            if (!str_starts_with($path, 'migrations/') || !str_ends_with($path, '.php') || !isset($normalizedFiles[$path])) {
                throw new RuntimeException('Invalid migration path in the update manifest: ' . $path);
            }

            $normalizedMigrations[] = $path;
        }

        $manifest['version'] = $version;
        $manifest['minimum_version'] = $minimumVersion;
        $manifest['minimum_php'] = $minimumPhp;
        $manifest['package'] = $package;
        $manifest['sha256'] = $sha256;
        $manifest['size'] = $size;
        $manifest['files'] = $normalizedFiles;
        $manifest['delete'] = array_values(array_unique($normalizedDelete));
        $manifest['migrations'] = array_values(array_unique($normalizedMigrations));

        if (isset($manifest['kind']) && !in_array($manifest['kind'], ['install', 'update'], true)) { throw new RuntimeException('Invalid artifact kind.'); }
        if (array_key_exists('distribution', $manifest)) {
            if (!isset($manifest['kind'])) { throw new RuntimeException('Product packages must declare their artifact kind.'); }
            if (!is_array($manifest['distribution'])) { throw new RuntimeException('Invalid signed product descriptor.'); }
            $plan = \TinyCat\Core\Modules\Distribution::validate($manifest['distribution']);
            if (!isset($normalizedFiles['App/distribution.json'])) { throw new RuntimeException('A product package must contain its descriptor.'); }
            if (\TinyCat\Core\Modules\Distribution::modular($plan) && version_compare($minimumVersion, \TinyCat\Core\Modules\Distribution::RUNTIME, '<')) { throw new RuntimeException('Module-aware packages must require a module-aware runtime.'); }
            if ($plan['format'] === 2 && \TinyCat\Core\Modules\Distribution::modular($plan) && version_compare($minimumVersion, \TinyCat\Core\Modules\Distribution::FRAMEWORK_RUNTIME, '<')) { throw new RuntimeException('Framework packages require the activation transition runtime.'); }
            if ($plan['format'] === 3 && version_compare($minimumVersion, \TinyCat\Core\Modules\Distribution::DOMAIN_RUNTIME, '<')) { throw new RuntimeException('Domain-owned schema packages require the domain transition runtime.'); }
            foreach ($plan['schemas'] as $schema) {
                foreach ($schema['migrations'] as $migration) { if (($normalizedFiles[$migration['file']] ?? '') !== $migration['checksum']) { throw new RuntimeException('Signed module migration is missing or inconsistent.'); } }
            }
            $manifest['distribution'] = $plan;
        } elseif (isset($normalizedFiles['App/distribution.json'])) { throw new RuntimeException('Unsigned product identity is not allowed.'); }

        return $manifest;
    }

    private static function verifyManifestSignature(string $manifest, string $signature): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('The Sodium extension is required to verify TinyCat updates.');
        }

        $publicKeyEncoded = trim((string) self::option('updates.public_key', self::DEFAULT_PUBLIC_KEY));
        $publicKey = base64_decode($publicKeyEncoded, true);
        $signatureRaw = base64_decode(trim($signature), true);

        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('The TinyCat update signing key is not configured.');
        }

        if (!is_string($signatureRaw) || strlen($signatureRaw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('The update manifest signature is invalid.');
        }

        if (!sodium_crypto_sign_verify_detached($signatureRaw, $manifest, $publicKey)) {
            throw new RuntimeException('The update manifest signature could not be verified.');
        }
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function assertCompatibility(array $manifest): void
    {
        if (($manifest['kind'] ?? 'update') !== 'update') { throw new RuntimeException('An installation artifact cannot be applied as an update.'); }
        $plan = $manifest['distribution'] ?? null;
        if (is_array($plan)) { \TinyCat\Core\Modules\Deployment::transition(\TinyCat\Support\Paths::basePath(), $plan); }
        else {
            $current = \TinyCat\Core\Modules\Distribution::read(\TinyCat\Support\Paths::basePath());
            if ($current !== null && \TinyCat\Core\Modules\Distribution::modular($current)) { throw new RuntimeException('This product requires a module-aware update package.'); }
        }
        $version = (string) ($manifest['version'] ?? '');
        $minimumVersion = (string) ($manifest['minimum_version'] ?? '');
        $minimumPhp = (string) ($manifest['minimum_php'] ?? '');

        if (!version_compare($version, \TinyCat\Support\Version::CURRENT, '>')) {
            throw new RuntimeException('The selected release is not newer than the installed version.');
        }

        if (version_compare(\TinyCat\Support\Version::CURRENT, $minimumVersion, '<')) {
            throw new RuntimeException('This release requires TinyCat ' . $minimumVersion . ' or newer.');
        }

        if (version_compare(PHP_VERSION, $minimumPhp, '<')) {
            throw new RuntimeException('This release requires PHP ' . $minimumPhp . ' or newer.');
        }
    }

    private static function requireRuntimeExtensions(bool $install): void
    {
        $required = ['curl', 'sodium'];

        $missing = array_values(array_filter($required, static fn (string $extension): bool => !extension_loaded($extension)));

        if ($install && !class_exists('ZipArchive') && !class_exists('PharData')) {
            $missing[] = 'zip or phar';
        }

        if ($missing !== []) {
            throw new RuntimeException('Missing PHP extensions required for updates: ' . implode(', ', $missing) . '.');
        }
    }

    private static function assertFreeSpace(int $packageSize): void
    {
        $directory = self::updateRoot();
        self::ensureDirectory($directory);
        $free = disk_free_space($directory);
        $required = max(50 * 1024 * 1024, $packageSize * 3 + 20 * 1024 * 1024);

        if (is_float($free) && $free < $required) {
            throw new RuntimeException('There is not enough free disk space to stage and back up the update.');
        }
    }

    private static function requestText(string $url, int $maxBytes, string $accept): string
    {
        $directory = self::updateRoot() . DIRECTORY_SEPARATOR . 'downloads';
        self::ensureDirectory($directory);
        $temporary = tempnam($directory, '.tinycat-download-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create an update download file.');
        }

        try {
            self::downloadGitHubFile($url, $temporary, $maxBytes, $accept, 'updater', 'update');
            $content = file_get_contents($temporary);

            if (!is_string($content)) {
                throw new RuntimeException('Unable to read downloaded update metadata.');
            }

            return $content;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function extractPackage(string $packagePath, string $stageDirectory, array $manifest): void
    {
        self::extractVerifiedPackage(
            $packagePath,
            $stageDirectory,
            (array) ($manifest['files'] ?? []),
            static fn (string $path, bool $directory): string => $directory
                ? self::managedDirectoryPath($path)
                : self::managedPath($path),
            self::MAX_PACKAGE_FILES,
            self::MAX_PACKAGE_BYTES,
            'update'
        );
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function preflightManagedTargets(array $manifest): void
    {
        $base = \TinyCat\Support\Paths::basePath();

        if (!is_dir($base) || !is_writable($base)) {
            throw new RuntimeException('The TinyCat application directory is not writable.');
        }

        $targets = array_values(array_unique(array_merge(
            array_keys((array) ($manifest['files'] ?? [])),
            (array) ($manifest['delete'] ?? [])
        )));

        foreach ($targets as $path) {
            $target = self::pathBelow($base, (string) $path);
            self::assertNoManagedSymlink($target, (string) $path);
            self::assertEntryPointReplaceable($target, (string) ($manifest['files'][$path] ?? ''));
            $existing = is_file($target) ? $target : dirname($target);

            while (!file_exists($existing) && dirname($existing) !== $existing) {
                $existing = dirname($existing);
            }

            if (!is_writable($existing)) {
                throw new RuntimeException('Update target is not writable: ' . $path);
            }
        }
    }

    private static function assertEntryPointReplaceable(string $target, string $expectedHash): void
    {
        if (PHP_OS_FAMILY !== 'Windows') { return; }
        $entry = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $resolved = realpath($target);
        if ($entry !== false && $resolved !== false && strcasecmp($entry, $resolved) === 0 && ($expectedHash === '' || hash_file('sha256', $target) !== $expectedHash)) { throw new RuntimeException('Use the private rescue CLI to replace the running entry point: php App/Console.php rescue.'); }
    }

    private static function assertNoManagedSymlink(string $target, string $relative): void
    {
        $base = rtrim(\TinyCat\Support\Paths::basePath(), DIRECTORY_SEPARATOR);
        $current = $base;

        foreach (explode(DIRECTORY_SEPARATOR, substr($target, strlen($base) + 1)) as $segment) {
            if ($segment === '') {
                continue;
            }

            $current .= DIRECTORY_SEPARATOR . $segment;

            if (is_link($current)) {
                throw new RuntimeException('Update target contains a symbolic link: ' . $relative);
            }
        }
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function createBackup(array $manifest, string $targetVersion, bool $databaseBackupRequired = false): string
    {
        $name = \TinyCat\Support\Version::CURRENT . '-to-' . $targetVersion . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $backup = self::updateRoot() . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $name;
        $filesDirectory = $backup . DIRECTORY_SEPARATOR . 'files';
        self::ensureDirectory($filesDirectory);
        $paths = array_values(array_unique(array_merge(
            array_keys((array) ($manifest['files'] ?? [])),
            (array) ($manifest['delete'] ?? [])
        )));
        $backedUp = []; $newFiles = [];

        foreach ($paths as $path) {
            $path = self::managedPath((string) $path);
            $source = self::pathBelow(\TinyCat\Support\Paths::basePath(), $path);

            if (!is_file($source)) {
                $newFiles[] = $path;
                continue;
            }

            $targetFiles = (array) ($manifest['files'] ?? []);
            $targetHash = (string) ($targetFiles[$path] ?? '');
            $sourceHash = hash_file('sha256', $source);

            if (!is_string($sourceHash)) {
                throw new RuntimeException('Unable to hash application file for backup: ' . $path);
            }

            // Files present in the package are not necessarily changed: every
            // signed release ships the complete managed tree. A deletion always
            // needs a copy; a replacement only does when its contents differ.
            if ($targetHash !== '' && hash_equals($targetHash, $sourceHash)) {
                continue;
            }

            $target = self::pathBelow($filesDirectory, $path);
            self::ensureDirectory(dirname($target));

            if (!copy($source, $target)) {
                throw new RuntimeException('Unable to back up application file: ' . $path);
            }

            $backedUp[$path] = $sourceHash;
        }

        $metadata = [
            'format' => 2,
            'created_at' => date(DATE_ATOM),
            'from_version' => \TinyCat\Support\Version::CURRENT,
            'to_version' => $targetVersion,
            'files' => $backedUp,
            'new_files' => $newFiles,
            'deployment' => \TinyCat\Core\Modules\Deployment::read(\TinyCat\Support\Paths::basePath()),
            'deployment_source' => is_file(\TinyCat\Support\Paths::basePath('storage/deployment.json')) ? file_get_contents(\TinyCat\Support\Paths::basePath('storage/deployment.json')) : null,
            'database_backup_required' => $databaseBackupRequired,
        ];
        self::writeJsonFile($backup . DIRECTORY_SEPARATOR . 'backup.json', $metadata);

        return $backup;
    }

    private static function backupDatabase(string $backupDirectory): void
    {
        \TinyCat\Database\Snapshots::write(\TinyCat\Database\Connection::get(), $backupDirectory);
        $metadataFile = $backupDirectory . '/backup.json';
        if (is_file($metadataFile)) {
            $metadata = self::decodeJson((string) file_get_contents($metadataFile), 'backup');
            $metadata['database_identity'] = \TinyCat\Database\Snapshots::identity(\TinyCat\Database\Connection::get());
            $metadata['database'] = [];
            foreach (['database.sqlite', 'database.sql', 'database.index.jsonl'] as $name) {
                if (is_file($backupDirectory . '/' . $name)) { $metadata['database'][$name] = hash_file('sha256', $backupDirectory . '/' . $name); }
            }
            self::writeJsonFile($metadataFile, $metadata);
        }
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function applyFiles(string $stageDirectory, array $manifest): void
    {
        foreach (array_keys((array) ($manifest['files'] ?? [])) as $path) {
            $path = self::managedPath((string) $path);
            $source = self::pathBelow($stageDirectory, $path);
            $target = self::pathBelow(\TinyCat\Support\Paths::basePath(), $path);
            if (is_file($target) && hash_file('sha256', $target) === ($manifest['files'][$path] ?? '')) { continue; }
            self::ensureDirectory(dirname($target));
            $temporary = $target . '.tinycat-update-' . bin2hex(random_bytes(4));

            if (!copy($source, $temporary)) {
                throw new RuntimeException('Unable to stage application file: ' . $path);
            }

            @chmod($temporary, 0664);

            if (@rename($temporary, $target)) {
                continue;
            }

            if (is_file($target) && @unlink($target) && @rename($temporary, $target)) {
                continue;
            }

            @unlink($temporary);
            throw new RuntimeException('Unable to replace application file: ' . $path);
        }
    }

    /**
     * @param array<mixed> $manifest
     * @return array<mixed>
     */
    private static function applyMigrations(string $stageDirectory, array $manifest): array
    {
        $paths = (array) ($manifest['migrations'] ?? []);

        if ($paths === []) {
            return [];
        }

        MigrationRegistry::ensure();
        $applied = [];
        $version = (string) ($manifest['version'] ?? '');

        foreach ($paths as $path) {
            $path = self::managedPath((string) $path);
            $migration = pathinfo($path, PATHINFO_FILENAME);
            $checksum = (string) ((array) $manifest['files'])[$path];
            $file = self::pathBelow($stageDirectory, $path);
            if (MigrationRegistry::apply($migration, $version, $file, $checksum)) {
                $applied[] = $migration;
            }
        }

        return $applied;
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function hasPendingMigrations(array $manifest, ?string $stageRoot = null): bool
    {
        $modulePending = false;
        if (is_array($manifest['distribution'] ?? null)) {
            if ($stageRoot === null) { throw new RuntimeException('Verified staged module sources are required.'); }
            $transition = \TinyCat\Core\Modules\Deployment::transition(\TinyCat\Support\Paths::basePath(), $manifest['distribution']);
            $effective = \TinyCat\Core\Modules\PackageRegistry::extend(\TinyCat\Support\Paths::basePath(), $manifest['distribution']);
            $schemas = \TinyCat\Core\Modules\Distribution::schemas($effective, $stageRoot, $transition['installed'], \TinyCat\Support\Paths::basePath());
            $modulePending = $schemas !== [] && \TinyCat\Database\ModuleSchemas::pending(\TinyCat\Database\Connection::get(), $schemas);
        }
        $pending = [];
        $files = (array) ($manifest['files'] ?? []);

        foreach ((array) ($manifest['migrations'] ?? []) as $path) {
            $path = self::managedPath((string) $path);
            $pending[pathinfo($path, PATHINFO_FILENAME)] = (string) ($files[$path] ?? '');
        }

        return ($pending !== [] && MigrationRegistry::hasPending($pending)) || $modulePending;
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function deleteLegacyFiles(array $manifest): void
    {
        foreach ((array) ($manifest['delete'] ?? []) as $path) {
            $path = self::managedPath((string) $path);
            $target = self::pathBelow(\TinyCat\Support\Paths::basePath(), $path);

            if (is_file($target) && !@unlink($target)) {
                throw new RuntimeException('Unable to remove legacy application file: ' . $path);
            }

            self::removeEmptyManagedDirectories(dirname($target));
        }
    }

    private static function clearRuntimeCache(): void
    {
        $cache = \TinyCat\Support\Cache::directory();

        if (!is_dir($cache)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            // Immutable URLs can still be referenced by pages opened before an update.
            $relative = str_replace('\\', '/', substr($path, strlen($cache) + 1));
            if ($relative === 'assets' || str_starts_with($relative, 'assets/')) { continue; }

            if ($entry->isLink()) {
                continue;
            }

            if ($entry->isDir()) {
                @rmdir($path);
            } elseif ($entry->isFile()) {
                @unlink($path);
            }
        }
    }

    private static function enableMaintenance(string $version): void
    {
        self::ensureDirectory(dirname(self::maintenanceFile()));
        self::writeJsonFile(self::maintenanceFile(), [
            'started_at' => date(DATE_ATOM),
            'from_version' => \TinyCat\Support\Version::CURRENT,
            'to_version' => $version,
        ]);
    }

    private static function maintenancePathAllowed(string $path): bool
    {
        return $path === '/admin/updates'
            || str_starts_with($path, '/api/updates/')
            || $path === '/login'
            || $path === '/logout'
            || $path === '/api/users/sessions'
            || $path === '/api/users/sessions/current'
            || $path === '/api/installation'
            || str_starts_with($path, '/api/installation/')
            || $path === '/install'
            || str_starts_with($path, '/install/');
    }

    private static function maintenanceFile(): string
    {
        return \TinyCat\Support\Storage::path('maintenance.json');
    }

    private static function updateRoot(): string
    {
        return \TinyCat\Support\Storage::path('updates');
    }

    private static function managedPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (
            $path === ''
            || strlen($path) > 240
            || str_contains($path, "\0")
            || str_contains($path, '//')
            || str_ends_with($path, '/')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || preg_match('/(^|\/)\.\.?($|\/)/', $path) === 1
            || preg_match('/^[A-Za-z0-9._\/-]+$/', $path) !== 1
        ) {
            throw new RuntimeException('Unsafe path in update package.');
        }

        $root = explode('/', $path, 2)[0];
        $allowedRoots = ['App', 'Public', 'assets', 'docs', 'lang', 'migrations'];
        $allowedFiles = ['index.php', 'scheduled-tasks.php', '.htaccess', 'LICENSE', 'README.md'];

        if (!in_array($root, $allowedRoots, true) && !in_array($path, $allowedFiles, true)) {
            throw new RuntimeException('Update package targets a protected path: ' . $path);
        }

        return $path;
    }

    private static function managedDirectoryPath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        return self::managedPath($path . '/.directory-placeholder');
    }

    private static function pathBelow(string $root, string $relative): string
    {
        $relative = self::managedPath($relative);

        return rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private static function verifyFileHash(string $file, string $expected): void
    {
        $actual = hash_file('sha256', $file);

        if (!is_string($actual) || !hash_equals(strtolower($expected), strtolower($actual))) {
            throw new RuntimeException('Update file integrity verification failed: ' . basename($file));
        }
    }

    /**
     * @param array<mixed> $data
     */
    private static function writeJsonFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!\TinyCat\Support\Storage::write($path, $json . "\n")) {
            throw new RuntimeException('Unable to write update state.');
        }
    }

    private static function removeDirectory(string $directory): void
    {
        $root = realpath(self::updateRoot());
        $target = realpath($directory);

        if ($root === false || $target === false || $target === $root || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }

        @rmdir($target);
    }

    private static function removeEmptyManagedDirectories(string $directory): void
    {
        $base = rtrim(\TinyCat\Support\Paths::basePath(), DIRECTORY_SEPARATOR);
        $current = $directory;

        while ($current !== $base && str_starts_with($current, $base . DIRECTORY_SEPARATOR)) {
            if (!@rmdir($current)) {
                break;
            }

            $current = dirname($current);
        }
    }

    private static function relativeStoragePath(string $path): string
    {
        $base = rtrim(\TinyCat\Support\Paths::basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base)))
            : $path;
    }
}
