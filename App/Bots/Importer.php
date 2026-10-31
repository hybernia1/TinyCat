<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use Throwable;
use RuntimeException;
use PDOException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Importer
{
    public const DEFAULT_PUBLICATION_INTERVAL = 30;
    private const FEED_HISTORY_KEEP = 500;

    private function __construct()
    {
    }

    /**
     * @return array<string, array{runner: callable(array<string, mixed>): array<string, mixed>, options: array<string, int>, admin: array<string, string>}>
     */
    public static function scheduledTasks(): array
    {
        return [
            'feeds' => [
                'runner' => static fn (array $context): array => self::runScheduledTask($context),
                'options' => ['bot_limit' => 20],
                'admin' => [
                    'icon' => 'rss',
                    'title' => 'cron.tasks.feeds',
                    'help' => 'cron.tasks.feeds_help',
                    'schedule' => 'cron.tasks.feeds_schedule',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredTables(): array
    {
        return [
            'bot_sources',
            'bot_feed_history',
            'bot_source_posts',
            'bot_source_runs',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runScheduledTask(array $context): array
    {
        $limit = (int) ($context['options']['bot_limit'] ?? 20);
        $runId = (int) ($context['scheduler_run_id'] ?? 0);
        $sourceId = (int) ($context['options']['source_id'] ?? 0);
        if ($sourceId > 0) {
            $source = \TinyCat\Database\Connection::one(
                'SELECT bs.*, u.username, u.status AS user_status FROM bot_sources bs
                 INNER JOIN users u ON u.id = bs.bot_user_id
                 WHERE bs.id = ? AND bs.enabled = 1 AND u.role = ? AND u.status = ?',
                [$sourceId, 'bot', 'active']
            );
            $results = [$source !== null ? self::runSource($source, true, $runId) : ['source_id' => $sourceId, 'status' => 'skipped']];
        } else {
            $results = self::runDueSources($limit, $runId);
        }
        return self::scheduledResult($results);
    }

    /** @param list<array<string, mixed>> $results
     * @return array<string, mixed> */
    private static function scheduledResult(array $results): array
    {
        $summary = [];
        $skipped = [];
        $error = '';
        $imported = 0;
        foreach ($results as $result) {
            $status = (string) ($result['status'] ?? 'unknown');
            $summary[$status] = (int) ($summary[$status] ?? 0) + 1;
            $imported += (int) ($result['imported_count'] ?? ($status === 'posted' ? 1 : 0));
            if ($error === '' && !empty($result['error'])) { $error = (string) $result['error']; }
            foreach ((array) ($result['skipped_items'] ?? []) as $item) {
                $skipped[] = ['source_id' => (int) $result['source_id']] + $item;
            }
        }
        return \TinyCat\Scheduler\Tasks::result(
            ($summary['error'] ?? 0) > 0 ? 'failed' : 'completed',
            count($results), $imported,
            details: ['summary' => $summary, 'skipped_items' => $skipped],
            error: $error, results: $results
        );
    }

    private static function normalizeFeedUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $portNumber = isset($parts['port']) ? (int) $parts['port'] : 0;
        $isDefaultPort = ($scheme === 'http' && $portNumber === 80)
            || ($scheme === 'https' && $portNumber === 443);
        $port = $portNumber > 0 && !$isDefaultPort ? ':' . $portNumber : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    public static function feedSourceHash(string $url): string
    {
        $url = self::normalizeFeedUrl($url);
        return $url !== '' ? hash('sha256', $url) : '';
    }

    public static function sourceDuplicateExists(string $feedUrl, int $excludeId = 0): bool
    {
        $feedHash = self::feedSourceHash($feedUrl);

        if ($feedHash === '') {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM bot_sources WHERE feed_hash = ?';
        $params = [$feedHash];

        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        return (int) \TinyCat\Database\Connection::value($sql, $params) > 0;
    }

    public static function isDuplicateSourceException(Throwable $exception): bool
    {
        return $exception instanceof PDOException
            && (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * @param list<string> $itemHashes
     * @return array<string, true>
     */
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

    /** @param list<array<mixed>> $preparedLinks
     * @param array<mixed>|null $image */
    private static function persistFeedPost(int $sourceId, int $botUserId, string $feedHash, string $itemHash, \TinyCat\Status\PostCreation $draft, array $preparedLinks, int $publicationInterval = self::DEFAULT_PUBLICATION_INTERVAL, ?array $image = null): int
    {
        $publishedAt = \TinyCat\Support\Dates::dateDb();
        return \TinyCat\Database\Transactions::run(static function () use ($sourceId, $botUserId, $feedHash, $itemHash, $draft, $preparedLinks, $publishedAt, $publicationInterval, $image): int {
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
                'SELECT MAX(c.published_at) FROM bot_source_posts bsp INNER JOIN content c ON c.id = bsp.content_id WHERE bsp.source_id = ?',
                [$sourceId]
            );
            $nextPublication = $lastPublication !== ''
                ? \TinyCat\Support\Dates::dateDb((new \DateTimeImmutable($lastPublication, \TinyCat\Support\Dates::timezone()))->modify('+' . $publicationInterval . ' minutes'))
                : $publishedAt;
            $publicationTime = max($publishedAt, $nextPublication);
            $contentId = \TinyCat\Status\PostCreation::create($botUserId, $draft, $preparedLinks, $image, publishedAt: $publicationTime);
            \TinyCat\Database\Connection::insert('bot_source_posts', ['source_id' => $sourceId, 'content_id' => $contentId]);
            \TinyCat\Database\Connection::update('bot_sources', ['last_imported_at' => $publishedAt], ['id' => $sourceId]);
            return $contentId;
        });
    }

    private static function pruneFeedHistory(int $botUserId, string $feedHash, int $keep = self::FEED_HISTORY_KEEP): void
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

    public static function defaultSourceTemplate(): string
    {
        return "{{title}}\n\n{{description}}\n\n{{url}}\n\n#{categories}";
    }

    /** Legacy or incomplete source payloads receive the same default as the schema.
     * @param array<string, mixed> $source */
    public static function publicationInterval(array $source): int
    {
        $interval = (int) ($source['publication_interval_minutes'] ?? 0);
        return $interval >= 5 && $interval <= 43200 ? $interval : self::DEFAULT_PUBLICATION_INTERVAL;
    }

    private static function sourceSelect(): string
    {
        return 'SELECT bs.*, u.username,
            (SELECT COUNT(*) FROM bot_source_posts bsp INNER JOIN content c ON c.id = bsp.content_id WHERE bsp.source_id = bs.id AND c.published_at <= ?) AS published_count,
            (SELECT COUNT(*) FROM bot_source_posts bsp INNER JOIN content c ON c.id = bsp.content_id WHERE bsp.source_id = bs.id AND c.published_at > ?) AS queued_count
            FROM bot_sources bs';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findSource(int $id): ?array
    {
        $now = \TinyCat\Support\Dates::dateDb();
        return $id > 0
            ? \TinyCat\Database\Connection::one(
                self::sourceSelect() . '
                 LEFT JOIN users u ON u.id = bs.bot_user_id AND u.role = ?
                 WHERE bs.id = ?
                 LIMIT 1',
                [$now, $now, 'bot', $id]
            )
            : null;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public static function sources(?int $botUserId = null, ?string $order = null): array
    {
        return self::sourcesQuery($botUserId)->order($order ?? 'u.username ASC, bs.name ASC, bs.id ASC')->all();
    }

    public static function sourcesQuery(?int $botUserId = null): \TinyCat\Database\Query
    {
        $now = \TinyCat\Support\Dates::dateDb();
        $query = new \TinyCat\Database\Query(self::sourceSelect() . ' INNER JOIN users u ON u.id = bs.bot_user_id', [$now, $now]);
        $query->where('u.role = ?', 'bot');
        if ($botUserId !== null && $botUserId > 0) { $query->where('bs.bot_user_id = ?', $botUserId); }
        return $query;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function sourceResource(array $source): array
    {
        return [
            'id' => (int) ($source['id'] ?? 0),
            'bot_user_id' => (int) ($source['bot_user_id'] ?? 0),
            'bot_username' => (string) ($source['username'] ?? ''),
            'name' => (string) ($source['name'] ?? ''),
            'feed_url' => (string) ($source['feed_url'] ?? ''),
            'published_count' => (int) ($source['published_count'] ?? 0),
            'queued_count' => (int) ($source['queued_count'] ?? 0),
            'post_count' => (int) ($source['published_count'] ?? 0) + (int) ($source['queued_count'] ?? 0),
            'published_url' => '/admin/bots/' . (int) ($source['bot_user_id'] ?? 0) . '?source=' . (int) ($source['id'] ?? 0) . '&publication=published',
            'queue_url' => '/admin/bots/queue?source=' . (int) ($source['id'] ?? 0),
            'interval_minutes' => (int) ($source['interval_minutes'] ?? 60),
            'publication_interval_minutes' => self::publicationInterval($source),
            'post_template' => (string) ($source['post_template'] ?? ''),
            'enabled' => (bool) ($source['enabled'] ?? false),
            'last_checked_at' => !empty($source['last_checked_at']) ? \TinyCat\Support\Dates::dateIso($source['last_checked_at']) : '',
            'last_imported_at' => !empty($source['last_imported_at']) ? \TinyCat\Support\Dates::dateIso($source['last_imported_at']) : '',
            'next_run_at' => !empty($source['next_run_at']) ? \TinyCat\Support\Dates::dateIso($source['next_run_at']) : '',
            'last_error' => (string) ($source['last_error'] ?? ''),
        ];
    }

    private static function createSourceRun(int $sourceId, int $schedulerRunId): int
    {
        // Both scheduled and manual imports own the shared feeds lock here.
        \TinyCat\Database\Connection::exec("UPDATE bot_source_runs SET status = 'interrupted' WHERE source_id = ? AND status = 'running'", [$sourceId]);
        return (int) \TinyCat\Database\Connection::insert('bot_source_runs', [
            'source_id' => $sourceId, 'scheduler_run_id' => $schedulerRunId > 0 ? $schedulerRunId : null,
            'status' => 'running', 'started_at' => \TinyCat\Support\Dates::dateDb(),
        ]);
    }

    private static function finishSourceRun(int $runId, string $status, int $itemsSeen = 0, string $error = ''): void
    {
        if ($runId < 1) { return; }
        \TinyCat\Database\Connection::update('bot_source_runs', [
            'status' => $status, 'items_seen' => max(0, $itemsSeen),
            'error' => $error !== '' ? \TinyCat\Bots\FeedParser::text($error, 500) : null,
        ], ['id' => $runId]);
    }

    public static function deleteSource(int $sourceId): void
    {
        if ($sourceId < 1) {
            return;
        }

        \TinyCat\Database\Transactions::run(static function () use ($sourceId): void {
            \TinyCat\Database\Connection::delete('bot_source_runs', ['source_id' => $sourceId]);
            \TinyCat\Database\Connection::delete('bot_sources', ['id' => $sourceId]);
        });
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $item
     */
    public static function renderPost(array $source, array $item): string
    {
        $categories = array_values(array_map(static fn (mixed $category): string => (string) $category, (array) ($item['categories'] ?? [])));
        $values = [
            '{{image}}' => '',
            '{{title}}' => (string) ($item['title'] ?? ''),
            '{{description}}' => (string) ($item['description'] ?? ''),
            '{{url}}' => (string) ($item['url'] ?? ''),
            '{{author}}' => (string) ($item['author'] ?? ''),
            '{{source}}' => (string) ($source['name'] ?? ''),
            '{{categories}}' => implode(', ', $categories),
            '#{categories}' => self::feedCategoryTags($categories),
        ];
        return trim(preg_replace("/\n{3,}/", "\n\n", strtr((string) ($source['post_template'] ?? self::defaultSourceTemplate()), $values)) ?? '');
    }

    /**
     * @param list<string> $categories
     */
    private static function feedCategoryTags(array $categories): string
    {
        $tags = [];

        foreach ($categories as $category) {
            $tag = \TinyCat\Status\Tags::status_tag_normalize((string) $category);
            if ($tag !== '') {
                $tags[$tag] = '#' . $tag;
            }
        }

        return implode(' ', array_values($tags));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function runDueSources(int $limit = 10, int $schedulerRunId = 0): array
    {
        $limit = max(1, min(100, $limit));
        $sources = \TinyCat\Database\Connection::all(
            'SELECT bs.*, u.username, u.status AS user_status
                FROM bot_sources bs
                INNER JOIN users u ON u.id = bs.bot_user_id
                WHERE bs.enabled = 1 AND u.role = ? AND u.status = ?
                    AND (bs.next_run_at IS NULL OR bs.next_run_at <= ?)
                ORDER BY COALESCE(bs.next_run_at, bs.created_at) ASC, bs.id ASC
                LIMIT ' . $limit,
            ['bot', 'active', \TinyCat\Support\Dates::dateDb()]
        );
        $results = [];

        foreach ($sources as $source) {
            $results[] = self::runSource($source, false, $schedulerRunId);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function runSource(array $source, bool $force = false, int $schedulerRunId = 0): array
    {
        return \TinyCat\Http\Client::withConnectionReuse(static fn (): array => self::runSourceBatch($source, $force, $schedulerRunId));
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed> */
    private static function runSourceBatch(array $source, bool $force, int $schedulerRunId): array
    {
        $sourceId = (int) ($source['id'] ?? 0);
        $interval = max(5, min(43200, (int) ($source['interval_minutes'] ?? 60)));
        $now = \TinyCat\Support\Dates::dateDb();
        $next = date('Y-m-d H:i:s', time() + $interval * 60);
        $claimed = $force
            ? \TinyCat\Database\Connection::exec(
                'UPDATE bot_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND enabled = 1',
                [$next, $now, $sourceId]
            )
            : \TinyCat\Database\Connection::exec(
                'UPDATE bot_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND enabled = 1 AND (next_run_at IS NULL OR next_run_at <= ?)',
                [$next, $now, $sourceId, $now]
            );

        if ($sourceId < 1 || $claimed < 1) {
            return ['source_id' => $sourceId, 'status' => 'skipped'];
        }

        $runId = self::createSourceRun($sourceId, $schedulerRunId);
        $itemsSeen = 0;

        try {
            $items = \TinyCat\Http\Session::withoutLock(static fn (): array => FeedParser::fetch((string) ($source['feed_url'] ?? '')));
            $itemsSeen = count($items);
            if ($items === []) {
                throw new RuntimeException('Source contains no usable items.');
            }

            return self::importItems($source, $items, $runId);
        } catch (Throwable $exception) {
            return self::sourceFailure($sourceId, $runId, $itemsSeen, $exception);
        }
    }

    /**
     * Rejections are diagnostic only: never claim them in successful feed history.
     * Re-evaluate on later runs so policy or template changes can admit the item.
     *
     * @param array<string, mixed> $source
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function importItems(array $source, array $items, int $runId): array
    {
        $sourceId = (int) ($source['id'] ?? 0);
        $botUserId = (int) ($source['bot_user_id'] ?? 0);
        $feedHash = self::feedSourceHash((string) ($source['feed_url'] ?? ''));
        $itemsSeen = count($items);
        $skippedItems = [];
        $contentIds = [];

        try {
            $itemHashes = array_map(static fn (array $item): string => hash('sha256', (string) ($item['guid'] ?? '')), $items);
            $historyItemHashes = self::feedHistoryHashes($botUserId, $feedHash, $itemHashes);
            $interval = self::publicationInterval($source);
            if (str_contains((string) ($source['post_template'] ?? ''), '{{image}}') && \TinyCat\Status\Images::status_images_enabled()) {
                $items = \TinyCat\Http\Session::withoutLock(static fn (): array => WordPressFeed::images(array_values(array_filter($items,
                    static fn (array $item): bool => !isset($historyItemHashes[hash('sha256', (string) ($item['guid'] ?? ''))])))));
            }

            foreach ($items as $item) {
                $hash = hash('sha256', (string) ($item['guid'] ?? ''));
                if (isset($historyItemHashes[$hash])) { continue; }

                $draft = \TinyCat\Status\PostCreation::fromText(self::renderPost($source, $item));
                $rejection = $draft->rejection;
                if ($rejection === null && $draft->body === '' && !str_contains((string) ($source['post_template'] ?? ''), '{{image}}')) {
                    $rejection = ['code' => 'status_required', 'params' => []];
                }
                if ($rejection !== null) {
                    $skippedItems[] = ['item_hash' => $hash, 'code' => $rejection['code'], 'params' => $rejection['params']];
                    // Suppress repeated GUIDs within this run, without persisting a claim.
                    $historyItemHashes[$hash] = true;
                    continue;
                }

                $image = \TinyCat\Http\Session::withoutLock(static fn (): ?array => self::prepareImage($source, $item));
                if ($draft->body === '' && $image === null) {
                    $skippedItems[] = ['item_hash' => $hash, 'code' => 'status_required', 'params' => []];
                    $historyItemHashes[$hash] = true;
                    continue;
                }
                try {
                    $feedLink = \TinyCat\Status\LinkParser::fromRaw((string) ($item['url'] ?? ''));
                    $preparedLinks = \TinyCat\Status\Links::prepareLinks(
                        $draft->links,
                        [
                            'url_hash' => (string) ($feedLink['url_hash'] ?? ''),
                            'title' => (string) ($item['title'] ?? ''), 'description' => (string) ($item['description'] ?? ''),
                            'audio_url' => (string) ($item['audio_url'] ?? ''),
                            'image_urls' => array_values(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), is_string(...))),
                        ]
                    );
                    $contentId = self::persistFeedPost($sourceId, $botUserId, $feedHash, $hash, $draft, $preparedLinks, $interval, $image);
                } catch (Throwable $exception) {
                    if ($image !== null) { \TinyCat\Status\ImageStorage::delete((string) $image['path']); }
                    throw $exception;
                }
                $contentIds[] = $contentId;
                $historyItemHashes[$hash] = true;
            }

            $status = $contentIds !== [] ? 'posted' : 'current';
            self::finishSourceRun($runId, $status, $itemsSeen);
            return ['source_id' => $sourceId, 'status' => $status, 'content_id' => $contentIds[0] ?? 0, 'content_ids' => $contentIds, 'imported_count' => count($contentIds), 'skipped_items' => $skippedItems];
        } catch (Throwable $exception) {
            return self::sourceFailure($sourceId, $runId, $itemsSeen, $exception) + ['imported_count' => count($contentIds), 'content_ids' => $contentIds, 'skipped_items' => $skippedItems];
        }
    }

    /** @param array<string, mixed> $source
     * @param array<string, mixed> $item
     * @return array<mixed>|null */
    private static function prepareImage(array $source, array $item): ?array
    {
        if (!str_contains((string) ($source['post_template'] ?? ''), '{{image}}') || !\TinyCat\Status\Images::status_images_enabled()) { return null; }
        $urls = array_slice(array_values(array_unique(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), static fn (mixed $url): bool => is_string($url) && $url !== ''))), 0, 8);
        $failure = null;
        foreach ($urls as $url) {
            try { return \TinyCat\Status\ImageStorage::fromRemote($url); }
            catch (RuntimeException|\LengthException $exception) { $failure = $exception; }
        }
        if ($failure !== null) { throw $failure; }
        return null;
    }

    /** @return array<string, mixed> */
    private static function sourceFailure(int $sourceId, int $runId, int $itemsSeen, Throwable $exception): array
    {
        $error = \TinyCat\Bots\FeedParser::text($exception->getMessage(), 500);
        \TinyCat\Database\Connection::update('bot_sources', ['last_error' => $error], ['id' => $sourceId]);
        self::finishSourceRun($runId, 'error', $itemsSeen, $error);
        return ['source_id' => $sourceId, 'status' => 'error', 'error' => $error];
    }
}
