<?php
declare(strict_types=1);
namespace TinyCat\Blog;
use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Distribution;
use TinyCat\Core\Modules\Lifecycle;
use TinyCat\Core\Runtime\Application;
use TinyCat\Core\Runtime\HttpKernel;


use TinyCat\Core\Runtime\Configuration;
use TinyCat\Database\Connection;
use TinyCat\Database\ModuleSchemas;
use TinyCat\Support\Config;

/** Programmatic fixture adapter over the minimal manifest preset and shared runtime. */
final class Installation
{
    public static function modules(): Loader
    {
        return Loader::fromSelection(dirname(__DIR__, 4), require \TinyCat\Support\Paths::basePath('App/Modules/Blog/selection.php'));
    }

    public static function boot(Configuration $configuration, ?Loader $modules = null): void
    {
        Application::start($configuration, $modules ?? self::modules());
    }

    /** Explicit fresh installation; refuses existing application data. Creates no account or secret. */
    public static function install(\PDO $pdo): void
    {
        if (!in_array($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME), ['mysql', 'sqlite'], true)) { throw new \InvalidArgumentException('Unsupported database.'); }
        $query = $pdo->query($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'" : 'SHOW TABLES');
        if ($query === false) { throw new \RuntimeException('Cannot inspect installation database.'); }
        $tables = $query->fetchAll(\PDO::FETCH_COLUMN);
        if ($tables !== []) { throw new \RuntimeException('Blog fresh installation requires an empty database.'); }
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') { $pdo->exec('PRAGMA foreign_keys = ON'); }
        ModuleSchemas::install($pdo, Distribution::definitions(self::modules(), false));
    }

    /** Existing Blog-owned migrations only. Product conversion and package updates belong to lifecycle. */
    public static function upgrade(\PDO $pdo): void { ModuleSchemas::synchronize($pdo, Distribution::definitions(self::modules(), false)); }

    public static function respond(): void
    {
        \TinyCat\Core\Runtime\HttpKernel::respond();
    }
}
