<?php
declare(strict_types=1);

namespace TinyCat\Core\Database;

use PDO;
use PDOStatement;
use RuntimeException;

/** Explicit PDO operations also usable while a historical Connection class is loaded. */
final class Statements
{
    /** @param array<mixed> $params */
    public static function query(PDO $pdo, string $sql, array $params = []): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false || !$statement->execute($params)) { throw new RuntimeException('Database statement failed.'); }
        return $statement;
    }
}
