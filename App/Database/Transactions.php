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

    public static function run(callable $callback): mixed
    {
        $pdo = Connection::get();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            self::rollbackIfActive($pdo);

            throw $exception;
        }
    }

    private static function rollbackIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
