<?php
declare(strict_types=1);

namespace TinyCat\Users;

use RuntimeException;

final class Recovery
{
    public static function send(string $email): void
    {
        $email = Profiles::user_email_normalize($email);
        if (!Profiles::user_email_valid($email)) { return; }
        $user = \TinyCat\Database\Connection::one('SELECT id, username FROM users WHERE email = ? AND status = ? LIMIT 1', [$email, 'active']);
        if ($user === null) { return; }
        $token = bin2hex(random_bytes(32));
        self::auth_recovery_replace_token((int) $user['id'], hash('sha256', $token), \TinyCat\Support\Dates::dateDb('+60 minutes'));
        \TinyCat\Mail\Templates::email_template_send('password_reset', (int) $user['id'], ['reset_url' => \TinyCat\Site\Presentation::absolute_url('/recovery?token=' . rawurlencode($token))]);
    }

    /** @return array<string, list<string>> */
    public static function confirm(string $token, string $password, string $confirmation): array
    {
        $user = \TinyCat\Database\Connection::one('SELECT u.* FROM password_reset_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > ? AND u.status = ? LIMIT 1', [hash('sha256', $token), \TinyCat\Support\Dates::dateDb(), 'active']);
        $errors = Login::auth_password_validation_field_errors($password, $confirmation);
        if ($token === '' || $user === null) { $errors['token'] = [\TinyCat\Support\Localization::t('auth.recovery_invalid')]; }
        if ($errors !== []) { return $errors; }
        if (!self::auth_recovery_consume_token((int) ($user['id'] ?? 0), hash('sha256', $token), Authentication::authPassword($password), \TinyCat\Support\Dates::dateDb())) {
            return ['token' => [\TinyCat\Support\Localization::t('auth.recovery_invalid')]];
        }
        return [];
    }

    public static function auth_recovery_replace_token(int $userId, string $tokenHash, string $expiresAt): void
    {
        if ($userId < 1 || $tokenHash === '' || $expiresAt === '') {
            return;
        }

        \TinyCat\Database\Transactions::run(static function () use ($userId, $tokenHash, $expiresAt): void {
            \TinyCat\Database\Connection::delete('password_reset_tokens', ['user_id' => $userId]);
            \TinyCat\Database\Connection::insert('password_reset_tokens', [
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    public static function auth_recovery_consume_token(
        int $userId,
        string $tokenHash,
        string $passwordHash,
        string $usedAt
    ): bool {
        if ($userId < 1 || $tokenHash === '' || $passwordHash === '' || $usedAt === '') {
            return false;
        }

        return \TinyCat\Database\Transactions::run(static function () use ($userId, $tokenHash, $passwordHash, $usedAt): bool {
            $consumed = \TinyCat\Database\Connection::exec(
                'UPDATE password_reset_tokens
                    SET used_at = ?
                    WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ?',
                [$usedAt, $userId, $tokenHash, $usedAt]
            );

            if ($consumed !== 1) {
                return false;
            }

            $updated = \TinyCat\Database\Connection::exec(
                'UPDATE users SET password = ? WHERE id = ? AND status = ?',
                [$passwordHash, $userId, 'active']
            );

            if ($updated !== 1) {
                throw new RuntimeException('Password recovery user changed before token consumption.');
            }

            return true;
        });
    }
}
