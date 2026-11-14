<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Core\Text\Segments;

use TinyCat\Core\Ui\InteractionTarget;

/** Canonical discussion data; presentation definitions belong to the UI layer. */
final class DiscussionResource
{
    /**
     * @param array<mixed> $rows
     * @param array<string, mixed>|null $actor
     *
     * @return list<array<string, mixed>> */
    public static function comments(array $rows, ?array $actor, InteractionTarget $target): array
    {
        return array_map(static fn (array $row): array => self::comment($row, $actor, $target), array_values($rows));
    }

    /**
     * @param array<mixed> $row
     * @param array<string, mixed>|null $actor
     *
     * @return array<string, mixed> */
    public static function comment(array $row, ?array $actor, InteractionTarget $target): array
    {
        $id = (int) $row['id']; $authorId = (int) $row['user_id'];
        $own = $actor !== null && (int) $actor['id'] === $authorId;
        $admin = \TinyCat\Core\Identity\Roles::isAdmin($actor);
        return ['id' => $id, 'content_id' => $target->id, 'parent_id' => (int) ($row['parent_id'] ?? 0),
            'author' => ['id' => $authorId, 'username' => (string) ($row['author_name'] ?? ''), 'avatar_url' => \TinyCat\Core\Identity\Avatars::user_avatar_url($row)],
            'body' => (string) $row['body'], 'editable_body' => (string) $row['body'],
            'segments' => Segments::compactTags(Segments::text((string) $row['body'], [], $target->id, $id, $target->kind)),
            'created_at' => \TinyCat\Support\Dates::dateIso((string) $row['created_at']),
            'likes_count' => (int) ($row['likes_count'] ?? 0), 'liked' => $actor !== null && (bool) ($row['liked'] ?? false),
            'capabilities' => ['edit' => $own || $admin, 'delete' => $own || $admin || $target->manage, 'interact' => $target->interact],
            'replies' => self::comments((array) ($row['replies'] ?? []), $actor, $target)];
    }
}
