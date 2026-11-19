<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

use TinyCat\Social\Posts\Tags;
use TinyCat\Social\Profiles\{Repository, Resource};

/** One data projection for server-rendered and deferred social sidebars. */
final class Sidebar
{
    /** @return array<string, mixed> */
    public static function load(bool $compute = true): array
    {
        return self::resource([
            'tags' => Tags::public_trending_tags(8, 7, $compute),
            'authors' => Repository::public_top_authors(5, 7, $compute),
            'site_footer_html' => \TinyCat\Core\Ui\Site::site_footer_html(),
        ]);
    }

    /** @param array<mixed> $data
     * @return array<string, mixed> */
    public static function resource(array $data): array
    {
        $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
        $authors = is_array($data['authors'] ?? null) ? $data['authors'] : [];
        foreach ($tags as &$tag) {
            $tag['url'] = (string) ($tag['url'] ?? Tags::tag_url((string) ($tag['name'] ?? '')));
        }
        unset($tag);
        return [
            'tags' => $tags,
            'authors' => array_map(static fn (array $author): array => Resource::profile($author + ['username' => $author['name'] ?? '']) + ['posts_count' => (int) ($author['posts_count'] ?? 0)], $authors),
            'privacy_url' => \TinyCat\Http\LocaleRouting::url('/privacy'),
            'footer' => \TinyCat\Support\RichText::nodes((string) ($data['site_footer_html'] ?? '')),
        ];
    }
}
