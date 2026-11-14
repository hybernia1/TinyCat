<?php
declare(strict_types=1);
namespace TinyCat\Core\Mail;

/** Settings owned by this capability; definitions are evaluated only for the editor. */
final class Settings
{
    /** @return list<\TinyCat\Core\Modules\SettingsSection> */
    public static function sections(): array { return [new \TinyCat\Core\Modules\SettingsSection('tinycat.mail.settings.email', 'email', self::section_email(...), 500)]; }

    /** @return array<mixed> */
    public static function section_email(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.email'),
                'icon' => 'mail',
                'fields' => [
                    ['key' => 'email.smtp', 'label' => \TinyCat\Support\Localization::t('settings.fields.smtp'), 'type' => 'email_smtp', 'default' => \TinyCat\Core\Mail\Smtp::email_smtp_default_config(), 'span' => true],
                    ['key' => 'email.templates', 'label' => \TinyCat\Support\Localization::t('settings.fields.email_templates'), 'type' => 'email_templates', 'default' => \TinyCat\Core\Mail\Templates::email_template_default_states(), 'span' => true],
                ],
        ];
    }
}
