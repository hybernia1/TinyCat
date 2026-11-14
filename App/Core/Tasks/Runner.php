<?php
declare(strict_types=1);

namespace TinyCat\Core\Tasks;

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
        $fileLock = null;
        $started = hrtime(true);
        try {
            $context = Tasks::context($name, $context);
            $sqlite = Connection::driver() === 'sqlite';
            $sqlLock = !$sqlite || Connection::value("SELECT name FROM pragma_function_list WHERE lower(name) = 'get_lock' LIMIT 1") !== null;
            if ($sqlLock) { $lockResult = Connection::value('SELECT GET_LOCK(?, 0)', [$lockName]); }
            else {
                $directory = Paths::basePath('storage/locks/tasks');
                if (!\TinyCat\Support\Storage::ensureDirectory($directory)) { throw new RuntimeException('Cannot prepare task locks.'); }
                $fileLock = fopen($directory . '/' . hash('sha256', $lockName) . '.lock', 'c+b');
                if ($fileLock === false) { throw new RuntimeException('Cannot open task lock.'); }
                $lockResult = flock($fileLock, LOCK_EX | LOCK_NB) ? 1 : 0;
            }
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
            History::finish($runId, $result, (int) ((hrtime(true) - $started) / 1_000_000));
            return $result;
        } catch (Throwable $exception) {
            $result = Tasks::result('failed', error: $debug ? $exception->getMessage() : $name . ' task failed.');
            if ($runId > 0) {
                try { History::finish($runId, $result, (int) ((hrtime(true) - $started) / 1_000_000)); } catch (Throwable) {}
            }
            return $result;
        } finally {
            if (is_resource($fileLock)) { if ($locked) { flock($fileLock, LOCK_UN); } fclose($fileLock); }
            elseif ($locked) {
                try {
                    Connection::value('SELECT RELEASE_LOCK(?)', [$lockName]);
                } catch (Throwable) {
                    // The connection also releases its advisory locks on close.
                }
            }
        }
    }
}
