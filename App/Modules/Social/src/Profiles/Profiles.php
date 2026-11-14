<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

final class Profiles
{
    /**
     * @param array<mixed> $user
     */
    public static function user_display_name(?array $user): string
    {
        if ($user === null) {
            return '';
        }

        return trim((string) ($user['username'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public static function user_is_admin(?array $user): bool
    {
        return \TinyCat\Core\Identity\Roles::isAdmin($user);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>|null
     */
    public static function user_public_payload(?array $user): ?array
    {
        return \TinyCat\Core\Identity\Resource::identity($user);
    }

    /**
     * @param array<mixed> $target
     * @param array<mixed> $actor
     */
    public static function user_can_edit_profile(?array $target, ?array $actor): bool
    {
        return \TinyCat\Core\Identity\Profile::canEdit($target, $actor);
    }

    public static function theme_normalize(string $theme): string
    {
        return \TinyCat\Core\Identity\Profile::theme($theme);
    }

    /**
     * @param array<mixed> $user
     */
    public static function user_theme(?array $user): string
    {
        return \TinyCat\Core\Identity\Profile::theme((string) ($user['theme'] ?? 'system'));
    }

    public static function user_email_normalize(string $email): string { return \TinyCat\Core\Identity\Email::normalize($email); }
    public static function user_email_max_length(): int { return \TinyCat\Core\Identity\Email::maxLength(); }
    public static function user_email_valid(string $email): bool { return \TinyCat\Core\Identity\Email::valid($email); }
    public static function user_email_taken(string $email, ?int $ignoreId = null): bool { return \TinyCat\Core\Identity\Email::taken($email, $ignoreId); }

    public static function author_url(int $id): string
    {
        return \TinyCat\Support\LocalUrls::resolve($id > 0 ? '/author/' . $id : '/');
    }

    public static function author_feed_url(int $id, string $media = ''): string
    {
        $media = \TinyCat\Social\Content\Archive::type($media);
        return $id > 0 ? '/author/' . $id . '/feed' . ($media !== '' ? '?media=' . $media : '') : '/';
    }

    /**
     * @param array<mixed> $params
     */
    public static function author_api_url(int $id, string $action = 'follow', array $params = []): string
    {
        return '/api/users/' . $id . '/followers/me';
    }

    public static function author_unfollow(int $followerId, int $authorId): void
    {
        if ($followerId < 1 || $authorId < 1 || $followerId === $authorId) {
            return;
        }

        \TinyCat\Database\Connection::delete('user_followers', [
            'user_id' => $authorId,
            'follower_id' => $followerId,
        ]);
    }

    public static function author_profile_edit_modal_id(int $authorId): string
    {
        return 'account-editor-modal-' . max(0, $authorId);
    }

    public static function author_profile_edit_modal_url(int $authorId, string $focus = ''): string
    {
        return '/api/users/' . $authorId . '/management?' . http_build_query(['panel' => 'account', 'focus' => $focus]);
    }

    public static function app_apply_user_locale(): void { \TinyCat\Core\Identity\Locale::apply(); }

    public static function app_auth_account_ready(): bool
    {
        return \TinyCat\Core\Identity\Readiness::ready();
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_roles(): array
    {
        return \TinyCat\Core\Identity\ReadModel::roles();
    }

    public static function tc_admin_super_admin_id(): int
    {
        return \TinyCat\Core\Identity\ReadModel::primaryIdCached();
    }
}
