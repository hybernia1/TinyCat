<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Settings owned by this capability; definitions are evaluated only for the editor. */
final class Settings
{
    /** @return list<\TinyCat\Core\Modules\SettingsSection> */
    public static function sections(): array { return [new \TinyCat\Core\Modules\SettingsSection('tinycat.publishing.settings.security', 'security', self::section_security(...), 400), new \TinyCat\Core\Modules\SettingsSection('tinycat.publishing.settings.content_images', 'content_images', self::section_content_images(...), 200), new \TinyCat\Core\Modules\SettingsSection('tinycat.publishing.settings.analytics', 'analytics', self::section_analytics(...), 600)]; }

    /** @return array<mixed> */
    public static function section_content_images(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.content_images'),
                'icon' => 'image',
                'fields' => [
                    ['key' => 'content_images.enabled', 'label' => \TinyCat\Support\Localization::t('settings.fields.content_images_enabled'), 'type' => 'bool', 'default' => true, 'help' => \TinyCat\Support\Localization::t('settings.fields.content_images_enabled_help')],
                    ['key' => 'content_images.max_upload_kb', 'label' => \TinyCat\Support\Localization::t('settings.fields.content_images_max_upload_kb'), 'type' => 'int', 'default' => 100, 'min' => 20, 'max' => 1024, 'help' => \TinyCat\Support\Localization::t('settings.fields.content_images_max_upload_kb_help')],
                ],
        ];
    }

    /** @return array<mixed> */
    public static function section_analytics(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.analytics'),
                'icon' => 'chart',
                'fields' => [
                    ['key' => 'content_links.utm.enabled', 'label' => \TinyCat\Support\Localization::t('settings.fields.utm_enabled'), 'type' => 'bool', 'default' => true, 'max' => 120],
                    ['key' => 'content_links.utm.source', 'label' => \TinyCat\Support\Localization::t('settings.fields.utm_source'), 'type' => 'optional_text', 'default' => '', 'max' => 120, 'help' => \TinyCat\Support\Localization::t('settings.fields.utm_source_help')],
                    ['key' => 'content_links.utm.medium', 'label' => \TinyCat\Support\Localization::t('settings.fields.utm_medium'), 'type' => 'text', 'default' => 'referral', 'max' => 120],
                    ['key' => 'content_links.utm.campaign', 'label' => \TinyCat\Support\Localization::t('settings.fields.utm_campaign'), 'type' => 'optional_text', 'default' => '', 'max' => 120],
                ],
        ];
    }
    /** @return array<mixed> */
    public static function section_security(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.security'),
                'icon' => 'shield',
                'fields' => [
                    ['key' => 'moderation.blocked_urls', 'label' => \TinyCat\Support\Localization::t('moderation.url_blocker_label'), 'type' => 'textarea', 'default' => '', 'span' => true, 'placeholder' => \TinyCat\Support\Localization::t('moderation.url_blocker_placeholder'), 'help' => \TinyCat\Support\Localization::t('moderation.url_blocker_help'), 'normalize' => static fn (mixed $raw): array => [implode(', ', \TinyCat\Core\Security\UrlRules::rules((string) $raw)), 'string']],
                ],
        ];
    }
}
