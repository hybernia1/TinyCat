<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

use TinyCat\Core\Text\{Policies, TextPolicy};

/** Product text and media policies; no social provider is inferred or loaded. */
final class Integration
{
    /** @var \Closure(string): string|null */
    private static ?\Closure $blocked = null;
    /** @var \Closure(): string|null */
    private static ?\Closure $base = null;
    /** @var \Closure(string, mixed): mixed|null */
    private static ?\Closure $option = null;
    /** @var \Closure(string): string|null */
    private static ?\Closure $path = null;

    /** @param \Closure(string): string|null $blocked
     * @param \Closure(string): string|null $mentions
     * @param \Closure(): string|null $base
     * @param \Closure(string, mixed): mixed|null $option
     * @param \Closure(string): string|null $path */
    public static function configure(?\Closure $blocked = null, ?\Closure $mentions = null, ?\Closure $base = null, ?\Closure $option = null, ?\Closure $path = null): void
    {
        self::$blocked = $blocked; self::$base = $base; self::$option = $option; self::$path = $path;
        Policies::forget('post');
        if ($mentions !== null) { Policies::provide('tinycat.social', 'post', new TextPolicy(normalize: $mentions)); }
        \TinyCat\Core\Links\Urls::configure($base);
    }

    /** @param \Closure(string): string $policy */
    public static function blocking(\Closure $policy): void { self::$blocked = $policy; }
    /** @param \Closure(string): string $policy */
    public static function mentions(\Closure $policy): void { Policies::provide('tinycat.social', 'post', new TextPolicy(normalize: $policy)); }
    /** @param \Closure(): string $base @param \Closure(string): string $path */
    public static function location(\Closure $base, \Closure $path): void { self::$base = $base; self::$path = $path; \TinyCat\Core\Links\Urls::configure($base); }

    public static function blockedHost(string $text): string { return self::$blocked !== null ? (self::$blocked)($text) : Policy::blockedHost($text); }
    public static function normalizeMentions(string $text, string $kind = 'post'): string { $normalize = self::policy($kind)->normalize; return $normalize !== null ? $normalize($text) : $text; }
    public static function baseUrl(): string
    {
        if (self::$base !== null) { return (self::$base)(); }
        $base = (string) self::option('publishing.base_url', self::option('app.url', ''));
        return $base !== '' ? $base : \TinyCat\Support\LocalUrls::absolute('/');
    }
    public static function option(string $key, mixed $default): mixed { return self::$option !== null ? (self::$option)($key, $default) : \TinyCat\Core\Runtime\Startup::option($key, $default); }
    public static function unlocalizedPath(string $path): string { return self::$path !== null ? (self::$path)($path) : \TinyCat\Http\LocaleRouting::split($path)['path']; }
    public static function provide(string $owner, string $kind, TextPolicy $policy): void
    {
        Policies::provide($owner, $kind, $policy);
    }

    public static function policy(string $kind): TextPolicy { return Policies::policy($kind); }
}
