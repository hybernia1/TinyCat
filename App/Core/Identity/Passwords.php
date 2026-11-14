<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

final class Passwords
{
    public static function maxLength(): int
    {
        return 1024;
    }

    public static function tooLong(string $password): bool
    {
        return strlen($password) > self::maxLength();
    }

    /** @return array<string, list<string>> */
    public static function issueKeys(string $password, string $passwordConfirm): array
    {
        if (strlen($password) < 8) {
            return ['password' => ['account.messages.password_short']];
        }

        if (self::tooLong($password)) {
            return ['password' => ['account.messages.password_too_long']];
        }

        if ($password !== $passwordConfirm) {
            return ['password_confirm' => ['account.messages.password_mismatch']];
        }

        return [];
    }

    /** @return array<string, list<string>> */
    public static function fieldErrors(string $password, string $passwordConfirm): array
    {
        return array_map(static fn (array $keys): array => array_map(\TinyCat\Support\Localization::t(...), $keys), self::issueKeys($password, $passwordConfirm));
    }

    /**
     * @return array<mixed>
     */
    public static function errors(string $password, string $passwordConfirm): array
    {
        $messages = [];

        foreach (self::fieldErrors($password, $passwordConfirm) as $fieldMessages) {
            $messages = array_merge($messages, $fieldMessages);
        }

        return $messages;
    }
}
