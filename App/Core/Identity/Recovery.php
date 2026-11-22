<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use RuntimeException;

final class Recovery
{
    /** @param callable(int, string): void $deliver Trusted delivery of the plaintext token. */
    public static function request(string $email, callable $deliver): void
    {
        $email = Email::normalize($email);
        if (!Email::valid($email)) { return; }
        $token = bin2hex(random_bytes(32));
        $userId = \TinyCat\Database\Transactions::run(static function () use ($email, $token): int {
            $user = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT id FROM users WHERE email = ? AND status = ? LIMIT 1'), [$email, 'active']);
            if ($user === null) { return 0; }
            self::replaceToken((int) $user['id'], hash('sha256', $token), \TinyCat\Support\Dates::dateDb('+60 minutes'));
            return (int) $user['id'];
        });
        if ($userId > 0) { $deliver($userId, $token); }
    }

    /** @return array<string, list<string>> */
    public static function confirm(string $token, string $password, string $confirmation): array
    {
        $user = \TinyCat\Database\Connection::one('SELECT u.* FROM password_reset_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > ? AND u.status = ? LIMIT 1', [hash('sha256', $token), \TinyCat\Support\Dates::dateDb(), 'active']);
        $errors = Passwords::fieldErrors($password, $confirmation);
        if ($token === '' || $user === null) { $errors['token'] = [\TinyCat\Support\Localization::t('auth.recovery_invalid')]; }
        if ($errors !== []) { return $errors; }
        if (!self::consumeToken((int) ($user['id'] ?? 0), hash('sha256', $token), Authentication::authPassword($password), \TinyCat\Support\Dates::dateDb())) {
            return ['token' => [\TinyCat\Support\Localization::t('auth.recovery_invalid')]];
        }
        return [];
    }

    public static function replaceToken(int $userId, string $tokenHash, string $expiresAt): void
    {
        if ($userId < 1 || $tokenHash === '' || $expiresAt === '') {
            return;
        }

        \TinyCat\Database\Transactions::run(static function () use ($userId, $tokenHash, $expiresAt): void {
            $user = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT id FROM users WHERE id = ?'), [$userId]);
            if ($user === null) { return; }
            \TinyCat\Database\Connection::delete('password_reset_tokens', ['user_id' => $userId]);
            \TinyCat\Database\Connection::insert('password_reset_tokens', [
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    public static function consumeToken(
        int $userId,
        string $tokenHash,
        string $passwordHash,
        string $usedAt
    ): bool {
        if ($userId < 1 || $tokenHash === '' || $passwordHash === '' || $usedAt === '') {
            return false;
        }

        return \TinyCat\Database\Transactions::run(static function () use ($userId, $tokenHash, $passwordHash, $usedAt): bool {
            // All credential operations lock the account before its reset tokens.
            $user = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM users WHERE id = ?'), [$userId]);
            $consumed = \TinyCat\Database\Connection::exec(
                'UPDATE password_reset_tokens
                    SET used_at = ?
                    WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ?',
                [$usedAt, $userId, $tokenHash, $usedAt]
            );

            if ($consumed !== 1) {
                return false;
            }

            if ($user === null || ($user['status'] ?? '') !== 'active') {
                throw new RuntimeException('Password recovery user changed before token consumption.');
            }
            Passwords::persist($userId, ['password' => $passwordHash]);

            return true;
        });
    }
}
