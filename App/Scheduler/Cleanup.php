<?php
declare(strict_types=1);

namespace TinyCat\Scheduler;

use Throwable;

final class Cleanup
{
    public static function cleanup_batch_size(mixed $value): int
    {
        $size = (int) $value;
        $allowed = [500, 1000, 2500, 5000];

        return in_array($size, $allowed, true) ? $size : 1000;
    }

    /**
     * @return array<mixed>
     */
    public static function cron_cleanup_tasks(): array
    {
        return [
            'orphan_terms',
            'orphan_links',
            'orphan_status_image_files',
            'orphan_link_image_files',
            'orphan_avatar_files',
            'temporary_files',
            'update_files',
            'expired_cache_files',
            'old_action_limits',
            'old_password_reset_tokens',
            'old_read_notifications',
            'old_scheduler_runs',
            'bot_history',
        ];
    }

    /**
     * @param array<mixed> $tasks
     * @return array<mixed>
     */
    public static function cron_cleanup_run(int $batchSize = 500, bool $force = false, ?array $tasks = null): array
    {
        $batchSize = \TinyCat\Scheduler\Cleanup::cleanup_batch_size($batchSize);
        $fullRun = $tasks === null;
        $tasks = $tasks === null
            ? \TinyCat\Scheduler\Cleanup::cron_cleanup_tasks()
            : array_values(array_intersect(\TinyCat\Scheduler\Cleanup::cron_cleanup_tasks(), $tasks));
        $now = time();
        $interval = 3600;
        $lastRun = max(0, (int) \TinyCat\Settings\Store::setting('cron.cleanup_last_run', 0));

        if (!$force && $lastRun > $now - $interval) {
            return [
                'due' => false,
                'last_run' => $lastRun,
                'next_run' => $lastRun + $interval,
                'has_more' => false,
                'results' => [],
            ];
        }

        $results = [];
        if ($fullRun) {
            $nextTask = (string) \TinyCat\Settings\Store::setting('cron.cleanup_next_task', 'update_files');
            $offset = array_search($nextTask, $tasks, true);
            if (is_int($offset)) { $tasks = [...array_slice($tasks, $offset), ...array_slice($tasks, 0, $offset)]; }
        }

        foreach ($tasks as $index => $task) {
            // Advance before work so even a killed PHP worker gives the following task its turn.
            if ($fullRun) { \TinyCat\Settings\Store::setSetting('cron.cleanup_next_task', $tasks[($index + 1) % count($tasks)], 'string', 'cron'); }
            try {
                $results[$task] = \TinyCat\Scheduler\Cleanup::cleanup_task_run($task, $batchSize);
            } catch (Throwable $exception) {
                $results[$task] = [
                    'task' => $task,
                    'changed' => 0,
                    'has_more' => false,
                    'batch_size' => $batchSize,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $hasMore = array_any($results, static fn (array $result): bool => !empty($result['has_more']));
        $hasErrors = array_any($results, static fn (array $result): bool => isset($result['error']));

        if (!$hasMore && !$hasErrors) {
            \TinyCat\Settings\Store::setSetting('cron.cleanup_last_run', $now, 'int', 'cron');
        }

        return [
            'due' => true,
            'last_run' => $lastRun > 0 ? $lastRun : null,
            'next_run' => !$hasMore && !$hasErrors ? $now + $interval : null,
            'has_more' => $hasMore,
            'results' => $results,
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function cleanup_task_run(string $task, int $batchSize): array
    {
        $batchSize = \TinyCat\Scheduler\Cleanup::cleanup_batch_size($batchSize);
        $startedAt = hrtime(true);
        $error = '';
        if (in_array($task, ['orphan_status_image_files', 'orphan_link_image_files', 'orphan_avatar_files', 'temporary_files', 'update_files', 'expired_cache_files', 'bot_history'], true)) {
            $result = match ($task) {
                'orphan_status_image_files' => \TinyCat\Status\ImageStorage::cleanupOrphans($batchSize),
                'orphan_link_image_files' => \TinyCat\Status\Links::cleanupOrphanImages($batchSize),
                'orphan_avatar_files' => \TinyCat\Users\Avatar::cleanupOrphans($batchSize),
                'temporary_files' => FileCleanup::temporary($batchSize),
                'update_files' => \TinyCat\Updates\Cleanup::run($batchSize),
                'bot_history' => \TinyCat\Bots\Cleanup::run($batchSize),
                default => \TinyCat\Support\Cache::cleanupExpired($batchSize),
            };
            $error = (string) ($result['error'] ?? '');
            $changed = $result['changed'];
            $hasMore = !empty($result['has_more']);
        } else {
            $changed = \TinyCat\Scheduler\Cleanup::cleanup_delete($task, $batchSize);
            $hasMore = $changed >= $batchSize && \TinyCat\Scheduler\Cleanup::cleanup_has_rows($task);
        }

        return [
            'task' => $task,
            'changed' => $changed,
            'has_more' => $hasMore,
            'batch_size' => $batchSize,
            'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            'done' => !$hasMore && $error === '',
        ] + ($error !== '' ? ['error' => $error] : []);
    }

    public static function cleanup_has_rows(string $task): bool
    {
        $value = match ($task) {
            'orphan_terms' => \TinyCat\Database\Connection::value(
                'SELECT 1
                    FROM terms t
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM content_tags ct
                        WHERE ct.term_id = t.id
                    )
                    LIMIT 1'
            ),
            'orphan_links' => \TinyCat\Database\Connection::value(
                'SELECT 1
                    FROM links l
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM content_links cl
                        WHERE cl.link_id = l.id
                    )
                    LIMIT 1'
            ),
            'old_action_limits' => \TinyCat\Database\Connection::value(
                'SELECT 1
                    FROM ip_action_limits
                    WHERE bucket_start < ?
                    LIMIT 1',
                [\TinyCat\Support\Dates::dateDb(time() - \TinyCat\Security\RateLimitPolicy::retentionSeconds())]
            ),
            'old_password_reset_tokens' => \TinyCat\Database\Connection::value(
                'SELECT 1
                    FROM password_reset_tokens
                    WHERE used_at IS NOT NULL OR expires_at < ?
                    LIMIT 1',
                [\TinyCat\Support\Dates::dateDb()]
            ),
            'old_scheduler_runs' => \TinyCat\Database\Connection::value('SELECT 1 FROM scheduler_runs WHERE started_at < ? LIMIT 1', [\TinyCat\Support\Dates::dateDb('-30 days')]),
            'old_read_notifications' => \TinyCat\Database\Connection::value(
                'SELECT 1
                    FROM notifications
                    WHERE read_at IS NOT NULL AND read_at < ?
                    LIMIT 1',
                [\TinyCat\Support\Dates::dateDb('-30 days')]
            ),
            default => null,
        };

        return $value !== null && $value !== false;
    }

    public static function cleanup_delete(string $task, int $batchSize): int
    {
        $batchSize = \TinyCat\Scheduler\Cleanup::cleanup_batch_size($batchSize);

        return match ($task) {
            'orphan_terms' => \TinyCat\Scheduler\Cleanup::cleanup_delete_limited(
                'DELETE FROM terms
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM content_tags ct
                        WHERE ct.term_id = terms.id
                    )
                    LIMIT ' . $batchSize
            ),
            'orphan_links' => \TinyCat\Scheduler\Cleanup::cleanup_delete_limited(
                'DELETE FROM links
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM content_links cl
                        WHERE cl.link_id = links.id
                    )
                    LIMIT ' . $batchSize
            ),
            'old_action_limits' => \TinyCat\Scheduler\Cleanup::cleanup_delete_limited(
                'DELETE FROM ip_action_limits
                    WHERE bucket_start < ?
                    LIMIT ' . $batchSize,
                [\TinyCat\Support\Dates::dateDb(time() - \TinyCat\Security\RateLimitPolicy::retentionSeconds())]
            ),
            'old_password_reset_tokens' => \TinyCat\Scheduler\Cleanup::cleanup_delete_limited(
                'DELETE FROM password_reset_tokens
                    WHERE used_at IS NOT NULL OR expires_at < ?
                    LIMIT ' . $batchSize,
                [\TinyCat\Support\Dates::dateDb()]
            ),
            'old_scheduler_runs' => self::cleanup_delete_limited('DELETE FROM scheduler_runs WHERE started_at < ? LIMIT ' . $batchSize, [\TinyCat\Support\Dates::dateDb('-30 days')]),
            'old_read_notifications' => \TinyCat\Scheduler\Cleanup::cleanup_delete_limited(
                'DELETE FROM notifications
                    WHERE read_at IS NOT NULL AND read_at < ?
                    LIMIT ' . $batchSize,
                [\TinyCat\Support\Dates::dateDb('-30 days')]
            ),
            default => 0,
        };
    }

    /**
     * @param array<mixed> $params
     */
    public static function cleanup_delete_limited(string $sql, array $params = []): int
    {
        return \TinyCat\Database\Connection::exec($sql, $params);
    }
}
