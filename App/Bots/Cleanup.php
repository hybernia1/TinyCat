<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use Throwable;

final class Cleanup
{
    /**
     * @return array<string, mixed>
     */
    public static function run(int $batch): array
    {
        $batch = max(1, min(5000, $batch));
        $now = time();
        $errorsBefore = date('Y-m-d H:i:s', $now - 14 * 86400);
        $historyBefore = date('Y-m-d H:i:s', $now - 14 * 86400);
        $results = [];

        try {
            $results['old_errors'] = \TinyCat\Database\Connection::exec(
                'DELETE FROM bot_source_runs WHERE status IN (?, ?) AND started_at < ? LIMIT ' . $batch,
                ['error', 'interrupted', $errorsBefore]
            );
            $results['orphan_history'] = \TinyCat\Database\Connection::exec(
                'DELETE FROM bot_feed_history
                 WHERE created_at < ?
                    AND NOT EXISTS (
                        SELECT 1 FROM bot_sources
                        WHERE bot_sources.bot_user_id = bot_feed_history.bot_user_id
                            AND bot_sources.feed_hash = bot_feed_history.feed_hash
                    )
                 LIMIT ' . $batch,
                [$historyBefore]
            );
            $results['successful_run_cap'] = self::pruneSuccessfulRunsPerSource($batch);
        } catch (Throwable $exception) {
            return [
                'due' => true,
                'changed' => array_sum($results),
                'has_more' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $changed = array_sum($results);
        $hasMore = array_any($results, static fn (int $count): bool => $count >= $batch);
        return [
            'due' => true,
            'changed' => $changed,
            'has_more' => $hasMore,
            'results' => $results,
        ];
    }

    private static function pruneSuccessfulRunsPerSource(int $batch): int
    {
        $changed = 0;
        $keep = 50;

        foreach (\TinyCat\Database\Connection::all('SELECT source_id AS id FROM bot_source_runs WHERE status NOT IN (?, ?, ?) GROUP BY source_id HAVING COUNT(*) > ' . $keep . ' ORDER BY source_id ASC LIMIT ' . $batch, ['error', 'running', 'interrupted']) as $source) {
            $sourceId = (int) ($source['id'] ?? 0);
            if ($sourceId < 1 || $changed >= $batch) {
                break;
            }

            $remaining = max(1, $batch - $changed);
            $changed += \TinyCat\Database\Connection::exec(
                'DELETE FROM bot_source_runs
                 WHERE source_id = ? AND status NOT IN (?, ?, ?)
                    AND id NOT IN (
                        SELECT id FROM (
                            SELECT id
                            FROM bot_source_runs
                            WHERE source_id = ? AND status NOT IN (?, ?, ?)
                            ORDER BY started_at DESC, id DESC
                            LIMIT ' . $keep . '
                        ) retained_runs
                    )
                 LIMIT ' . $remaining,
                [$sourceId, 'error', 'running', 'interrupted', $sourceId, 'error', 'running', 'interrupted']
            );
        }

        return $changed;
    }

}
