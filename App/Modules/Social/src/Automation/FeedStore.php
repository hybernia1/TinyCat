<?php
declare(strict_types=1);
namespace TinyCat\Social\Automation;

use RuntimeException;

/** Social owns bot identities, bounded GUID history and publication timelines. */
final class FeedStore implements \TinyCat\Core\Feeds\FeedSourceStore
{
    public function claim(array $source, bool $force): bool
    {
        $id = (int) ($source['id'] ?? 0);
        $now = \TinyCat\Support\Dates::dateDb();
        $next = date('Y-m-d H:i:s', time() + max(5, min(43200, (int) ($source['interval_minutes'] ?? 60))) * 60);
        $where = $force ? '' : ' AND (next_run_at IS NULL OR next_run_at <= ?)';
        return $id > 0 && \TinyCat\Database\Connection::exec('UPDATE bot_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND enabled = 1' . $where, $force ? [$next, $now, $id] : [$next, $now, $id, $now]) > 0;
    }
    public function start(array $source, int $schedulerRunId): int { return Importer::createSourceRun((int) $source['id'], $schedulerRunId); }
    public function history(array $source, array $hashes): array { return self::feedHistoryHashes((int) $source['bot_user_id'], Importer::feedSourceHash((string) $source['feed_url']), $hashes); }
    public function duplicate(array $source, ?array $link): bool
    {
        return $link !== null && \TinyCat\Database\Connection::value("SELECT 1 FROM social_links l INNER JOIN social_post_links cl ON cl.link_id = l.id INNER JOIN social_posts c ON c.id = cl.content_id WHERE l.url_hash = ? AND c.author_id = ? AND c.kind = 'post' LIMIT 1", [$link['url_hash'], (int) $source['bot_user_id']]) !== null;
    }
    public function commit(array $source, string $hash, callable $write): int
    {
        return $this->commitIdentity($source, Importer::feedSourceHash((string) $source['feed_url']), $hash, $write);
    }
    /** @param array<string, mixed> $source
     * @param callable(string): int $write */
    public function commitIdentity(array $source, string $feedHash, string $itemHash, callable $write): int
    {
        $sourceId = (int) $source['id']; $botUserId = (int) $source['bot_user_id'];
        $publicationInterval = Importer::publicationInterval($source);
        $publishedAt = \TinyCat\Support\Dates::dateDb();
        return \TinyCat\Database\Transactions::run(static function () use ($sourceId, $botUserId, $feedHash, $itemHash, $publishedAt, $publicationInterval, $write): int {
            // Downloads happen outside the transaction. Recheck identity under the
            // same row lock that serializes edits/deletion before saving a mapping.
            $current = \TinyCat\Database\Connection::one('SELECT bot_user_id, feed_hash, enabled FROM bot_sources WHERE id = ?'
                . (\TinyCat\Database\Connection::driver() === 'mysql' ? ' FOR UPDATE' : ''), [$sourceId]);
            if ($current === null || (int) $current['bot_user_id'] !== $botUserId || !hash_equals((string) $current['feed_hash'], $feedHash) || !(bool) $current['enabled']) {
                throw new RuntimeException('Source changed or was disabled during import. Retry using its current settings.');
            }
            self::recordFeedHistory($botUserId, $feedHash, $itemHash, $publishedAt);
            // Feed executions share the scheduler lock; each source owns its timeline.
            $lastPublication = (string) \TinyCat\Database\Connection::value(
                'SELECT MAX(c.published_at) FROM bot_source_posts bsp INNER JOIN social_posts c ON c.id = bsp.content_id WHERE bsp.source_id = ?',
                [$sourceId]
            );
            $nextPublication = $lastPublication !== ''
                ? \TinyCat\Support\Dates::dateDb((new \DateTimeImmutable($lastPublication, \TinyCat\Support\Dates::timezone()))->modify('+' . $publicationInterval . ' minutes'))
                : $publishedAt;
            $publicationTime = max($publishedAt, $nextPublication);
            $contentId = $write($publicationTime);
            \TinyCat\Database\Connection::insert('bot_source_posts', ['source_id' => $sourceId, 'content_id' => $contentId]);
            \TinyCat\Database\Connection::update('bot_sources', ['last_imported_at' => $publishedAt], ['id' => $sourceId]);
            return $contentId;
        });
    }
    public function finish(array $source, int $runId, string $status, int $itemsSeen, string $error): void
    {
        if ($error !== '') { \TinyCat\Database\Connection::update('bot_sources', ['last_error' => $error], ['id' => (int) $source['id']]); }
        Importer::finishSourceRun($runId, $status, $itemsSeen, $error);
    }

    /** @param list<string> $itemHashes
     * @return array<string, true> */
    private static function feedHistoryHashes(int $botUserId, string $feedHash, array $itemHashes): array
    {
        $itemHashes = array_values(array_unique(array_filter($itemHashes, static fn (mixed $hash): bool => preg_match('/^[a-f0-9]{64}$/', (string) $hash) === 1)));
        if ($botUserId < 1 || !preg_match('/^[a-f0-9]{64}$/', $feedHash) || $itemHashes === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($itemHashes), '?'));
        $rows = \TinyCat\Database\Connection::all(
            'SELECT item_hash
             FROM bot_feed_history
             WHERE bot_user_id = ? AND feed_hash = ? AND item_hash IN (' . $placeholders . ')',
            array_merge([$botUserId, $feedHash], $itemHashes)
        );

        return array_fill_keys(array_column($rows, 'item_hash'), true);
    }

    private static function recordFeedHistory(
        int $botUserId,
        string $feedHash,
        string $itemHash,
        string $createdAt = ''
    ): void
    {
        if ($botUserId < 1 || $feedHash === '' || $itemHash === '') {
            return;
        }

        // Let storage failures (including a concurrent duplicate claim) roll
        // back the post and all its relations together with the history entry.
        $data = [
            'bot_user_id' => $botUserId,
            'feed_hash' => $feedHash,
            'item_hash' => $itemHash,
            'created_at' => $createdAt !== '' ? $createdAt : \TinyCat\Support\Dates::dateDb(),
        ];
        \TinyCat\Database\Connection::insert('bot_feed_history', $data);

        self::pruneFeedHistory($botUserId, $feedHash);
    }

    private static function pruneFeedHistory(int $botUserId, string $feedHash, int $keep = 500): void
    {
        if ($botUserId < 1 || !preg_match('/^[a-f0-9]{64}$/', $feedHash)) {
            return;
        }

        $keep = max(10, min(1000, $keep));
        \TinyCat\Database\Connection::exec(
            'DELETE FROM bot_feed_history
                WHERE bot_user_id = ? AND feed_hash = ?
                    AND item_hash NOT IN (
                        SELECT item_hash FROM (
                            SELECT item_hash
                            FROM bot_feed_history
                            WHERE bot_user_id = ? AND feed_hash = ?
                            ORDER BY created_at DESC, item_hash DESC
                            LIMIT ' . $keep . '
                        ) recent_items
                    )',
            [$botUserId, $feedHash, $botUserId, $feedHash]
        );
    }

}
