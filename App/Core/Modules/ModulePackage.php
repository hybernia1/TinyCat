<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

use TinyCat\Support\Paths;
use TinyCat\Support\Storage;
use TinyCat\Database\{Connection, ModuleSchemas};

/** Administrator-supplied module archives use the updater's bounded extraction and lifecycle. */
final class ModulePackage extends \TinyCat\Core\Updates\Package
{
    public const MAX_BYTES = 33554432;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public static function validate(array $data): array
    {
        if (array_diff(array_keys($data), ['format', 'kind', 'id', 'directory', 'version', 'minimum_core', 'minimum_php', 'manifest', 'schemas', 'files']) !== []
            || !in_array($data['format'] ?? null, [1, 2], true) || ($data['kind'] ?? null) !== 'tinycat-module'
            || !is_string($data['id'] ?? null) || preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/D', $data['id']) !== 1
            || !is_string($data['directory'] ?? null) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $data['directory']) !== 1
            || !is_array($data['manifest'] ?? null) || ($data['manifest']['id'] ?? null) !== $data['id'] || ($data['manifest']['coreApi'] ?? null) !== 1
            || !is_array($data['files'] ?? null) || $data['files'] === [] || count($data['files']) > 4999 || !is_array($data['schemas'] ?? null)) { throw new \RuntimeException('Invalid module package metadata.'); }
        foreach (['version', 'minimum_core', 'minimum_php'] as $key) { if (!is_string($data[$key] ?? null) || !self::validVersion($data[$key])) { throw new \RuntimeException('Invalid module package version.'); } }
        self::path($data['directory']);
        if ($data['format'] === 2 && version_compare($data['minimum_core'], SchemaMigration::ARCHIVE_RUNTIME, '<')) { throw new \RuntimeException('Archived module history requires the checkpoint-aware runtime.'); }
        try {
            Distribution::validate(['format' => $data['format'] === 2 ? 4 : 3, 'product' => 'tinycat.package',
                'selection' => [$data['id'] => 'App/Modules/' . $data['directory'] . '/module.json'], 'active' => [], 'required' => [],
                'requires' => [$data['id'] => []], 'schemas' => $data['schemas'], 'legacy' => false,
                'framework' => \TinyCat\Core\Runtime\Framework::CAPABILITIES,
                'schemaOwners' => array_fill_keys(array_keys($data['schemas']), $data['id'])]);
        } catch (\InvalidArgumentException $error) { throw new \RuntimeException('Invalid module schema metadata.', 0, $error); }
        if (version_compare(\TinyCat\Support\Version::CURRENT, $data['minimum_core'], '<') || version_compare(PHP_VERSION, $data['minimum_php'], '<')) { throw new \RuntimeException('Module requires a newer TinyCat or PHP runtime.'); }
        foreach ($data['files'] as $path => $hash) { if (!is_string($path) || self::path($path) === 'package.json' || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) { throw new \RuntimeException('Invalid module file inventory.'); } }
        self::assertDistinctPackagePaths(array_keys($data['files']), 'Conflicting module paths');
        if (!isset($data['files']['module.json'])) { throw new \RuntimeException('Module manifest is missing.'); }
        return $data;
    }

    public static function path(string $path, bool $directory = false): string
    {
        $path = $directory ? rtrim($path, '/') : $path;
        if (strlen($path) > 240 || preg_match('~^[A-Za-z0-9][A-Za-z0-9_./-]*$~D', $path) !== 1 || str_contains($path, '//')) { throw new \RuntimeException('Invalid module archive path.'); }
        foreach (explode('/', $path) as $part) { if ($part === '' || $part[0] === '.' || str_ends_with($part, '.') || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part)) { throw new \RuntimeException('Invalid module archive path.'); } }
        return $path;
    }

    /** Validates and extracts without including a single uploaded PHP file.
     * @return array{package: array<string, mixed>, stage: string} */
    public static function prepare(string $archive): array
    {
        return self::workspace(static fn (): array => self::prepareUnlocked($archive));
    }

    /** @return array{package: array<string, mixed>, stage: string} */
    private static function prepareUnlocked(string $archive): array
    {
        if (!is_file($archive) || filesize($archive) > self::MAX_BYTES) { throw new \RuntimeException('Module ZIP exceeds the upload limit.'); }
        $copy = Storage::path('modules/uploads/' . bin2hex(random_bytes(16)) . '.zip');
        self::ensureDirectory(dirname($copy));
        if (!copy($archive, $copy)) { throw new \RuntimeException('Cannot stage module upload.'); }
        try { return self::prepareArchive($copy); }
        finally { if (is_file($copy)) { unlink($copy); } }
    }

    /** @return array{package: array<string, mixed>, stage: string} */
    private static function prepareArchive(string $archive): array
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) { throw new \RuntimeException('Cannot open module ZIP.'); }
            try { $raw = $zip->getFromName('package.json', 1048577); }
            finally { $zip->close(); }
        } else {
            $zip = new \PharData($archive);
            $raw = isset($zip['package.json']) && $zip['package.json']->getSize() <= 1048576 ? $zip['package.json']->getContent() : false;
        }
        if (!is_string($raw) || strlen($raw) > 1048576) { throw new \RuntimeException('Module package descriptor is missing or too large.'); }
        $package = self::validate(self::decodeJson($raw, 'module package', 64));
        $stage = Storage::path('modules/staging/' . bin2hex(random_bytes(16)));
        try {
            self::extractVerifiedPackage($archive, $stage, $package['files'] + ['package.json' => hash('sha256', $raw)], self::path(...), 5000, 104857600, 'module');
            $definition = Definition::fromManifest($stage . '/module.json');
            if (json_decode((string) file_get_contents($stage . '/module.json'), true, 64, JSON_THROW_ON_ERROR) !== $package['manifest']) { throw new \RuntimeException('Package and module manifest disagree.'); }
            foreach ($package['files'] as $path => $hash) {
                if (str_ends_with($path, '.php')) {
                    if (filesize($stage . '/' . $path) > 2097152) { throw new \RuntimeException('Module PHP source exceeds the validation limit.'); }
                    token_get_all((string) file_get_contents($stage . '/' . $path), TOKEN_PARSE);
                }
            }
            foreach ($definition->providers as $class) {
                $relative = 'src/' . str_replace('\\', '/', substr($class, strlen($definition->namespace))) . '.php';
                if (!isset($package['files'][$relative])) { throw new \RuntimeException('Module provider file is missing.'); }
            }
            foreach ($package['schemas'] as $schema) {
                foreach ([...($schema['migrations'] ?? []), ...(isset($schema['fresh']) ? [$schema['fresh']] : []), ...(isset($schema['legacy']) ? [$schema['legacy']] : [])] as $migration) {
                    if ($migration['file'] === null) { continue; }
                    $prefix = 'App/Modules/' . $package['directory'] . '/';
                    if (!is_string($migration['file'] ?? null) || !str_starts_with($migration['file'], $prefix)) { throw new \RuntimeException('Module migration escapes its owner.'); }
                    $relative = substr($migration['file'], strlen($prefix));
                    if (!isset($package['files'][$relative]) || !hash_equals((string) $migration['checksum'], ModuleSchemas::checksum($stage . '/' . $relative))) { throw new \RuntimeException('Module migration checksum mismatch.'); }
                }
            }
            return ['package' => $package, 'stage' => $stage];
        } catch (\Throwable $error) { self::cleanup($stage); throw $error; }
    }

    /** @return array<string, mixed> */
    public static function install(string $archive): array
    {
        return self::workspace(static fn (): array => self::installUnlocked($archive));
    }

    /** @return array<string, mixed> */
    private static function installUnlocked(string $archive): array
    {
        $root = Paths::basePath();
        $baseFile = $root . '/App/distribution.json';
        if (!is_file($baseFile)) { throw new \RuntimeException('Module installation requires an installed distribution.'); }
        $base = Distribution::validate(self::decodeJson((string) file_get_contents($baseFile), 'distribution'));
        $state = Deployment::read($root);
        if (!in_array($base['format'] ?? null, [3, 4], true) || $state === null) { throw new \RuntimeException('Install the current framework before adding module packages.'); }
        $prepared = self::prepareUnlocked($archive); $package = $prepared['package']; $stage = $prepared['stage'];
        try {
            $id = $package['id']; $prefix = 'App/Modules/' . $package['directory'] . '/';
            $packages = PackageRegistry::read($root); $old = $packages[$id] ?? null;
            if (isset($base['selection'][$id])) { throw new \RuntimeException('This module is supplied by the distribution; update it through the distribution updater.'); }
            if ($old === null && file_exists($root . '/' . rtrim($prefix, '/'))) { throw new \RuntimeException('Target module directory is already present.'); }
            if ($old !== null) {
                if ($old['directory'] !== $package['directory'] || $old['manifest']['namespace'] !== $package['manifest']['namespace'] || version_compare($package['version'], $old['version'], '<=')) { throw new \RuntimeException('Select a newer package with the same module identity.'); }
                if (isset($old['manifest']['httpNamespace']) && $old['manifest']['httpNamespace'] !== ($package['manifest']['httpNamespace'] ?? null)) { throw new \RuntimeException('A module update must preserve its HTTP namespace.'); }
                foreach ($old['schemas'] as $stream => $schema) {
                    foreach ($schema['migrations'] as $index => $migration) {
                        $nextMigration = $package['schemas'][$stream]['migrations'][$index] ?? null;
                        if (!is_array($nextMigration) || $nextMigration['version'] !== $migration['version'] || $nextMigration['id'] !== $migration['id'] || $nextMigration['checksum'] !== $migration['checksum'] || ($nextMigration['file'] !== $migration['file'] && $nextMigration['file'] !== null)) { throw new \RuntimeException('Published module migration history cannot be changed.'); }
                    }
                }
            }
            $next = $packages; $next[$id] = $package;
            $effective = PackageRegistry::extend($root, $base, $next);
            Distribution::selection($effective, $state['active']);
            $livePlan = Distribution::read($root) ?? throw new \RuntimeException('Missing distribution.');
            $definitions = array_values(array_filter(Loader::fromSelection($root, $livePlan['selection'])->catalog->definitions(), static fn (Definition $definition): bool => $definition->id !== $id));
            new Catalog([...$definitions, Definition::fromManifest($stage . '/module.json')]);
            $fingerprint = hash('sha256', json_encode([$packages, $state, $base], JSON_THROW_ON_ERROR));
            $record = json_encode(['format' => 1, 'packages' => $next], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($record) > 4194304) { throw new \RuntimeException('Local module catalog exceeds its size limit.'); }
            $mapped = $stage . '/mapped'; self::ensureDirectory($mapped . '/' . $prefix);
            $files = [];
            foreach ($package['files'] as $path => $hash) {
                $target = $mapped . '/' . $prefix . $path; self::ensureDirectory(dirname($target));
                if (!copy($stage . '/' . $path, $target)) { throw new \RuntimeException('Cannot prepare module files.'); }
                $files[$prefix . $path] = $hash;
            }
            self::ensureDirectory($mapped . '/App');
            if (file_put_contents($mapped . '/' . PackageRegistry::FILE, $record) === false) { throw new \RuntimeException('Cannot prepare module catalog.'); }
            $files[PackageRegistry::FILE] = hash('sha256', $record);
            $deleted = array_map(static fn (string $path): string => $prefix . $path, array_keys(array_diff_key($old['files'] ?? [], $package['files'])));
            $installed = in_array($id, $state['installed'], true);
            $stagedPlan = $effective;
            $stagedPlan['schemas'] = array_filter($effective['schemas'], static fn (string $stream): bool => $effective['schemaOwners'][$stream] === $id, ARRAY_FILTER_USE_KEY);
            $stagedPlan['schemaOwners'] = array_intersect_key($effective['schemaOwners'], $stagedPlan['schemas']);
            return \TinyCat\Core\Updates\Manager::runLifecycle(static function () use ($root, $effective, $state, $installed, $id, $package): array {
                if ($installed) { ModuleSchemas::synchronize(Connection::get(), Distribution::schemas($effective, $root, $state['installed'])); }
                Deployment::write($root, $effective, $state['active'], $state['installed'], \TinyCat\Support\Version::CURRENT);
                return ['id' => $id, 'version' => $package['version'], 'active' => in_array($id, $state['active'], true)];
            }, $installed && $package['schemas'] !== [], static function () use ($root, $fingerprint, $baseFile, $installed, $stagedPlan, $mapped, $id): void {
                $now = [PackageRegistry::read($root), Deployment::read($root), self::decodeJson((string) file_get_contents($baseFile), 'distribution')];
                if (!hash_equals($fingerprint, hash('sha256', json_encode($now, JSON_THROW_ON_ERROR)))) { throw new \RuntimeException('Module catalog changed; retry installation.'); }
                if ($installed && $stagedPlan['schemas'] !== []) { ModuleSchemas::pending(Connection::get(), Distribution::schemas($stagedPlan, $mapped, [$id])); }
            }, ['stage' => $mapped, 'files' => $files, 'delete' => $deleted]);
        } finally { self::cleanup($stage); }
    }

    /** Cleanup never waits for a package operation while the scheduler holds deployment admission.
     * @return array{changed: int, has_more: bool} */
    public static function cleanupAbandoned(int $batch): array
    {
        $cleanup = static function () use ($batch): array {
            $entered = false;
            try {
                return \TinyCat\Core\Updates\Manager::runTask(static function () use ($batch, &$entered): array {
                    $entered = true;
                    $cutoff = time() - 172800;
                    $root = Storage::path('modules');
                    return \TinyCat\Support\FileSweep::run($root, $batch, static function (array $files) use ($root, $cutoff): array {
                        $changed = 0;
                        foreach ($files as $file) {
                            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                            if (preg_match('/^retiring-([a-f0-9]{32})\.json$/D', $relative, $marker) === 1) {
                                if (!is_dir($root . '/staging/' . $marker[1]) && \TinyCat\Support\FileSweep::remove($file->getPathname())) { $changed++; }
                                continue;
                            }
                            if (preg_match('~^(?:uploads/[a-f0-9]{32}\.zip|staging/[a-f0-9]{32}/.+)$~D', $relative) !== 1) { continue; }
                            clearstatcache(true, $file->getPathname());
                            $modified = @filemtime($file->getPathname());
                            if ($modified !== false && $modified < $cutoff && \TinyCat\Support\FileSweep::remove($file->getPathname())) { $changed++; }
                        }
                        return ['changed' => $changed, 'has_more' => false];
                    }, 'abandoned-module-packages', true, static function (string $relative) use ($root, $cutoff): bool {
                        $parts = explode('/', $relative);
                        if ($relative === 'uploads' || $relative === 'staging') { return true; }
                        if ($parts[0] !== 'staging' || preg_match('/^[a-f0-9]{32}$/D', $parts[1] ?? '') !== 1) { return false; }
                        $marker = $root . '/retiring-' . $parts[1] . '.json';
                        if (is_link($marker)) { throw new \RuntimeException('Unsafe module retirement marker.'); }
                        if (is_file($marker)) { return true; }
                        $modified = @filemtime($root . '/staging/' . $parts[1]);
                        if ($modified === false || $modified >= $cutoff) { return false; }
                        if (!\TinyCat\Support\Storage::write($marker, '{}')) { throw new \RuntimeException('Cannot checkpoint abandoned module retirement.'); }
                        return true;
                    }, true);
                });
            } catch (\RuntimeException $error) {
                if ($entered) { throw $error; }
                return ['changed' => 0, 'has_more' => true];
            }
        };
        $result = self::workspace($cleanup, false);
        if (!is_int($result['changed'] ?? null) || !is_bool($result['has_more'] ?? null)) { throw new \RuntimeException('Invalid module workspace cleanup result.'); }
        return ['changed' => $result['changed'], 'has_more' => $result['has_more']];
    }

    /** @template T of array<mixed>
     * @param callable(): T $operation
     * @return ($wait is true ? T : T|array{changed: int, has_more: bool}) */
    private static function workspace(callable $operation, bool $wait = true): array
    {
        $directory = Storage::path('modules');
        self::ensureDirectory($directory);
        if (is_link($directory) || is_link($directory . '/workspace.lock')) { throw new \RuntimeException('Unsafe module workspace lock.'); }
        $lock = fopen($directory . '/workspace.lock', 'c+');
        if ($lock === false) { throw new \RuntimeException('Cannot open module workspace lock.'); }
        try {
            if (!flock($lock, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
                if ($wait) { throw new \RuntimeException('Cannot acquire module workspace lock.'); }
                return ['changed' => 0, 'has_more' => true];
            }
            return $operation();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function cleanup(string $stage): void
    {
        $base = realpath(Storage::path('modules/staging')); $resolved = realpath($stage);
        if ($base === false || $resolved === false || !ModuleAutoload::inside($resolved, $base) || dirname($resolved) !== $base) { return; }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($resolved);
    }
}
