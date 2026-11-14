<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

final class Entrypoints
{
    public static function run(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9-]*\.php$/D', $name) !== 1) { throw new \InvalidArgumentException('Invalid entrypoint.'); }
        $catalog = \TinyCat\Core\Modules\Loader::load(\TinyCat\Support\Paths::basePath())->catalog;
        $matches = [];
        foreach ($catalog->resources('entrypoints') as $directory) { if (is_file($directory . '/' . $name)) { $matches[] = $directory . '/' . $name; } }
        if (count($matches) !== 1) { throw new \RuntimeException('Entrypoint requires exactly one active owner.'); }
        require $matches[0];
    }
}
