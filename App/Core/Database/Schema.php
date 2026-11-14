<?php
declare(strict_types=1);

namespace TinyCat\Database;

use LogicException;

use Throwable;
use InvalidArgumentException;

final class Schema
{
    /** @var list<string> */
    private static array $requiredTables = [];
    /** @var \Closure(): bool|null */
    private static ?\Closure $accountReady = null;

    /** @param list<string> $tables
     * @param callable(): bool $accountReady
     */
    public static function configure(array $tables, callable $accountReady): void
    {
        if (count($tables) !== count(array_unique($tables))) { throw new LogicException('Required database table names must be unique.'); }
        self::$requiredTables = $tables;
        self::$accountReady = \Closure::fromCallable($accountReady);
    }

    /**
     * @return array<mixed>
     */
    public static function app_required_tables(): array
    {
        return self::$requiredTables;
    }

    /**
     * @param array<mixed> $tables
     * @return array<mixed>
     */
    public static function app_existing_tables(array $tables): array
    {
        $tables = array_values(array_unique(array_filter(array_map(
            static fn (mixed $table): string => trim((string) $table),
            $tables
        ), static fn (string $table): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1)));

        if ($tables === []) {
            return [];
        }

        $driver = \TinyCat\Database\Connection::driver();
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));

        if ($driver === 'sqlite') {
            $rows = \TinyCat\Database\Connection::all(
                'SELECT name FROM sqlite_master WHERE type = ? AND name IN (' . $placeholders . ')',
                array_merge(['table'], $tables)
            );

            return array_values(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows));
        }

        $rows = \TinyCat\Database\Connection::all(
            'SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN (' . $placeholders . ')',
            $tables
        );

        return array_values(array_map(static fn (array $row): string => (string) ($row['TABLE_NAME'] ?? ''), $rows));
    }

    public static function app_sql_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<mixed> $requiredTables
     * @return array<mixed>
     */
    public static function app_db_status(?array $requiredTables = null): array
    {
        $requiredTables ??= \TinyCat\Database\Schema::app_required_tables();
        $status = [
            'connected' => false,
            'account_ready' => false,
            'ready' => false,
            'missing_tables' => [],
            'error' => null,
        ];

        try {
            \TinyCat\Database\Connection::get()->query('SELECT 1');
            $status['connected'] = true;
            $existingTables = array_flip(\TinyCat\Database\Schema::app_existing_tables(array_map('strval', $requiredTables)));

            foreach ($requiredTables as $table) {
                if (!isset($existingTables[(string) $table])) {
                    $status['missing_tables'][] = (string) $table;
                }
            }

            if ($status['missing_tables'] === []) {
                $status['account_ready'] = self::$accountReady !== null && (self::$accountReady)();
            }

            $status['ready'] = $status['missing_tables'] === [] && $status['account_ready'];
        } catch (Throwable $exception) {
            $status['error'] = $exception->getMessage();
        }

        return $status;
    }

    /**
     * @param array<mixed> $requiredTables
     */
    public static function app_db_ready(?array $requiredTables = null): bool
    {
        return (bool) \TinyCat\Database\Schema::app_db_status($requiredTables)['ready'];
    }
}
