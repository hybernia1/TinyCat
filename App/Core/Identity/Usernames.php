<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use Throwable;

final class Usernames
{
    public static function normalize(string $username): string
    {
        return trim($username);
    }

    public static function key(string $username): string
    {
        $username = self::normalize($username);

        return function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    public static function pattern(): string
    {
        // Numeric-only names would collide with the existing @user-ID storage format.
        return '(?!\p{N}+$)[\p{L}\p{N}][\p{L}\p{N}\p{M}_.\-]{0,30}[\p{L}\p{N}\p{M}_]';
    }

    public static function valid(string $username): bool
    {
        return preg_match('~\A' . self::pattern() . '\z~u', self::normalize($username)) === 1;
    }

    public static function hint(): string
    {
        return \TinyCat\Support\Localization::t('account.username_hint');
    }

    public static function taken(string $username, ?int $ignoreId = null): bool
    {
        $username = self::normalize($username);

        if ($username === '') {
            return false;
        }

        $params = ['username' => $username];
        $sql = 'SELECT COUNT(*) FROM users WHERE username = :username';

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        try {
            return (int) \TinyCat\Database\Connection::value($sql, $params) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
