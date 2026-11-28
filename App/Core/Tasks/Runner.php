<?php
declare(strict_types=1);

namespace TinyCat\Core\Tasks;

use TinyCat\Database\Connection;
use Throwable;
use RuntimeException;

final class Runner
{
    /**
     * @param callable(array<string, mixed>): array<string, mixed> $runner
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function run(string $name, callable $runner, array $context): array
    {
        try { $context = Tasks::context($name, $context); }
        catch (Throwable $exception) { \TinyCat\Core\Runtime\Failure::report($exception); return Tasks::result('failed', error: \TinyCat\Core\Runtime\Failure::message($exception, $name . ' task failed.')); }
        return self::runPrepared($name, $runner, $context);
    }

    /** Execute a context already validated by Execution::contexts or Tasks::context.
     * @internal
     * @param callable(array<string, mixed>): array<string, mixed> $runner
     * @param array<string, mixed> $context
     * @return array<string, mixed> */
    public static function runPrepared(string $name, callable $runner, array $context): array
    {
        // Deployment admission precedes the task lock, history and owner callback.
        try {
            return \TinyCat\Core\Updates\Manager::runTask(static fn (): array => self::runLocked($name, $runner, $context));
        } catch (Throwable $exception) {
            \TinyCat\Core\Runtime\Failure::report($exception);
            return Tasks::result('failed', error: \TinyCat\Core\Runtime\Failure::message($exception, $name . ' task failed.'));
        }
    }

    public static function lockName(string $name): string
    {
        $identity = (string) Connection::value('SELECT DATABASE()');
        if ($identity === '') { throw new RuntimeException('Scheduled tasks require a selected database.'); }
        return 'tinycat_scheduled_' . substr(hash('sha256', 'mysql:' . $identity . ':' . $name), 0, 46);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $runner
     * @param array<string, mixed> $context
     * @return array<string, mixed> */
    private static function runLocked(string $name, callable $runner, array $context): array
    {
        $lockName = '';
        $locked = false;
        $runId = 0;
        $started = hrtime(true);
        try {
            $lockName = self::lockName($name);
            $lockResult = Connection::value('SELECT GET_LOCK(?, 0)', [$lockName]);
            if ($lockResult === null || $lockResult === false) { throw new RuntimeException('Unable to acquire scheduled task lock.'); }
            $locked = (int) $lockResult === 1;
            if (!$locked) { return Tasks::result('already_running'); }
            $runId = History::start($name, $context);
            $result = $runner(array_replace($context, ['scheduler_run_id' => $runId]));
            if (!isset($result['ok'], $result['status'], $result['count'], $result['changed'], $result['has_more'], $result['error'], $result['summary'], $result['results'])
                || !is_array($result['summary']) || !is_array($result['results'])
                || !is_bool($result['ok']) || !is_bool($result['has_more']) || !is_string($result['error'])
                || !is_int($result['count']) || $result['count'] < 0 || !is_int($result['changed']) || $result['changed'] < 0
                || $result['ok'] !== ($result['status'] !== 'failed')
                || !in_array($result['status'], ['completed', 'failed', 'not_due', 'has_more'], true)) {
                throw new RuntimeException('Invalid scheduled task result.');
            }
            foreach (['count', 'changed', 'has_more'] as $key) { $result['summary'][$key] = $result[$key]; }
            History::finish($runId, $result, (int) ((hrtime(true) - $started) / 1_000_000));
            return $result;
        } catch (Throwable $exception) {
            \TinyCat\Core\Runtime\Failure::report($exception);
            $result = Tasks::result('failed', error: \TinyCat\Core\Runtime\Failure::message($exception, $name . ' task failed.'));
            if ($runId > 0) {
                try { History::finish($runId, $result, (int) ((hrtime(true) - $started) / 1_000_000)); }
                catch (Throwable $historyError) { \TinyCat\Core\Runtime\Failure::report($historyError); }
            }
            return $result;
        } finally {
            if ($locked) {
                try {
                    Connection::value('SELECT RELEASE_LOCK(?)', [$lockName]);
                } catch (Throwable $failure) { \TinyCat\Core\Runtime\Failure::report($failure);
                    // The connection also releases its advisory locks on close.
                }
            }
        }
    }
}
