<?php
declare(strict_types=1);

namespace TinyCat\Users;

use Throwable;

final class Usernames
{
    public static function username_normalize(string $username): string
    {
        return trim($username);
    }

    public static function username_key(string $username): string
    {
        $username = \TinyCat\Users\Usernames::username_normalize($username);

        return function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    public static function username_pattern(): string
    {
        // Numeric-only names would collide with the existing @user-ID storage format.
        return '(?!\p{N}+$)[\p{L}\p{N}][\p{L}\p{N}\p{M}_.\-]{0,30}[\p{L}\p{N}\p{M}_]';
    }

    public static function username_valid(string $username): bool
    {
        return preg_match('~\A' . \TinyCat\Users\Usernames::username_pattern() . '\z~u', \TinyCat\Users\Usernames::username_normalize($username)) === 1;
    }

    public static function username_hint(): string
    {
        return \TinyCat\Support\Localization::t('account.username_hint');
    }

    public static function user_username_taken(string $username, ?int $ignoreId = null): bool
    {
        $username = \TinyCat\Users\Usernames::username_normalize($username);

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
