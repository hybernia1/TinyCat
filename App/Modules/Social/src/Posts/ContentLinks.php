<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Social\Content\Redirects;

use TinyCat\Core\Text\LinkParser;

use TinyCat\Social\Content\Links;

use TinyCat\Core\Settings\Store;

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
        $item = \TinyCat\Database\Connection::one('SELECT c.body FROM social_posts c INNER JOIN users u ON u.id = c.author_id WHERE c.kind = \'post\' AND c.id = ? AND c.published_at <= ? AND c.status = \'published\' AND u.status = ?', [$contentId, \TinyCat\Support\Dates::dateDb(), 'active']);
        if ($item === null) {
            $canonicalId = Redirects::target($contentId);
            return $canonicalId === null ? null : self::resolve($canonicalId, $hash, $commentId);
        }
        if ($commentId > 0) {
            $comment = \TinyCat\Database\Connection::one('SELECT cc.body FROM social_comments cc INNER JOIN users u ON u.id = cc.user_id WHERE cc.id = ? AND cc.content_id = ? AND u.status = ?', [$commentId, $contentId, 'active']);
            if ($comment === null) { return null; }
            $item = $comment;
        }
        return \TinyCat\Social\Content\Outbound::resolve($hash, (string) $item['body'], $commentId === 0 ? static fn (): array => \TinyCat\Database\Connection::all('SELECT l.normalized_url, l.url_hash FROM social_post_links cl INNER JOIN social_links l ON l.id = cl.link_id WHERE cl.content_id = ?', [$contentId]) : null);
    }
}
