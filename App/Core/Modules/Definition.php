<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use InvalidArgumentException;
use JsonException;
use stdClass;

/** Metadata only: loading a definition never loads a provider or domain class. */
final readonly class Definition
{
    /**
     * @param list<string> $requires
     * @param list<string> $exports
     * @param array<string, string> $providers
     * @param array<string, string> $resources Canonical paths within the module.
     * @param array{product: string, legacy: bool, required: list<string>, artifact: string}|null $distribution
     */
    private function __construct(
        public string $id,
        public string $namespace,
        public string $root,
        public array $requires,
        public array $exports,
        public array $providers,
        public array $resources,
        public ?string $manifest = null,
        public string $translationDomain = 'app',
        public ?array $distribution = null,
    ) {}

    public static function fromManifest(string $file): self
    {
        $resolved = realpath($file);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new InvalidArgumentException('Unreadable module manifest: ' . $file);
        }
        $source = file_get_contents($resolved);
        if ($source === false) { throw new InvalidArgumentException('Unreadable module manifest: ' . $file); }
        try { $data = json_decode($source, false, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new InvalidArgumentException('Invalid module JSON: ' . $file, 0, $error); }
        if (!$data instanceof stdClass) { throw new InvalidArgumentException('Module manifest must be an object: ' . $file); }
        return self::parse($data, dirname($resolved), $resolved);
    }

    /** Mandatory framework capabilities use metadata without selectable manifests. */
    public static function fromMetadata(stdClass $data, string $root): self
    {
        return self::parse($data, $root, null);
    }

    private static function parse(stdClass $data, string $root, ?string $manifest): self
    {
        $allowed = ['format', 'id', 'coreApi', 'namespace', 'requires', 'exports', 'providers', 'resources', 'translationDomain', 'distribution'];
        if (array_diff(array_keys(get_object_vars($data)), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown module metadata field.');
        }
        if (($data->format ?? null) !== 1 || ($data->coreApi ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported module format or core API.');
        }
        $id = self::id($data->id ?? null);
        $namespace = $data->namespace ?? null;
        if (!is_string($namespace) || preg_match('/^(?:[A-Za-z][A-Za-z0-9]*\\\\)+$/D', $namespace) !== 1) {
            throw new InvalidArgumentException('Invalid module namespace: ' . $id);
        }
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) { throw new InvalidArgumentException('Missing module root: ' . $id); }
        $requires = self::stringList(property_exists($data, 'requires') ? $data->requires : [], 'requires');
        foreach ($requires as $dependency) {
            self::id($dependency);
            if ($dependency === $id) { throw new InvalidArgumentException('Module cannot require itself: ' . $id); }
        }
        $exports = self::stringList(property_exists($data, 'exports') ? $data->exports : [], 'exports');
        foreach ($exports as $export) { self::ownedClass($export, $namespace); }
        $providers = self::stringMap(property_exists($data, 'providers') ? $data->providers : new stdClass(), [
            'services', 'routes', 'schema', 'settings', 'permissions', 'tasks', 'navigation', 'health', 'contributions',
        ]);
        foreach ($providers as $provider) { self::ownedClass($provider, $namespace); }
        $resources = self::stringMap(property_exists($data, 'resources') ? $data->resources : new stdClass(), [
            'translations', 'emails', 'templates', 'assets', 'components', 'componentExtensions', 'migrations', 'entrypoints',
        ]);
        foreach ($resources as $kind => $path) {
            $path = str_replace('\\', '/', $path);
            if (preg_match('~^(?!/)(?![A-Za-z]:)[^\x00]+$~D', $path) !== 1
                || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
                throw new InvalidArgumentException('Invalid module resource path: ' . $path);
            }
            $resolved = realpath($resolvedRoot . DIRECTORY_SEPARATOR . $path);
            $prefix = rtrim($resolvedRoot, '/\\') . DIRECTORY_SEPARATOR;
            $inside = $resolved !== false && (PHP_OS_FAMILY === 'Windows'
                ? str_starts_with(strtolower($resolved), strtolower($prefix))
                : str_starts_with($resolved, $prefix));
            if (!$inside || !is_readable($resolved)) { throw new InvalidArgumentException('Module resource escapes its root or is unreadable: ' . $path); }
            $directory = in_array($kind, ['translations', 'emails', 'templates', 'migrations', 'entrypoints'], true);
            if ($directory ? !is_dir($resolved) : !is_file($resolved)) {
                throw new InvalidArgumentException('Wrong module resource type: ' . $path);
            }
            $resources[$kind] = $resolved;
        }
        $translationDomain = $data->translationDomain ?? 'app';
        if (!is_string($translationDomain) || preg_match('/^[a-z][a-z0-9_-]*$/D', $translationDomain) !== 1) { throw new InvalidArgumentException('Invalid translation domain.'); }
        $distribution = null;
        if (isset($data->distribution)) {
            $profile = $data->distribution;
            if (!$profile instanceof stdClass || array_diff(array_keys(get_object_vars($profile)), ['product', 'legacy', 'required', 'artifact']) !== []
                || !is_string($profile->product ?? null) || preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/D', $profile->product) !== 1
                || !is_bool($profile->legacy ?? null) || !is_string($profile->artifact ?? null) || preg_match('/^[a-z][a-z0-9.-]*$/D', $profile->artifact) !== 1) { throw new InvalidArgumentException('Invalid distribution profile.'); }
            $required = self::stringList($profile->required ?? [], 'required');
            foreach ($required as $requiredId) { self::id($requiredId); }
            $distribution = ['product' => $profile->product, 'legacy' => $profile->legacy, 'required' => $required, 'artifact' => $profile->artifact];
        }
        return new self($id, $namespace, $resolvedRoot, $requires, $exports, $providers, $resources, $manifest, $translationDomain, $distribution);
    }

    private static function id(mixed $id): string
    {
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9]*)+$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid module ID.');
        }
        return $id;
    }

    private static function ownedClass(string $class, string $namespace): void
    {
        if (!str_starts_with($class, $namespace)
            || preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)+$/D', $class) !== 1) {
            throw new InvalidArgumentException('Module class must belong to its namespace: ' . $class);
        }
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) { throw new InvalidArgumentException('Module ' . $field . ' must be a list.'); }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || isset($seen[$item])) {
                throw new InvalidArgumentException('Module ' . $field . ' contains an invalid or duplicate entry.');
            }
            $seen[$item] = true;
        }
        return $value;
    }

    /** @param list<string> $allowed
     * @return array<string, string> */
    private static function stringMap(mixed $value, array $allowed): array
    {
        if (!$value instanceof stdClass) { throw new InvalidArgumentException('Module providers/resources must be objects.'); }
        $result = [];
        foreach (get_object_vars($value) as $key => $item) {
            if (!in_array($key, $allowed, true) || !is_string($item) || $item === '') {
                throw new InvalidArgumentException('Unknown or invalid module contribution: ' . $key);
            }
            $result[$key] = $item;
        }
        return $result;
    }
}
