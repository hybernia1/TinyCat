<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

use TinyCat\Core\Ui\InteractionTarget;

/** Social prepares reactions before passing rows to the data-only common formatter. */
final class DiscussionResource
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
        $row['liked'] = $actor !== null && Comments::status_comment_user_liked((int) $row['id'], (int) $actor['id']);
        $row['replies'] = self::prepare((array) ($row['replies'] ?? []), $actor);
        return \TinyCat\Core\Ui\DiscussionResource::comment($row, $actor, $target);
    }
    /** @param array<mixed> $rows
     * @param array<string, mixed>|null $actor
     * @return list<array<string, mixed>> */
    private static function prepare(array $rows, ?array $actor): array
    {
        return array_map(static function (array $row) use ($actor): array {
            $row['liked'] = $actor !== null && Comments::status_comment_user_liked((int) $row['id'], (int) $actor['id']);
            $row['replies'] = self::prepare((array) ($row['replies'] ?? []), $actor);
            return $row;
        }, array_values($rows));
    }
}
