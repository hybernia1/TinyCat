<?php
declare(strict_types=1);

namespace TinyCat\Database;

use LogicException;
use PDO;

use Throwable;

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

        return self::existing(Connection::get(), $tables);
    }

    /** One inventory per batch, shared by HTTP readiness and explicit deployment connections.
     * @param list<string> $tables
     * @return list<string> */
    public static function existing(PDO $pdo, array $tables): array
    {
        $tables = array_values(array_unique($tables));
        foreach ($tables as $table) { \TinyCat\Core\Database\Statements::identifier($table); }
        $existing = [];
        foreach (array_chunk($tables, 500) as $batch) {
            $placeholders = implode(', ', array_fill(0, count($batch), '?'));
            $sql = 'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')';
            $query = \TinyCat\Core\Database\Statements::query($pdo, $sql, $batch);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $name) { $existing[] = (string) $name; }
        }
        return $existing;
    }

    public static function app_sql_identifier(string $identifier): string
    {
        return \TinyCat\Core\Database\Statements::quote($identifier);
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
            // With no required tables there is no schema query to prove connectivity.
            if ($requiredTables === []) {
                \TinyCat\Database\Connection::get()->query('SELECT 1');
            }
            $existingTables = array_flip(\TinyCat\Database\Schema::app_existing_tables(array_map('strval', $requiredTables)));
            $status['connected'] = true;

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
            \TinyCat\Core\Runtime\Failure::report($exception);
            $status['error'] = \TinyCat\Core\Runtime\Failure::message($exception, 'Operation failed.');
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

    public static function identifier(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) { throw new \InvalidArgumentException('Invalid schema identifier.'); }
        return $name;
    }

    public static function exists(PDO $pdo, string $table): bool
    {
        self::identifier($table);
        $query = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $query->execute([$table]); return $query->fetchColumn() !== false;
    }

    /** @return list<string> */
    public static function columns(PDO $pdo, string $table): array
    {
        $table = self::identifier($table);
        $query = \TinyCat\Core\Database\Statements::query($pdo, 'SHOW COLUMNS FROM ' . $table);
        return array_values(array_map(static fn (array $row): string => (string) $row['Field'], $query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @param list<string> $columns */
    public static function requireColumns(PDO $pdo, string $table, array $columns): void
    {
        if (!self::exists($pdo, $table) || array_diff($columns, self::columns($pdo, $table)) !== []) { throw new \RuntimeException('Historical schema is incompatible: ' . $table); }
    }

}
