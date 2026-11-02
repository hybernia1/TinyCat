<?php
declare(strict_types=1);

namespace TinyCat\Status;

use TinyCat\Site\LocaleRouting;
use TinyCat\Site\Presentation;
use TinyCat\Support\Dates;
use TinyCat\Users\Profiles;
use TinyCat\Users\Roles;

final class StructuredData
{
    /** @param array<string, mixed> $item
     * @return array<string, mixed> */
    public static function post(array $item): array
    {
        $url = Presentation::absolute_url(Posts::status_url((int) $item['id']));
        return array_filter([
            '@type' => 'DiscussionForumPosting', '@id' => $url, 'url' => $url,
            'headline' => ViewData::status_meta_title($item),
            'text' => trim((string) ($item['body'] ?? '')) !== '' ? (string) $item['body'] : null,
            'keywords' => Tags::status_tags_from_text((string) ($item['body'] ?? '')),
            'image' => Images::status_image_jsonld($item) ?? (ViewData::status_meta_link_image($item) ?: null),
            'datePublished' => !empty($item['published_at']) ? Dates::dateTime((string) $item['published_at'], DATE_ATOM) : null,
            'author' => self::author($item),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $item
     * @return array<string, string> */
    public static function author(array $item): array
    {
        $url = Presentation::absolute_url(LocaleRouting::canonical(Profiles::author_url((int) $item['author_id'])));
        return [
            '@type' => Roles::profileSchemaType((string) ($item['author_role'] ?? '')),
            '@id' => $url . '#author', 'url' => $url,
            'name' => (string) ($item['author_name'] ?? $item['author_username'] ?? ''),
        ];
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed> */
    public static function itemList(array $items): array
    {
        return ['@type' => 'ItemList', 'numberOfItems' => count($items),
            'itemListElement' => array_map(static fn (array $item, int $index): array => [
                '@type' => 'ListItem', 'position' => $index + 1,
                'url' => Presentation::absolute_url(Posts::status_url((int) $item['id'])),
                'item' => self::post($item),
            ], $items, array_keys($items))];
    }
}
