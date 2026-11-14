<?php
declare(strict_types=1);

namespace TinyCat\Core\Ui;

final class Site
{
    public static function site_name(): string
    {
        return (string) \TinyCat\Core\Runtime\Startup::option('site.name', 'TinyCat');
    }

    public static function site_google_analytics_configured(): bool
    {
        $measurementId = trim((string) \TinyCat\Core\Runtime\Startup::option('analytics.google_measurement_id', ''));

        return preg_match('/^G-[A-Z0-9]+$/i', $measurementId) === 1;
    }

    public static function site_captcha_enabled(): bool
    {
        return (bool) \TinyCat\Core\Runtime\Startup::option('security.captcha.enabled', false);
    }

    public static function site_home_title(): string
    {
        $title = trim((string) \TinyCat\Core\Runtime\Startup::option('site.home_title', ''));

        return $title !== '' ? $title : \TinyCat\Core\Ui\Site::site_name();
    }

    public static function site_meta_description(): string
    {
        $description = trim((string) \TinyCat\Core\Runtime\Startup::option('site.meta_description', ''));

        return $description !== ''
            ? \TinyCat\Core\Ui\Site::meta_text($description, 180)
            : '';
    }

    public static function site_logo_url(): string
    {
        return trim((string) \TinyCat\Core\Runtime\Startup::option('site.logo_url', ''));
    }

    public static function site_favicon_url(): string
    {
        return trim((string) \TinyCat\Core\Runtime\Startup::option('site.favicon_url', ''));
    }

    public static function site_footer_html(): string
    {
        return trim((string) \TinyCat\Core\Runtime\Startup::option('site.footer_html', ''));
    }

    public static function site_meta_image_url(): string
    {
        return \TinyCat\Core\Ui\Site::site_logo_url() ?: \TinyCat\Core\Ui\Site::site_favicon_url();
    }

    public static function absolute_url(string $url = ''): string { return \TinyCat\Support\LocalUrls::absolute($url); }

    public static function meta_text(string $text, int $limit = 180): string { return \TinyCat\Support\Text::excerpt($text, $limit); }

}
