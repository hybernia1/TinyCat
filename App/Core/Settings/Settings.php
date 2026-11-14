<?php
declare(strict_types=1);
namespace TinyCat\Core\Settings;

/** Settings owned by this capability; definitions are evaluated only for the editor. */
final class Settings
{
    /** @return list<\TinyCat\Core\Modules\SettingsSection> */
    public static function sections(): array { return [new \TinyCat\Core\Modules\SettingsSection('tinycat.settings.settings.site', 'site', self::section_site(...), 0), new \TinyCat\Core\Modules\SettingsSection('tinycat.settings.settings.performance', 'performance', self::section_performance(...), 100), new \TinyCat\Core\Modules\SettingsSection('tinycat.settings.settings.localization', 'localization', self::section_localization(...), 300), new \TinyCat\Core\Modules\SettingsSection('tinycat.settings.settings.analytics', 'analytics', self::section_analytics(...), 601)]; }

    /** @return array<mixed> */
    public static function section_site(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.site'),
                'icon' => 'home',
                'fields' => [
                    ['key' => 'site.name', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_name'), 'type' => 'text', 'default' => 'TinyCat', 'max' => 120, 'span' => true],
                    ['key' => 'site.homepage', 'label' => \TinyCat\Support\Localization::t('settings.fields.homepage'), 'type' => 'select', 'optional' => true, 'default' => '', 'options' => self::choices('homepages')],
                    ['key' => 'site.theme', 'label' => \TinyCat\Support\Localization::t('settings.fields.theme'), 'type' => 'select', 'optional' => true, 'default' => '', 'options' => self::choices('themes')],
                    ['key' => 'app.url', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_url'), 'type' => 'site_url', 'default' => '', 'max' => 2048, 'span' => true],
                    ['key' => 'site.home_title', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_home_title'), 'type' => 'optional_text', 'default' => '', 'max' => 160, 'span' => true],
                    ['key' => 'site.meta_description', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_meta_description'), 'type' => 'textarea', 'default' => '', 'max' => 500, 'span' => true, 'placeholder' => \TinyCat\Support\Localization::t('settings.fields.site_meta_description_placeholder'), 'help' => \TinyCat\Support\Localization::t('settings.fields.site_meta_description_help')],
                    ['key' => 'site.logo_url', 'path_key' => 'site.logo_path', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_logo'), 'type' => 'site_image', 'variant' => 'logo', 'default' => ''],
                    ['key' => 'site.favicon_url', 'path_key' => 'site.favicon_path', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_favicon'), 'type' => 'site_image', 'variant' => 'favicon', 'default' => ''],
                    ['key' => 'site.footer_html', 'label' => \TinyCat\Support\Localization::t('settings.fields.site_footer'), 'type' => 'textarea', 'default' => '', 'span' => true],
                ],
        ];
    }

    /** @return array<string, string> */
    private static function choices(string $kind): array
    {
        $options = ['' => \TinyCat\Support\Localization::t('settings.fields.automatic')];
        $entries = \TinyCat\Core\Modules\Registrations::current()->entries;
        foreach ($kind === 'homepages' ? $entries->homepages : $entries->themes as $entry) { $options[$entry->id] = ($entry->label)(); }
        return $options;
    }

    /** @return array<mixed> */
    public static function section_performance(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.performance'),
                'icon' => 'settings',
                'fields' => [
                    ['key' => 'performance.minify_css', 'label' => \TinyCat\Support\Localization::t('settings.fields.minify_css'), 'type' => 'bool', 'default' => false, 'help' => \TinyCat\Support\Localization::t('settings.fields.minify_css_help')],
                    ['key' => 'performance.minify_js', 'label' => \TinyCat\Support\Localization::t('settings.fields.minify_js'), 'type' => 'bool', 'default' => false, 'help' => \TinyCat\Support\Localization::t('settings.fields.minify_js_help')],
                    ['key' => 'performance.minify_html', 'label' => \TinyCat\Support\Localization::t('settings.fields.minify_html'), 'type' => 'bool', 'default' => false, 'help' => \TinyCat\Support\Localization::t('settings.fields.minify_html_help')],
                ],
        ];
    }

    /** @return array<mixed> */
    public static function section_localization(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.localization'),
                'icon' => 'globe',
                'fields' => [
                    ['key' => 'i18n.locale', 'label' => \TinyCat\Support\Localization::t('settings.fields.locale'), 'type' => 'language', 'default' => (string) \TinyCat\Core\Settings\Store::config('install.locale', 'en')],
                    ['key' => 'datetime.timezone', 'label' => \TinyCat\Support\Localization::t('settings.fields.timezone'), 'type' => 'timezone', 'default' => 'UTC'],
                    ['key' => 'datetime.date', 'label' => \TinyCat\Support\Localization::t('settings.fields.date_format'), 'type' => 'date_format', 'default' => 'd.m.Y'],
                    ['key' => 'datetime.time', 'label' => \TinyCat\Support\Localization::t('settings.fields.time_format'), 'type' => 'time_format', 'default' => 'H:i'],
                    ['key' => 'datetime.datetime', 'label' => \TinyCat\Support\Localization::t('settings.fields.datetime_format'), 'type' => 'datetime_format', 'default' => 'd.m.Y H:i'],
                    ['key' => 'datetime.relative', 'label' => \TinyCat\Support\Localization::t('settings.fields.relative_datetime'), 'type' => 'bool', 'default' => false],
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
                    ['key' => 'analytics.google_measurement_id', 'label' => \TinyCat\Support\Localization::t('settings.fields.google_measurement_id'), 'type' => 'optional_text', 'default' => '', 'max' => 40, 'help' => \TinyCat\Support\Localization::t('settings.fields.google_measurement_id_help')],
                ],
        ];
    }
}
