<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Core\Feeds\{FeedEntry, FeedTarget, WordPressFeed};
use TinyCat\Core\Text\LinkParser;
use TinyCat\Support\Text;

final class FeedPublication implements FeedTarget
{
    public function prepare(array $source, array $item): FeedEntry
    {
        $title = trim(Text::strip_html_tags_preserving_text((string) ($item['title'] ?? '')));
        $body = trim(Text::strip_html_tags_preserving_text((string) ($item['content'] ?? $item['description'] ?? '')));
        $url = trim((string) ($item['url'] ?? '')); if ($url !== '' && !str_contains($body, $url)) { $body .= ($body !== '' ? "\n\n" : '') . $url; }
        $rejection = null;
        try { Articles::validateText($title, $body); } catch (\InvalidArgumentException) { $rejection = ['code' => 'article_invalid', 'params' => []]; }
        $links = Links::status_links_from_text($body); $origin = LinkParser::fromRaw($url);
        if ($origin !== null) { $links = [$origin, ...array_filter($links, static fn (array $link): bool => $link['url_hash'] !== $origin['url_hash'])]; }
        return new FeedEntry($body, array_slice($links, 0, 3), compact('title', 'body'), $rejection);
    }

    public function enrich(array $source, array $items): array
    {
        return !empty($source['import_images']) && Images::enabled() ? WordPressFeed::images($items, static fn (string $url): bool => Settings::blockedHost($url) === '') : $items;
    }

    public function attachments(array $source, array $item, FeedEntry $entry): array
    {
        $image = null;
        if (!empty($source['import_images']) && Images::enabled() && \TinyCat\Core\Links\Providers::youtubeThumbnails((string) ($item['url'] ?? '')) === []) {
            $urls = array_slice(array_values(array_unique(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), static fn (mixed $url): bool => is_string($url) && $url !== ''))), 0, 8); $failure = null;
            foreach ($urls as $url) { try { $image = ImageStorage::fromRemote($url); break; } catch (\RuntimeException|\LengthException $error) { $failure = $error; } }
            if ($image === null && $failure !== null) { throw $failure; }
        }
        try {
            $link = LinkParser::fromRaw((string) ($item['url'] ?? ''));
            $links = Links::prepare($entry->links, ['url_hash' => (string) ($link['url_hash'] ?? ''), 'title' => (string) ($item['title'] ?? ''), 'description' => (string) ($item['description'] ?? ''), 'audio_url' => (string) ($item['audio_url'] ?? ''), 'image_urls' => array_values(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), is_string(...)))]);
            return compact('links', 'image');
        } catch (\Throwable $error) { if ($image !== null) { $this->discard($image); } throw $error; }
    }

    public function discard(array $image): void { ImageStorage::delete((string) $image['path']); }
    public function publish(array $source, FeedEntry $entry, array $links, ?array $image, string $publishedAt): int
    {
        return Articles::createPrepared((int) $source['author_id'], (string) $entry->data['title'], (string) $entry->data['body'], (string) $source['publication_status'], $links, $image, $publishedAt);
    }
}
