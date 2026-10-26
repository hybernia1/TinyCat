<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** PHP arrays in the signed inventory are cached by OPcache, without JSON parsing. */
final class CompiledTranslations
{
    /** @var array<mixed>|null */
    private static ?array $manifest = null;
    /** @var array<string, array<mixed>> */
    private static array $catalogs = [];

    /** @return array<mixed> */
    private static function manifest(): array
    {
        if (self::$manifest !== null) { return self::$manifest; }
        $file = Paths::basePath('App/Support/compiled/translations.php');
        return self::$manifest = is_file($file) ? require $file : [];
    }

    /** @return array<string, string>|null */
    public static function packages(): ?array
    {
        return self::manifest()['packages'] ?? null;
    }

    /** @return array<mixed>|null */
    private static function catalog(string $locale): ?array
    {
        if (isset(self::$catalogs[$locale])) { return self::$catalogs[$locale]; }
        $name = self::manifest()['catalogs'][$locale] ?? null;
        if (!is_string($name)) { return null; }
        if (!preg_match('/^[a-z0-9-]+\.[a-f0-9]{24}\.php$/D', $name)) {
            throw new \RuntimeException('Invalid compiled translation path.');
        }
        return self::$catalogs[$locale] = require Paths::basePath('App/Support/compiled/' . $name);
    }

    /** @return array<string, string>|null */
    public static function application(string $locale): ?array
    {
        return self::catalog($locale)['app'] ?? null;
    }

    /** @return array<mixed>|null */
    public static function emails(string $locale): ?array
    {
        return self::catalog($locale)['emails'] ?? null;
    }
}
