<?php
declare(strict_types=1);

namespace TinyCat\Social\Bots;

use Throwable;
use RuntimeException;
use PDOException;
use TinyCat\Core\Feeds\FeedParser;
use TinyCat\Social\Posts\LinkParser;
use TinyCat\Http\{Client, Session};

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Importer
{
    public const DEFAULT_PUBLICATION_INTERVAL = 30;


    public static function scheduledTask(): \TinyCat\Core\Modules\ScheduledTask
    {
        return new \TinyCat\Core\Modules\ScheduledTask(
            'feeds', static fn (array $context): array => self::runScheduledTask($context),
            options: ['bot_limit' => 20],
            admin: [
                    'icon' => 'rss',
                    'title' => 'cron.tasks.feeds',
                    'help' => 'cron.tasks.feeds_help',
                    'schedule' => 'cron.tasks.feeds_schedule',
                    'cadence' => 'cron.cadence.feeds',
            ], order: 10, modeOptions: ['admin' => ['source_id']],
            normalize: self::normalizeTaskOptions(...),
            healthAge: static fn (): ?int => (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM bot_sources WHERE enabled = 1') > 0 ? 1800 : null,
            detail: static fn (array $run): array => Resource::runDetails($run),
            batchOption: 'bot_limit', optionLabels: ['bot_limit' => 'cron.options.bot_limit', 'source_id' => 'cron.options.source_id'],
        );
    }

    /** @param array<string, int> $options
     * @return array<string, int> */
    private static function normalizeTaskOptions(array $options): array
    {
        $options['bot_limit'] = max(1, min(100, $options['bot_limit']));
        if (isset($options['source_id'])) {
            $sourceId = filter_var($options['source_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($sourceId === false) { throw new \InvalidArgumentException('Invalid feed source ID.'); }
            return ['bot_limit' => 1, 'source_id' => $sourceId];
        }
        return $options;
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
        $hasMore = $sourceId === 0 && \TinyCat\Database\Connection::value(
            'SELECT 1 FROM bot_sources bs INNER JOIN users u ON u.id = bs.bot_user_id WHERE bs.enabled = 1 AND u.role = ? AND u.status = ? AND (bs.next_run_at IS NULL OR bs.next_run_at <= ?) LIMIT 1',
            ['bot', 'active', \TinyCat\Support\Dates::dateDb()]
        ) !== null;
        return self::scheduledResult($results, $hasMore);
    }

    /** @param list<array<string, mixed>> $results
     * @return array<string, mixed> */
    private static function scheduledResult(array $results, bool $hasMore = false): array
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
        return \TinyCat\Core\Tasks\Tasks::result(
            ($summary['error'] ?? 0) > 0 ? 'failed' : ($hasMore ? 'has_more' : ($results === [] ? 'not_due' : 'completed')),
            count($results), $imported, $hasMore,
            details: ['summary' => $summary, 'skipped_items' => $skipped],
            error: $error, results: $results
        );
    }

    public static function feedSourceHash(string $url): string
    {
        $url = self::normalizeSourceUrl($url);
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
            (SELECT COUNT(*) FROM bot_source_posts bsp INNER JOIN social_posts c ON c.id = bsp.content_id WHERE bsp.source_id = bs.id AND c.published_at <= ?) AS published_count,
            (SELECT COUNT(*) FROM bot_source_posts bsp INNER JOIN social_posts c ON c.id = bsp.content_id WHERE bsp.source_id = bs.id AND c.published_at > ?) AS queued_count
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

    public static function createSourceRun(int $sourceId, int $schedulerRunId): int
    {
        // Both scheduled and manual imports own the shared feeds lock here.
        \TinyCat\Database\Connection::exec("UPDATE bot_source_runs SET status = 'interrupted' WHERE source_id = ? AND status = 'running'", [$sourceId]);
        return (int) \TinyCat\Database\Connection::insert('bot_source_runs', [
            'source_id' => $sourceId, 'scheduler_run_id' => $schedulerRunId > 0 ? $schedulerRunId : null,
            'status' => 'running', 'started_at' => \TinyCat\Support\Dates::dateDb(),
        ]);
    }

    public static function finishSourceRun(int $runId, string $status, int $itemsSeen = 0, string $error = ''): void
    {
        if ($runId < 1) { return; }
        \TinyCat\Database\Connection::update('bot_source_runs', [
            'status' => $status, 'items_seen' => max(0, $itemsSeen),
            'error' => $error !== '' ? \TinyCat\Core\Text\Text::htmlText($error, 500) : null,
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
        return (new self())->run($source, $force, $schedulerRunId);
    }

    private static function normalizeSourceUrl(string $url): string
    {
        $parts = parse_url(trim($url)); if (!is_array($parts)) { return ''; }
        $scheme = strtolower((string) ($parts['scheme'] ?? '')); $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') { return ''; }
        $port = (int) ($parts['port'] ?? 0); $default = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $path = (string) ($parts['path'] ?? '/'); $path = $path === '/' ? '/' : rtrim($path, '/');
        return $scheme . '://' . $host . ($port > 0 && !$default ? ':' . $port : '') . $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }
    public function __construct(private FeedStore $store = new FeedStore(), private FeedPublication $target = new FeedPublication()) {}

    /** @param array<string, mixed> $source
     * @return array<string, mixed> */
    public function run(array $source, bool $force = false, int $schedulerRunId = 0): array
    {
        return Client::withConnectionReuse(function () use ($source, $force, $schedulerRunId): array {
            if (!$this->store->claim($source, $force)) { return ['source_id' => (int) $source['id'], 'status' => 'skipped']; }
            $runId = $this->store->start($source, $schedulerRunId);
            try {
                $items = Session::withoutLock(static fn (): array => FeedParser::fetch((string) $source['feed_url']));
                if ($items === []) { throw new \RuntimeException('Source contains no usable items.'); }
            } catch (\Throwable $error) { return $this->complete($source, $runId, 0, [], [], $error); }
            return $this->import($source, $items, $runId);
        });
    }

    /** @param array<string, mixed> $source
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed> */
    public function import(array $source, array $items, int $runId = 0): array
    {
        $seen = count($items); $ids = []; $skipped = [];
        try {
            $history = $this->store->history($source, array_map(self::hash(...), $items));
            $items = Session::withoutLock(fn (): array => $this->target->enrich($source, array_values(array_filter($items, static fn (array $item): bool => !isset($history[self::hash($item)])))));
            foreach ($items as $item) {
                $hash = self::hash($item);
                if (isset($history[$hash])) { continue; }
                $history[$hash] = true;
                $link = LinkParser::fromRaw((string) ($item['url'] ?? ''));
                if ($this->store->duplicate($source, $link)) { continue; }
                $entry = $this->target->prepare($source, $item);
                $rejection = $entry->rejection;
                if ($rejection !== null) { $skipped[] = ['item_hash' => $hash, 'code' => $rejection['code'], 'params' => $rejection['params']]; continue; }
                $attachments = Session::withoutLock(fn (): array => $this->target->attachments($source, $item, $entry));
                $image = $attachments['image'];
                if ($entry->body === '' && $image === null) { $skipped[] = ['item_hash' => $hash, 'code' => 'status_required', 'params' => []]; continue; }
                try {
                    $links = $attachments['links'];
                    $ids[] = $this->store->commit($source, $hash, fn (string $at): int => $this->target->publish($source, $entry, $links, $image, $at));
                } catch (\Throwable $error) {
                    if ($image !== null) { $this->target->discard($image); }
                    throw $error;
                }
            }
        } catch (\Throwable $error) { return $this->complete($source, $runId, $seen, $ids, $skipped, $error); }
        return $this->complete($source, $runId, $seen, $ids, $skipped);
    }

    /** @param array<string, mixed> $item */
    private static function hash(array $item): string { return hash('sha256', (string) ($item['guid'] ?? '')); }

    /** @param array<string, mixed> $source
     * @param list<int> $ids
     * @param list<array<string, mixed>> $skipped
     * @return array<string, mixed> */
    private function complete(array $source, int $runId, int $seen, array $ids, array $skipped, ?\Throwable $error = null): array
    {
        if ($error !== null) { \TinyCat\Core\Runtime\Failure::report($error); }
        $status = $error !== null ? 'error' : ($ids !== [] ? 'posted' : 'current');
        $message = $error !== null ? \TinyCat\Core\Text\Text::htmlText(\TinyCat\Core\Runtime\Failure::message($error, 'Feed import failed.'), 500) : '';
        $this->store->finish($source, $runId, $status, $seen, $message);
        return ['source_id' => (int) $source['id'], 'status' => $status, 'content_id' => $ids[0] ?? 0, 'content_ids' => $ids, 'imported_count' => count($ids), 'skipped_items' => $skipped] + ($error !== null ? ['error' => $message] : []);
    }
}
