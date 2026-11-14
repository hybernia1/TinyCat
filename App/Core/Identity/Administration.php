<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;
use TinyCat\Support\Text;

/** Administrative account policy and writes, independent of HTTP and avatar storage. */
final class Administration
{
    /** @return list<string> */
    public static function statuses(): array { return ['active', 'waiting', 'ban']; }

    public static function primaryId(): int
    {
        return (int) Connection::value('SELECT id FROM users WHERE role = ? ORDER BY created_at ASC, id ASC LIMIT 1', ['admin']);
    }

    public static function isPrimary(int $id): bool { return $id > 0 && $id === self::primaryId(); }

    /** @param array<mixed> $actor
     * @param array<mixed> $input
     * @param array<mixed>|null $existing
     * @return array<string, mixed> Server-prepared payload, not a client resource. */
    public static function prepare(array $actor, array $input, ?array $existing = null): array
    {
        self::requireAdmin($actor);
        if ($existing !== null && !Roles::managedByCoreAdmin((string) ($existing['role'] ?? ''))) { throw new \DomainException('user_not_found'); }
        $creating = $existing === null;
        $errors = [];
        foreach (['role', 'status', 'username', 'email', 'password'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field]) && !(in_array($field, ['email', 'password'], true) && $input[$field] === null)) {
                $errors[$field] = [match ($field) {
                    'email' => 'account.messages.email_invalid',
                    'password' => 'users.validation.password_required',
                    default => 'users.validation.' . $field . '_invalid',
                }];
            }
        }
        if ($errors !== []) { throw new ValidationException($errors); }
        $role = (string) ($input['role'] ?? '');
        $status = (string) ($input['status'] ?? $existing['status'] ?? '');
        if (!Roles::managedByCoreAdmin($role)) { $errors['role'] = ['users.validation.role_invalid']; }
        if (!in_array($status, self::statuses(), true)) { $errors['status'] = ['users.validation.status_invalid']; }
        if ($errors !== []) { throw new ValidationException($errors); }
        $username = $creating ? Usernames::normalize((string) ($input['username'] ?? '')) : (string) ($existing['username'] ?? '');
        if ($creating && !Usernames::valid($username)) { throw new ValidationException(['username' => ['users.messages.username_invalid']]); }
        if ($creating && Usernames::taken($username)) { throw new ValidationException(['username' => ['users.messages.username_taken']]); }
        $id = $existing === null ? null : (int) $existing['id'];
        $emailProvided = ($input['email'] ?? null) !== null;
        $email = Email::normalize((string) ($emailProvided || $creating ? ($input['email'] ?? '') : ($existing['email'] ?? '')));
        self::requireEmail($email, $id);
        $password = (string) ($input['password'] ?? '');
        if ($creating && $password === '') { throw new ValidationException(['password' => ['users.validation.password_required']]); }
        if ($password !== '' && strlen($password) < 8) { throw new ValidationException(['password' => ['users.validation.password_min']]); }
        if (Passwords::tooLong($password)) { throw new ValidationException(['password' => ['users.validation.password_max']]); }
        if ($existing !== null) { self::protectChange($existing, $role, $status); }
        $data = ['role' => $role, 'bio' => Text::plain_text_limit((string) ($input['bio'] ?? ''), 500)];
        if ($creating || array_key_exists('status', $input)) { $data['status'] = $status; }
        if ($creating || $emailProvided) { $data['email'] = $email !== '' ? $email : null; }
        if ($creating) { $data['username'] = $username; }
        if ($password !== '') { $data['password'] = Authentication::authPassword($password); }
        return $data;
    }

    /** @param array<mixed> $actor
     * @param array<mixed> $input */
    public static function create(array $actor, array $input): int
    {
        return (int) Connection::insert('users', self::prepare($actor, $input));
    }

    /** Convenience for writes without an accompanying file transaction.
     * @param array<mixed> $actor
     * @param array<mixed> $input
     * @return array<mixed> */
    public static function update(array $actor, int $id, array $input): array
    {
        return Transactions::run(static function () use ($actor, $id, $input): array {
            self::requireAdmin($actor);
            $user = Connection::one(Connection::lockingSelect('SELECT * FROM users WHERE id = ?'), [$id]);
            if ($user === null) { throw new \DomainException('user_not_found'); }
            return self::persist($actor, $user, self::prepare($actor, $input, $user));
        });
    }

    /** Recheck policy inside the transaction that locked the account, including avatar transactions.
     * @param array<mixed> $actor
     * @param array<mixed> $locked
     * @param array<string, mixed> $prepared Server-generated prepare() output.
     * @return array<mixed> */
    public static function persist(array $actor, array $locked, array $prepared): array
    {
        self::requireAdmin($actor);
        if (!Connection::get()->inTransaction()) { throw new \LogicException('Administrative persistence requires an account transaction.'); }
        if (!Roles::managedByCoreAdmin((string) ($locked['role'] ?? ''))) { throw new \DomainException('user_not_found'); }
        $data = array_intersect_key($prepared, array_flip(['role', 'bio', 'status', 'email', 'password']));
        $role = (string) ($data['role'] ?? '');
        $status = (string) ($data['status'] ?? $locked['status'] ?? '');
        if (!Roles::managedByCoreAdmin($role)) { throw new ValidationException(['role' => ['users.validation.role_invalid']]); }
        if (!in_array($status, self::statuses(), true)) { throw new ValidationException(['status' => ['users.validation.status_invalid']]); }
        if (array_key_exists('email', $data)) { self::requireEmail((string) $data['email'], (int) $locked['id']); }
        if (array_key_exists('password', $data) && (!is_string($data['password']) || password_get_info($data['password'])['algo'] === null)) { throw new \LogicException('Administrative persistence requires a password hash.'); }
        self::protectChange($locked, $role, $status);
        Connection::update('users', $data, ['id' => (int) $locked['id']]);
        return array_replace($locked, $data);
    }

    /** @param array<mixed> $actor
     * @param array<mixed> $user
     * @param array<string, Participant> $participants */
    public static function setActive(array $actor, array $user, bool $active, array $participants = []): void
    {
        self::requireAdmin($actor);
        Transactions::run(static function () use ($user, $active, $participants): void {
            $locked = Connection::one(Connection::lockingSelect('SELECT * FROM users WHERE id = ?'), [(int) ($user['id'] ?? 0)]);
            if ($locked === null) { throw new \DomainException('user_not_found'); }
            if (!$active && self::isPrimary((int) $locked['id'])) { throw new \DomainException('super_admin_protected'); }
            Lifecycle::setActive($locked, $active, $participants);
        });
    }

    /** @param array<mixed> $actor
     * @param array<string, Participant> $participants
     * @return array<mixed>|null */
    public static function delete(array $actor, int $id, array $participants = []): ?array
    {
        self::requireAdmin($actor);
        $guard = new Participant(prepareDelete: static function (array $user): Deletion {
            if (self::isPrimary((int) $user['id'])) { throw new \DomainException('super_admin_protected'); }
            return new Deletion();
        });
        return Lifecycle::delete($id, ['__accounts_administration' => $guard] + $participants);
    }

    /** @param array<mixed> $actor */
    private static function requireAdmin(array $actor): void
    {
        if ((int) ($actor['id'] ?? 0) < 1 || !Roles::isAdmin($actor)) { throw new \DomainException('forbidden'); }
    }

    private static function requireEmail(string $email, ?int $id): void
    {
        if ($email !== '' && !Email::valid($email)) { throw new ValidationException(['email' => ['account.messages.email_invalid']]); }
        if ($email !== '' && Email::taken($email, $id)) { throw new ValidationException(['email' => ['account.messages.email_taken']]); }
    }

    /** @param array<mixed> $user */
    private static function protectChange(array $user, string $role, string $status): void
    {
        if (self::isPrimary((int) $user['id']) && ($role !== 'admin' || $status !== 'active')) {
            throw new ValidationException(['role' => ['users.messages.super_admin_protected'], 'status' => ['users.messages.super_admin_protected']]);
        }
    }
}
