<?php
declare(strict_types=1);

namespace TinyCat {
    use JsonException;
    use RuntimeException;
    use ZipArchive;

    if (!defined('TINYCAT')) {
        http_response_code(403);
        exit('Forbidden');
    }

    /**
     * Shared validation primitives for signed core and extension packages.
     */
    abstract class PackageManager
    {
        protected static function githubUrl(string $url, string $errorMessage): string
        {
            $url = self::httpsUrl($url);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $allowed = $host === 'github.com'
                || $host === 'api.github.com'
                || str_ends_with($host, '.githubusercontent.com');

            if ($url === '' || !$allowed) {
                throw new RuntimeException($errorMessage);
            }

            return $url;
        }

        protected static function httpsUrl(string $url): string
        {
            $url = trim($url);
            $parts = parse_url($url);

            if (
                !is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || trim((string) ($parts['host'] ?? '')) === ''
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return '';
            }

            return $url;
        }

        protected static function decodeJson(string $json, string $label, int $depth = 512): array
        {
            try {
                $decoded = json_decode($json, true, $depth, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Invalid ' . $label . ': ' . $exception->getMessage(), 0, $exception);
            }

            if (!is_array($decoded)) {
                throw new RuntimeException('Invalid ' . $label . '.');
            }

            return $decoded;
        }

        protected static function validVersion(string $version): bool
        {
            return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1;
        }

        protected static function zipEntryIsSymlink(ZipArchive $zip, int $index): bool
        {
            $operations = 0;
            $attributes = 0;

            return $zip->getExternalAttributesIndex($index, $operations, $attributes)
                && $operations === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & 0170000) === 0120000;
        }
    }
}

namespace TinyCat\Update {
    use Cache;
    use Core;
    use FilesystemIterator;
    use InvalidArgumentException;
    use JsonException;
    use PDO;
    use PharData;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use RuntimeException;
    use SplFileInfo;
    use Throwable;
    use ZipArchive;

final class Manager extends \TinyCat\PackageManager
{
    private const string DEFAULT_REPOSITORY = 'hybernia1/TinyCat';
    private const string MANIFEST_ASSET = 'tinycat-update.json';
    private const string SIGNATURE_ASSET = 'tinycat-update.sig';
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

    public static function repository(): string
    {
        $repository = trim((string) config('updates.repository', self::DEFAULT_REPOSITORY));

        return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) === 1
            ? $repository
            : self::DEFAULT_REPOSITORY;
    }

    public static function cachedRelease(): ?array
    {
        $release = Cache::get(self::CACHE_KEY, self::CACHE_TTL);

        return is_array($release) ? $release : null;
    }

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
        $manifestUrl = $assets[self::MANIFEST_ASSET] ?? '';
        $signatureUrl = $assets[self::SIGNATURE_ASSET] ?? '';

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
        $compatible = version_compare(Core::VERSION, (string) $manifest['minimum_version'], '>=')
            && version_compare(PHP_VERSION, (string) $manifest['minimum_php'], '>=');
        $result = [
            'current_version' => Core::VERSION,
            'version' => $version,
            'available' => version_compare($version, Core::VERSION, '>'),
            'compatible' => $compatible,
            'published_at' => trim((string) ($release['published_at'] ?? '')),
            'release_url' => self::httpsUrl((string) ($release['html_url'] ?? '')),
            'notes' => trim((string) ($release['body'] ?? '')),
            'manifest' => $manifest,
            'package_url' => $packageUrl,
        ];

        Cache::put(self::CACHE_KEY, $result);

        return $result;
    }

    public static function installLatest(): array
    {
        self::requireRuntimeExtensions(true);
        @set_time_limit(600);
        ignore_user_abort(true);
        $release = self::check(true);

        if (empty($release['available'])) {
            return [
                'updated' => false,
                'version' => Core::VERSION,
                'message' => 'TinyCat is already up to date.',
            ];
        }

        $manifest = (array) ($release['manifest'] ?? []);
        self::assertCompatibility($manifest);
        self::assertFreeSpace((int) ($manifest['size'] ?? 0));

        $updateRoot = self::updateRoot();
        self::ensureDirectory($updateRoot);
        $lockPath = $updateRoot . DIRECTORY_SEPARATOR . 'update.lock';
        $lock = fopen($lockPath, 'c+');

        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Another update is already running.');
        }

        $version = (string) $manifest['version'];
        $workId = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $workDirectory = $updateRoot . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $workId;
        $packagePath = $workDirectory . DIRECTORY_SEPARATOR . (string) $manifest['package'];
        $filesChanged = false;

        try {
            self::ensureDirectory($workDirectory);
            self::downloadToFile((string) $release['package_url'], $packagePath, self::MAX_PACKAGE_BYTES);
            self::verifyFileHash($packagePath, (string) $manifest['sha256']);

            $stageDirectory = $workDirectory . DIRECTORY_SEPARATOR . 'package';
            self::extractPackage($packagePath, $stageDirectory, $manifest);
            self::preflightManagedTargets($manifest);
            $databaseBackupRequired = self::hasPendingMigrations($manifest);

            self::enableMaintenance($version);
            $backupDirectory = self::createBackup($manifest, $version, $databaseBackupRequired);

            // Release packages contain the complete managed file tree. Back up
            // only files that will actually be replaced or removed, and avoid
            // an expensive database dump when every release migration is
            // already applied.
            if ($databaseBackupRequired) {
                self::backupDatabase($backupDirectory);
            }

            $filesChanged = true;
            self::applyFiles($stageDirectory, $manifest);
            $migrations = self::applyMigrations($stageDirectory, $manifest);
            self::deleteLegacyFiles($manifest);
            self::clearRuntimeCache();
            self::disableMaintenance();
            Cache::forget(self::CACHE_KEY);

            return [
                'updated' => true,
                'version' => $version,
                'backup' => self::relativeStoragePath($backupDirectory),
                'migrations' => $migrations,
                'message' => 'TinyCat was updated successfully.',
            ];
        } catch (Throwable $exception) {
            if (!$filesChanged) {
                self::disableMaintenance();
            }

            throw new RuntimeException(
                $filesChanged
                    ? 'The update stopped after application files were changed. Maintenance mode remains active. Restore the recorded backup before reopening the site. ' . $exception->getMessage()
                    : $exception->getMessage(),
                0,
                $exception
            );
        } finally {
            self::removeDirectory($workDirectory);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

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

        http_response_code(503);
        header('Retry-After: 300');
        header('Cache-Control: no-store, max-age=0');

        if (str_starts_with($path, '/api') || Core::wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'maintenance',
                'message' => 'TinyCat is being updated. Please try again shortly.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>TinyCat maintenance</title></head><body>'
            . '<main style="max-width:42rem;margin:10vh auto;padding:2rem;font:16px/1.5 system-ui,sans-serif">'
            . '<h1>TinyCat is being updated</h1><p>Please try again shortly.</p></main></body></html>';
        exit;
    }

    public static function disableMaintenance(): void
    {
        $file = self::maintenanceFile();

        if (is_file($file) && !@unlink($file)) {
            throw new RuntimeException('Unable to disable maintenance mode.');
        }
    }

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

        $normalizedDelete = [];

        foreach ($delete as $path) {
            $path = self::managedPath((string) $path);

            if (isset($normalizedFileKeys[strtolower($path)])) {
                throw new RuntimeException('A managed file cannot also be deleted by the same update: ' . $path);
            }

            $normalizedDelete[] = $path;
        }

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

        return $manifest;
    }

    private static function verifyManifestSignature(string $manifest, string $signature): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('The Sodium extension is required to verify TinyCat updates.');
        }

        $publicKeyEncoded = trim((string) config('updates.public_key', self::DEFAULT_PUBLIC_KEY));
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

    private static function assertCompatibility(array $manifest): void
    {
        $version = (string) ($manifest['version'] ?? '');
        $minimumVersion = (string) ($manifest['minimum_version'] ?? '');
        $minimumPhp = (string) ($manifest['minimum_php'] ?? '');

        if (!version_compare($version, Core::VERSION, '>')) {
            throw new RuntimeException('The selected release is not newer than the installed version.');
        }

        if (version_compare(Core::VERSION, $minimumVersion, '<')) {
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

        if ((is_int($free) || is_float($free)) && $free < $required) {
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
            self::downloadToFile($url, $temporary, $maxBytes, $accept);
            $content = file_get_contents($temporary);

            if (!is_string($content)) {
                throw new RuntimeException('Unable to read downloaded update metadata.');
            }

            return $content;
        } finally {
            @unlink($temporary);
        }
    }

    private static function downloadToFile(string $url, string $target, int $maxBytes, string $accept = 'application/octet-stream'): void
    {
        self::githubUrl($url, 'The update source host is not allowed.');
        $directory = dirname($target);
        self::ensureDirectory($directory);
        $handle = fopen($target, 'wb');

        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create the update download file.');
        }

        $curl = curl_init($url);

        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('Unable to initialize the update download.');
        }

        $written = 0;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TinyCat/' . Core::VERSION . ' updater',
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28'],
            CURLOPT_WRITEFUNCTION => static function ($resource, string $chunk) use ($handle, $maxBytes, &$written): int {
                $length = strlen($chunk);
                $written += $length;

                if ($written > $maxBytes) {
                    return 0;
                }

                $result = fwrite($handle, $chunk);
                return $result === false ? 0 : $result;
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        try {
            self::githubUrl($effectiveUrl, 'The update source host is not allowed.');
        } catch (Throwable $exception) {
            @unlink($target);
            throw $exception;
        }

        if ($ok !== true || $status < 200 || $status >= 300 || $written > $maxBytes) {
            @unlink($target);
            throw new RuntimeException(
                $written > $maxBytes
                    ? 'The update download exceeded the allowed size.'
                    : 'The update download failed with HTTP ' . $status . ($error !== '' ? ': ' . $error : '.')
            );
        }

        @chmod($target, 0600);
    }

    private static function extractPackage(string $packagePath, string $stageDirectory, array $manifest): void
    {
        self::ensureDirectory($stageDirectory);

        if (!class_exists('ZipArchive')) {
            self::extractPackageWithPhar($packagePath, $stageDirectory, $manifest);
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($packagePath) !== true) {
            throw new RuntimeException('Unable to open the downloaded update package.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_PACKAGE_FILES) {
                throw new RuntimeException('The update package contains an invalid number of files.');
            }

            $expected = (array) ($manifest['files'] ?? []);
            $seen = [];
            $totalSize = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if (!is_array($stat)) {
                    throw new RuntimeException('Unable to inspect the update package.');
                }

                $rawName = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

                if (str_ends_with($rawName, '/')) {
                    self::managedDirectoryPath($rawName);
                    continue;
                }

                $path = self::managedPath($rawName);

                if (!array_key_exists($path, $expected) || isset($seen[$path])) {
                    throw new RuntimeException('Unexpected or duplicate file in the update package: ' . $path);
                }

                if (self::zipEntryIsSymlink($zip, $index)) {
                    throw new RuntimeException('Symbolic links are not allowed in update packages.');
                }

                $size = max(0, (int) ($stat['size'] ?? 0));
                $totalSize += $size;

                if ($totalSize > self::MAX_PACKAGE_BYTES) {
                    throw new RuntimeException('The extracted update package exceeds the allowed size.');
                }

                $target = self::pathBelow($stageDirectory, $path);
                self::ensureDirectory(dirname($target));
                $input = $zip->getStream((string) $stat['name']);
                $output = fopen($target, 'wb');

                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new RuntimeException('Unable to extract update file: ' . $path);
                }

                $copied = stream_copy_to_stream($input, $output, self::MAX_PACKAGE_BYTES + 1);
                fclose($input);
                fclose($output);

                if (!is_int($copied) || $copied !== $size) {
                    throw new RuntimeException('The extracted update file has an invalid size: ' . $path);
                }

                self::verifyFileHash($target, (string) $expected[$path]);
                $seen[$path] = true;
            }

            $missing = array_diff(array_keys($expected), array_keys($seen));

            if ($missing !== []) {
                throw new RuntimeException('The update package is missing files: ' . implode(', ', array_slice($missing, 0, 5)));
            }
        } finally {
            $zip->close();
        }
    }

    private static function extractPackageWithPhar(string $packagePath, string $stageDirectory, array $manifest): void
    {
        try {
            $archive = new PharData($packagePath);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to open the downloaded update package.', 0, $exception);
        }

        $expected = (array) ($manifest['files'] ?? []);
        $seen = [];
        $totalSize = 0;
        $count = 0;
        $realPackage = realpath($packagePath);

        if ($realPackage === false) {
            throw new RuntimeException('Unable to resolve the downloaded update package.');
        }

        $prefix = 'phar://' . str_replace('\\', '/', $realPackage) . '/';
        $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->isDir()) {
                continue;
            }

            $count++;

            if ($count > self::MAX_PACKAGE_FILES || $entry->isLink()) {
                throw new RuntimeException('The update package contains too many files or a symbolic link.');
            }

            $uri = str_replace('\\', '/', $entry->getPathname());

            if (!str_starts_with($uri, $prefix)) {
                throw new RuntimeException('Unable to resolve a file inside the update package.');
            }

            $path = self::managedPath(substr($uri, strlen($prefix)));

            if (!array_key_exists($path, $expected) || isset($seen[$path])) {
                throw new RuntimeException('Unexpected or duplicate file in the update package: ' . $path);
            }

            $size = max(0, $entry->getSize());
            $totalSize += $size;

            if ($totalSize > self::MAX_PACKAGE_BYTES) {
                throw new RuntimeException('The extracted update package exceeds the allowed size.');
            }

            $target = self::pathBelow($stageDirectory, $path);
            self::ensureDirectory(dirname($target));
            $input = fopen($uri, 'rb');
            $output = fopen($target, 'wb');

            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Unable to extract update file: ' . $path);
            }

            $copied = stream_copy_to_stream($input, $output, self::MAX_PACKAGE_BYTES + 1);
            fclose($input);
            fclose($output);

            if (!is_int($copied) || $copied !== $size) {
                throw new RuntimeException('The extracted update file has an invalid size: ' . $path);
            }

            self::verifyFileHash($target, (string) $expected[$path]);
            $seen[$path] = true;
        }

        if ($count < 1) {
            throw new RuntimeException('The update package is empty.');
        }

        $missing = array_diff(array_keys($expected), array_keys($seen));

        if ($missing !== []) {
            throw new RuntimeException('The update package is missing files: ' . implode(', ', array_slice($missing, 0, 5)));
        }
    }

    private static function preflightManagedTargets(array $manifest): void
    {
        $base = base_path();

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
            $existing = is_file($target) ? $target : dirname($target);

            while (!file_exists($existing) && dirname($existing) !== $existing) {
                $existing = dirname($existing);
            }

            if (!is_writable($existing)) {
                throw new RuntimeException('Update target is not writable: ' . $path);
            }
        }
    }

    private static function assertNoManagedSymlink(string $target, string $relative): void
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
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

    private static function createBackup(array $manifest, string $targetVersion, bool $databaseBackupRequired = false): string
    {
        $name = Core::VERSION . '-to-' . $targetVersion . '-' . date('Ymd-His');
        $backup = self::updateRoot() . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $name;
        $filesDirectory = $backup . DIRECTORY_SEPARATOR . 'files';
        self::ensureDirectory($filesDirectory);
        $paths = array_values(array_unique(array_merge(
            array_keys((array) ($manifest['files'] ?? [])),
            (array) ($manifest['delete'] ?? [])
        )));
        $backedUp = [];

        foreach ($paths as $path) {
            $path = self::managedPath((string) $path);
            $source = self::pathBelow(base_path(), $path);

            if (!is_file($source)) {
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
            'created_at' => date(DATE_ATOM),
            'from_version' => Core::VERSION,
            'to_version' => $targetVersion,
            'files' => $backedUp,
            'database_backup_required' => $databaseBackupRequired,
        ];
        self::writeJsonFile($backup . DIRECTORY_SEPARATOR . 'backup.json', $metadata);

        return $backup;
    }

    private static function backupDatabase(string $backupDirectory): void
    {
        $driver = (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $database = (array) config('database', []);
            $path = (string) ($database['path'] ?? $database['name'] ?? '');

            if ($path === '' || $path === ':memory:' || !is_file($path)) {
                throw new RuntimeException('The SQLite database cannot be backed up automatically.');
            }

            if (!copy($path, $backupDirectory . DIRECTORY_SEPARATOR . 'database.sqlite')) {
                throw new RuntimeException('Unable to back up the SQLite database.');
            }

            return;
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException('Automatic database backup is not supported for the configured driver.');
        }

        $target = $backupDirectory . DIRECTORY_SEPARATOR . 'database.sql';
        $handle = fopen($target, 'wb');

        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create the database backup.');
        }

        try {
            db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            db()->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

            foreach ($tables as $row) {
                $table = (string) array_values($row)[0];

                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
                    throw new RuntimeException('Unsafe table name encountered during backup.');
                }

                $quotedTable = '`' . str_replace('`', '``', $table) . '`';
                $create = one('SHOW CREATE TABLE ' . $quotedTable);
                $createSql = is_array($create) ? (string) (array_values($create)[1] ?? '') : '';

                if ($createSql === '') {
                    throw new RuntimeException('Unable to read the schema for table ' . $table . '.');
                }

                fwrite($handle, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n" . $createSql . ";\n");
                $statement = db()->query('SELECT * FROM ' . $quotedTable);

                while (($data = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $columns = array_map(
                        static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
                        array_keys($data)
                    );
                    $values = array_map(
                        static fn (mixed $value): string => $value === null ? 'NULL' : db()->quote((string) $value),
                        array_values($data)
                    );
                    fwrite(
                        $handle,
                        'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n"
                    );
                }

                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");

            if (db()->inTransaction()) {
                db()->commit();
            }
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }

            fclose($handle);
            @unlink($target);
            throw $exception;
        }

        fclose($handle);
        @chmod($target, 0600);
    }

    private static function applyFiles(string $stageDirectory, array $manifest): void
    {
        foreach (array_keys((array) ($manifest['files'] ?? [])) as $path) {
            $path = self::managedPath((string) $path);
            $source = self::pathBelow($stageDirectory, $path);
            $target = self::pathBelow(base_path(), $path);
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

    private static function hasPendingMigrations(array $manifest): bool
    {
        $pending = [];
        $files = (array) ($manifest['files'] ?? []);

        foreach ((array) ($manifest['migrations'] ?? []) as $path) {
            $path = self::managedPath((string) $path);
            $pending[pathinfo($path, PATHINFO_FILENAME)] = (string) ($files[$path] ?? '');
        }

        return MigrationRegistry::hasPending($pending);
    }

    private static function deleteLegacyFiles(array $manifest): void
    {
        foreach ((array) ($manifest['delete'] ?? []) as $path) {
            $path = self::managedPath((string) $path);
            $target = self::pathBelow(base_path(), $path);

            if (is_file($target) && !@unlink($target)) {
                throw new RuntimeException('Unable to remove legacy application file: ' . $path);
            }

            self::removeEmptyManagedDirectories(dirname($target));
        }
    }

    private static function clearRuntimeCache(): void
    {
        $cache = base_path('storage/cache');

        if (!is_dir($cache)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

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
            'from_version' => Core::VERSION,
            'to_version' => $version,
        ]);
    }

    private static function maintenancePathAllowed(string $path): bool
    {
        return $path === '/admin/updates'
            || str_starts_with($path, '/api/admin/updates')
            || $path === '/login'
            || $path === '/logout'
            || $path === '/api/auth/login'
            || $path === '/api/auth/logout'
            || $path === '/install'
            || str_starts_with($path, '/install/');
    }

    private static function maintenanceFile(): string
    {
        return base_path('storage/maintenance.json');
    }

    private static function updateRoot(): string
    {
        return base_path('storage/updates');
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
        $allowedRoots = ['App', 'Extensions', 'Public', 'assets', 'docs', 'lang', 'migrations'];
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

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
    }

    private static function writeJsonFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to write update state.');
        }

        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to activate update state.');
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
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
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
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base)))
            : $path;
    }
}

final class MigrationRegistry
{
    private const array REQUIRED_COLUMNS = ['migration', 'version', 'checksum', 'applied_at'];
    private const string OUTDATED_SCHEMA_MESSAGE =
        'The migration registry is outdated. Update TinyCat to 1.0.14 before installing TinyCat 2.x.';

    private function __construct()
    {
    }

    public static function ensure(): void
    {
        $driver = (string) db()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $suffix = $driver === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';

        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('The migration registry requires MySQL, MariaDB or SQLite.');
        }

        run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL,
                version VARCHAR(32) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (migration)
            )' . $suffix
        );

        self::assertCurrentSchema($driver);
    }

    public static function history(?string $prefix = null): array
    {
        self::ensure();
        $rows = all(
            'SELECT migration, version, checksum, applied_at
             FROM schema_migrations
             ORDER BY applied_at DESC, migration DESC'
        );

        if ($prefix === null || $prefix === '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_starts_with((string) ($row['migration'] ?? ''), $prefix)
        ));
    }

    public static function checksum(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('Migration file was not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Migration file could not be read: ' . $path);
        }

        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $content));
    }

    public static function applied(string $migration, string $checksum): bool
    {
        self::assertMigration($migration, $checksum);
        self::ensure();
        $existing = one('SELECT checksum FROM schema_migrations WHERE migration = ? LIMIT 1', [$migration]);

        if ($existing === null) {
            return false;
        }
        if (!hash_equals((string) ($existing['checksum'] ?? ''), $checksum)) {
            throw new RuntimeException('Applied migration checksum mismatch: ' . $migration);
        }

        return true;
    }

    /**
     * Reports whether a release would modify the database, without creating
     * the migration registry as a side effect.
     *
     * @param array<string, string> $migrations migration ID => checksum
     */
    public static function hasPending(array $migrations): bool
    {
        if ($migrations === []) {
            return false;
        }

        foreach ($migrations as $migration => $checksum) {
            self::assertMigration((string) $migration, (string) $checksum);
        }

        $driver = (string) db()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('The migration registry requires MySQL, MariaDB or SQLite.');
        }

        $exists = $driver === 'mysql'
            ? one("SHOW TABLES LIKE 'schema_migrations'") !== null
            : one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations' LIMIT 1") !== null;

        if (!$exists) {
            return true;
        }

        $applied = array_column(all('SELECT migration, checksum FROM schema_migrations'), 'checksum', 'migration');

        foreach ($migrations as $migration => $checksum) {
            if (!isset($applied[$migration]) || !hash_equals((string) $applied[$migration], (string) $checksum)) {
                return true;
            }
        }

        return false;
    }

    public static function apply(string $migration, string $version, string $path, ?string $checksum = null): bool
    {
        $version = trim($version);
        $path = realpath($path) ?: '';
        $checksum ??= $path !== '' ? self::checksum($path) : '';
        self::assertMigration($migration, $checksum);

        if ($version === '' || strlen($version) > 32) {
            throw new InvalidArgumentException('Invalid migration version.');
        }
        if ($path === '' || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            throw new RuntimeException('Migration file was not found: ' . $migration);
        }
        if (!hash_equals($checksum, self::checksum($path))) {
            throw new RuntimeException('Migration file checksum mismatch: ' . $migration);
        }
        if (self::applied($migration, $checksum)) {
            return false;
        }

        $callback = require $path;
        if (!is_callable($callback)) {
            throw new RuntimeException('Migration must return a callable: ' . $migration);
        }

        $callback(db());
        insert('schema_migrations', [
            'migration' => $migration,
            'version' => $version,
            'checksum' => $checksum,
            'applied_at' => date_db(),
        ]);

        return true;
    }

    private static function assertCurrentSchema(string $driver): void
    {
        if ($driver === 'mysql') {
            $columns = array_column(all('SHOW COLUMNS FROM schema_migrations'), null, 'Field');
            $primary = [];

            foreach ($columns as $name => $column) {
                if (strtoupper((string) ($column['Key'] ?? '')) === 'PRI') {
                    $primary[] = (string) $name;
                }
            }

            self::assertRequiredColumns($columns, 'Null', $primary);
            return;
        }

        $columns = array_column(all('PRAGMA table_info(schema_migrations)'), null, 'name');
        $primary = [];

        foreach ($columns as $name => $column) {
            $position = (int) ($column['pk'] ?? 0);
            if ($position > 0) {
                $primary[$position] = (string) $name;
            }
        }

        ksort($primary);
        self::assertRequiredColumns($columns, 'notnull', array_values($primary));
    }

    private static function assertRequiredColumns(array $columns, string $nullableKey, array $primary): void
    {
        $current = $primary === ['migration'];

        foreach (self::REQUIRED_COLUMNS as $column) {
            $current = $current
                && isset($columns[$column])
                && ($nullableKey === 'Null'
                    ? strtoupper((string) ($columns[$column][$nullableKey] ?? 'YES')) === 'NO'
                    : (int) ($columns[$column][$nullableKey] ?? 0) === 1);
        }

        if (!$current) {
            throw new RuntimeException(self::OUTDATED_SCHEMA_MESSAGE);
        }
    }

    private static function assertMigration(string $migration, string $checksum): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,189}$/', $migration) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1
        ) {
            throw new InvalidArgumentException('Invalid migration metadata.');
        }
    }
}
}

namespace TinyCat\Extension {
    use Cache;
    use Core;
    use FilesystemIterator;
    use PharData;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use RuntimeException;
    use SplFileInfo;
    use Throwable;
    use ZipArchive;

final class Store extends \TinyCat\PackageManager
{
    private const string DEFAULT_REPOSITORY = 'hybernia1/TinyCat-Extensions';
    private const string CATALOG_ASSET = 'tinycat-extensions.json';
    private const string SIGNATURE_ASSET = 'tinycat-extensions.sig';
    private const string CACHE_KEY = 'extension_store_catalog';
    private const int CACHE_TTL = 900;
    private const int MAX_METADATA_BYTES = 1048576;
    private const int MAX_PACKAGE_BYTES = 26214400;
    private const int MAX_PACKAGE_FILES = 1000;
    private const string DEFAULT_PUBLIC_KEY = 'zyqmqAwPK6K+c5V/cCifO4dP4s2rVDfzhoUST5Wqjcw=';

    private function __construct()
    {
    }

    public static function repository(): string
    {
        $repository = trim((string) config('extensions.repository', self::DEFAULT_REPOSITORY));

        return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) === 1
            ? $repository
            : self::DEFAULT_REPOSITORY;
    }

    public static function cachedCatalog(): ?array
    {
        $catalog = Cache::get(self::CACHE_KEY, self::CACHE_TTL);

        return is_array($catalog) ? $catalog : null;
    }

    public static function catalog(bool $force = false): array
    {
        if (!$force && ($cached = self::cachedCatalog()) !== null) {
            return $cached;
        }

        self::requireRuntimeExtensions(false);
        $releaseJson = self::requestText(
            'https://api.github.com/repos/' . self::repository() . '/releases/latest',
            self::MAX_METADATA_BYTES,
            'application/vnd.github+json'
        );
        $release = self::decodeJson($releaseJson, 'GitHub extension release', 128);
        $assets = self::releaseAssets($release);
        $catalogUrl = $assets[self::CATALOG_ASSET] ?? '';
        $signatureUrl = $assets[self::SIGNATURE_ASSET] ?? '';

        if ($catalogUrl === '' || $signatureUrl === '') {
            throw new RuntimeException('The official extension release does not contain a catalog and signature.');
        }

        $catalogJson = self::requestText($catalogUrl, self::MAX_METADATA_BYTES, 'application/octet-stream');
        $signature = self::requestText($signatureUrl, 4096, 'application/octet-stream');
        self::verifyCatalogSignature($catalogJson, $signature);
        $catalog = self::validateCatalog(self::decodeJson($catalogJson, 'extension catalog', 128), $assets);
        $result = [
            'repository' => self::repository(),
            'release_url' => self::httpsUrl((string) ($release['html_url'] ?? '')),
            'published_at' => trim((string) ($release['published_at'] ?? '')),
            'extensions' => $catalog,
        ];

        Cache::put(self::CACHE_KEY, $result);

        return $result;
    }

    public static function install(string $slug): array
    {
        self::requireRuntimeExtensions(true);
        $slug = strtolower(trim($slug));
        $catalog = self::catalog(true);
        $extension = (array) (($catalog['extensions'] ?? [])[$slug] ?? []);

        if ($extension === []) {
            throw new RuntimeException('The selected extension is not available in the official catalog.');
        }
        if (empty($extension['compatible'])) {
            throw new RuntimeException(
                'Extension ' . $slug . ' requires TinyCat ' . (string) $extension['minimum_tinycat']
                . ' and PHP ' . (string) $extension['minimum_php'] . ' or newer.'
            );
        }

        $available = Loader::available();
        $current = is_array($available[$slug] ?? null) ? $available[$slug] : null;
        $installedVersions = Lifecycle::installedVersions();
        $installedVersion = trim((string) ($installedVersions[$slug] ?? ''));
        $targetVersion = (string) $extension['version'];

        if ($installedVersion !== '' && version_compare($installedVersion, $targetVersion, '>')) {
            throw new RuntimeException('Extension downgrades are not supported.');
        }

        $wasEnabled = $current === null || !array_key_exists('requested_enabled', $current)
            ? true
            : !empty($current['requested_enabled']);
        $root = base_path('Extensions');
        self::ensureDirectory($root);
        self::assertWritableExtensionRoot($root, (string) $extension['directory']);
        $runtime = base_path('storage/extensions');
        self::ensureDirectory($runtime);
        $lock = fopen($runtime . DIRECTORY_SEPARATOR . 'install.lock', 'c+');

        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another extension installation is already running.');
        }

        $work = $runtime . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR
            . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $package = $work . DIRECTORY_SEPARATOR . (string) $extension['package'];
        $stage = $work . DIRECTORY_SEPARATOR . 'package';
        $source = $stage . DIRECTORY_SEPARATOR . (string) $extension['directory'];
        $target = $root . DIRECTORY_SEPARATOR . (string) $extension['directory'];
        $backup = '';
        $filesPromoted = false;
        $migrationStarted = false;

        try {
            self::ensureDirectory($work);
            self::downloadToFile(
                (string) $extension['package_url'],
                $package,
                min(self::MAX_PACKAGE_BYTES, max(1, (int) $extension['size']))
            );
            self::verifyFile($package, (string) $extension['sha256'], (int) $extension['size']);
            self::extractPackage($package, $stage, (array) $extension['files']);
            $discovered = Loader::discover($stage)[$slug] ?? null;

            if (!is_array($discovered)
                || (string) ($discovered['version'] ?? '') !== $targetVersion
                || (string) ($discovered['minimum_tinycat'] ?? '') !== (string) $extension['minimum_tinycat']
                || (string) ($discovered['minimum_php'] ?? '') !== (string) $extension['minimum_php']
            ) {
                throw new RuntimeException('The downloaded extension manifest does not match the signed catalog.');
            }

            if (is_dir($target)) {
                $backup = $runtime . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR
                    . $slug . '-' . ($installedVersion !== '' ? $installedVersion : 'unregistered') . '-' . date('Ymd-His');
                self::ensureDirectory(dirname($backup));
                if (!@rename($target, $backup)) {
                    throw new RuntimeException('Unable to back up the installed extension.');
                }
            }

            if (!@rename($source, $target)) {
                throw new RuntimeException('Unable to move the verified extension into place.');
            }
            $filesPromoted = true;
            $migrationStarted = true;
            $migration = Lifecycle::migrateDiscovered($slug, $root);
            $states = Core::setting('extensions.states', []);
            $states = is_array($states) ? $states : [];
            $states[$slug] = $wasEnabled;
            ksort($states, SORT_STRING);
            Core::setSetting('extensions.states', $states, 'json', 'extensions');

            return [
                'slug' => $slug,
                'name' => (string) $extension['name'],
                'version' => $targetVersion,
                'updated' => $installedVersion !== '',
                'enabled' => $wasEnabled,
                'backup' => $backup !== '' ? self::relativePath($backup) : '',
                'migrations' => (array) ($migration['migrations'] ?? []),
            ];
        } catch (Throwable $exception) {
            if (!$migrationStarted && $filesPromoted && is_dir($target)) {
                self::removeDirectory($target);
            }
            if (!$migrationStarted && $backup !== '' && is_dir($backup) && !file_exists($target)) {
                @rename($backup, $target);
            }
            throw $exception;
        } finally {
            self::removeDirectory($work);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function uninstall(string $slug, string $mode): array
    {
        $slug = strtolower(trim($slug));
        $mode = strtolower(trim($mode));
        $extension = Lifecycle::all()[$slug] ?? null;

        if (!is_array($extension) || empty($extension['installed'])) {
            throw new RuntimeException('The selected extension is not installed.');
        }
        if (!empty($extension['enabled']) || !empty($extension['requested_enabled'])) {
            throw new RuntimeException('Disable the extension before uninstalling it.');
        }
        if (!is_array($extension['uninstall'] ?? null)) {
            throw new RuntimeException('This extension does not provide a verified uninstall handler.');
        }

        $root = base_path('Extensions');
        $definition = Loader::discover($root)[$slug] ?? null;
        $uninstall = is_array($definition['uninstall'] ?? null) ? $definition['uninstall'] : null;

        if (!is_array($definition) || !is_array($uninstall)) {
            throw new RuntimeException('The extension uninstall definition could not be verified.');
        }

        $options = [];
        foreach ((array) ($uninstall['options'] ?? []) as $option) {
            if (is_array($option)) {
                $options[(string) ($option['id'] ?? '')] = $option;
            }
        }
        if (!isset($options[$mode])) {
            throw new RuntimeException('The selected extension uninstall mode is invalid.');
        }

        $target = (string) ($definition['root'] ?? '');
        $targetReal = realpath($target);
        $rootReal = realpath($root);
        if ($targetReal === false || $rootReal === false
            || strtolower(dirname($targetReal)) !== strtolower($rootReal)
            || is_link($targetReal)
        ) {
            throw new RuntimeException('The installed extension path is not safe to remove.');
        }

        $runtime = base_path('storage/extensions');
        self::ensureDirectory($runtime);
        $lock = fopen($runtime . DIRECTORY_SEPARATOR . 'install.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Another extension operation is already running.');
        }

        $backup = $runtime . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR
            . $slug . '-uninstall-' . (string) ($extension['installed_version'] ?? $extension['version'] ?? 'unknown')
            . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $moved = false;

        try {
            self::ensureDirectory(dirname($backup));
            if (!@rename($targetReal, $backup)) {
                throw new RuntimeException('Unable to back up the extension before uninstalling it.');
            }
            $moved = true;

            $handler = $backup . DIRECTORY_SEPARATOR . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                self::packagePath((string) ($uninstall['handler'] ?? ''))
            );
            $handlerReal = realpath($handler);
            $backupPrefix = strtolower(rtrim((string) realpath($backup), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
            if ($handlerReal === false || !str_starts_with(strtolower($handlerReal), $backupPrefix)) {
                throw new RuntimeException('The extension uninstall handler is unavailable.');
            }

            $callback = require $handlerReal;
            if (!is_callable($callback)) {
                throw new RuntimeException('The extension uninstall handler must return a callable.');
            }

            $result = $callback(db(), [
                'slug' => $slug,
                'mode' => $mode,
                'option' => $options[$mode],
            ]);
            if (!is_array($result) || !is_bool($result['data_removed'] ?? null)) {
                throw new RuntimeException('The extension uninstall handler returned an invalid result.');
            }

            db_transaction(static function () use ($slug, $result): void {
                if ($result['data_removed']) {
                    $migrationPrefix = strtr('extension:' . $slug . ':', ['!' => '!!', '%' => '!%', '_' => '!_']);
                    run("DELETE FROM schema_migrations WHERE migration LIKE ? ESCAPE '!'", [$migrationPrefix . '%']);
                }

                $versions = Lifecycle::installedVersions();
                unset($versions[$slug]);
                ksort($versions, SORT_STRING);
                Core::setSetting('extensions.installed_versions', $versions, 'json', 'extensions');

                $states = Core::setting('extensions.states', []);
                $states = is_array($states) ? $states : [];
                unset($states[$slug]);
                ksort($states, SORT_STRING);
                Core::setSetting('extensions.states', $states, 'json', 'extensions');
            });

            return [
                ...$result,
                'slug' => $slug,
                'name' => (string) ($extension['name'] ?? $slug),
                'mode' => $mode,
                'backup' => self::relativePath($backup),
            ];
        } catch (Throwable $exception) {
            if ($moved && is_dir($backup) && !file_exists($targetReal)) {
                @rename($backup, $targetReal);
            }
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function validateCatalog(array $catalog, array $assets): array
    {
        if (($catalog['schema'] ?? null) !== 1 || !is_array($catalog['extensions'] ?? null)) {
            throw new RuntimeException('The official extension catalog has an unsupported format.');
        }

        $validated = [];

        foreach ($catalog['extensions'] as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new RuntimeException('The official extension catalog contains an invalid entry.');
            }

            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            $name = trim((string) ($item['name'] ?? ''));
            $directory = trim((string) ($item['directory'] ?? ''));
            $version = trim((string) ($item['version'] ?? ''));
            $requires = is_array($item['requires'] ?? null) ? $item['requires'] : [];
            $minimumTinycat = trim((string) ($requires['tinycat'] ?? ''));
            $minimumPhp = trim((string) ($requires['php'] ?? '8.4.0'));
            $package = basename(trim((string) ($item['package'] ?? '')));
            $sha256 = strtolower(trim((string) ($item['sha256'] ?? '')));
            $size = (int) ($item['size'] ?? 0);
            $descriptions = is_array($item['descriptions'] ?? null) ? $item['descriptions'] : [];
            $homepage = self::httpsUrl((string) ($item['homepage'] ?? ''));
            $files = self::validateFiles((array) ($item['files'] ?? []), $directory);

            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1
                || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $directory) !== 1
                || strtolower($directory) !== $slug
                || $name === '' || strlen($name) > 120
                || !self::validVersion($version)
                || !self::validVersion($minimumTinycat)
                || !self::validVersion($minimumPhp)
                || preg_match('/^[A-Za-z0-9._-]+\.zip$/', $package) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
                || $size < 1 || $size > self::MAX_PACKAGE_BYTES
                || !isset($files[$directory . '/extension.json'])
                || isset($validated[$slug])
                || !isset($assets[$package])
            ) {
                throw new RuntimeException('The official extension catalog contains an invalid entry.');
            }

            $normalizedDescriptions = [];
            foreach ($descriptions as $locale => $description) {
                $locale = strtolower(trim((string) $locale));
                $description = trim((string) $description);
                if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) === 1 && strlen($description) <= 500) {
                    $normalizedDescriptions[$locale] = $description;
                }
            }

            $validated[$slug] = [
                'slug' => $slug,
                'name' => $name,
                'directory' => $directory,
                'version' => $version,
                'minimum_tinycat' => $minimumTinycat,
                'minimum_php' => $minimumPhp,
                'compatible' => version_compare(Core::VERSION, $minimumTinycat, '>=')
                    && version_compare(PHP_VERSION, $minimumPhp, '>='),
                'package' => $package,
                'package_url' => $assets[$package],
                'sha256' => $sha256,
                'size' => $size,
                'files' => $files,
                'descriptions' => $normalizedDescriptions,
                'homepage' => $homepage,
            ];
        }

        ksort($validated, SORT_STRING);
        return $validated;
    }

    private static function validateFiles(array $files, string $directory): array
    {
        if ($files === [] || array_is_list($files) || count($files) > self::MAX_PACKAGE_FILES) {
            throw new RuntimeException('The extension package file list is invalid.');
        }

        $validated = [];
        $prefix = $directory . '/';

        foreach ($files as $path => $hash) {
            $path = self::packagePath((string) $path);
            $hash = strtolower(trim((string) $hash));
            if (!str_starts_with($path, $prefix) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || isset($validated[$path])) {
                throw new RuntimeException('The extension package file list is invalid.');
            }
            $validated[$path] = $hash;
        }

        ksort($validated, SORT_STRING);
        return $validated;
    }

    private static function releaseAssets(array $release): array
    {
        $assets = [];
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            $name = basename(trim((string) ($asset['name'] ?? '')));
            $url = self::httpsUrl((string) ($asset['browser_download_url'] ?? ''));
            if ($name !== '' && $url !== '' && !isset($assets[$name])) {
                $assets[$name] = $url;
            }
        }
        return $assets;
    }

    private static function verifyCatalogSignature(string $catalog, string $signature): void
    {
        $encoded = trim((string) config('extensions.public_key', self::DEFAULT_PUBLIC_KEY));
        $publicKey = base64_decode($encoded, true);
        $signatureRaw = base64_decode(trim($signature), true);

        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signatureRaw) || strlen($signatureRaw) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signatureRaw, $catalog, $publicKey)
        ) {
            throw new RuntimeException('The official extension catalog signature could not be verified.');
        }
    }

    private static function requestText(string $url, int $maxBytes, string $accept): string
    {
        $directory = base_path('storage/extensions/downloads');
        self::ensureDirectory($directory);
        $temporary = tempnam($directory, '.tinycat-extension-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create an extension download file.');
        }
        try {
            self::downloadToFile($url, $temporary, $maxBytes, $accept);
            $content = file_get_contents($temporary);
            if (!is_string($content)) {
                throw new RuntimeException('Unable to read extension metadata.');
            }
            return $content;
        } finally {
            @unlink($temporary);
        }
    }

    private static function downloadToFile(string $url, string $target, int $maxBytes, string $accept = 'application/octet-stream'): void
    {
        self::githubUrl($url, 'The extension download URL is not an allowed GitHub URL.');
        self::ensureDirectory(dirname($target));
        $handle = fopen($target, 'wb');
        $curl = curl_init($url);
        if (!is_resource($handle) || $curl === false) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Unable to initialize the extension download.');
        }

        $written = 0;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TinyCat/' . Core::VERSION . ' extension-store',
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28'],
            CURLOPT_WRITEFUNCTION => static function ($resource, string $chunk) use ($handle, $maxBytes, &$written): int {
                $length = strlen($chunk);
                $written += $length;
                if ($written > $maxBytes) return 0;
                $result = fwrite($handle, $chunk);
                return $result === false ? 0 : $result;
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        try {
            self::githubUrl($effectiveUrl, 'The extension download URL is not an allowed GitHub URL.');
        } catch (Throwable $exception) {
            @unlink($target);
            throw $exception;
        }
        if ($ok !== true || $status < 200 || $status >= 300 || $written > $maxBytes) {
            @unlink($target);
            throw new RuntimeException($written > $maxBytes
                ? 'The extension download exceeded the signed package size.'
                : 'The extension download failed with HTTP ' . $status . ($error !== '' ? ': ' . $error : '.'));
        }
        @chmod($target, 0600);
    }

    private static function extractPackage(string $package, string $stage, array $expected): void
    {
        self::ensureDirectory($stage);
        if (!class_exists('ZipArchive')) {
            self::extractPackageWithPhar($package, $stage, $expected);
            return;
        }
        $zip = new ZipArchive();
        if ($zip->open($package) !== true) {
            throw new RuntimeException('Unable to open the extension package.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_PACKAGE_FILES) {
                throw new RuntimeException('The extension package contains an invalid number of files.');
            }
            $seen = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) throw new RuntimeException('Unable to inspect the extension package.');
                $raw = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if (str_ends_with($raw, '/')) {
                    self::packagePath(rtrim($raw, '/'));
                    continue;
                }
                $path = self::packagePath($raw);
                if (!isset($expected[$path]) || isset($seen[$path]) || self::zipEntryIsSymlink($zip, $index)) {
                    throw new RuntimeException('Unexpected file in the extension package: ' . $path);
                }
                $size = max(0, (int) ($stat['size'] ?? 0));
                $total += $size;
                if ($total > self::MAX_PACKAGE_BYTES) throw new RuntimeException('The extracted extension is too large.');
                $target = self::pathBelow($stage, $path);
                self::ensureDirectory(dirname($target));
                $input = $zip->getStream((string) $stat['name']);
                $output = fopen($target, 'wb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new RuntimeException('Unable to extract extension file: ' . $path);
                }
                $copied = stream_copy_to_stream($input, $output, self::MAX_PACKAGE_BYTES + 1);
                fclose($input);
                fclose($output);
                if (!is_int($copied) || $copied !== $size) throw new RuntimeException('Invalid extension file size: ' . $path);
                self::verifyFile($target, (string) $expected[$path], $size);
                $seen[$path] = true;
            }
            $missing = array_diff(array_keys($expected), array_keys($seen));
            if ($missing !== []) throw new RuntimeException('The extension package is incomplete.');
        } finally {
            $zip->close();
        }
    }

    private static function extractPackageWithPhar(string $package, string $stage, array $expected): void
    {
        try {
            $archive = new PharData($package);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to open the extension package.', 0, $exception);
        }

        $real = realpath($package);
        if ($real === false) throw new RuntimeException('Unable to resolve the extension package.');
        $prefix = 'phar://' . str_replace('\\', '/', $real) . '/';
        $seen = [];
        $total = 0;
        $count = 0;

        foreach (new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY) as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->isDir()) continue;
            $count++;
            if ($count > self::MAX_PACKAGE_FILES || $entry->isLink()) {
                throw new RuntimeException('The extension package contains too many files or a symbolic link.');
            }
            $uri = str_replace('\\', '/', $entry->getPathname());
            if (!str_starts_with($uri, $prefix)) throw new RuntimeException('Unable to resolve an extension package file.');
            $path = self::packagePath(substr($uri, strlen($prefix)));
            if (!isset($expected[$path]) || isset($seen[$path])) {
                throw new RuntimeException('Unexpected file in the extension package: ' . $path);
            }
            $size = max(0, $entry->getSize());
            $total += $size;
            if ($total > self::MAX_PACKAGE_BYTES) throw new RuntimeException('The extracted extension is too large.');
            $target = self::pathBelow($stage, $path);
            self::ensureDirectory(dirname($target));
            $input = fopen($uri, 'rb');
            $output = fopen($target, 'wb');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Unable to extract extension file: ' . $path);
            }
            $copied = stream_copy_to_stream($input, $output, self::MAX_PACKAGE_BYTES + 1);
            fclose($input);
            fclose($output);
            if (!is_int($copied) || $copied !== $size) throw new RuntimeException('Invalid extension file size: ' . $path);
            self::verifyFile($target, (string) $expected[$path], $size);
            $seen[$path] = true;
        }
        if ($count < 1 || array_diff(array_keys($expected), array_keys($seen)) !== []) {
            throw new RuntimeException('The extension package is incomplete.');
        }
    }

    private static function verifyFile(string $path, string $sha256, int $size): void
    {
        if (filesize($path) !== $size || !hash_equals($sha256, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('The downloaded extension package failed integrity verification.');
        }
    }

    private static function packagePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || strlen($path) > 240 || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z0-9._\/-]+$/', $path) !== 1
        ) {
            throw new RuntimeException('Invalid extension package path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new RuntimeException('Invalid extension package path.');
            }
        }
        return $path;
    }

    private static function assertWritableExtensionRoot(string $root, string $directory): void
    {
        if (!is_writable($root) || is_link($root)) {
            throw new RuntimeException('The TinyCat Extensions directory is not writable.');
        }
        $target = $root . DIRECTORY_SEPARATOR . $directory;
        if (is_link($target)) {
            throw new RuntimeException('An extension cannot be installed through a symbolic link.');
        }
    }

    private static function requireRuntimeExtensions(bool $install): void
    {
        $missing = array_values(array_filter(['curl', 'sodium'], static fn (string $name): bool => !extension_loaded($name)));
        if ($install && !class_exists('ZipArchive') && !class_exists('PharData')) $missing[] = 'zip or phar';
        if ($missing !== []) throw new RuntimeException('Missing PHP extensions required by the extension store: ' . implode(', ', $missing) . '.');
    }

    private static function pathBelow(string $root, string $relative): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::packagePath($relative));
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the extension working directory.');
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) @unlink($entry->getPathname());
            elseif ($entry->isDir()) @rmdir($entry->getPathname());
        }
        @rmdir($directory);
    }

    private static function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with(strtolower($path), strtolower($base))
            ? str_replace('\\', '/', substr($path, strlen($base)))
            : '';
    }
}
}
