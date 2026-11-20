<?php
declare(strict_types=1);
namespace TinyCat\Blog;

/** Blog owns its publication policies; no Social setting is consulted. */
final class Settings
{
    public const KEYS = ['blog.images.enabled', 'blog.images.max_upload_kb', 'blog.blocked_urls', 'blog.links.utm.enabled', 'blog.links.utm.source', 'blog.links.utm.medium', 'blog.links.utm.campaign'];
    public static function get(string $key, mixed $default = null): mixed { return \TinyCat\Core\Runtime\Startup::option($key, $default); }
    public static function blockedHost(string $text): string { return \TinyCat\Core\Security\UrlRules::match($text, \TinyCat\Core\Security\UrlRules::rules((string) self::get('blog.blocked_urls', '')), \TinyCat\Core\Text\LinkParser::pattern()); }

    public static function section(): \TinyCat\Core\Modules\SettingsSection
    {
        return new \TinyCat\Core\Modules\SettingsSection('tinycat.blog.settings', 'blog', static fn (): array => ['label' => ['t' => 'blog.articles'], 'icon' => 'file-text', 'fields' => [
            ['key' => 'blog.images.enabled', 'label' => ['t' => 'blog.settings.images_enabled'], 'type' => 'bool', 'default' => true],
            ['key' => 'blog.images.max_upload_kb', 'label' => ['t' => 'blog.settings.images_max_upload_kb'], 'type' => 'int', 'default' => 100, 'min' => 20, 'max' => 1024],
            ['key' => 'blog.blocked_urls', 'label' => ['t' => 'blog.settings.blocked_urls'], 'type' => 'textarea', 'default' => '', 'span' => true],
            ['key' => 'blog.links.utm.enabled', 'label' => ['t' => 'blog.settings.utm_enabled'], 'type' => 'bool', 'default' => true],
            ['key' => 'blog.links.utm.source', 'label' => ['t' => 'blog.settings.utm_source'], 'type' => 'optional_text', 'default' => ''],
            ['key' => 'blog.links.utm.medium', 'label' => ['t' => 'blog.settings.utm_medium'], 'type' => 'text', 'default' => 'referral'],
            ['key' => 'blog.links.utm.campaign', 'label' => ['t' => 'blog.settings.utm_campaign'], 'type' => 'optional_text', 'default' => ''],
        ]], 350);
    }

    public static function tagged(string $url): string
    {
        if (!(bool) self::get('blog.links.utm.enabled', true)) { return $url; }
        $source = trim((string) self::get('blog.links.utm.source', ''));
        if ($source === '') { $source = (string) parse_url(\TinyCat\Support\LocalUrls::absolute('/'), PHP_URL_HOST); }
        return \TinyCat\Core\Links\Tracking::append($url, ['utm_source' => $source, 'utm_medium' => (string) self::get('blog.links.utm.medium', 'referral'), 'utm_campaign' => (string) self::get('blog.links.utm.campaign', '')]);
    }
}
