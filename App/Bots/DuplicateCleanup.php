<?php
declare(strict_types=1);
namespace TinyCat\Bots;

use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;
use TinyCat\Support\Dates;

/** Bounded housekeeping for provably identical public posts of the same bot. */
final class DuplicateCleanup
{
    private static function bodyKey(string $alias): string
    {
        return Connection::driver() === 'mysql' ? 'SHA2(' . $alias . '.body,256)' : $alias . '.body COLLATE BINARY';
    }

    /** Compare sets in both directions. Different attachments are deliberately not inferred equal. */
    private static function sameRelations(string $newer, string $older): string
    {
        $conditions = [];
        foreach (['content_links' => ['link_id'], 'content_tags' => ['term_id'], 'content_images' => ['path', 'width', 'height', 'bytes']] as $table => $keys) {
            $equal = implode(' AND ', array_map(static fn (string $key): string => $key === 'path'
                ? (Connection::driver() === 'mysql' ? 'BINARY a.path=BINARY b.path' : 'a.path=b.path COLLATE BINARY')
                : 'a.' . $key . '=b.' . $key, $keys));
            foreach ([[$newer, $older], [$older, $newer]] as [$left, $right]) {
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM ' . $table . ' a WHERE a.content_id=' . $left . '.id AND NOT EXISTS (SELECT 1 FROM ' . $table . ' b WHERE b.content_id=' . $right . '.id AND ' . $equal . '))';
            }
        }
        return implode(' AND ', $conditions);
    }

    /** Read-only preview; also used by the scheduled batch.
     * @return array<mixed> */
    public static function candidates(int $limit): array
    {
        $limit = max(1, min(501, $limit));
        $now = Dates::dateDb();
        $key = self::bodyKey('c');
        $rows = Connection::all("SELECT id,author_id,body_key FROM (
            SELECT c.id,c.author_id," . $key . " AS body_key,
                COUNT(*) OVER (PARTITION BY c.author_id," . $key . ") AS copies
            FROM content c INNER JOIN users u ON u.id=c.author_id
            WHERE u.role='bot' AND u.status='active' AND c.status='published' AND c.published_at<=? AND TRIM(c.body)<>''
            ) grouped WHERE copies>1 ORDER BY id", [$now]);
        if ($rows === []) { return []; }
        $metadata = [];
        foreach (array_chunk(array_column($rows, 'id'), 500) as $ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach (['content_links' => 'link_id', 'content_tags' => 'term_id', 'content_images' => 'path,width,height,bytes'] as $table => $columns) {
                foreach (Connection::all('SELECT content_id,' . $columns . ' FROM ' . $table . ' WHERE content_id IN (' . $placeholders . ') ORDER BY content_id,' . $columns, $ids) as $relation) {
                    $id = (int) $relation['content_id'];
                    unset($relation['content_id']);
                    // Normalize numeric PDO values consistently across database drivers.
                    foreach ($relation as $column => &$value) {
                        if ($column !== 'path' && $value !== null) { $value = (int) $value; }
                    }
                    unset($value);
                    $metadata[$id][$table][] = $relation;
                }
            }
        }
        $keepers = [];
        $pairs = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $relations = $metadata[$id] ?? [];
            ksort($relations);
            $signature = json_encode([(int) $row['author_id'], $row['body_key'], $relations], JSON_THROW_ON_ERROR);
            if (!isset($keepers[$signature])) { $keepers[$signature] = $id; continue; }
            $pairs[] = ['id' => $id, 'keeper_id' => $keepers[$signature]];
            if (count($pairs) >= $limit) { break; }
        }
        return $pairs;
    }

    private static function merge(int $oldId, int $keeperId): bool
    {
        return Transactions::run(static function () use ($oldId, $keeperId): bool {
            $rows = Connection::all(Connection::lockingSelect('SELECT c.*,u.role AS author_role,u.status AS author_status
                FROM content c INNER JOIN users u ON u.id=c.author_id WHERE c.id IN (?,?) ORDER BY c.id'), [$keeperId, $oldId]);
            if (count($rows) !== 2) { return false; }
            [$keeper, $old] = $rows;
            if ((int) $keeper['id'] !== $keeperId || (int) $old['id'] !== $oldId || $keeperId >= $oldId
                || (int) $keeper['author_id'] !== (int) $old['author_id'] || $keeper['body'] !== $old['body'] || (string) $old['body'] === '') { return false; }
            foreach ($rows as $row) {
                if ($row['author_role'] !== 'bot' || $row['author_status'] !== 'active' || $row['status'] !== 'published' || $row['published_at'] > Dates::dateDb()) { return false; }
            }
            if (Connection::value('SELECT 1 FROM content n INNER JOIN content k ON k.id=? WHERE n.id=? AND ' . self::sameRelations('n', 'k'), [$keeperId, $oldId]) === null) { return false; }
            foreach (Connection::all('SELECT user_id FROM content_likes WHERE content_id=?', [$oldId]) as $like) {
                if (Connection::value('SELECT 1 FROM content_likes WHERE content_id=? AND user_id=?', [$keeperId, $like['user_id']]) === null) {
                    Connection::insert('content_likes', ['content_id' => $keeperId, 'user_id' => $like['user_id']]);
                }
            }
            Connection::update('content_comments', ['content_id' => $keeperId], ['content_id' => $oldId]);
            Connection::update('content_reports', ['content_id' => $keeperId], ['content_id' => $oldId]);
            \TinyCat\Notifications\Inbox::mergeContent($oldId, $keeperId);
            // Flatten earlier aliases before deleting their previous target.
            Connection::update('content_redirects', ['content_id' => $keeperId], ['content_id' => $oldId]);
            Connection::upsert('content_redirects', ['old_id' => $oldId], ['content_id' => $keeperId]);
            // A shared attachment is still owned by the keeper. Orphan files use normal file cleanup.
            \TinyCat\Status\Posts::status_delete_content($oldId, deleteReports: false, deleteNotifications: false, deleteImageFile: false);
            return true;
        });
    }

    /** @return array<string, mixed> */
    public static function run(int $batch): array
    {
        $batch = max(1, min(500, $batch));
        $changed = 0;
        try {
            $candidates = self::candidates($batch + 1);
            foreach (array_slice($candidates, 0, $batch) as $row) {
                if (self::merge((int) $row['id'], (int) $row['keeper_id'])) { $changed++; }
            }
            return ['due' => true, 'changed' => $changed, 'has_more' => count($candidates) > $batch];
        } catch (\Throwable $exception) {
            return ['due' => true, 'changed' => $changed, 'has_more' => false, 'error' => $exception->getMessage()];
        }
    }
}
