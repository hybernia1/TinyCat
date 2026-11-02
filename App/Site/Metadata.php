<?php
declare(strict_types=1);

namespace TinyCat\Site;

/** One document contract for server-rendered heads and public navigation. */
final class Metadata
{
    /** @param array<string, mixed> $page
     * @return array<string, mixed> */
    public static function document(array $page): array
    {
        $meta = (array) ($page['meta'] ?? []);
        $plain = static fn (string $value): string => (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $title = $plain((string) ($page['title'] ?? Presentation::site_name()));
        $title = $title === Presentation::site_name() ? $title : $title . ' | ' . Presentation::site_name();
        $metaTitle = $plain(Presentation::meta_text((string) ($meta['title'] ?? $title), 120));
        $absolute = static fn (string $url): string => $url !== '' ? Presentation::absolute_url($url) : '';
        $image = $absolute(trim((string) ($meta['image'] ?? Presentation::site_meta_image_url())));
        $locale = \TinyCat\Support\Localization::locale();
        $locales = ['cs' => 'cs_CZ', 'en' => 'en_US', 'de' => 'de_DE', 'es' => 'es_ES', 'fr' => 'fr_FR', 'pt' => 'pt_BR', 'ru' => 'ru_RU', 'it' => 'it_IT', 'nl' => 'nl_NL', 'pl' => 'pl_PL', 'tr' => 'tr_TR', 'uk' => 'uk_UA'];
        return [
            'title' => $title, 'meta_title' => $metaTitle,
            'description' => $plain(Presentation::meta_text((string) ($meta['description'] ?? Presentation::site_meta_description()), 180)),
            'url' => $absolute(LocaleRouting::canonical((string) ($meta['url'] ?? $page['current'] ?? '/'))),
            'type' => (string) ($meta['type'] ?? 'website'),
            'locale' => $locales[$locale] ?? str_replace('-', '_', $locale),
            'image' => $image,
            'image_type' => $image !== '' ? trim((string) ($meta['image_type'] ?? '')) : '',
            'image_width' => $image !== '' ? max(0, (int) ($meta['image_width'] ?? 0)) : 0,
            'image_height' => $image !== '' ? max(0, (int) ($meta['image_height'] ?? 0)) : 0,
            'image_alt' => $image !== '' ? ($plain(trim((string) ($meta['image_alt'] ?? ''))) ?: $metaTitle) : '',
            'rss' => $absolute(trim((string) ($meta['rss'] ?? ''))),
            'oembed' => (string) ($meta['oembed'] ?? ''), 'oembed_xml' => (string) ($meta['oembed_xml'] ?? ''),
            'robots' => trim((string) ($meta['robots'] ?? '')),
            'jsonld' => isset($meta['jsonld']) && is_array($meta['jsonld']) ? LocaleRouting::schema($meta['jsonld']) : null,
            'tags' => array_values((array) ($meta['tags'] ?? [])),
            'published_time' => !empty($meta['published_time']) ? \TinyCat\Support\Dates::dateTime((string) $meta['published_time'], DATE_ATOM) : '',
            'author' => !empty($meta['author']) ? $absolute(LocaleRouting::canonical((string) $meta['author'])) : '',
        ];
    }
}
