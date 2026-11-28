<?php
declare(strict_types=1);

namespace TinyCat\Support;

use TinyCat\Core\Modules\DeploymentIdentity;

/** PHP arrays in the signed inventory are cached by OPcache, without JSON parsing. */
final class CompiledTranslations
{
    /** @var array<string, array<mixed>> */
    private static array $catalogs = [];
    /** @return array<mixed> */
    private static function manifest(?string $locale = null): array
    {
        if (class_exists(\TinyCat\Core\Modules\Resources::class, false) && \TinyCat\Core\Modules\Resources::isScoped()) { return []; }
        if (!in_array('inventory', get_class_methods(DeploymentIdentity::class), true)) { return []; }
        $inventory = DeploymentIdentity::inventory($locale);
        return $inventory['translations'] ?? [];
    }

    /** @return array<string, string>|null */
    public static function packages(): ?array
    {
        return self::manifest()['packages'] ?? null;
    }

    /** @return array<mixed>|null */
    private static function catalog(string $locale, string $channel): ?array
    {
        $manifest = self::manifest($locale);
        if ($manifest === []) { return null; }
        $key = $manifest['generation'] . ':' . $locale;
        if (isset(self::$catalogs[$key])) { DeploymentIdentity::delivered(); return self::$catalogs[$key][$channel] ?? null; }
        $name = $manifest['catalogs'][$locale] ?? null;
        if (!is_string($name)) { return null; }
        if (!preg_match('/^[a-z0-9-]+\.[a-f0-9]{24}\.php$/D', $name)) {
            throw new \RuntimeException('Invalid compiled translation path.');
        }
        $file = Paths::basePath('App/Core/Support/compiled/' . $name);
        if (!is_file($file)) { DeploymentIdentity::reject(); return null; }
        $catalog = require $file;
        if (!is_array($catalog) || !is_array($catalog['app'] ?? null) || !is_array($catalog['emails'] ?? null)) { DeploymentIdentity::reject(); return null; }
        self::$catalogs[$key] = $catalog;
        DeploymentIdentity::delivered();
        return $catalog[$channel] ?? null;
    }

    /** @return array<string, string>|null */
    public static function application(string $locale): ?array
    {
        return self::catalog($locale, 'app');
    }

    /** @return array<mixed>|null */
    public static function emails(string $locale): ?array
    {
        return self::catalog($locale, 'emails');
    }
}
