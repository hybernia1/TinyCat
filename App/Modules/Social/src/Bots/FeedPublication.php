<?php
declare(strict_types=1);
namespace TinyCat\Social\Bots;

use RuntimeException;
use TinyCat\Social\Posts\LinkParser;
use TinyCat\Social\Posts\PostCreation;

final class FeedPublication
{
    /** Pure preparation, shared by preview and execution.
     * @param array<string, mixed> $source
     * @param array<string, mixed> $item */
    public function prepare(array $source, array $item): PostCreation
    {
        return PostCreation::fromText(self::cleanImportBoilerplate(self::renderPost($source, $item)));
    }

    /** @param array<string, mixed> $source
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>> */
    public function enrich(array $source, array $items): array
    {
        return str_contains((string) ($source['post_template'] ?? ''), '{{image}}') && \TinyCat\Social\Posts\Images::status_images_enabled()
            ? \TinyCat\Social\Bots\WordPressFeed::images($items, static fn (string $url): bool => \TinyCat\Social\Posts\Policy::blockedHost($url) === '') : $items;
    }

    /** Downloads and owner-specific preparation happen outside the publication transaction.
     * @param array<string, mixed> $source
     * @param array<string, mixed> $item
     * @return array{links: list<array<mixed>>, image: array<mixed>|null} */
    public function attachments(array $source, array $item, PostCreation $entry): array
    {
        $image = self::prepareImage($source, $item);
        try {
            $link = \TinyCat\Social\Posts\LinkParser::fromRaw((string) ($item['url'] ?? ''));
            $links = \TinyCat\Social\Posts\Links::prepareLinks($entry->links, ['url_hash' => (string) ($link['url_hash'] ?? ''), 'title' => (string) ($item['title'] ?? ''), 'description' => self::descriptionText((string) (($item['description_html'] ?? '') !== '' ? $item['description_html'] : ($item['description'] ?? '')), 1200), 'audio_url' => (string) ($item['audio_url'] ?? ''), 'image_urls' => array_values(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), is_string(...)))]);
            return compact('links', 'image');
        } catch (\Throwable $error) { if ($image !== null) { $this->discard($image); } throw $error; }
    }

    /** @param array<mixed> $image */
    public function discard(array $image): void { \TinyCat\Social\Posts\ImageStorage::delete((string) $image['path']); }

    /** Called inside the source store's transaction; must not download files.
     * @param array<string, mixed> $source
     * @param list<array<mixed>> $links
     * @param array<mixed>|null $image */
    public function publish(array $source, PostCreation $entry, array $links, ?array $image, string $publishedAt): int
    {
        return PostCreation::create((int) $source['bot_user_id'], $entry, $links, $image, publishedAt: $publishedAt);
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
            '{{description}}' => self::descriptionText((string) (($item['description_html'] ?? '') !== '' ? $item['description_html'] : ($item['description'] ?? '')), 1200),
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
            $tag = \TinyCat\Social\Posts\Tags::normalizeTag((string) $category);
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
        if (\TinyCat\Social\Posts\Providers::youtubeThumbnails((string) ($item['url'] ?? '')) !== []) { return null; }
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

    public static function descriptionText(string $value, int $limit): string
    {
        $value = preg_replace_callback(
            '~<a\b([^>]*)>(.*?)</a\s*>~is',
            static function (array $match): string {
                $label = (string) $match[2];
                $plainLabel = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (\TinyCat\Social\Bots\FeedPublication::isReadMore($plainLabel)) { return ' '; }
                $videoUrl = self::feedHtmlVideoUrl((string) $match[1], 'href');
                if ($videoUrl === '') {
                    return $label;
                }

                $labelText = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                foreach (\TinyCat\Social\Posts\LinkParser::extract($labelText) as $labelLink) {
                    if ((string) ($labelLink['normalized_url'] ?? '') === $videoUrl) {
                        return $label;
                    }
                }

                return $label . ' ' . $videoUrl;
            },
            $value
        ) ?? $value;
        $value = preg_replace_callback(
            '~<(?:iframe|embed)\b([^>]*)>(?:\s*</iframe\s*>)?~is',
            static fn (array $match): string => self::feedHtmlVideoUrl((string) $match[1], 'src'),
            $value
        ) ?? $value;
        $value = preg_replace('~<(?:br\b[^>]*|/(?:p|div|li|h[1-6]|blockquote)\s*)>~iu', ' ', $value) ?? $value;
        $value = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace_callback(
            \TinyCat\Social\Posts\LinkParser::pattern(),
            static function (array $match): string {
                [$url, $tail] = \TinyCat\Social\Posts\LinkParser::splitTail((string) ($match[0] ?? ''));
                $videoUrl = self::supportedVideoUrl($url);
                return ($videoUrl !== '' ? $videoUrl : '') . $tail;
            },
            $value
        ) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = preg_replace('/\s+([.,;:!?])/u', '$1', $value) ?? $value;
        $value = \TinyCat\Social\Bots\FeedPublication::cleanImportBoilerplate(trim($value));

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private static function feedHtmlVideoUrl(string $attributes, string $name): string
    {
        $name = preg_quote($name, '~');
        if (preg_match('~\b' . $name . '\s*=\s*(["\'])(.*?)\1~is', $attributes, $match) === 1) {
            return self::supportedVideoUrl(html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('~\b' . $name . '\s*=\s*([^\s>]+)~i', $attributes, $match) === 1) {
            return self::supportedVideoUrl(html_entity_decode(trim((string) $match[1], "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private static function supportedVideoUrl(string $url): string
    {
        $link = \TinyCat\Social\Posts\LinkParser::fromRaw(trim($url));
        return ($link['link_type'] ?? '') === 'video' ? (string) ($link['normalized_url'] ?? '') : '';
    }
    private const MORE = '(?:read\s+more|continue\s+reading|keep\s+reading|číst\s+(?:dál|dále|více)|přečíst\s+(?:více|celý\s+článek)|celý\s+článek|pokračovat\s+ve\s+čtení)';
    private const END = '[\s.…!»:→›\-]*';

    public static function isReadMore(string $label): bool
    {
        return preg_match('~^(?:' . self::MORE . '|more|více)' . self::END . '$~iuD', trim($label)) === 1;
    }

    /** Import-only editorial cleanup before canonical Social preparation. */
    public static function cleanImportBoilerplate(string $body): string
    {
        $body = PostCreation::normalize($body);
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            $line = preg_replace('~(?:^|\s)The post\s+(?:(?!https?://)[^\n])*?\s+appeared first on\s+(?:(?!https?://)[^\n#])+?(?=\s*#|\s*$)~iu', '', $line) ?? $line;
            $line = preg_replace('~^' . self::MORE . self::END . '(?=https?://|www\.|$)~iu', '', $line) ?? $line;
            $line = preg_replace('~([.!?…])\s+' . self::MORE . self::END . '(?=https?://|www\.|$)~iu', '$1 ', $line) ?? $line;
            $line = trim($line);
        }
        unset($line);
        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '');
    }
}
