<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;


use TinyCat\Social\Posts\LinkParser;

use TinyCat\Social\Posts\Links;


/** Resolve only links belonging to currently public content, never arbitrary redirect targets. */
final class ContentLinks
{
    public static function url(int $contentId, string $url, int $commentId = 0, ?string $knownHash = null): string
    {
        if ($contentId < 1 || Links::isInternal($url)) { return $url; }
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
        $item = \TinyCat\Database\Connection::one('SELECT c.body FROM social_posts c INNER JOIN users u ON u.id = c.author_id WHERE c.kind = \'post\' AND c.id = ? AND c.published_at <= ? AND c.status = \'published\' AND u.status = ?', [$contentId, \TinyCat\Support\Dates::dateDb(), 'active']);
        if ($item === null) {
            $canonicalId = Repository::redirectTarget($contentId);
            return $canonicalId === null ? null : self::resolve($canonicalId, $hash, $commentId);
        }
        if ($commentId > 0) {
            $comment = \TinyCat\Database\Connection::one('SELECT cc.body FROM social_comments cc WHERE cc.id = ? AND cc.content_id = ? AND ' . \TinyCat\Social\Discussion\Comments::visiblePredicate(), [$commentId, $contentId]);
            if ($comment === null) { return null; }
            $item = $comment;
        }
        return \TinyCat\Social\Posts\ContentLinks::resolveText($hash, (string) $item['body'], $commentId === 0 ? static fn (): array => \TinyCat\Database\Connection::all('SELECT l.normalized_url, l.url_hash FROM social_post_links cl INNER JOIN social_links l ON l.id = cl.link_id WHERE cl.content_id = ?', [$contentId]) : null, self::tagged(...));
    }

    public static function tagged(string $url): string
    {
        if (!(bool) \TinyCat\Core\Runtime\Startup::option('content_links.utm.enabled', true)) { return $url; }
        $source = trim((string) \TinyCat\Core\Runtime\Startup::option('content_links.utm.source', ''));
        if ($source === '') { $source = (string) parse_url((string) \TinyCat\Core\Runtime\Startup::option('app.url', ''), PHP_URL_HOST); }
        if ($source === '') { return $url; }
        $values = ['utm_source' => $source, 'utm_medium' => (string) \TinyCat\Core\Runtime\Startup::option('content_links.utm.medium', 'referral'), 'utm_campaign' => (string) \TinyCat\Core\Runtime\Startup::option('content_links.utm.campaign', '')];
        return \TinyCat\Core\Links\Tracking::append($url, $values);
    }
/** @param \Closure(): array<mixed>|null $attachments
     * @param \Closure(string): string|null $tagged
     * @return array{url: string, original_url: string}|null */
    public static function resolveText(string $hash, string $body, ?\Closure $attachments = null, ?\Closure $tagged = null): ?array
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
        if (LinkParser::fromRaw($raw) === null || \TinyCat\Core\Links\Urls::isInternal($raw)) { return null; }
        $original = preg_match('~^https?://~i', $raw) === 1 ? $raw : 'https://' . $raw;
        return ['url' => ($tagged !== null ? $tagged($original) : $original), 'original_url' => $original];
    }
}
