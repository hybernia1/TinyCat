<?php
declare(strict_types=1);

namespace TinyCat\Updates;

use JsonException;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

    abstract class Package
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

        /**
     * @return array<mixed>
     */
    protected static function decodeJson(string $json, string $label, int $depth = 512): array
        {
            try {
                if ($depth < 1) { throw new \InvalidArgumentException('JSON depth must be positive.'); }
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

        /** @param list<string> $paths */
        protected static function assertDistinctPackagePaths(array $paths, string $errorMessage): void
        {
            $normalized = [];

            foreach ($paths as $path) {
                $key = strtolower($path);

                if (isset($normalized[$key])) {
                    throw new RuntimeException($errorMessage . ': ' . $path);
                }

                $normalized[$key] = $path;
            }

            foreach ($normalized as $key => $path) {
                $ancestor = $key;

                while (($separator = strrpos($ancestor, '/')) !== false) {
                    $ancestor = substr($ancestor, 0, $separator);

                    if (isset($normalized[$ancestor])) {
                        throw new RuntimeException($errorMessage . ': ' . $path);
                    }
                }
            }
        }

        protected static function ensureDirectory(string $directory): void
        {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create package working directory.');
            }
        }

        protected static function downloadGitHubFile(
            string $url,
            string $target,
            int $maxBytes,
            string $accept,
            string $userAgent,
            string $label
        ): void {
            self::githubUrl($url, 'The ' . $label . ' source host is not allowed.');
            self::ensureDirectory(dirname($target));
            \TinyCat\Http\Client::download($url, $target, $maxBytes, [
                'accept' => $accept, 'timeout' => 180, 'redirects' => 5,
                'user_agent' => 'TinyCat/' . \TinyCat\Support\Version::CURRENT . ' ' . $userAgent,
                'headers' => ['X-GitHub-Api-Version: 2022-11-28'],
                'policy' => static fn (string $next): bool => self::githubUrl($next, 'The ' . $label . ' source host is not allowed.') !== '',
            ]);
        }

        /**
         * @param array<string, string> $expectedFiles relative path => SHA-256
         * @param callable(string, bool): string $normalizePath
         */
        protected static function extractVerifiedPackage(
            string $package,
            string $stage,
            array $expectedFiles,
            callable $normalizePath,
            int $maxFiles,
            int $maxBytes,
            string $label
        ): void {
            self::ensureDirectory($stage);

            if (!class_exists('ZipArchive')) {
                self::extractVerifiedPackageWithPhar(
                    $package,
                    $stage,
                    $expectedFiles,
                    $normalizePath,
                    $maxFiles,
                    $maxBytes,
                    $label
                );
                return;
            }

            $zip = new ZipArchive();

            if ($zip->open($package) !== true) {
                throw new RuntimeException('Unable to open the ' . $label . ' package.');
            }

            try {
                if ($zip->numFiles < 1 || $zip->numFiles > $maxFiles) {
                    throw new RuntimeException('The ' . $label . ' package contains an invalid number of files.');
                }

                $seen = [];
                $totalSize = 0;

                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);

                    if (!is_array($stat)) {
                        throw new RuntimeException('Unable to inspect the ' . $label . ' package.');
                    }

                    $rawPath = str_replace('\\', '/', (string) $stat['name']);

                    if (str_ends_with($rawPath, '/')) {
                        $normalizePath($rawPath, true);
                        continue;
                    }

                    $path = $normalizePath($rawPath, false);

                    if (!isset($expectedFiles[$path]) || isset($seen[$path]) || self::zipEntryIsSymlink($zip, $index)) {
                        throw new RuntimeException('Unexpected file in the ' . $label . ' package: ' . $path);
                    }

                    $size = max(0, (int) $stat['size']);
                    $totalSize += $size;

                    if ($totalSize > $maxBytes) {
                        throw new RuntimeException('The extracted ' . $label . ' package exceeds the allowed size.');
                    }

                    self::extractVerifiedFile(
                        $zip->getStream((string) $stat['name']),
                        rtrim($stage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path),
                        $size,
                        (string) $expectedFiles[$path],
                        $label,
                        $path
                    );
                    $seen[$path] = true;
                }

                self::assertPackageComplete($expectedFiles, $seen, $label);
            } finally {
                $zip->close();
            }
        }

        /**
         * @param array<string, string> $expectedFiles
         * @param callable(string, bool): string $normalizePath
         */
        private static function extractVerifiedPackageWithPhar(
            string $package,
            string $stage,
            array $expectedFiles,
            callable $normalizePath,
            int $maxFiles,
            int $maxBytes,
            string $label
        ): void {
            try {
                $archive = new PharData($package);
            } catch (Throwable $exception) {
                throw new RuntimeException('Unable to open the ' . $label . ' package.', 0, $exception);
            }

            $realPackage = realpath($package);
            if ($realPackage === false) {
                throw new RuntimeException('Unable to resolve the ' . $label . ' package.');
            }

            $prefix = 'phar://' . str_replace('\\', '/', $realPackage) . '/';
            $seen = [];
            $totalSize = 0;
            $count = 0;

            foreach (new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY) as $entry) {
                if (!$entry instanceof SplFileInfo || $entry->isDir()) {
                    continue;
                }

                $count++;

                if ($count > $maxFiles || $entry->isLink()) {
                    throw new RuntimeException('The ' . $label . ' package contains too many files or a symbolic link.');
                }

                $uri = str_replace('\\', '/', $entry->getPathname());

                if (!str_starts_with($uri, $prefix)) {
                    throw new RuntimeException('Unable to resolve a file inside the ' . $label . ' package.');
                }

                $path = $normalizePath(substr($uri, strlen($prefix)), false);

                if (!isset($expectedFiles[$path]) || isset($seen[$path])) {
                    throw new RuntimeException('Unexpected file in the ' . $label . ' package: ' . $path);
                }

                $size = max(0, $entry->getSize());
                $totalSize += $size;

                if ($totalSize > $maxBytes) {
                    throw new RuntimeException('The extracted ' . $label . ' package exceeds the allowed size.');
                }

                $input = fopen($uri, 'rb');
                self::extractVerifiedFile(
                    $input,
                    rtrim($stage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path),
                    $size,
                    (string) $expectedFiles[$path],
                    $label,
                    $path
                );
                $seen[$path] = true;
            }

            if ($count < 1) {
                throw new RuntimeException('The ' . $label . ' package is empty.');
            }

            self::assertPackageComplete($expectedFiles, $seen, $label);
        }

        private static function extractVerifiedFile(
            mixed $input,
            string $target,
            int $size,
            string $expectedHash,
            string $label,
            string $path
        ): void {
            self::ensureDirectory(dirname($target));
            $output = fopen($target, 'wb');

            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Unable to extract ' . $label . ' file: ' . $path);
            }

            $copied = stream_copy_to_stream($input, $output, $size + 1);
            fclose($input);
            fclose($output);

            $actualHash = hash_file('sha256', $target);
            if (
                !is_int($copied)
                || $copied !== $size
                || filesize($target) !== $size
                || !is_string($actualHash)
                || !hash_equals(strtolower($expectedHash), strtolower($actualHash))
            ) {
                throw new RuntimeException('The extracted ' . $label . ' file failed integrity verification: ' . $path);
            }
        }

        /**
         * @param array<string, string> $expectedFiles
         * @param array<string, true> $seen
         */
        private static function assertPackageComplete(array $expectedFiles, array $seen, string $label): void
        {
            $missing = array_diff(array_keys($expectedFiles), array_keys($seen));

            if ($missing !== []) {
                throw new RuntimeException('The ' . $label . ' package is incomplete.');
            }
        }
    }
