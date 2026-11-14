<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Core\Feeds\FeedSourceStore;
use TinyCat\Database\{Connection, Transactions};
use TinyCat\Support\Dates;

/** Blog owns sources and successful claims; scheduler_runs owns execution diagnostics. */
final class FeedStore implements FeedSourceStore
{
    public function claim(array $source, bool $force): bool
    {
        $now = Dates::dateDb();
        $next = Dates::dateDb((new \DateTimeImmutable($now, Dates::timezone()))->modify('+' . (int) $source['interval_minutes'] . ' minutes'));
        return Connection::exec('UPDATE blog_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND revision = ? AND enabled = 1' . ($force ? '' : ' AND (next_run_at IS NULL OR next_run_at <= ?)'), [$next, $now, (int) $source['id'], (int) $source['revision'], ...($force ? [] : [$now])]) > 0;
    }
    public function start(array $source, int $schedulerRunId): int { return $schedulerRunId; }
    public function history(array $source, array $hashes): array
    {
        if ($hashes === []) { return []; }
        $rows = Connection::all('SELECT item_hash FROM blog_feed_history WHERE source_id = ? AND item_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')', [(int) $source['id'], ...$hashes]);
        return array_fill_keys(array_column($rows, 'item_hash'), true);
    }
    public function duplicate(array $source, ?array $link): bool
    {
        return $link !== null && Connection::value("SELECT 1 FROM blog_links l INNER JOIN blog_article_links r ON r.link_id = l.id INNER JOIN blog_articles a ON a.id = r.article_id WHERE l.url_hash = ? AND a.author_id = ? LIMIT 1", [$link['url_hash'], (int) $source['author_id']]) !== null;
    }
    public function commit(array $source, string $hash, callable $write): int
    {
        return Transactions::run(static function () use ($source, $hash, $write): int {
            $current = Connection::one(Connection::lockingSelect('SELECT * FROM blog_sources WHERE id = ?'), [(int) $source['id']]);
            $author = Connection::one(Connection::lockingSelect('SELECT role, status FROM users WHERE id = ?'), [(int) $source['author_id']]);
            if ($current === null || !(bool) $current['enabled'] || (int) $current['revision'] !== (int) $source['revision'] || (int) $current['author_id'] !== (int) $source['author_id'] || !hash_equals((string) $current['feed_hash'], (string) $source['feed_hash']) || $author === null || $author['status'] !== 'active' || !\TinyCat\Core\Identity\Roles::isAdmin($author)) { throw new \RuntimeException('Source or author changed during import. Retry using its current settings.'); }
            $now = Dates::dateDb();
            Connection::insert('blog_feed_history', ['source_id' => (int) $source['id'], 'item_hash' => $hash, 'created_at' => $now]);
            $id = $write($now);
            Connection::insert('blog_source_articles', ['source_id' => (int) $source['id'], 'article_id' => $id]);
            Connection::update('blog_sources', ['last_imported_at' => $now], ['id' => (int) $source['id']]);
            return $id;
        });
    }
    public function finish(array $source, int $runId, string $status, int $itemsSeen, string $error): void
    {
        Connection::exec('UPDATE blog_sources SET last_error = ? WHERE id = ? AND revision = ?', [$error !== '' ? $error : null, (int) $source['id'], (int) $source['revision']]);
    }
}
