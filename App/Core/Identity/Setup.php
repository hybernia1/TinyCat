<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Passwords;
use TinyCat\Core\Identity\Usernames;

final class Setup
{
    /** @param array<string, mixed> $input
     * @return array<string, string> */
    public static function issues(array $input): array
    {
        foreach (['username', 'password', 'password_confirm'] as $field) { if (!is_string($input[$field] ?? null)) { return [$field => 'install.messages.required_fields']; } }
        $username = Usernames::normalize($input['username']);
        if ($username === '' || $input['password'] === '') { return ['username' => 'install.messages.required_fields']; }
        if (!Usernames::valid($username)) { return ['username' => 'install.messages.invalid_username']; }
        if (strlen($input['password']) < Passwords::minLength()) { return ['password' => 'install.messages.password_length']; }
        if (Passwords::tooLong($input['password'])) { return ['password' => 'account.messages.password_too_long']; }
        if ($input['password'] !== $input['password_confirm']) { return ['password_confirm' => 'install.messages.password_mismatch']; }
        return [];
    }

    /** @param array<string, mixed> $input
     * @return array{username: string, password: string, locale: string} */
    public static function input(array $input): array
    {
        if (self::issues($input) !== []) { throw new \InvalidArgumentException('Invalid administrator credentials.'); }
        $username = Usernames::normalize($input['username']);
        $locale = $input['locale'] ?? \TinyCat\Support\Config::get('i18n.locale', 'en');
        if (!is_string($locale) || !isset(\TinyCat\Support\TranslationSources::packages()[$locale])) { throw new \InvalidArgumentException('Invalid administrator locale.'); }
        return ['username' => $username, 'password' => $input['password'], 'locale' => $locale];
    }

    /** Trusted initial-account write for installation transports; validate input explicitly before calling. */
    public static function administrator(string $username, string $password, string $locale): int
    {
        $username = Usernames::normalize($username);
        $existing = \TinyCat\Database\Connection::one('SELECT id FROM users WHERE username = ?', [$username]);
        $data = ['username' => $username, 'password' => Authentication::authPassword($password), 'role' => 'admin', 'status' => 'active', 'locale' => $locale, 'theme' => 'system'];
        if ($existing !== null) { \TinyCat\Database\Connection::update('users', $data, ['id' => $existing['id']]); return (int) $existing['id']; }
        return (int) \TinyCat\Database\Connection::insert('users', $data);
    }
}
