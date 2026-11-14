<?php
declare(strict_types=1);

namespace TinyCat\Core\Mail;

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
        return array_keys((array) (self::email_catalog('en')['templates'] ?? []));
    }

    /**
     * @return array<mixed>
     */
    public static function email_template_default_states(): array
    {
        return array_fill_keys(self::email_template_keys(), true);
    }

    public static function email_template_enabled(string $templateKey): bool
    {
        $states = \TinyCat\Core\Settings\Store::setting('email.templates', self::email_template_default_states());

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
        static $cacheRevision = -1;
        $requestedLocale = \TinyCat\Support\Languages::language_code($requestedLocale !== '' ? $requestedLocale : \TinyCat\Support\Localization::locale());
        $requestedLocale = $requestedLocale !== '' ? $requestedLocale : 'en';

        $revision = class_exists(\TinyCat\Core\Modules\Resources::class, false) ? \TinyCat\Core\Modules\Resources::revision() : 0;
        if ($cacheRevision !== $revision) { $cache = []; $cacheRevision = $revision; }
        $cacheKey = $revision . ':' . $requestedLocale;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $catalog = [];
        foreach (\TinyCat\Support\Localization::fallbacks($requestedLocale) as $locale) {
            $data = \TinyCat\Support\CompiledTranslations::emails($locale);
            if ($data === null && \TinyCat\Support\CompiledTranslations::packages() === null) {
                $data = \TinyCat\Support\TranslationSources::channel('emails', $locale);
            }
            if (is_array($data)) { $catalog = array_replace_recursive($catalog, $data); }
        }
        return $cache[$cacheKey] = $catalog;
    }

    /**
     * @param array<mixed> $vars
     */
    public static function email_template_send(string $templateKey, int $userId, array $vars = []): bool
    {
        if (!\TinyCat\Core\Modules\Registrations::current()->entries->mailDelivery) { return false; }
        try {
            $user = self::email_user($userId);
            if ($user === null) return false;
            $address = \TinyCat\Core\Identity\Email::normalize((string) ($user['email'] ?? ''));
            if (!\TinyCat\Core\Identity\Email::valid($address)) return false;
            if (!self::email_template_enabled($templateKey)) return false;
            $catalog = self::email_catalog((string) ($user['locale'] ?? ''));
            $template = (array) (($catalog['templates'] ?? [])[$templateKey] ?? []);
            if ($template === []) return false;
            if (($template['channel'] ?? '') === 'notifications' && !(bool) ($user['email_notifications'] ?? false)) return false;
            $vars += ['site' => (string) \TinyCat\Core\Runtime\Startup::option('site.name', 'TinyCat'), 'username' => (string) $user['username'], 'email' => $address];
            $vars += ['site_url' => rtrim((string) \TinyCat\Core\Runtime\Startup::option('app.url', ''), '/') . '/', 'signature' => (string) ($catalog['signature'] ?? '')];
            $vars['signature'] = \TinyCat\Support\Localization::format((string) $vars['signature'], ['site' => (string) \TinyCat\Core\Runtime\Startup::option('site.name', 'TinyCat')]);
            $subject = (string) ($template['subject'] ?? '');
            $body = (string) ($template['body'] ?? '');
            $subject = \TinyCat\Support\Localization::format($subject, $vars);
            $body = \TinyCat\Support\Localization::format($body, $vars);
            return \TinyCat\Core\Mail\Smtp::email_smtp_send($address, $subject, $body);
        } catch (Throwable) {
            return false;
        }
    }

    public static function email_notification_templates_enabled(): bool
    {
        foreach ((array) (self::email_catalog('en')['templates'] ?? []) as $templateKey => $template) {
            if (($template['channel'] ?? '') === 'notifications' && self::email_template_enabled($templateKey)) {
                return true;
            }
        }

        return false;
    }
}
