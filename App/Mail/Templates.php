<?php
declare(strict_types=1);

namespace TinyCat\Mail;

use Throwable;

final class Templates
{
    /**
     * @return array<mixed>
     */
    public static function email_user(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }

        try {
            return \TinyCat\Database\Connection::one('SELECT id, username, email, email_notifications, locale FROM users WHERE id = ? LIMIT 1', [$userId]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<mixed>
     */
    public static function email_template_keys(): array
    {
        static $keys;
        if ($keys !== null) {
            return $keys;
        }

        $catalog = \TinyCat\Mail\Templates::email_catalog('en');
        $keys = array_keys((array) ($catalog['templates'] ?? []));

        return $keys;
    }

    /**
     * @return array<mixed>
     */
    public static function email_template_default_states(): array
    {
        return array_fill_keys(\TinyCat\Mail\Templates::email_template_keys(), true);
    }

    public static function email_template_enabled(string $templateKey): bool
    {
        $states = \TinyCat\Settings\Store::setting('email.templates', \TinyCat\Mail\Templates::email_template_default_states());

        return is_array($states) && array_key_exists($templateKey, $states)
            ? (bool) $states[$templateKey]
            : true;
    }

    /**
     * @return array<mixed>
     */
    public static function email_catalog(string $requestedLocale = ''): array
    {
        static $cache = [];
        $requestedLocale = \TinyCat\Support\Languages::language_code($requestedLocale !== '' ? $requestedLocale : \TinyCat\Support\Localization::locale());
        $requestedLocale = $requestedLocale !== '' ? $requestedLocale : 'en';

        if (isset($cache[$requestedLocale])) {
            return $cache[$requestedLocale];
        }

        $baseLocale = explode('-', $requestedLocale, 2)[0];
        $locales = array_values(array_unique([$requestedLocale, $baseLocale, 'en']));

        foreach ($locales as $locale) {
            $path = \TinyCat\Support\Paths::basePath('lang/' . $locale . '/emails.json');

            if (!is_file($path)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($path), true);

            if (is_array($data)) {
                return $cache[$requestedLocale] = $data;
            }
        }

        return $cache[$requestedLocale] = ['signature' => '', 'templates' => []];
    }

    /**
     * @param array<mixed> $vars
     */
    public static function email_template_send(string $templateKey, int $userId, array $vars = []): bool
    {
        try {
            $user = \TinyCat\Mail\Templates::email_user($userId);
            if ($user === null) return false;
            $address = \TinyCat\Users\Profiles::user_email_normalize((string) ($user['email'] ?? ''));
            if (!\TinyCat\Users\Profiles::user_email_valid($address)) return false;
            if (str_starts_with($templateKey, 'notification_') && !(bool) ($user['email_notifications'] ?? false)) return false;
            if (!\TinyCat\Mail\Templates::email_template_enabled($templateKey)) return false;
            $catalog = \TinyCat\Mail\Templates::email_catalog((string) ($user['locale'] ?? ''));
            $template = (array) (($catalog['templates'] ?? [])[$templateKey] ?? []);
            if ($template === []) return false;
            $vars += ['site' => \TinyCat\Site\Presentation::site_name(), 'username' => (string) $user['username'], 'email' => $address];
            $vars += ['site_url' => \TinyCat\Site\Presentation::absolute_url('/'), 'signature' => (string) ($catalog['signature'] ?? '')];
            $vars['signature'] = str_replace('{{site}}', \TinyCat\Site\Presentation::site_name(), (string) $vars['signature']);
            $subject = (string) ($template['subject'] ?? '');
            $body = (string) ($template['body'] ?? '');
            foreach ($vars as $key => $value) {
                $subject = str_replace('{{' . $key . '}}', (string) $value, $subject);
                $body = str_replace('{{' . $key . '}}', (string) $value, $body);
            }
            return \TinyCat\Mail\Smtp::email_smtp_send($address, $subject, $body);
        } catch (Throwable) {
            return false;
        }
    }

    public static function email_notification_templates_enabled(): bool
    {
        foreach (\TinyCat\Mail\Templates::email_template_keys() as $templateKey) {
            if (str_starts_with($templateKey, 'notification_') && \TinyCat\Mail\Templates::email_template_enabled($templateKey)) {
                return true;
            }
        }

        return false;
    }
}
