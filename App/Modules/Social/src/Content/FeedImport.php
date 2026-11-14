<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

use RuntimeException;

/** Feed preparation independent of bot accounts, queues and scheduling. */
final class FeedImport
{
    public static function normalizeFeedUrl(string $url): string { return \TinyCat\Core\Feeds\SourceUrl::normalize($url); }

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
            $tag = \TinyCat\Social\Content\Tags::status_tag_normalize((string) $category);
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
            try { return \TinyCat\Social\Content\ImageStorage::fromRemote($url); }
            catch (RuntimeException|\LengthException $exception) { $failure = $exception; }
        }
        if ($failure !== null) { throw $failure; }
        return null;
    }

}
