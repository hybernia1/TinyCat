<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Official signed extension catalog and package installer.
 */
final class ExtensionStore
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
        $release = self::decodeJson($releaseJson, 'GitHub extension release');
        $assets = self::releaseAssets($release);
        $catalogUrl = $assets[self::CATALOG_ASSET] ?? '';
        $signatureUrl = $assets[self::SIGNATURE_ASSET] ?? '';

        if ($catalogUrl === '' || $signatureUrl === '') {
            throw new RuntimeException('The official extension release does not contain a catalog and signature.');
        }

        $catalogJson = self::requestText($catalogUrl, self::MAX_METADATA_BYTES, 'application/octet-stream');
        $signature = self::requestText($signatureUrl, 4096, 'application/octet-stream');
        self::verifyCatalogSignature($catalogJson, $signature);
        $catalog = self::validateCatalog(self::decodeJson($catalogJson, 'extension catalog'), $assets);
        $result = [
            'repository' => self::repository(),
            'release_url' => self::validatedHttpsUrl((string) ($release['html_url'] ?? '')),
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

        $available = ExtensionLoader::available();
        $current = is_array($available[$slug] ?? null) ? $available[$slug] : null;
        $installedVersions = ExtensionLifecycle::installedVersions();
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
            $discovered = ExtensionLoader::discover($stage)[$slug] ?? null;

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
            $migration = ExtensionLifecycle::migrateDiscovered($slug, $root);
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
            $homepage = self::validatedHttpsUrl((string) ($item['homepage'] ?? ''));
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
            $url = self::validatedHttpsUrl((string) ($asset['browser_download_url'] ?? ''));
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
        self::validatedGithubUrl($url);
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
            self::validatedGithubUrl($effectiveUrl);
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

    private static function validatedGithubUrl(string $url): string
    {
        $url = self::validatedHttpsUrl($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($url === '' || !($host === 'api.github.com' || $host === 'github.com' || str_ends_with($host, '.githubusercontent.com'))) {
            throw new RuntimeException('The extension download URL is not an allowed GitHub URL.');
        }
        return $url;
    }

    private static function validatedHttpsUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
                ? $url
                : '';
    }

    private static function decodeJson(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid ' . $label . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) throw new RuntimeException('Invalid ' . $label . '.');
        return $decoded;
    }

    private static function validVersion(string $version): bool
    {
        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private static function requireRuntimeExtensions(bool $install): void
    {
        $missing = array_values(array_filter(['curl', 'sodium'], static fn (string $name): bool => !extension_loaded($name)));
        if ($install && !class_exists('ZipArchive') && !class_exists('PharData')) $missing[] = 'zip or phar';
        if ($missing !== []) throw new RuntimeException('Missing PHP extensions required by the extension store: ' . implode(', ', $missing) . '.');
    }

    private static function zipEntryIsSymlink(ZipArchive $zip, int $index): bool
    {
        $operations = 0;
        $attributes = 0;
        return $zip->getExternalAttributesIndex($index, $operations, $attributes)
            && $operations === ZipArchive::OPSYS_UNIX
            && (($attributes >> 16) & 0170000) === 0120000;
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
