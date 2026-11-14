<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

/** Technical runtime inputs supplied by selected owners, never product-ID switches. */
final class Startup
{
    public static bool $legacySchemas = false;
    /** @var \Closure(): (array<string, mixed>|null)|null */
    public static ?\Closure $identity = null;
    /** @var list<\Closure(): bool> */
    public static array $readiness = [];
    /** @var \Closure(): mixed|null */
    public static ?\Closure $locale = null;
    /** @var \Closure(string, mixed): mixed|null */
    public static ?\Closure $dates = null;
    /** @var \Closure(string, mixed): mixed|null */
    public static ?\Closure $options = null;
    private static ?HttpPolicy $http = null;
    private static ?string $httpOwner = null;
    /** @var \Closure(\Throwable): void|null */
    public static ?\Closure $exception = null;
    /** @var list<string> */
    public static array $frameOrigins = [];

    public static function provideHttp(string $owner, HttpPolicy $policy): void
    {
        if (self::$httpOwner !== null && self::$httpOwner !== $owner) { throw new \LogicException('Conflicting HTTP policy providers.'); }
        self::$httpOwner = $owner; self::$http = $policy;
    }

    public static function http(): HttpPolicy { return self::$http ?? new HttpPolicy(); }

    public static function option(string $key, mixed $default = null): mixed
    {
        return self::$options !== null ? (self::$options)($key, $default) : \TinyCat\Support\Config::get($key, $default);
    }

    public static function context(Configuration $configuration): Context
    {
        return new Context($configuration, identity: self::$identity,
            ready: static function (): bool { foreach (self::$readiness as $ready) { if (!$ready()) { return false; } } return true; },
            locale: self::$locale, dates: self::$dates);
    }
}
