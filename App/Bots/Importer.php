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
        foreach ($results as $result) {
            $status = (string) ($result['status'] ?? 'unknown');
            $summary[$status] = (int) ($summary[$status] ?? 0) + 1;
            if ($error === '' && !empty($result['error'])) { $error = (string) $result['error']; }
            foreach ((array) ($result['skipped_items'] ?? []) as $item) {
                $skipped[] = ['source_id' => (int) $result['source_id']] + $item;
            }
        }
        return \TinyCat\Scheduler\Tasks::result(
            ($summary['error'] ?? 0) > 0 ? 'failed' : 'completed',
            count($results), (int) ($summary['posted'] ?? 0),
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

    /** @param list<array<mixed>> $preparedLinks */
    private static function persistFeedPost(int $sourceId, int $botUserId, string $feedHash, string $itemHash, \TinyCat\Status\PostCreation $draft, array $preparedLinks, int $runId, int $itemsSeen): int
    {
        $publishedAt = \TinyCat\Support\Dates::dateDb();
        return \TinyCat\Database\Transactions::run(static function () use ($sourceId, $botUserId, $feedHash, $itemHash, $draft, $preparedLinks, $runId, $itemsSeen, $publishedAt): int {
            self::recordFeedHistory($botUserId, $feedHash, $itemHash, $publishedAt);
            $contentId = \TinyCat\Status\PostCreation::create($botUserId, $draft, $preparedLinks);
            \TinyCat\Database\Connection::update('bot_sources', ['last_imported_at' => $publishedAt], ['id' => $sourceId]);
            self::finishSourceRun($runId, 'posted', $itemsSeen);
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
        return "{{title}}\n\n{{description}}\n\n{{url}}";
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findSource(int $id): ?array
    {
        return $id > 0
            ? \TinyCat\Database\Connection::one(
                'SELECT bs.*, u.username
                 FROM bot_sources bs
                 LEFT JOIN users u ON u.id = bs.bot_user_id AND u.role = ?
                 WHERE bs.id = ?
                 LIMIT 1',
                ['bot', $id]
            )
            : null;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public static function sources(?int $botUserId = null, ?string $order = null): array
    {
        $sql = 'SELECT bs.*, u.username FROM bot_sources bs INNER JOIN users u ON u.id = bs.bot_user_id WHERE u.role = ?';
        $params = ['bot'];

        if ($botUserId !== null && $botUserId > 0) {
            $sql .= ' AND bs.bot_user_id = ?';
            $params[] = $botUserId;
        }

        return \TinyCat\Database\Connection::all($sql . ' ORDER BY ' . ($order ?? 'u.username ASC, bs.name ASC, bs.id ASC'), $params);
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
            'interval_minutes' => (int) ($source['interval_minutes'] ?? 60),
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
    private static function renderPost(array $source, array $item): string
    {
        $categories = array_values(array_map(static fn (mixed $category): string => (string) $category, (array) ($item['categories'] ?? [])));
        $values = [
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
            $response = \TinyCat\Http\Session::withoutLock(static fn (): ?array => \TinyCat\Http\Client::get((string) ($source['feed_url'] ?? ''), 2097152, [
                'accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml', 'timeout' => 10,
            ]));
            if ($response === null) {
                throw new RuntimeException('RSS feed could not be downloaded.');
            }

            $items = \TinyCat\Bots\FeedParser::parse($response['body'], $response['url']);
            $itemsSeen = count($items);
            if ($items === []) {
                throw new RuntimeException('RSS feed contains no usable items.');
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

        try {
            $itemHashes = array_map(static fn (array $item): string => hash('sha256', (string) ($item['guid'] ?? '')), $items);
            $historyItemHashes = self::feedHistoryHashes($botUserId, $feedHash, $itemHashes);

            foreach ($items as $item) {
                $hash = hash('sha256', (string) ($item['guid'] ?? ''));
                if (isset($historyItemHashes[$hash])) { continue; }

                $draft = \TinyCat\Status\PostCreation::fromText(self::renderPost($source, $item));
                $rejection = $draft->rejection;
                if ($rejection === null && $draft->body === '') {
                    $rejection = ['code' => 'status_required', 'params' => []];
                }
                if ($rejection !== null) {
                    $skippedItems[] = ['item_hash' => $hash, 'code' => $rejection['code'], 'params' => $rejection['params']];
                    // Suppress repeated GUIDs within this run, without persisting a claim.
                    $historyItemHashes[$hash] = true;
                    continue;
                }

                $feedLink = \TinyCat\Status\LinkParser::fromRaw((string) ($item['url'] ?? ''));
                $preparedLinks = \TinyCat\Status\Links::prepareLinks(
                    $draft->links,
                    [
                        'url_hash' => (string) ($feedLink['url_hash'] ?? ''),
                        'title' => (string) ($item['title'] ?? ''), 'description' => (string) ($item['description'] ?? ''),
                        'image_urls' => array_values(array_filter((array) ($item['image_urls'] ?? [$item['image_url'] ?? '']), is_string(...))),
                    ]
                );
                $contentId = self::persistFeedPost($sourceId, $botUserId, $feedHash, $hash, $draft, $preparedLinks, $runId, $itemsSeen);
                return ['source_id' => $sourceId, 'status' => 'posted', 'content_id' => $contentId, 'skipped_items' => $skippedItems];
            }

            self::finishSourceRun($runId, 'current', $itemsSeen);
            return ['source_id' => $sourceId, 'status' => 'current', 'skipped_items' => $skippedItems];
        } catch (Throwable $exception) {
            return self::sourceFailure($sourceId, $runId, $itemsSeen, $exception) + ['skipped_items' => $skippedItems];
        }
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
