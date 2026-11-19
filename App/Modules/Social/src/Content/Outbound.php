<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Social owns tracking policy; the shared resolver accepts only owner-supplied data. */
final class Outbound
{
    /** @param \Closure(): array<mixed>|null $attachments
     * @return array{url: string, original_url: string}|null */
    public static function resolve(string $hash, string $body, ?\Closure $attachments = null): ?array { return \TinyCat\Core\Links\Outbound::resolve($hash, $body, $attachments, self::tagged(...)); }
    /** @return array{url: string, original_url: string}|null */
    public static function destination(string $raw): ?array { return \TinyCat\Core\Links\Outbound::destination($raw, self::tagged(...)); }

    public static function tagged(string $url): string
    {
        if (!(bool) \TinyCat\Core\Runtime\Startup::option('content_links.utm.enabled', true)) { return $url; }
        $source = trim((string) \TinyCat\Core\Runtime\Startup::option('content_links.utm.source', ''));
        if ($source === '') { $source = (string) parse_url((string) \TinyCat\Core\Runtime\Startup::option('app.url', ''), PHP_URL_HOST); }
        if ($source === '') { return $url; }
        $values = ['utm_source' => $source, 'utm_medium' => (string) \TinyCat\Core\Runtime\Startup::option('content_links.utm.medium', 'referral'), 'utm_campaign' => (string) \TinyCat\Core\Runtime\Startup::option('content_links.utm.campaign', '')];
        return \TinyCat\Core\Links\Tracking::append($url, $values);
    }
}
