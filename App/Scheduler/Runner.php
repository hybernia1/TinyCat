<?php
declare(strict_types=1);

namespace TinyCat\Scheduler;

use TinyCat\Database\Connection;
use TinyCat\Support\Paths;
use Throwable;
use RuntimeException;

final class Runner
{
    /**
     * @param callable(array<string, mixed>): array<string, mixed> $runner
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function run(string $name, callable $runner, array $context, bool $debug = false): array
    {
        $suffix = substr(hash('sha256', Paths::basePath()), 0, 24);
        $lockName = 'tinycat_scheduled_' . $name . '_' . $suffix;
        $locked = false;
        $runId = 0;
        $started = hrtime(true);
        try {
            $lockResult = Connection::value('SELECT GET_LOCK(?, 0)', [$lockName]);
            if ($lockResult === null || $lockResult === false) { throw new RuntimeException('Unable to acquire scheduled task lock.'); }
            $locked = (int) $lockResult === 1;
            if (!$locked) { return ['ok' => true, 'status' => 'already_running', 'skipped' => true]; }
            $runId = History::start($name, $context);
            $result = $runner(array_replace($context, ['scheduler_run_id' => $runId]));
            History::finish($runId, $result, (int) ((hrtime(true) - $started) / 1_000_000));
            return $result;
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'status' => 'failed', 'error' => $debug ? $exception->getMessage() : $name . ' task failed.'];
            if ($runId > 0) {
                try { History::finish($runId, $result, (int) ((hrtime(true) - $started) / 1_000_000)); } catch (Throwable) {}
            }
            return $result;
        } finally {
            if ($locked) {
                try {
                    Connection::value('SELECT RELEASE_LOCK(?)', [$lockName]);
                } catch (Throwable) {
                    // The connection also releases its advisory locks on close.
                }
            }
        }
    }
}
