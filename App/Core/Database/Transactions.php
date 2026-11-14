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

    /** External effects belong after the outer managed commit, never after a rollback.
     * @param callable(): void $callback */
    public static function afterCommit(callable $callback): void
    {
        if (!Connection::get()->inTransaction()) { $callback(); return; }
        if (self::$afterCommit === null) { throw new \LogicException('Deferred effects require a transaction managed by Transactions::run.'); }
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

        try {
            $result = $callback();
            $pdo->commit();
            $afterCommit = self::$afterCommit ?? throw new \LogicException('Managed transaction effects were lost.');
            self::$afterCommit = null;
        } catch (Throwable $exception) {
            self::$afterCommit = null;
            self::rollbackIfActive($pdo);

            throw $exception;
        }
        while (!$afterCommit->isEmpty()) { $effect = $afterCommit->dequeue(); $effect(); }
        return $result;
    }

    private static function rollbackIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
