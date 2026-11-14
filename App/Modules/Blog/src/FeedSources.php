<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Core\Modules\ScheduledTask;
use TinyCat\Core\Feeds\FeedParser;
use TinyCat\Core\Feeds\FeedPipeline;
use TinyCat\Database\{Connection, Transactions};
use TinyCat\Support\Dates;

final class FeedSources
{
    public static function pipeline(): FeedPipeline { return new FeedPipeline(new FeedStore(), new FeedPublication()); }

    public static function task(): ScheduledTask
    {
        return new ScheduledTask('article_feeds', self::scheduled(...), ['source_limit' => 20], ['icon' => 'rss', 'title' => 'blog.feeds.title', 'help' => 'blog.feeds.help', 'schedule' => 'blog.feeds.schedule'], 15,
            modeOptions: ['admin' => ['source_id'], 'cli' => ['source_id']], normalize: static function (array $options): array {
                $options['source_limit'] = max(1, min(100, (int) $options['source_limit']));
                if (isset($options['source_id'])) { $options['source_id'] = max(0, (int) $options['source_id']); }
                return $options;
            });
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed> */
    public static function scheduled(array $context): array
    {
        $options = (array) ($context['options'] ?? []);
        $id = (int) ($options['source_id'] ?? 0);
        $where = $id > 0 ? 's.id = ?' : '(s.next_run_at IS NULL OR s.next_run_at <= ?)';
        $sources = Connection::all("SELECT s.* FROM blog_sources s INNER JOIN users u ON u.id = s.author_id WHERE s.enabled = 1 AND u.status = 'active' AND u.role = 'admin' AND " . $where . ' ORDER BY COALESCE(s.next_run_at, s.created_at), s.id LIMIT ' . max(1, min(100, (int) ($options['source_limit'] ?? 20))), [$id > 0 ? $id : Dates::dateDb()]);
        if ($id > 0 && $sources === []) { throw new \RuntimeException('Source is unavailable.'); }
        $results = []; $changed = 0; $errors = [];
        foreach ($sources as $source) {
            $result = self::pipeline()->run(self::runtime($source), $id > 0, (int) ($context['scheduler_run_id'] ?? 0));
            $results[] = $result; $changed += (int) ($result['imported_count'] ?? 0);
            if ($result['status'] === 'error') { $errors[] = (string) $result['error']; }
        }
        return \TinyCat\Core\Tasks\Tasks::result($errors !== [] ? 'failed' : 'completed', count($results), $changed, error: implode(' ', $errors), results: $results);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array { return Connection::find('blog_sources', ['id' => $id]); }

    /** @param array<string, mixed> $source
     * @return array<string, mixed> */
    public static function runtime(array $source): array { return $source; }

    /** @param array<string, mixed> $input */
    public static function save(array $input, int $id = 0): int
    {
        foreach (['name', 'feed_url', 'publication_status'] as $field) { if (isset($input[$field]) && !is_string($input[$field])) { throw new \InvalidArgumentException('Invalid source.'); } }
        $name = trim((string) ($input['name'] ?? '')); $url = trim((string) ($input['feed_url'] ?? ''));
        $authorId = filter_var($input['author_id'] ?? 0, FILTER_VALIDATE_INT);
        $author = $authorId !== false && $authorId > 0 ? Connection::find('users', ['id' => $authorId]) : null;
        $interval = filter_var($input['interval_minutes'] ?? 60, FILTER_VALIDATE_INT);
        $status = (string) ($input['publication_status'] ?? 'hidden');
        if ($name === '' || mb_strlen($name) > 120 || strlen($url) > 2048 || !\TinyCat\Http\Client::isSafeUrl($url) || $author === null || $author['status'] !== 'active' || !\TinyCat\Core\Identity\Roles::isAdmin($author) || $interval === false || $interval < 5 || $interval > 43200 || !in_array($status, ['hidden', 'published'], true)) { throw new \InvalidArgumentException('Invalid source.'); }
        $data = ['name' => $name, 'feed_url' => $url, 'feed_hash' => hash('sha256', \TinyCat\Core\Feeds\SourceUrl::normalize($url)), 'author_id' => (int) $author['id'], 'interval_minutes' => $interval, 'publication_status' => $status,
            'enabled' => (int) filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN), 'import_images' => (int) filter_var($input['import_images'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        return Transactions::run(static function () use ($id, $data): int {
            $old = $id > 0 ? Connection::one(Connection::lockingSelect('SELECT * FROM blog_sources WHERE id = ?'), [$id]) : null;
            if ($id > 0 && $old === null) { throw new \DomainException('Source not found.', 404); }
            if (Connection::value('SELECT id FROM blog_sources WHERE feed_hash = ? AND id <> ?', [$data['feed_hash'], $id]) !== null) { throw new \InvalidArgumentException('Source already exists.'); }
            if ($old !== null) {
                if (!hash_equals((string) $old['feed_hash'], $data['feed_hash'])) { throw new \InvalidArgumentException('Create a new source to change its feed URL.'); }
                Connection::update('blog_sources', $data + ['revision' => (int) $old['revision'] + 1, 'next_run_at' => null], ['id' => $id]);
                return $id;
            }
            return (int) Connection::insert('blog_sources', $data + ['created_at' => Dates::dateDb()]);
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function preview(array $input): array
    {
        $url = is_string($input['feed_url'] ?? null) ? trim($input['feed_url']) : '';
        $body = is_string($input['document'] ?? null) ? $input['document'] : '';
        if (strlen($url) > 2048 || strlen($body) > 2097152 || ($url !== '' && !\TinyCat\Http\Client::isSafeUrl($url)) || ($url === '' && $body === '')) { throw new \InvalidArgumentException('Invalid feed.'); }
        $document = \TinyCat\Http\Session::withoutLock(static fn (): array => $body !== '' ? FeedParser::document($body, $url) : FeedParser::inspect($url));
        $items = [];
        foreach (array_slice(array_reverse($document['items']), 0, 3) as $item) {
            $entry = self::pipeline()->preview([], $item);
            $rejection = $entry->rejection;
            $items[] = $entry->data + ['rejection' => $rejection !== null ? ['code' => $rejection['code'], 'params' => $rejection['params']] : null];
        }
        return ['format' => $document['format'], 'title' => $document['title'], 'item_count' => count($document['items']), 'items' => $items];
    }
}
