<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Social owns tracking policy; the shared resolver accepts only owner-supplied data. */
final class Outbound
{
    /** @param \Closure(): array<mixed>|null $attachments
     * @return array{url: string, original_url: string}|null */
    public static function resolve(string $hash, string $body, ?\Closure $attachments = null): ?array { return \TinyCat\Core\Links\Outbound::resolve($hash, $body, $attachments, self::tagged(...)); }
    /** @param array{url: string, original_url: string}|null $link
     * @return array{url: string, original_url: string} */
    public static function response(\TinyCat\Http\Request $request, ?array $link): array { return \TinyCat\Core\Links\Outbound::response($request, $link); }
    /** @return array{url: string, original_url: string}|null */
    public static function destination(string $raw): ?array { return \TinyCat\Core\Links\Outbound::destination($raw, self::tagged(...)); }

    public static function tagged(string $url): string
    {
        if (!(bool) Integration::option('content_links.utm.enabled', true)) { return $url; }
        $source = trim((string) Integration::option('content_links.utm.source', ''));
        if ($source === '') { $source = (string) parse_url((string) Integration::option('app.url', ''), PHP_URL_HOST); }
        if ($source === '') { return $url; }
        $values = ['utm_source' => $source, 'utm_medium' => (string) Integration::option('content_links.utm.medium', 'referral'), 'utm_campaign' => (string) Integration::option('content_links.utm.campaign', '')];
        return \TinyCat\Core\Links\Tracking::append($url, $values);
    }
}
