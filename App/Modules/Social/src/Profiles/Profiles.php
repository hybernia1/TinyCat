<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

final class Profiles
{
    public static function author_url(int $id): string
    {
        return \TinyCat\Http\LocalUrls::resolve($id > 0 ? '/author/' . $id : '/');
    }

    public static function author_feed_url(int $id, string $media = ''): string
    {
        $media = \TinyCat\Social\Posts\Archive::type($media);
        return $id > 0 ? '/author/' . $id . '/feed' . ($media !== '' ? '?media=' . $media : '') : '/';
    }

    /**
     * @param array<mixed> $user
     * @return array<string, mixed>
     */
    public static function resource(array $user): array
    {
        return \TinyCat\Core\Identity\Resource::profile($user, Profiles::author_url((int) ($user['id'] ?? 0)));
    }
    /** @param array<mixed>|null $row */
    public static function avatarUrl(?array $row): string
    {
        if ($row === null) { return ''; }
        foreach ([['author_id', 'author_avatar_exists', 'author_updated_at', 'author_name'], ['actor_id', 'actor_avatar_exists', 'actor_updated_at', 'actor_name'], ['user_id', 'user_avatar_exists', 'user_updated_at', 'username'], ['id', 'avatar_exists', 'updated_at', 'username']] as [$id, $exists, $updated, $name]) {
            if (!array_key_exists($id, $row) || !array_key_exists($exists, $row)) { continue; }
            if ((int) $row[$id] > 0) {
                return \TinyCat\Core\Identity\Avatars::user_avatar_url(['id' => $row[$id], 'username' => $row[$name] ?? $row['author_username'] ?? $row['actor_username'] ?? null, 'avatar_exists' => $row[$exists], 'updated_at' => $row[$updated] ?? null,
                    'avatar_version' => $row[str_replace('avatar_exists', 'avatar_version', $exists)] ?? null]);
            }
        }
        return '';
    }
}
