<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use TinyCat\Core\Modules\Catalog;

/** Catalog-owned namespace roots. Unselected directories never become autoload roots. */
final class ModuleAutoload
{
    private static bool $registered = false;
    private static ?Catalog $selected = null;

    /** @template T
     * @param callable(): T $action
     * @return T */
    public static function withCatalog(Catalog $catalog, callable $action): mixed
    {
        if (!self::$registered) {
            class_exists(Resources::class);
            spl_autoload_register(self::load(...), true, true);
            self::$registered = true;
        }
        $previous = self::$selected;
        self::$selected = $catalog;
        try { return $action(); }
        finally { self::$selected = $previous; }
    }

    public static function load(string $class): void
    {
        foreach (['TinyCat\\Core\\', 'TinyCat\\Http\\', 'TinyCat\\Database\\', 'TinyCat\\Support\\'] as $prefix) { if (str_starts_with($class, $prefix)) { return; } }
        $catalog = self::$selected ?? Resources::catalog();
        foreach ($catalog->definitions() as $definition) {
            if ($definition->manifest === null || !str_starts_with($class, $definition->namespace)) { continue; }
            $relative = substr($class, strlen($definition->namespace));
            if (preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)*$/D', $relative) !== 1) { return; }
            $root = realpath($definition->root . '/src');
            $file = realpath($definition->root . '/src/' . str_replace('\\', '/', $relative) . '.php');
            if ($root === false || !self::inside($root, $definition->root) || $file === false || !is_file($file) || !self::inside($file, $root)) { return; }
            require_once $file;
            return;
        }
    }

    public static function inside(string $file, string $root): bool
    {
        $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        return PHP_OS_FAMILY === 'Windows' ? str_starts_with(strtolower($file), strtolower($prefix)) : str_starts_with($file, $prefix);
    }
}
