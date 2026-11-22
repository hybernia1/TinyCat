<?php
declare(strict_types=1);
namespace TinyCat\Social\Bots;

use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;
use TinyCat\Support\Dates;

/** Bounded housekeeping for provably identical public posts of the same bot. */
final class DuplicateCleanup
{
    /** Compare sets in both directions. Different attachments are deliberately not inferred equal. */
    private static function sameRelations(string $newer, string $older): string
    {
        $conditions = [];
        foreach (['social_post_links' => ['link_id'], 'social_post_tags' => ['term_id'], 'social_post_images' => ['path', 'width', 'height', 'bytes']] as $table => $keys) {
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
        return self::scan(max(1, min(501, $limit)), 0)['pairs'];
    }

    /** Bounded input window; keepers can precede it, so cross-window duplicates remain visible.
     * @return array{pairs: list<array{id: int, keeper_id: int}>, next: int, has_more: bool} */
    private static function scan(int $limit, int $after): array
    {
        $window = max(256, min(1000, $limit * 2));
        $now = Dates::dateDb();
        $body = Connection::driver() === 'mysql' ? 'BINARY k.body = BINARY c.body' : 'k.body = c.body COLLATE BINARY';
        $rows = Connection::all("SELECT c.id,
            (SELECT k.id FROM social_posts k WHERE k.id<c.id AND k.author_id=c.author_id
                AND k.kind='post' AND k.status='published' AND k.published_at<=? AND " . $body . '
                AND ' . self::sameRelations('c', 'k') . " ORDER BY k.id LIMIT 1) AS keeper_id
            FROM (SELECT p.* FROM social_posts p INNER JOIN users u ON u.id=p.author_id
                WHERE p.id>? AND p.kind='post' AND u.role='bot' AND u.status='active'
                    AND p.status='published' AND p.published_at<=? AND TRIM(p.body)<>''
                ORDER BY p.id LIMIT " . ($window + 1) . ') c ORDER BY c.id', [$now, $after, $now]);
        $pairs = []; $next = $after; $processed = 0;
        foreach (array_slice($rows, 0, $window) as $row) {
            $next = (int) $row['id']; $processed++;
            if ($row['keeper_id'] !== null) { $pairs[] = ['id' => $next, 'keeper_id' => (int) $row['keeper_id']]; }
            if (count($pairs) >= $limit) { break; }
        }
        return ['pairs' => $pairs, 'next' => $next, 'has_more' => count($rows) > $processed];
    }

    private static function cursorFile(): string
    {
        if (Connection::driver() === 'mysql') { $identity = 'mysql:' . (string) Connection::value('SELECT DATABASE()'); }
        else {
            $main = array_find(Connection::all('PRAGMA database_list'), static fn (array $row): bool => $row['name'] === 'main');
            $identity = 'sqlite:' . (($main['file'] ?? '') !== '' ? $main['file'] : getmypid() . ':' . spl_object_id(Connection::get()));
        }
        return \TinyCat\Support\Cache::file('duplicate-bots-' . hash('sha256', $identity) . '.json', 'maintenance-cursors');
    }

    private static function merge(int $oldId, int $keeperId): bool
    {
        return Transactions::run(static function () use ($oldId, $keeperId): bool {
            $rows = Connection::all(Connection::lockingSelect('SELECT c.*,u.role AS author_role,u.status AS author_status
                FROM social_posts c INNER JOIN users u ON u.id=c.author_id WHERE c.id IN (?,?) ORDER BY c.id'), [$keeperId, $oldId]);
            if (count($rows) !== 2) { return false; }
            [$keeper, $old] = $rows;
            if ((int) $keeper['id'] !== $keeperId || (int) $old['id'] !== $oldId || $keeperId >= $oldId
                || (int) $keeper['author_id'] !== (int) $old['author_id'] || $keeper['body'] !== $old['body'] || (string) $old['body'] === '') { return false; }
            foreach ($rows as $row) {
                if ($row['kind'] !== 'post' || $row['author_role'] !== 'bot' || $row['author_status'] !== 'active' || $row['status'] !== 'published' || $row['published_at'] > Dates::dateDb()) { return false; }
            }
            if (Connection::value('SELECT 1 FROM social_posts n INNER JOIN social_posts k ON k.id=? WHERE n.id=? AND ' . self::sameRelations('n', 'k'), [$keeperId, $oldId]) === null) { return false; }
            foreach (Connection::all('SELECT user_id FROM social_post_likes WHERE content_id=?', [$oldId]) as $like) {
                if (Connection::value('SELECT 1 FROM social_post_likes WHERE content_id=? AND user_id=?', [$keeperId, $like['user_id']]) === null) {
                    Connection::insert('social_post_likes', ['content_id' => $keeperId, 'user_id' => $like['user_id']]);
                }
            }
            Connection::update('social_comments', ['content_id' => $keeperId], ['content_id' => $oldId]);
            Connection::update('content_reports', ['content_id' => $keeperId], ['content_id' => $oldId]);
            \TinyCat\Social\Posts\NotificationHistory::mergeContent($oldId, $keeperId);
            // Flatten earlier aliases before deleting their previous target.
            Connection::update('social_post_redirects', ['content_id' => $keeperId], ['content_id' => $oldId]);
            Connection::upsert('social_post_redirects', ['old_id' => $oldId], ['content_id' => $keeperId]);
            // A shared attachment is still owned by the keeper. Orphan files use normal file cleanup.
            \TinyCat\Social\Posts\Posts::status_delete_content($oldId, deleteReports: false, deleteNotifications: false, deleteImageFile: false);
            return true;
        });
    }

    /** @return array{due: bool, changed: int, has_more: bool, error?: string} */
    public static function run(int $batch): array
    {
        $batch = max(1, min(500, $batch));
        $changed = 0;
        try {
            $cursor = self::cursorFile();
            $saved = is_file($cursor) ? json_decode((string) file_get_contents($cursor), true) : null;
            $scan = self::scan($batch, is_int($saved) ? max(0, $saved) : 0);
            foreach ($scan['pairs'] as $row) {
                if (self::merge((int) $row['id'], (int) $row['keeper_id'])) { $changed++; }
            }
            if (!\TinyCat\Support\Storage::write($cursor, json_encode($scan['has_more'] ? $scan['next'] : 0, JSON_THROW_ON_ERROR))) { throw new \RuntimeException('Cannot save duplicate cleanup progress.'); }
            return ['due' => true, 'changed' => $changed, 'has_more' => $scan['has_more']];
        } catch (\Throwable $exception) {
            \TinyCat\Core\Runtime\Failure::report($exception);
            return ['due' => true, 'changed' => $changed, 'has_more' => false, 'error' => \TinyCat\Core\Runtime\Failure::message($exception, 'Operation failed.')];
        }
    }
}
