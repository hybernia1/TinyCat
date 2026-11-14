<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

/** Tree shaping for prepared rows; no database access or domain visibility rules. */
final class ThreadTree
{
    /** @param array<mixed> $rows
     * @return array<mixed> */
    public static function fromRows(array $rows): array
    {
        $parents = [];
        $children = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $parentId = (int) ($row['parent_id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            $row['replies'] = [];

            if ($parentId > 0) {
                $children[$parentId][] = $row;
                continue;
            }

            $parents[$id] = $row;
        }

        foreach ($children as $parentId => $items) {
            if (!isset($parents[$parentId])) {
                continue;
            }

            $parents[$parentId]['replies'] = $items;
        }

        return array_values($parents);
    }

    /** @param array<int, array<string, mixed>> $comments
     * @return list<array<string, mixed>> */
    public static function flatten(array $comments): array
    {
        $rows = [];
        $pending = $comments;

        while ($pending !== []) {
            $comment = array_pop($pending);

            $rows[] = $comment;

            foreach ((array) ($comment['replies'] ?? []) as $reply) {
                if (is_array($reply)) {
                    $pending[] = $reply;
                }
            }
        }

        return $rows;
    }
}
