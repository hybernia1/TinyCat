<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

/** Social sidebar composition; site identity belongs to core. */
final class Presentation
{
    public static function public_sidebar(?string $activeTag = null, bool $compute = false): string
    {
        $activeTag = \TinyCat\Social\Posts\Tags::status_tag_normalize((string) $activeTag);
        $sidebarUrl = '/api/site/sidebar' . ($activeTag !== '' ? '?tag=' . rawurlencode($activeTag) : '');

        if (!$compute) {
            return \TinyCat\Support\View::part('Social/site/sidebar-slot', ['sidebar_url' => $sidebarUrl]);
        }

        $tags = \TinyCat\Social\Posts\Tags::public_trending_tags(8, 7, $compute);
        $authors = \TinyCat\Social\Profiles\Repository::public_top_authors(5, 7, $compute);

        return \TinyCat\Social\Presentation\Views::sidebar([
            'active_tag' => $activeTag,
            'tags' => $tags,
            'authors' => $authors,
            'sidebar_url' => $sidebarUrl,
            'site_footer_html' => \TinyCat\Core\Ui\Site::site_footer_html(),
        ]);
    }
}
