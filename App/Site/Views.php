<?php
declare(strict_types=1);

namespace TinyCat\Site;

use TinyCat\Support\Components;

use TinyCat\Status\Tags;

/** Presentation components: prepare inputs, then render their private partial. */
final class Views
{
    /** @param array<mixed> $data */
    public static function sidebar(array $data = []): string
    {
        $active_tag = $data['active_tag'] ?? null;
        $authors = $data['authors'] ?? null;
        $sidebar_url = $data['sidebar_url'] ?? null;
        $site_footer_html = $data['site_footer_html'] ?? null;
        $tags = $data['tags'] ?? null;
        $activeTag = Tags::status_tag_normalize((string) ($active_tag ?? ''));
        $tags = is_array($tags ?? null) ? $tags : [];
        $authors = is_array($authors ?? null) ? $authors : [];
        $sidebarUrl = (string) ($sidebar_url ?? '/api/site/sidebar');
        $siteFooterHtml = (string) ($site_footer_html ?? '');
        foreach ($tags as &$tag) {
            $name = (string) ($tag['name'] ?? '');
            $tag['url'] = (string) ($tag['url'] ?? Tags::tag_url($name));
        }
        unset($tag);
        foreach ($authors as &$author) {
            $author = \TinyCat\Users\Resource::profile($author + ['username' => $author['name'] ?? '']) + ['posts_count' => (int) ($author['posts_count'] ?? 0)];
        }
        unset($author);
        return Components::render('site/sidebar', [
            'authors' => $authors,
            'tags' => $tags,
            'active_tag' => $activeTag,
            'url' => $sidebarUrl,
            'footer' => \TinyCat\Support\RichText::nodes($siteFooterHtml),
        ]);
    }
}
