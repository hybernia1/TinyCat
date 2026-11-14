<?php
declare(strict_types=1);

namespace TinyCat\Social\Automation;

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

    private function __construct()
    {
    }

    /**
     * @return array<string, array{runner: callable(array<string, mixed>): array<string, mixed>, options: array<string, int>, admin: array<string, string>}>
     */
    public static function scheduledTasks(): array
    {
        return ['feeds' => self::scheduledTask()->definition()];
    }

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
            ], order: 10, modeOptions: ['admin' => ['source_id']],
            normalize: self::normalizeTaskOptions(...),
            healthAge: static fn (): ?int => (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM bot_sources WHERE enabled = 1') > 0 ? 1800 : null,
            detail: RunDetails::present(...),
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
        return \TinyCat\Core\Tasks\Tasks::result(
            ($summary['error'] ?? 0) > 0 ? 'failed' : 'completed',
            count($results), $imported,
            details: ['summary' => $summary, 'skipped_items' => $skipped],
            error: $error, results: $results
        );
    }

    public static function feedSourceHash(string $url): string
    {
        $url = \TinyCat\Social\Content\FeedImport::normalizeFeedUrl($url);
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

    public static function defaultSourceTemplate(): string
    {
        return \TinyCat\Social\Content\FeedImport::defaultSourceTemplate();
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
            'error' => $error !== '' ? \TinyCat\Core\Feeds\FeedParser::text($error, 500) : null,
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
        return \TinyCat\Social\Content\FeedImport::renderPost($source, $item);
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
        return (new \TinyCat\Core\Feeds\FeedPipeline(new FeedStore(), new FeedPublication()))->run($source, $force, $schedulerRunId);
    }

}
