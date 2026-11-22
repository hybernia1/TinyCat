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
                'label' => ['t' => 'settings.sections.email'],
                'icon' => 'mail',
                'fields' => [
                    ['key' => 'email.smtp', 'label' => ['t' => 'settings.fields.smtp'], 'type' => 'email_smtp', 'default' => \TinyCat\Core\Mail\Smtp::email_smtp_default_config(), 'span' => true, 'fields' => self::smtpFields(), 'normalize' => self::normalizeSmtp(...)],
                    ['key' => 'email.templates', 'label' => ['t' => 'settings.fields.email_templates'], 'type' => 'email_templates', 'default' => \TinyCat\Core\Mail\Templates::email_template_default_states(), 'options' => array_combine(Templates::email_template_keys(), Templates::email_template_keys()), 'normalize' => self::normalizeTemplates(...), 'span' => true],
                ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function smtpFields(): array
    {
        $fields = [];
        $defaults = Smtp::email_smtp_default_config();
        foreach ([
            ['host', 'text', 190, 'smtp_host'], ['port', 'int', 65535, 'smtp_port'],
            ['username', 'text', 190, 'smtp_username'], ['password', 'password', 190, 'smtp_password'],
            ['encryption', 'text', 20, 'smtp_encryption'], ['from_address', 'email', 190, 'email_from'],
            ['from_name', 'text', 120, 'email_from_name'],
        ] as [$key, $type, $max, $label]) {
            $fields[] = ['key' => $key, 'type' => $type, 'default' => $defaults[$key], 'max' => $max, 'min' => 1,
                'optional' => $key !== 'port', 'label' => ['t' => 'settings.fields.' . $label]];
        }
        return $fields;
    }

    /** @return array{array<string, mixed>, string} */
    private static function normalizeSmtp(mixed $raw): array
    {
        if (!is_array($raw)) { throw new \InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
        $current = Smtp::email_smtp_config();
        $value = [];
        if (array_diff(array_keys($raw), array_column(self::smtpFields(), 'key')) !== []) { throw new \InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
        foreach (self::smtpFields() as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $raw)) { $value[$key] = $current[$key]; continue; }
            if ($key === 'password' && $raw[$key] === '') { $value[$key] = $current[$key]; continue; }
            $value[$key] = \TinyCat\Core\Settings\Administration::tc_admin_settings_value_from_post($field, $raw)[0];
        }
        $value['encryption'] = strtolower($value['encryption']);
        return [$value, 'json'];
    }

    /** @return array{array<string, bool>, string} */
    private static function normalizeTemplates(mixed $raw): array
    {
        if (!is_array($raw)) { throw new \InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
        $value = [];
        foreach ($raw as $key => $item) {
            if ($key === '_present' && $item === '1') { continue; }
            if (!in_array($key, Templates::email_template_keys(), true) || !in_array($item, [true, false, 0, 1, '0', '1', 'on', 'off'], true)) { throw new \InvalidArgumentException(\TinyCat\Support\Localization::t('common.request_failed')); }
        }
        foreach (Templates::email_template_keys() as $key) { $value[$key] = isset($raw[$key]) && in_array($raw[$key], [true, 1, '1', 'on'], true); }
        return [$value, 'json'];
    }
}
