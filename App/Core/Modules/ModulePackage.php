<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

use TinyCat\Support\Paths;
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
            || ($data['format'] ?? null) !== 1 || ($data['kind'] ?? null) !== 'tinycat-module'
            || !is_string($data['id'] ?? null) || preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/D', $data['id']) !== 1
            || !is_string($data['directory'] ?? null) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $data['directory']) !== 1
            || !is_array($data['manifest'] ?? null) || ($data['manifest']['id'] ?? null) !== $data['id'] || ($data['manifest']['coreApi'] ?? null) !== 1
            || !is_array($data['files'] ?? null) || $data['files'] === [] || count($data['files']) > 4999 || !is_array($data['schemas'] ?? null)) { throw new \RuntimeException('Invalid module package metadata.'); }
        foreach (['version', 'minimum_core', 'minimum_php'] as $key) { if (!is_string($data[$key] ?? null) || !self::validVersion($data[$key])) { throw new \RuntimeException('Invalid module package version.'); } }
        self::path($data['directory']);
        foreach ($data['schemas'] as $stream => $schema) {
            if (!is_string($stream) || !is_array($schema) || !is_int($schema['version'] ?? null) || !is_array($schema['tables'] ?? null) || !is_array($schema['migrations'] ?? null)) { throw new \RuntimeException('Invalid module schema metadata.'); }
            foreach ($schema['tables'] as $table) { if (!is_string($table)) { throw new \RuntimeException('Invalid module table.'); } }
            foreach ([...$schema['migrations'], ...(isset($schema['fresh']) ? [$schema['fresh']] : []), ...(isset($schema['legacy']) ? [$schema['legacy']] : [])] as $migration) {
                if (!is_array($migration) || !is_int($migration['version'] ?? null) || !is_string($migration['id'] ?? null) || !is_string($migration['file'] ?? null) || !is_string($migration['checksum'] ?? null)) { throw new \RuntimeException('Invalid module migration metadata.'); }
            }
        }
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
        if (!is_file($archive) || filesize($archive) > self::MAX_BYTES) { throw new \RuntimeException('Module ZIP exceeds the upload limit.'); }
        $copy = Paths::basePath('storage/modules/uploads/' . bin2hex(random_bytes(16)) . '.zip');
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
        $stage = Paths::basePath('storage/modules/staging/' . bin2hex(random_bytes(16)));
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
        $root = Paths::basePath();
        $baseFile = $root . '/App/distribution.json';
        if (!is_file($baseFile)) { throw new \RuntimeException('Module installation requires an installed distribution.'); }
        $base = Distribution::validate(self::decodeJson((string) file_get_contents($baseFile), 'distribution'));
        $state = Deployment::read($root);
        if (!in_array($base['format'] ?? 0, [2, 3], true) || $state === null) { throw new \RuntimeException('Install the current framework before adding module packages.'); }
        $prepared = self::prepare($archive); $package = $prepared['package']; $stage = $prepared['stage'];
        try {
            $id = $package['id']; $prefix = 'App/Modules/' . $package['directory'] . '/';
            $packages = PackageRegistry::read($root); $old = $packages[$id] ?? null;
            if (isset($base['selection'][$id])) { throw new \RuntimeException('This module is supplied by the distribution; update it through the distribution updater.'); }
            if ($old === null && file_exists($root . '/' . rtrim($prefix, '/'))) { throw new \RuntimeException('Target module directory is already present.'); }
            if ($old !== null) {
                if ($old['directory'] !== $package['directory'] || $old['manifest']['namespace'] !== $package['manifest']['namespace'] || version_compare($package['version'], $old['version'], '<=')) { throw new \RuntimeException('Select a newer package with the same module identity.'); }
                foreach ($old['schemas'] as $stream => $schema) {
                    foreach ($schema['migrations'] as $index => $migration) {
                        if (($package['schemas'][$stream]['migrations'][$index] ?? null) !== $migration) { throw new \RuntimeException('Published module migration history cannot be changed.'); }
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
            return \TinyCat\Core\Updates\Manager::runLifecycle(static function () use ($root, $effective, $state, $installed, $id, $package): array {
                if ($installed) { ModuleSchemas::synchronize(Connection::get(), Distribution::schemas($effective, $root, $state['installed'])); }
                Deployment::write($root, $effective, $state['active'], $state['installed'], \TinyCat\Support\Version::CURRENT);
                return ['id' => $id, 'version' => $package['version'], 'active' => in_array($id, $state['active'], true)];
            }, $installed && $package['schemas'] !== [], static function () use ($root, $fingerprint, $baseFile): void {
                $now = [PackageRegistry::read($root), Deployment::read($root), self::decodeJson((string) file_get_contents($baseFile), 'distribution')];
                if (!hash_equals($fingerprint, hash('sha256', json_encode($now, JSON_THROW_ON_ERROR)))) { throw new \RuntimeException('Module catalog changed; retry installation.'); }
            }, ['stage' => $mapped, 'files' => $files, 'delete' => $deleted]);
        } finally { self::cleanup($stage); }
    }

    private static function cleanup(string $stage): void
    {
        $base = realpath(Paths::basePath('storage/modules/staging')); $resolved = realpath($stage);
        if ($base === false || $resolved === false || !ModuleAutoload::inside($resolved, $base) || dirname($resolved) !== $base) { return; }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($resolved);
    }
}
