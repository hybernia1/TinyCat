<?php
declare(strict_types=1);

namespace TinyCat\Support;

use RuntimeException;

/** Dependency graph for local, side-effect-only ES module sources. */
final class AssetGraph
{
    /** @var array<string, string>|null */
    private static ?array $buildSources = null;
    /** @var array<string, list<string>>|null */
    private static ?array $buildImports = null;

    /**
     * @template T
     * @param callable(): T $build
     * @return T
     */
    public static function withSnapshot(callable $build): mixed
    {
        $sources = self::$buildSources; $imports = self::$buildImports;
        self::$buildSources = []; self::$buildImports = [];
        try { return $build(); }
        finally { self::$buildSources = $sources; self::$buildImports = $imports; }
    }

    public static function resolve(string $importer, string $relative): string
    {
        if (!str_starts_with($relative, '.')) { throw new RuntimeException('Asset imports must be relative: ' . $relative); }
        $parts = [];
        foreach (explode('/', dirname($importer) . '/' . $relative) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { array_pop($parts); } else { $parts[] = $part; }
        }
        $path = implode('/', $parts);
        self::read($path);
        return $path;
    }

    public static function read(string $path): string
    {
        if (self::$buildSources !== null && isset(self::$buildSources[$path])) { return self::$buildSources[$path]; }
        $absolute = realpath(Paths::basePath($path));
        $assets = realpath(Paths::basePath('assets'));
        if (!str_starts_with($path, 'assets/') || !is_string($absolute) || !is_string($assets)
            || !str_starts_with($absolute, $assets . DIRECTORY_SEPARATOR) || !is_file($absolute)) {
            throw new RuntimeException('Missing or invalid asset: ' . $path);
        }
        $source = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($absolute));
        if (self::$buildSources !== null) { self::$buildSources[$path] = $source; }
        return $source;
    }

    /** @return list<string> */
    public static function imports(string $path): array
    {
        if (self::$buildImports !== null && isset(self::$buildImports[$path])) { return self::$buildImports[$path]; }
        preg_match_all('/^import\s+["\']([^"\']+)["\'];[ \t]*$/m', self::read($path), $matches);
        $imports = array_map(static fn (string $relative): string => self::resolve($path, $relative), $matches[1]);
        if (self::$buildImports !== null) { self::$buildImports[$path] = $imports; }
        return $imports;
    }

    /** @param list<string> $roots
     * @param list<string> $exclude
     * @return list<string>
     */
    public static function order(array $roots, array $exclude = []): array
    {
        $seen = array_fill_keys($exclude, true); $active = []; $ordered = [];
        $visit = static function (string $path) use (&$visit, &$seen, &$active, &$ordered): void {
            if (isset($seen[$path])) { return; }
            if (isset($active[$path])) { throw new RuntimeException('Cyclic asset dependency: ' . implode(' -> ', [...array_keys($active), $path])); }
            $active[$path] = true;
            foreach (self::imports($path) as $dependency) { $visit($dependency); }
            unset($active[$path]); $seen[$path] = true; $ordered[] = $path;
        };
        foreach ($roots as $root) { $visit($root); }
        return $ordered;
    }

    /** @param list<string> $ordered */
    public static function javascript(array $ordered): string
    {
        $sources = [];
        foreach ($ordered as $path) {
            $source = (string) preg_replace('/^import\s+["\'][^"\']+["\'];[ \t]*\n?/m', '', self::read($path));
            if (preg_match('/^\s*(?:import|export)\b/m', $source)) { throw new RuntimeException('Unsupported asset module syntax: ' . $path); }
            $sources[] = '/* ' . $path . " */\n" . $source;
        }
        return implode("\n;\n", $sources);
    }

    /** @param list<string> $selected */
    public static function stylesheet(array $selected): string
    {
        $allowed = array_fill_keys($selected, true); $sources = [];
        preg_match_all('/^@import\s+["\']([^"\']+)["\'];$/m', self::read('assets/styles/index.css'), $matches);
        foreach ($matches[1] as $relative) {
            $path = self::resolve('assets/styles/index.css', $relative);
            if (isset($allowed[$path])) { $sources[] = self::read($path); unset($allowed[$path]); }
        }
        if ($allowed !== []) { throw new RuntimeException('Styles missing from cascade order: ' . implode(', ', array_keys($allowed))); }
        return implode("\n", $sources);
    }
}
