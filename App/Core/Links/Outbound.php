<?php
declare(strict_types=1);
namespace TinyCat\Core\Links;

use TinyCat\Core\Text\LinkParser;

/** Resolve only an authorized owner's text and attachments; never arbitrary input URLs. */
final class Outbound
{
    /** @param \Closure(): array<mixed>|null $attachments
     * @param \Closure(string): string|null $tagged
     * @return array{url: string, original_url: string}|null */
    public static function resolve(string $hash, string $body, ?\Closure $attachments = null, ?\Closure $tagged = null): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) { return null; }
        preg_match_all(LinkParser::pattern(), $body, $matches);
        foreach ($matches[0] as $raw) {
            [$raw] = LinkParser::splitTail($raw); $link = LinkParser::fromRaw($raw);
            if ($link !== null && hash_equals((string) $link['url_hash'], $hash)) { return self::destination($raw, $tagged); }
        }
        foreach ($attachments !== null ? $attachments() : [] as $link) {
            $parsed = LinkParser::fromRaw((string) ($link['normalized_url'] ?? ''));
            if ($parsed !== null && (hash_equals((string) $parsed['url_hash'], $hash) || hash_equals((string) ($link['url_hash'] ?? ''), $hash))) { return self::destination((string) $link['normalized_url'], $tagged); }
        }
        return null;
    }
    /** @param array{url: string, original_url: string}|null $link
     * @return array{url: string, original_url: string} */
    public static function response(\TinyCat\Http\Request $request, ?array $link): array
    {
        header('Cache-Control: private, no-store'); header('Vary: Accept'); header('X-Robots-Tag: noindex, nofollow');
        if ($link === null) { \TinyCat\Http\Response::error('Content link not found.', 404, 'content_link_not_found'); }
        if ($request->input('navigate') === '1' && !$request->wantsJson() && str_contains(strtolower((string) $request->header('Accept')), 'text/html')) { header('Referrer-Policy: no-referrer'); header('Location: ' . $link['url'], true, 302); exit; }
        return $link;
    }
    /** @param \Closure(string): string|null $tagged
     * @return array{url: string, original_url: string}|null */
    public static function destination(string $raw, ?\Closure $tagged = null): ?array
    {
        if (LinkParser::fromRaw($raw) === null || Urls::status_internal_url($raw)) { return null; }
        $original = preg_match('~^https?://~i', $raw) === 1 ? $raw : 'https://' . $raw;
        return ['url' => ($tagged !== null ? $tagged($original) : $original), 'original_url' => $original];
    }

}
