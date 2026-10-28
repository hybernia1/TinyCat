<?php
declare(strict_types=1);
namespace TinyCat\Status;

use TinyCat\Settings\Store;

/** Resolve only links belonging to currently public content, never arbitrary redirect targets. */
final class ContentLinks
{
    public static function url(int $contentId, string $url, int $commentId = 0, ?string $knownHash = null): string
    {
        if ($contentId < 1 || Links::status_internal_url($url)) { return $url; }
        if ($knownHash !== null && preg_match('/^[a-f0-9]{64}$/D', $knownHash) === 1) { return self::navigation($contentId, $knownHash, $commentId); }
        $link = LinkParser::fromRaw($url);
        if ($link === null) { return $url; }
        return self::navigation($contentId, (string) $link['url_hash'], $commentId);
    }

    /** The caller already parsed and classified the link while preparing the feed. */
    public static function navigation(int $contentId, string $hash, int $commentId = 0): string
    {
        return '/api/statuses/' . $contentId . ($commentId > 0 ? '/comments/' . $commentId : '') . '/links/' . $hash . '?navigate=1';
    }

    /** @return array{url: string, original_url: string}|null */
    public static function resolve(int $contentId, string $hash, int $commentId = 0): ?array
    {
        $item = \TinyCat\Database\Connection::one('SELECT c.body FROM content c INNER JOIN users u ON u.id = c.author_id WHERE c.id = ? AND c.published_at <= ? AND u.status = ?', [$contentId, \TinyCat\Support\Dates::dateDb(), 'active']);
        if ($item === null) { return null; }
        if ($commentId > 0) {
            $comment = \TinyCat\Database\Connection::one('SELECT cc.body FROM content_comments cc INNER JOIN users u ON u.id = cc.user_id WHERE cc.id = ? AND cc.content_id = ? AND u.status = ?', [$commentId, $contentId, 'active']);
            if ($comment === null) { return null; }
            $item = $comment;
        }
        preg_match_all(LinkParser::pattern(), (string) $item['body'], $matches);
        foreach ($matches[0] as $raw) {
            [$raw] = LinkParser::splitTail($raw);
            $link = LinkParser::fromRaw($raw);
            if ($link !== null && hash_equals((string) $link['url_hash'], $hash)) { return self::destination($raw); }
        }
        if ($commentId === 0) {
            foreach (\TinyCat\Database\Connection::all('SELECT l.normalized_url, l.url_hash FROM content_links cl INNER JOIN links l ON l.id = cl.link_id WHERE cl.content_id = ?', [$contentId]) as $link) {
                $url = (string) ($link['normalized_url'] ?? '');
                $parsed = LinkParser::fromRaw($url);
                if ($parsed !== null && (hash_equals((string) $parsed['url_hash'], $hash) || hash_equals((string) $link['url_hash'], $hash))) { return self::destination($url); }
            }
        }
        return null;
    }

    /** @return array{url: string, original_url: string}|null */
    private static function destination(string $raw): ?array
    {
        if (LinkParser::fromRaw($raw) === null || Links::status_internal_url($raw)) { return null; }
        $original = preg_match('~^https?://~i', $raw) === 1 ? $raw : 'https://' . $raw;
        return ['url' => self::tagged($original), 'original_url' => $original];
    }

    public static function tagged(string $url): string
    {
        if (!(bool) Store::config('content_links.utm.enabled', true)) { return $url; }
        $source = trim((string) Store::config('content_links.utm.source', ''));
        if ($source === '') { $source = (string) parse_url((string) Store::config('app.url', ''), PHP_URL_HOST); }
        if ($source === '') { return $url; }
        $values = ['utm_source' => $source, 'utm_medium' => (string) Store::config('content_links.utm.medium', 'referral'), 'utm_campaign' => (string) Store::config('content_links.utm.campaign', '')];
        $parts = explode('#', $url, 2);
        $query = (string) parse_url($parts[0], PHP_URL_QUERY);
        $present = [];
        foreach (explode('&', $query) as $parameter) { $present[strtolower(urldecode(explode('=', $parameter, 2)[0]))] = true; }
        $extra = [];
        foreach ($values as $key => $value) { if ($value !== '' && !isset($present[$key])) { $extra[$key] = $value; } }
        if ($extra === []) { return $url; }
        $separator = str_contains($parts[0], '?') ? (str_ends_with($parts[0], '?') || str_ends_with($parts[0], '&') ? '' : '&') : '?';
        return $parts[0] . $separator . http_build_query($extra, '', '&', PHP_QUERY_RFC3986) . (isset($parts[1]) ? '#' . $parts[1] : '');
    }
}
