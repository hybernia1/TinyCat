<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

final class Locale
{
    public static function apply(): void
    {
        $user = Authentication::auth();
        $locale = \TinyCat\Support\Languages::language_code((string) ($user['locale'] ?? ''));
        $packages = \TinyCat\Support\Languages::language_packages();
        if ($locale !== '' && array_key_exists($locale, $packages)) { \TinyCat\Support\Localization::locale($locale); return; }
        $detected = \TinyCat\Support\Languages::language_detect((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), array_keys($packages));
        if ($detected !== null) { \TinyCat\Support\Localization::locale($detected); }
    }
}
