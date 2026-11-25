<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

final class Passwords
{
    /** Account credential writes serialize with recovery issuance and consumption.
     * @param array<string, mixed> $data */
    public static function persist(int $userId, array $data, ?string $expectedPassword = null): int
    {
        return \TinyCat\Database\Transactions::run(static function () use ($userId, $data, $expectedPassword): int {
            $locked = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM users WHERE id = ?'), [$userId]);
            if ($locked === null || ($expectedPassword !== null && !hash_equals($expectedPassword, (string) $locked['password']))) { return 0; }
            $changed = \TinyCat\Database\Connection::update('users', $data, ['id' => $userId]);
            if ((array_key_exists('password', $data) && $data['password'] !== $locked['password'])
                || (array_key_exists('email', $data) && $data['email'] !== ($locked['email'] ?? null))) {
                \TinyCat\Database\Connection::delete('password_reset_tokens', ['user_id' => $userId]);
            }
            return $changed;
        });
    }

    public static function minLength(): int { return 8; }

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
        if (strlen($password) < self::minLength()) {
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
        return array_map(static fn (array $keys): array => array_map(static fn (string $key): string => \TinyCat\Support\Localization::t($key, ['min' => (string) self::minLength()]), $keys), self::issueKeys($password, $passwordConfirm));
    }

}
