<?php
declare(strict_types=1);
namespace TinyCat\Social\Discussion;

use TinyCat\Social\Posts\InlinePresentation as Segments;

/** Social comment data, capabilities and prepared thread presentation. */
final class Resource
{
    /** @param array<mixed> $rows
     * @param array<string, mixed>|null $actor
     * @return list<array<string, mixed>> */
    public static function comments(array $rows, ?array $actor, InteractionTarget $target): array
    {
        if ($actor !== null) { Comments::status_preload_comment_tree_user_likes($rows, (int) $actor['id']); }
        return array_map(static fn (array $row): array => self::comment($row, $actor, $target), array_values($rows));
    }
    /** @param array<mixed> $row
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed> */
    public static function comment(array $row, ?array $actor, InteractionTarget $target): array
    {
        $id = (int) $row['id']; $authorId = (int) $row['user_id'];
        $own = $actor !== null && (int) $actor['id'] === $authorId;
        $admin = \TinyCat\Core\Identity\Roles::isAdmin($actor);
        return ['id' => $id, 'content_id' => $target->id, 'parent_id' => (int) ($row['parent_id'] ?? 0),
            'author' => ['id' => $authorId, 'username' => (string) ($row['author_name'] ?? ''), 'avatar_url' => \TinyCat\Social\Profiles\Profiles::avatarUrl($row)],
            'body' => (string) $row['body'], 'editable_body' => (string) $row['body'],
            'segments' => Segments::compactTags(Segments::text((string) $row['body'], [], $target->id, $id)),
            'created_at' => \TinyCat\Support\Dates::dateIso((string) $row['created_at']),
            'likes_count' => (int) ($row['likes_count'] ?? 0), 'liked' => $actor !== null && Comments::status_comment_user_liked($id, (int) $actor['id']),
            'capabilities' => ['edit' => $own || $admin, 'delete' => $own || $admin || $target->manage, 'interact' => $target->interact],
            'replies' => array_map(static fn (array $reply): array => self::comment($reply, $actor, $target), array_values((array) ($row['replies'] ?? [])))];
    }
}
