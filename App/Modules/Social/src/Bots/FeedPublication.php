<?php
declare(strict_types=1);
namespace TinyCat\Social\Bots;

use TinyCat\Core\Feeds\FeedEntry;
use RuntimeException;
use TinyCat\Core\Feeds\FeedTarget;
use TinyCat\Social\Posts\PostCreation;

final class FeedPublication implements FeedTarget
{
    public function prepare(array $source, array $item): FeedEntry
    {
        $draft = PostCreation::fromText(self::renderPost($source, $item));
        return new FeedEntry($draft->body, $draft->links, ['draft' => $draft, 'empty_code' => 'status_required'], $draft->rejection);
    }

    public function enrich(array $source, array $items): array
    {
        return str_contains((string) ($source['post_template'] ?? ''), '{{image}}') && \TinyCat\Social\Posts\Images::status_images_enabled()
            ? \TinyCat\Core\Feeds\WordPressFeed::images($items, static fn (string $url): bool => \TinyCat\Social\Posts\Policy::blockedHost($url) === '') : $items;
    }

    public function attachments(array $source, array $item, FeedEntry $entry): array
    {
        $image = self::prepareImage($source, $item);
        try {
            $link = \TinyCat\Core\Text\LinkParser::fromRaw((string) ($item['url'] ?? ''));
            $links = \TinyCat\Social\Posts\Links::prepareLinks($entry->links, ['url_hash' => (string) ($link['url_hash'] ?? ''), 'title' => (string) ($item['title'] ?? ''), 'description' => (string) ($item['description'] ?? ''), 'audio_url' => (string) ($item['audio_url'] ?? ''), 'image_urls' => array_values(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), is_string(...)))]);
            return compact('links', 'image');
        } catch (\Throwable $error) { if ($image !== null) { $this->discard($image); } throw $error; }
    }

    public function discard(array $image): void { \TinyCat\Social\Posts\ImageStorage::delete((string) $image['path']); }

    public function publish(array $source, FeedEntry $entry, array $links, ?array $image, string $publishedAt): int
    {
        $draft = $entry->data['draft'] ?? null;
        if (!$draft instanceof PostCreation) { throw new \LogicException('Missing prepared social post.'); }
        return PostCreation::create((int) $source['bot_user_id'], $draft, $links, $image, publishedAt: $publishedAt);
    }

    public static function defaultSourceTemplate(): string
    {
        return "{{title}}\n\n{{description}}\n\n{{url}}\n\n#{categories}";
    }

    /** @param array<string, mixed> $source
     * @param array<string, mixed> $item */
    public static function renderPost(array $source, array $item): string
    {
        $categories = array_values(array_map(static fn (mixed $category): string => (string) $category, (array) ($item['categories'] ?? [])));
        $values = [
            '{{image}}' => '',
            '{{title}}' => (string) ($item['title'] ?? ''),
            '{{description}}' => (string) ($item['description'] ?? ''),
            '{{url}}' => (string) ($item['url'] ?? ''),
            '{{author}}' => (string) ($item['author'] ?? ''),
            '{{source}}' => (string) ($source['name'] ?? ''),
            '{{categories}}' => implode(', ', $categories),
            '#{categories}' => self::feedCategoryTags($categories),
        ];
        return trim(preg_replace("/\n{3,}/", "\n\n", strtr((string) ($source['post_template'] ?? self::defaultSourceTemplate()), $values)) ?? '');
    }

    /**
     * @param list<string> $categories
     */
    private static function feedCategoryTags(array $categories): string
    {
        $tags = [];

        foreach ($categories as $category) {
            $tag = \TinyCat\Social\Posts\Tags::status_tag_normalize((string) $category);
            if ($tag !== '') {
                $tags[$tag] = '#' . $tag;
            }
        }

        return implode(' ', array_values($tags));
    }

    /** @param array<string, mixed> $source
     * @param array<string, mixed> $item
     * @return array<mixed>|null */
    public static function prepareImage(array $source, array $item): ?array
    {
        if (\TinyCat\Core\Links\Providers::youtubeThumbnails((string) ($item['url'] ?? '')) !== []) { return null; }
        if (!str_contains((string) ($source['post_template'] ?? ''), '{{image}}') || !(bool) \TinyCat\Core\Settings\Store::config('content_images.enabled', true)) { return null; }
        $urls = array_slice(array_values(array_unique(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), static fn (mixed $url): bool => is_string($url) && $url !== ''))), 0, 8);
        $failure = null;
        foreach ($urls as $url) {
            try { return \TinyCat\Social\Posts\ImageStorage::fromRemote($url); }
            catch (RuntimeException|\LengthException $exception) { $failure = $exception; }
        }
        if ($failure !== null) { throw $failure; }
        return null;
    }
}
