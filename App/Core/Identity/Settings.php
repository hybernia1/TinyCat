<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

/** Settings owned by this capability; definitions are evaluated only for the editor. */
final class Settings
{
    /** @return list<\TinyCat\Core\Modules\SettingsSection> */
    public static function sections(): array { return [new \TinyCat\Core\Modules\SettingsSection('tinycat.accounts.settings.security', 'security', self::section_security(...), 402)]; }

    /** @return array<mixed> */
    public static function section_security(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.security'),
                'icon' => 'shield',
                'fields' => [
                    ['key' => 'auth.registration.enabled', 'label' => \TinyCat\Support\Localization::t('settings.fields.registration_enabled'), 'type' => 'bool', 'default' => false],
                    ['key' => 'auth.registration.auto_approve', 'label' => \TinyCat\Support\Localization::t('settings.fields.registration_auto_approve'), 'type' => 'bool', 'default' => false],
                ],
        ];
    }
}
