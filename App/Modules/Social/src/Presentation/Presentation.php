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

        return \TinyCat\Support\Components::render('site/sidebar', Sidebar::load($compute) + [
            'active_tag' => $activeTag, 'url' => $sidebarUrl,
        ]);
    }
}
