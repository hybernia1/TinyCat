<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

final class Email
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function maxLength(): int
    {
        return 254;
    }

    public static function valid(string $email): bool
    {
        return strlen($email) <= self::maxLength() && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function taken(string $email, ?int $ignoreId = null): bool
    {
        $email = self::normalize($email);
        if ($email === '') {
            return false;
        }

        $params = ['email' => $email];
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        return (int) \TinyCat\Database\Connection::value($sql . ' LIMIT 1', $params) === 1;
    }
}
