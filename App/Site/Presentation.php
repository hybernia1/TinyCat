<?php
declare(strict_types=1);

namespace TinyCat\Site;

final class Presentation
{
    public static function site_name(): string
    {
        return (string) \TinyCat\Settings\Store::config('site.name', 'TinyCat');
    }

    public static function site_google_analytics_configured(): bool
    {
        $measurementId = trim((string) \TinyCat\Settings\Store::config('analytics.google_measurement_id', ''));

        return preg_match('/^G-[A-Z0-9]+$/i', $measurementId) === 1;
    }

    public static function site_captcha_enabled(): bool
    {
        return (bool) \TinyCat\Settings\Store::config('security.captcha.enabled', false);
    }

    public static function site_home_title(): string
    {
        $title = trim((string) \TinyCat\Settings\Store::config('site.home_title', ''));

        return $title !== '' ? $title : \TinyCat\Site\Presentation::site_name();
    }

    public static function site_meta_description(): string
    {
        $description = trim((string) \TinyCat\Settings\Store::config('site.meta_description', ''));

        return $description !== ''
            ? \TinyCat\Site\Presentation::meta_text($description, 180)
            : \TinyCat\Support\Localization::t('public.meta_description', ['site' => \TinyCat\Site\Presentation::site_name()]);
    }

    public static function site_logo_url(): string
    {
        return trim((string) \TinyCat\Settings\Store::config('site.logo_url', ''));
    }

    public static function site_favicon_url(): string
    {
        return trim((string) \TinyCat\Settings\Store::config('site.favicon_url', ''));
    }

    public static function site_footer_html(): string
    {
        return trim((string) \TinyCat\Settings\Store::config('site.footer_html', ''));
    }

    public static function site_meta_image_url(): string
    {
        return \TinyCat\Site\Presentation::site_logo_url() ?: \TinyCat\Site\Presentation::site_favicon_url();
    }

    public static function absolute_url(string $url = ''): string
    {
        $url = trim($url);

        if ($url === '') {
            $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        }

        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = \TinyCat\Http\Request::current()->requestScheme();

            return $scheme . ':' . $url;
        }

        $path = '/' . ltrim($url, '/');
        $base = rtrim((string) \TinyCat\Settings\Store::config('app.url', ''), '/');

        if ($base !== '') {
            return $base . $path;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

        return \TinyCat\Http\Request::current()->requestScheme() . '://' . $host . $path;
    }

    public static function meta_text(string $text, int $limit = 180): string
    {
        $text = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return '';
        }

        if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) <= $limit) {
            return $text;
        }

        $slice = function_exists('mb_substr') ? mb_substr($text, 0, max(0, $limit - 1), 'UTF-8') : substr($text, 0, max(0, $limit - 1));

        return rtrim((string) $slice) . '...';
    }

    public static function public_sidebar(?string $activeTag = null, bool $compute = false): string
    {
        $activeTag = \TinyCat\Status\Tags::status_tag_normalize((string) $activeTag);
        $sidebarUrl = '/api/site/sidebar' . ($activeTag !== '' ? '?tag=' . rawurlencode($activeTag) : '');

        if (!$compute) {
            return \TinyCat\Support\View::part('Site/sidebar-slot', ['sidebar_url' => $sidebarUrl]);
        }

        $tags = \TinyCat\Status\Tags::public_trending_tags(8, 7, $compute);
        $authors = \TinyCat\Users\Repository::public_top_authors(5, 7, $compute);

        return \TinyCat\Site\Views::sidebar([
            'active_tag' => $activeTag,
            'tags' => $tags,
            'authors' => $authors,
            'sidebar_url' => $sidebarUrl,
            'site_footer_html' => \TinyCat\Site\Presentation::site_footer_html(),
        ]);
    }
}
