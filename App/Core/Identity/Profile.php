<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use TinyCat\Database\Connection;
use TinyCat\Support\Languages;
use TinyCat\Support\Text;

/** Account profile writes; transport, session and presentation are caller responsibilities. */
final class Profile
{
    /** @param array<mixed>|null $target
     * @param array<mixed>|null $actor */
    public static function canEdit(?array $target, ?array $actor): bool
    {
        $targetId = (int) ($target['id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);
        return $targetId > 0 && $actorId > 0 && ($targetId === $actorId || Roles::isAdmin($actor));
    }

    public static function theme(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['system', 'light', 'dark'], true) ? $value : 'system';
    }

    /** @param array<mixed> $user
     * @param array<mixed> $actor
     * @param array<mixed> $input
     * @param list<string> $availableLocales
     * @return array<mixed> */
    public static function update(array $user, array $actor, array $input, array $availableLocales): array
    {
        self::requireEdit($user, $actor);
        $input = array_replace(['bio' => $user['bio'] ?? '', 'locale' => $user['locale'] ?? '', 'theme' => $user['theme'] ?? 'system'], $input);
        $id = (int) $user['id'];
        $emailProvided = ($input['email'] ?? null) !== null;
        $email = Email::normalize((string) ($emailProvided ? $input['email'] : ($user['email'] ?? '')));
        $locale = Languages::language_code((string) $input['locale']);
        $errors = [];
        if ($locale === '' || !in_array($locale, $availableLocales, true)) { $errors['locale'] = ['settings.messages.invalid_language']; }
        $errors += self::emailIssues($email, $id, $emailProvided);
        if ($errors !== []) { throw new ValidationException($errors); }
        $data = ['bio' => Text::plain_text_limit((string) $input['bio'], 500), 'locale' => $locale, 'theme' => self::theme((string) $input['theme'])];
        if ($emailProvided) { $data['email'] = $email !== '' ? $email : null; }
        $data += self::notifications($input, $email, $emailProvided);
        Connection::update('users', $data, ['id' => $id]);
        return array_replace($user, $data);
    }

    /** @param array<mixed> $user
     * @param array<mixed> $actor
     * @param array<mixed> $input
     * @return array<mixed> */
    public static function email(array $user, array $actor, array $input): array
    {
        self::requireEdit($user, $actor);
        $id = (int) $user['id'];
        $email = Email::normalize((string) ($input['email'] ?? ''));
        $errors = self::emailIssues($email, $id, true);
        if ($errors !== []) { throw new ValidationException($errors); }
        $data = ['email' => $email !== '' ? $email : null] + self::notifications($input, $email, true);
        Connection::update('users', $data, ['id' => $id]);
        return array_replace($user, $data);
    }

    /** @param array<mixed> $user
     * @param array<mixed> $actor
     * @param array<mixed> $input */
    public static function password(array $user, array $actor, array $input): void
    {
        self::requireEdit($user, $actor);
        if ((int) $actor['id'] !== (int) $user['id']) { throw new \DomainException('forbidden'); }
        $current = (string) ($input['current_password'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $hash = (string) ($user['password'] ?? '');
        $errors = [];
        if (Passwords::tooLong($current) || $hash === '' || !password_verify($current, $hash)) { $errors['current_password'] = ['account.messages.current_password_invalid']; }
        $errors += Passwords::issueKeys($password, (string) ($input['password_confirm'] ?? ''));
        if ($errors !== []) { throw new ValidationException($errors); }
        // A previously loaded credential must not overwrite a concurrent password change.
        $changed = Connection::update('users', ['password' => Authentication::authPassword($password)], ['id' => (int) $user['id'], 'password' => $hash]);
        if ($changed !== 1) { throw new ValidationException(['current_password' => ['account.messages.current_password_invalid']]); }
    }

    /** @param array<mixed> $user
     * @param array<mixed> $actor */
    private static function requireEdit(array $user, array $actor): void
    {
        if (!self::canEdit($user, $actor)) { throw new \DomainException('forbidden'); }
    }

    /** @return array<string, list<string>> */
    private static function emailIssues(string $email, int $id, bool $validate): array
    {
        if ($validate && $email !== '' && !Email::valid($email)) { return ['email' => ['account.messages.email_invalid']]; }
        if ($email !== '' && Email::taken($email, $id)) { return ['email' => ['account.messages.email_taken']]; }
        return [];
    }

    /** @param array<mixed> $input
     * @return array<string, int> */
    private static function notifications(array $input, string $email, bool $emailProvided): array
    {
        if (($input['email_notifications'] ?? null) !== null) { return ['email_notifications' => $email !== '' && (string) $input['email_notifications'] === '1' ? 1 : 0]; }
        return $emailProvided && $email === '' ? ['email_notifications' => 0] : [];
    }
}
