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
                'label' => ['t' => 'settings.sections.site'],
                'icon' => 'home',
                'fields' => [
                    ['key' => 'site.name', 'label' => ['t' => 'settings.fields.site_name'], 'type' => 'text', 'default' => 'TinyCat', 'max' => 120, 'span' => true],
                    ['key' => 'site.public_enabled', 'label' => ['t' => 'settings.fields.public_enabled'], 'type' => 'bool', 'default' => true, 'help' => ['t' => 'settings.fields.public_enabled_help']],
                    ['key' => 'site.homepage', 'label' => ['t' => 'settings.fields.homepage'], 'type' => 'select', 'optional' => true, 'default' => '', 'options' => self::choices('homepages')],
                    ['key' => 'site.theme', 'label' => ['t' => 'settings.fields.theme'], 'type' => 'select', 'optional' => true, 'default' => '', 'options' => self::choices('themes')],
                    ['key' => 'app.url', 'label' => ['t' => 'settings.fields.site_url'], 'type' => 'site_url', 'default' => '', 'max' => 2048, 'span' => true],
                    ['key' => 'site.home_title', 'label' => ['t' => 'settings.fields.site_home_title'], 'type' => 'optional_text', 'default' => '', 'max' => 160, 'span' => true],
                    ['key' => 'site.meta_description', 'label' => ['t' => 'settings.fields.site_meta_description'], 'type' => 'textarea', 'default' => '', 'max' => 500, 'span' => true, 'placeholder' => ['t' => 'settings.fields.site_meta_description_placeholder'], 'help' => ['t' => 'settings.fields.site_meta_description_help']],
                    ['key' => 'site.logo_url', 'path_key' => 'site.logo_path', 'label' => ['t' => 'settings.fields.site_logo'], 'type' => 'site_image', 'variant' => 'logo', 'default' => ''],
                    ['key' => 'site.favicon_url', 'path_key' => 'site.favicon_path', 'label' => ['t' => 'settings.fields.site_favicon'], 'type' => 'site_image', 'variant' => 'favicon', 'default' => ''],
                ],
        ];
    }

    /** @return array<string, string> */
    private static function choices(string $kind): array
    {
        $options = ['' => \TinyCat\Support\Localization::t('settings.fields.automatic')];
        if ($kind === 'themes') { $options += \TinyCat\Core\Ui\Theme::choices(); }
        else {
            foreach (\TinyCat\Core\Modules\Registrations::current()->entries->homepages as $entry) { $options[$entry->id] = ($entry->label)(); }
        }
        $selected = (string) \TinyCat\Core\Runtime\Startup::option($kind === 'themes' ? 'site.theme' : 'site.homepage', '');
        if ($selected !== '' && !isset($options[$selected])) { $options[$selected] = $selected . ' (' . \TinyCat\Support\Localization::t('common.not_found') . ')'; }
        return $options;
    }

    /** @return array<mixed> */
    public static function section_performance(): array
    {
        return [
                'label' => ['t' => 'settings.sections.performance'],
                'icon' => 'settings',
                'fields' => [
                    ['key' => 'performance.minify_css', 'label' => ['t' => 'settings.fields.minify_css'], 'type' => 'bool', 'default' => false, 'help' => ['t' => 'settings.fields.minify_css_help']],
                    ['key' => 'performance.minify_js', 'label' => ['t' => 'settings.fields.minify_js'], 'type' => 'bool', 'default' => false, 'help' => ['t' => 'settings.fields.minify_js_help']],
                    ['key' => 'performance.minify_html', 'label' => ['t' => 'settings.fields.minify_html'], 'type' => 'bool', 'default' => false, 'help' => ['t' => 'settings.fields.minify_html_help']],
                ],
        ];
    }

    /** @return array<mixed> */
    public static function section_localization(): array
    {
        return [
                'label' => ['t' => 'settings.sections.localization'],
                'icon' => 'globe',
                'fields' => [
                    ['key' => 'i18n.locale', 'label' => ['t' => 'settings.fields.locale'], 'type' => 'language', 'default' => (string) \TinyCat\Core\Settings\Store::config('install.locale', 'en')],
                    ['key' => 'datetime.timezone', 'label' => ['t' => 'settings.fields.timezone'], 'type' => 'timezone', 'default' => 'UTC'],
                    ['key' => 'datetime.date', 'label' => ['t' => 'settings.fields.date_format'], 'type' => 'date_format', 'default' => 'd.m.Y'],
                    ['key' => 'datetime.time', 'label' => ['t' => 'settings.fields.time_format'], 'type' => 'time_format', 'default' => 'H:i'],
                    ['key' => 'datetime.datetime', 'label' => ['t' => 'settings.fields.datetime_format'], 'type' => 'datetime_format', 'default' => 'd.m.Y H:i'],
                    ['key' => 'datetime.relative', 'label' => ['t' => 'settings.fields.relative_datetime'], 'type' => 'bool', 'default' => false],
                ],
        ];
    }

    /** @return array<mixed> */
    public static function section_analytics(): array
    {
        return [
                'label' => ['t' => 'settings.sections.analytics'],
                'icon' => 'chart',
                'fields' => [
                    ['key' => 'analytics.google_measurement_id', 'label' => ['t' => 'settings.fields.google_measurement_id'], 'type' => 'optional_text', 'default' => '', 'max' => 40, 'help' => ['t' => 'settings.fields.google_measurement_id_help']],
                ],
        ];
    }
}
