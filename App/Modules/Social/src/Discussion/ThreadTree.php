<?php
declare(strict_types=1);
namespace TinyCat\Social\Discussion;

/** Tree shaping for prepared rows; no database access or domain visibility rules. */
final class ThreadTree
{
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
