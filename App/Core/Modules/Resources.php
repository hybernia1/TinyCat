<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use TinyCat\Core\Modules\Catalog;
use TinyCat\Support\Paths;

/** The same explicit selection serves bootstrap, source readers and builds. */
final class Resources
{
    private static ?Catalog $default = null;
    private static ?Catalog $selected = null;
    private static ?string $selectedRoot = null;
    private static int $revision = 0;

    public static function catalog(?string $root = null): Catalog
    {
        $root = realpath($root ?? Paths::basePath()) ?: throw new \InvalidArgumentException('Missing application root.');
        if (self::$selected !== null && $root === self::$selectedRoot) { return self::$selected; }
        if ($root === realpath(Paths::basePath())) { return self::$default ??= Loader::load($root)->catalog; }
        return Loader::load($root)->catalog;
    }

    public static function configure(Catalog $catalog): void
    {
        self::$default = $catalog;
        self::$revision++;
    }

    public static function revision(): int { return self::$revision; }
    public static function isScoped(): bool { return self::$selected !== null; }

    public static function fingerprint(): string
    {
        $ids = array_map(static fn (\TinyCat\Core\Modules\Definition $definition): string => $definition->id, self::catalog()->definitions());
        sort($ids, SORT_STRING);
        return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
    }

    /** Build/test selection scope; no route or installation lifecycle is activated.
     * @template T
     * @param callable(): T $action
     * @return T */
    public static function withCatalog(Catalog $catalog, callable $action, ?string $root = null): mixed
    {
        $resolvedRoot = realpath($root ?? Paths::basePath()) ?: throw new \InvalidArgumentException('Missing application root.');
        $previous = self::$selected; $previousRoot = self::$selectedRoot;
        self::$selected = $catalog;
        self::$selectedRoot = $resolvedRoot;
        self::$revision++;
        try { return $action(); }
        finally { self::$selected = $previous; self::$selectedRoot = $previousRoot; self::$revision++; }
    }

    /** Preserve 5.x source order: shared UI first, then lexical module paths.
     * @return list<string> */
    public static function files(string $kind, ?string $root = null): array
    {
        $paths = self::catalog($root)->resources($kind);
        $ui = $paths['tinycat.ui'] ?? null;
        unset($paths['tinycat.ui']);
        ksort($paths, SORT_STRING);
        return $ui === null ? array_values($paths) : [$ui, ...array_values($paths)];
    }

    /** @return array<string, string> Compatibility owner name => selected source directory. */
    public static function translations(?string $root = null): array
    {
        $catalog = self::catalog($root);
        $directories = [];
        foreach (['translations', 'emails'] as $kind) {
            foreach ($catalog->resources($kind) as $id => $path) {
                $namespace = $catalog->get($id)->namespace;
                $segments = explode('\\', trim($namespace, '\\'));
                $owner = end($segments) . ($kind === 'emails' ? 'Emails' : '');
                if (isset($directories[$owner])) { throw new \LogicException('Duplicate translation owner: ' . $owner); }
                $directories[$owner] = str_replace('\\', '/', $path);
            }
        }
        ksort($directories);
        return $directories;
    }

    public static function ownsModuleAsset(string $absolute): bool
    {
        foreach (['assets', 'components'] as $kind) {
            foreach (self::files($kind) as $file) {
                $prefix = dirname($file) . DIRECTORY_SEPARATOR;
                if (PHP_OS_FAMILY === 'Windows' ? str_starts_with(strtolower($absolute), strtolower($prefix)) : str_starts_with($absolute, $prefix)) { return true; }
            }
        }
        return false;
    }

    public static function translationDomain(string $owner, ?string $root = null): string
    {
        $paths = self::translations($root);
        foreach (self::catalog($root)->definitions() as $definition) {
            if (isset($definition->resources['emails']) && ($paths[$owner] ?? null) === str_replace('\\', '/', $definition->resources['emails'])) { return 'emails'; }
            if (isset($definition->resources['translations']) && ($paths[$owner] ?? null) === str_replace('\\', '/', $definition->resources['translations'])) { return $definition->translationDomain; }
        }
        throw new \InvalidArgumentException('Unknown translation owner.');
    }

    public static function templateDirectory(string $owner): string
    {
        $matches = [];
        foreach (self::catalog()->definitions() as $definition) {
            $segments = explode('\\', trim($definition->namespace, '\\'));
            if (($definition->id === $owner || end($segments) === $owner) && isset($definition->resources['templates'])) { $matches[] = $definition->resources['templates']; }
        }
        if (count($matches) !== 1) { throw new \InvalidArgumentException('Partial must name exactly one selected template owner: ' . $owner); }
        return $matches[0];
    }
}
