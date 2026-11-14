<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

/** Compatibility entry points for the account avatar adapter. */
final class Avatars
{
    /** @param array<mixed>|null $user */
    public static function user_avatar_url(?array $user): string { return \TinyCat\Core\Identity\Avatars::user_avatar_url($user); }
    /** @param array<string, mixed> $user */
    public static function user_avatar_updated_at(array $user): string { return \TinyCat\Core\Identity\Avatars::user_avatar_updated_at($user); }
    /** @param array<mixed> $user
     * @return array<mixed> */
    public static function user_avatar_update_request(array $user): array { return \TinyCat\Core\Identity\Avatars::user_avatar_update_request($user); }
    /** @param array<mixed> $user
     * @return array<mixed> */
    public static function updateAppearance(array $user): array { return \TinyCat\Core\Identity\Avatars::updateAppearance($user); }
    public static function author_avatar_edit_modal_id(int $authorId): string { return \TinyCat\Core\Identity\Avatars::author_avatar_edit_modal_id($authorId); }
    public static function author_avatar_edit_modal_url(int $authorId): string { return \TinyCat\Core\Identity\Avatars::author_avatar_edit_modal_url($authorId); }
    /** @param array<mixed> $user
     * @param array<string, mixed> $payload
     * @param callable(): void|null $afterWrite
     * @param callable(array<mixed>): array<mixed>|null $write
     * @return array<string, mixed> */
    public static function admin_user_avatar_save(array $user, array $payload, ?callable $afterWrite = null, ?callable $write = null): array { return \TinyCat\Core\Identity\Avatars::admin_user_avatar_save($user, $payload, $afterWrite, $write); }
}
