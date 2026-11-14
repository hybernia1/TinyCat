<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Passwords;
use TinyCat\Core\Identity\Usernames;

final class Setup
{
    /** @param array<string, mixed> $input
     * @return array{username: string, password: string, locale: string} */
    public static function input(array $input): array
    {
        foreach (['username', 'password', 'password_confirm'] as $field) { if (!is_string($input[$field] ?? null)) { throw new \InvalidArgumentException('Missing administrator field.'); } }
        $username = Usernames::normalize($input['username']);
        if (!Usernames::valid($username) || Passwords::fieldErrors($input['password'], $input['password_confirm']) !== []) { throw new \InvalidArgumentException('Invalid administrator credentials.'); }
        $locale = $input['locale'] ?? \TinyCat\Support\Config::get('i18n.locale', 'en');
        if (!is_string($locale) || !isset(\TinyCat\Support\TranslationSources::packages()[$locale])) { throw new \InvalidArgumentException('Invalid administrator locale.'); }
        return ['username' => $username, 'password' => $input['password'], 'locale' => $locale];
    }
}
