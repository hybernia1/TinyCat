<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

final class Registration
{
    /** @param array<mixed> $input
     * @return array<mixed> */
    public static function input(array $input): array
    {
        $username = Usernames::normalize((string) ($input['username'] ?? ''));
        $email = Email::normalize((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $fieldErrors = [];

        if (!Usernames::valid($username)) {
            $fieldErrors['username'][] = \TinyCat\Support\Localization::t('account.messages.username_invalid');
        } elseif (Usernames::taken($username)) {
            $fieldErrors['username'][] = \TinyCat\Support\Localization::t('account.messages.username_taken');
        }

        if ($email !== '' && !Email::valid($email)) {
            $fieldErrors['email'][] = \TinyCat\Support\Localization::t('account.messages.email_invalid');
        } elseif ($email !== '' && Email::taken($email)) {
            $fieldErrors['email'][] = \TinyCat\Support\Localization::t('account.messages.email_taken');
        }

        $fieldErrors = array_merge(
            $fieldErrors,
            Passwords::fieldErrors($password, (string) ($input['password_confirm'] ?? ''))
        );

        if ((string) ($input['platform_terms'] ?? '') !== '1') {
            $fieldErrors['platform_terms'][] = \TinyCat\Support\Localization::t('auth.platform_terms_required');
        }

        $errors = [];

        foreach ($fieldErrors as $fieldMessages) {
            $errors = array_merge($errors, $fieldMessages);
        }

        return [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'errors' => $errors,
            'field_errors' => $fieldErrors,
        ];
    }

    /** @param array<mixed> $registration Validated registration values.
     * @return array<mixed> */
    public static function create(array $registration, bool $autoApprove, string $locale): array
    {
        $status = $autoApprove ? 'active' : 'waiting';
        $userId = (int) \TinyCat\Database\Connection::insert('users', [
            'username' => (string) ($registration['username'] ?? ''),
            'email' => ($registration['email'] ?? '') !== '' ? (string) $registration['email'] : null,
            'password' => \TinyCat\Core\Identity\Authentication::authPassword((string) ($registration['password'] ?? '')),
            'role' => 'user',
            'status' => $status,
            'locale' => $locale,
            'theme' => 'system',
            'bio' => '',
        ]);

        return [
            'user_id' => $userId,
            'status' => $status,
            'approved' => $status === 'active',
        ];
    }
}
