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

    public static function author_url(int $id): string
    {
        return \TinyCat\Support\LocalUrls::resolve($id > 0 ? '/author/' . $id : '/');
    }

    public static function author_feed_url(int $id, string $media = ''): string
    {
        $media = \TinyCat\Social\Posts\Archive::type($media);
        return $id > 0 ? '/author/' . $id . '/feed' . ($media !== '' ? '?media=' . $media : '') : '/';
    }

    public static function author_profile_edit_modal_id(int $authorId): string
    {
        return 'account-editor-modal-' . max(0, $authorId);
    }

    public static function author_profile_edit_modal_url(int $authorId, string $focus = ''): string
    {
        return '/api/users/' . $authorId . '/management?' . http_build_query(['panel' => 'account', 'focus' => $focus]);
    }

    /**
     * @param array<mixed> $user
     * @return array<string, mixed>
     */
    public static function resource(array $user): array
    {
        return \TinyCat\Core\Identity\Resource::profile($user, Profiles::author_url((int) ($user['id'] ?? 0)));
    }
}
