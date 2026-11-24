<?php
declare(strict_types=1);

namespace TinyCat\Database;

use Throwable;
use PDO;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Transactions
{
    /** @var \SplQueue<\Closure(): void>|null */
    private static ?\SplQueue $afterCommit = null;
    /** @var array<string, true> */
    private static array $afterCommitKeys = [];

    /** External effects belong after the outer managed commit, never after a rollback.
     * @param callable(): void $callback */
    public static function afterCommit(callable $callback, ?string $key = null): void
    {
        if (!Connection::get()->inTransaction()) { $callback(); return; }
        if (self::$afterCommit === null) { throw new \LogicException('Deferred effects require a transaction managed by Transactions::run.'); }
        if ($key !== null) {
            if (isset(self::$afterCommitKeys[$key])) { return; }
            self::$afterCommitKeys[$key] = true;
        }
        self::$afterCommit->enqueue(\Closure::fromCallable($callback));
    }

    public static function run(callable $callback): mixed
    {
        $pdo = Connection::get();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();
        self::$afterCommit = new \SplQueue();
        self::$afterCommitKeys = [];

        try {
            $result = $callback();
            $pdo->commit();
            $afterCommit = self::$afterCommit ?? throw new \LogicException('Managed transaction effects were lost.');
            self::$afterCommit = null;
            self::$afterCommitKeys = [];
        } catch (Throwable $exception) {
            self::$afterCommit = null;
            self::$afterCommitKeys = [];
            self::rollbackIfActive($pdo);

            throw $exception;
        }
        $failures = [];
        while (!$afterCommit->isEmpty()) {
            $effect = $afterCommit->dequeue();
            try {
                $effect();
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }
        if ($failures !== []) {
            throw new AfterCommitFailed($failures);
        }
        return $result;
    }

    private static function rollbackIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/** The database commit succeeded. Retrying the transaction can duplicate writes. */
final class AfterCommitFailed extends \RuntimeException
{
    /** @param non-empty-list<Throwable> $failures */
    public function __construct(public readonly array $failures)
    {
        parent::__construct('Database committed, but ' . count($failures) . ' after-commit effect(s) failed.', 0, $failures[0]);
    }
}
