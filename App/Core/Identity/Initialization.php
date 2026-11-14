<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

/** Trusted initial-account write shared by installation transports, never an HTTP resource. */
final class Initialization
{
    public static function administrator(string $username, string $password, string $locale): int
    {
        $username = Usernames::normalize($username);
        $existing = \TinyCat\Database\Connection::one('SELECT id FROM users WHERE username = ?', [$username]);
        $data = ['username' => $username, 'password' => Authentication::authPassword($password), 'role' => 'admin', 'status' => 'active', 'locale' => $locale, 'theme' => 'system'];
        if ($existing !== null) { \TinyCat\Database\Connection::update('users', $data, ['id' => $existing['id']]); return (int) $existing['id']; }
        return (int) \TinyCat\Database\Connection::insert('users', $data);
    }
}
