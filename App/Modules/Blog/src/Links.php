<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Core\Links\{Metadata, Presentation, Providers};
use TinyCat\Database\Connection;
use TinyCat\Support\{Dates, Text};

/** Blog owns link records and relations; fetching and raster conversion are shared mechanisms. */
final class Links extends Presentation
{
    /** @param list<array<mixed>> $links
     * @param array<string, mixed>|null $source
     * @return list<array<mixed>> */
    public static function prepare(array $links, ?array $source = null): array
    {
        $prepared = [];
        foreach ($links as $link) {
            $hash = (string) ($link['url_hash'] ?? ''); $url = (string) ($link['normalized_url'] ?? '');
            if ($hash === '' || self::status_internal_url($url) || Settings::blockedHost($url) !== '') { continue; }
            $cached = Connection::find('blog_links', ['url_hash' => $hash]);
            $fresh = $cached !== null && strtotime((string) $cached['updated_at']) >= time() - 86400;
            if ($fresh) { $link = array_replace($link, $cached); }
            else {
                $link = Metadata::enrich(array_replace($cached ?? [], $link));
                if ($source !== null && ($source['url_hash'] ?? '') === $hash) {
                    foreach (['title', 'description', 'audio_url'] as $field) { if (empty($link[$field]) && !empty($source[$field])) { $link[$field] = (string) $source[$field]; } }
                    if (empty($link['image_url'])) { $images = (array) ($source['image_urls'] ?? []); $link['image_url'] = (string) ($images[0] ?? ''); }
                }
                $link = array_replace($link, Providers::taxonomy($link));
                $image = (string) ($link['image_url'] ?? '');
                if ($image !== '' && Providers::youtubeThumbnails($url) === [] && Settings::blockedHost($image) === '') { $link['image_url'] = \TinyCat\Core\Files\RemoteImageCache::store($image); }
                $link['_metadata_updated_at'] = Dates::dateDb();
            }
            $prepared[$hash] = $link;
        }
        return array_values($prepared);
    }

    /** @param list<array<mixed>> $links */
    public static function sync(int $articleId, array $links): void
    {
        $old = array_column(Connection::all('SELECT link_id FROM blog_article_links WHERE article_id = ?', [$articleId]), 'link_id');
        Connection::delete('blog_article_links', ['article_id' => $articleId]);
        foreach ($links as $link) {
            $id = self::store($link);
            Connection::insert('blog_article_links', ['article_id' => $articleId, 'link_id' => $id, 'created_at' => Dates::dateDb()]);
        }
        self::cleanup($old);
    }

    /** @param array<mixed> $link */
    private static function store(array $link): int
    {
        $url = (string) ($link['normalized_url'] ?? ''); $hash = (string) ($link['url_hash'] ?? '');
        $data = ['normalized_url' => $url, 'url_hash' => $hash, 'provider' => Text::plain_text_limit((string) ($link['provider'] ?? ''), 40), 'link_type' => Text::plain_text_limit((string) ($link['link_type'] ?? 'web'), 20), 'title' => Text::plain_text_limit((string) ($link['title'] ?? ''), 255), 'description' => Text::plain_text_limit((string) ($link['description'] ?? ''), 500), 'image_url' => Text::plain_text_limit((string) ($link['image_url'] ?? ''), 2048), 'video_id' => Text::plain_text_limit((string) ($link['video_id'] ?? ''), 80), 'audio_url' => Text::plain_text_limit((string) ($link['audio_url'] ?? ''), 2048), 'updated_at' => (string) ($link['_metadata_updated_at'] ?? $link['updated_at'] ?? Dates::dateDb())];
        $old = Connection::find('blog_links', ['url_hash' => $hash]);
        if ($old === null) {
            try { return (int) Connection::insert('blog_links', $data + ['created_at' => Dates::dateDb()]); }
            catch (\PDOException $error) { if (!Connection::uniqueViolation($error)) { throw $error; } $old = Connection::find('blog_links', ['url_hash' => $hash]); }
        }
        if ($old === null) { throw new \RuntimeException('Cannot store article link.'); }
        Connection::update('blog_links', $data, ['id' => (int) $old['id']]);
        return (int) $old['id'];
    }

    /** @return list<array<mixed>> */
    public static function forArticle(int $id): array
    {
        return array_values(array_filter(Connection::all('SELECT l.* FROM blog_links l INNER JOIN blog_article_links r ON r.link_id = l.id WHERE r.article_id = ? ORDER BY l.id', [$id]), is_array(...)));
    }

    /** @param array<mixed> $ids */
    public static function cleanup(array $ids): void
    {
        foreach (array_chunk(Text::positive_int_ids($ids), 100) as $chunk) { Connection::exec('DELETE FROM blog_links WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') AND NOT EXISTS (SELECT 1 FROM blog_article_links r WHERE r.link_id = blog_links.id)', $chunk); }
    }
}
