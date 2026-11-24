<?php
declare(strict_types=1);

namespace TinyCat\Core\Database;

use PDO;
use PDOStatement;
use RuntimeException;
use InvalidArgumentException;

/** Explicit PDO operations also usable while a historical Connection class is loaded. */
final class Statements
{
    public static function identifier(string $name, bool $qualified = false): string
    {
        $pattern = $qualified
            ? '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/D'
            : '/^[A-Za-z_][A-Za-z0-9_]*$/D';
        if (preg_match($pattern, $name) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }
        return $name;
    }

    public static function quote(string $name, bool $mysql = true): string
    {
        $quote = $mysql ? '`' : '"';
        return $quote . self::identifier($name) . $quote;
    }

    /** @param array<mixed> $params */
    public static function query(PDO $pdo, string $sql, array $params = []): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false || !$statement->execute($params)) { throw new RuntimeException('Database statement failed.'); }
        return $statement;
    }
}
