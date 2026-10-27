<?php
declare(strict_types=1);

namespace TinyCat\Database;

use PDO;
use PDOStatement;
use PDOException;
use Throwable;
use RuntimeException;
use InvalidArgumentException;
use \TinyCat\Support\Config;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $database = Config::get('database', []);

        if (!is_array($database)) {
            throw new RuntimeException('Database config must be an array.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if (isset($database['options']) && is_array($database['options'])) {
            $options = $database['options'] + $options;
        }

        if (isset($database['dsn'])) {
            self::$pdo = new PDO(
                (string) $database['dsn'],
                (string) ($database['user'] ?? ''),
                (string) ($database['password'] ?? ''),
                $options
            );

            return self::$pdo;
        }

        $driver = (string) ($database['driver'] ?? 'mysql');

        if ($driver === 'sqlite') {
            $path = (string) ($database['path'] ?? $database['name'] ?? ':memory:');
            self::$pdo = new PDO('sqlite:' . $path, null, null, $options);
            return self::$pdo;
        }

        $host = (string) ($database['host'] ?? 'localhost');
        $name = (string) ($database['name'] ?? '');
        $charset = (string) ($database['charset'] ?? 'utf8mb4');
        $port = isset($database['port']) ? ';port=' . (int) $database['port'] : '';
        $dsn = sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        self::$pdo = new PDO(
            $dsn,
            (string) ($database['user'] ?? ''),
            (string) ($database['password'] ?? ''),
            $options
        );

        return self::$pdo;
    }

    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function driver(): string
    {
        return (string) self::get()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Escape literal text; the caller chooses prefix/substring matching. */
    public static function escapeLike(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    public static function lockingSelect(string $sql): string
    {
        return self::driver() === 'sqlite' ? $sql : $sql . ' FOR UPDATE';
    }

    /**
     * @param array<mixed> $params
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::get()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<mixed> $params
     */
    public static function exec(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<mixed> $params
     */
    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function insert(string $table, array $data): string
    {
        self::requireData($data);

        $columns = self::columns(array_keys($data));
        $params = [];
        $placeholders = [];

        foreach ($data as $column => $value) {
            $param = ':insert_' . $column;
            $params[$param] = $value;
            $placeholders[] = $param;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::identifier($table),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        self::query($sql, $params);

        $id = self::get()->lastInsertId();
        if ($id === false) {
            throw new RuntimeException('The database did not return an inserted ID.');
        }

        return $id;
    }

    /** Atomic insert/update for rows identified by a primary or unique key.
     * @param array<string, mixed> $key
     * @param array<string, mixed> $data */
    public static function upsert(string $table, array $key, array $data): void
    {
        self::requireData($key);
        self::requireData($data);
        if (in_array(null, $key, true)) { throw new InvalidArgumentException('Upsert keys cannot be null.'); }
        if (array_intersect_key($key, $data) !== []) { throw new InvalidArgumentException('Upsert data cannot replace its key.'); }
        $values = $key + $data;
        $columns = self::columns(array_keys($values));
        $updates = [];
        $driver = self::driver();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) { throw new RuntimeException('Unsupported upsert driver.'); }
        foreach (array_keys($data) as $column) {
            $name = self::column($column);
            $updates[] = $name . ' = ' . ($driver === 'sqlite' ? 'excluded.' . $name : 'VALUES(' . $name . ')');
        }
        $conflict = $driver === 'sqlite'
            ? 'ON CONFLICT (' . implode(', ', self::columns(array_keys($key))) . ') DO UPDATE SET '
            : 'ON DUPLICATE KEY UPDATE ';
        // Native MySQL semantics apply: a conflict on any unique index selects the row.
        self::exec('INSERT INTO ' . self::identifier($table) . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($values), '?')) . ') ' . $conflict . implode(', ', $updates), array_values($values));
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        self::requireData($data);
        self::requireData($where);

        $params = [];
        $sets = [];

        foreach ($data as $column => $value) {
            $name = self::column($column);
            $param = ':set_' . $column;
            $sets[] = $name . ' = ' . $param;
            $params[$param] = $value;
        }

        [$whereSql, $whereParams] = self::where($where);

        return self::exec(
            sprintf('UPDATE %s SET %s WHERE %s', self::identifier($table), implode(', ', $sets), $whereSql),
            $params + $whereParams
        );
    }

    /**
     * @param array<mixed> $where
     */
    public static function delete(string $table, array $where): int
    {
        self::requireData($where);

        [$whereSql, $params] = self::where($where);

        return self::exec(
            sprintf('DELETE FROM %s WHERE %s', self::identifier($table), $whereSql),
            $params
        );
    }

    /**
     * @param array<mixed> $where
     * @param array<mixed>|string $columns
     * @return array<mixed>|null
     */
    public static function find(string $table, array $where, array|string $columns = '*'): ?array
    {
        self::requireData($where);

        [$whereSql, $params] = self::where($where);
        $select = self::selectColumns($columns);

        return self::one(
            sprintf('SELECT %s FROM %s WHERE %s LIMIT 1', $select, self::identifier($table), $whereSql),
            $params
        );
    }

    /**
     * @param array<mixed> $where
     */
    public static function count(string $table, array $where = []): int
    {
        $params = [];
        $sql = sprintf('SELECT COUNT(*) FROM %s', self::identifier($table));

        if ($where !== []) {
            [$whereSql, $params] = self::where($where);
            $sql .= ' WHERE ' . $whereSql;
        }

        return (int) self::value($sql, $params);
    }

    /**
     * @param array<mixed>|string $columns
     */
    private static function selectColumns(array|string $columns): string
    {
        if ($columns === '*') {
            return '*';
        }

        if (is_string($columns)) {
            return self::column($columns);
        }

        return implode(', ', self::columns($columns));
    }

    /**
     * @param array<mixed> $columns
     * @return array<mixed>
     */
    private static function columns(array $columns): array
    {
        return array_map(static fn (string|int $column): string => self::column((string) $column), $columns);
    }

    private static function column(string $column): string
    {
        return self::identifier($column, false);
    }

    private static function identifier(string $identifier, bool $allowDot = true): string
    {
        $pattern = $allowDot
            ? '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/'
            : '/^[A-Za-z_][A-Za-z0-9_]*$/';

        if (!preg_match($pattern, $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }

        return $identifier;
    }

    /**
     * @param array<mixed> $where
     * @return array<mixed>
     */
    private static function where(array $where): array
    {
        $clauses = [];
        $params = [];
        $index = 0;

        foreach ($where as $column => $value) {
            $name = self::column((string) $column);
            $param = ':where_' . $column . '_' . $index;

            if ($value === null) {
                $clauses[] = $name . ' IS NULL';
            } elseif (is_array($value)) {
                if ($value === []) {
                    $clauses[] = '1 = 0';
                } else {
                    $in = [];

                    foreach ($value as $item) {
                        $itemParam = $param . '_' . count($in);
                        $in[] = $itemParam;
                        $params[$itemParam] = $item;
                    }

                    $clauses[] = $name . ' IN (' . implode(', ', $in) . ')';
                }
            } else {
                $clauses[] = $name . ' = ' . $param;
                $params[$param] = $value;
            }

            $index++;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<mixed> $data
     */
    private static function requireData(array $data): void
    {
        if ($data === []) {
            throw new InvalidArgumentException('Data array cannot be empty.');
        }
    }

    public static function uniqueViolation(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }

        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23505'
            || $driverCode === 1062
            || in_array($driverCode, [1555, 2067], true)
            || ($sqlState === '23000' && (
                str_contains($message, 'unique constraint')
                || str_contains($message, 'duplicate entry')
            ));
    }
}
