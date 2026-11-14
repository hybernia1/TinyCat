<?php
declare(strict_types=1);
namespace TinyCat\Core\Security;

/** Settings owned by this capability; definitions are evaluated only for the editor. */
final class Settings
{
    /** @return list<\TinyCat\Core\Modules\SettingsSection> */
    public static function sections(): array { return [new \TinyCat\Core\Modules\SettingsSection('tinycat.security.settings.security', 'security', self::section_security(...), 401)]; }

    /** @return array<mixed> */
    public static function section_security(): array
    {
        return [
                'label' => \TinyCat\Support\Localization::t('settings.sections.security'),
                'icon' => 'shield',
                'fields' => [
                    ['key' => 'security.captcha.enabled', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_enabled'), 'type' => 'bool', 'default' => false],
                    ['key' => 'security.captcha.provider', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_provider'), 'type' => 'select', 'default' => 'recaptcha', 'options' => ['recaptcha' => 'Google reCAPTCHA v2 Checkbox', 'turnstile' => 'Cloudflare Turnstile', 'hcaptcha' => 'hCaptcha'], 'help' => \TinyCat\Support\Localization::t('settings.fields.captcha_provider_help')],
                    ['key' => 'security.captcha.site_key', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_site_key'), 'type' => 'optional_text', 'default' => '', 'max' => 512],
                    ['key' => 'security.captcha.secret_key', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_secret_key'), 'type' => 'password', 'default' => '', 'max' => 512, 'help' => \TinyCat\Support\Localization::t('settings.fields.captcha_secret_key_help')],
                    ['key' => 'security.captcha.login_attempts', 'label' => \TinyCat\Support\Localization::t('settings.fields.captcha_login_attempts'), 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 10],
                ],
        ];
    }
}
