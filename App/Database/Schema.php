<?php
declare(strict_types=1);

namespace TinyCat\Database;

use LogicException;

use Throwable;
use InvalidArgumentException;

final class Schema
{
    /**
     * @return array<mixed>
     */
    public static function app_required_tables(): array
    {
        $tables = [
            'users',
            'content',
            'content_images',
            'terms',
            'content_tags',
            'links',
            'content_links',
            'content_likes',
            'content_comments',
            'comment_likes',
            'user_followers',
            'notifications',
            'content_reports',
            'ip_action_limits',
            'password_reset_tokens',
            ...\TinyCat\Bots\Importer::requiredTables(),
            'settings',
        ];

        if (count($tables) !== count(array_unique($tables))) {
            throw new LogicException('Required database table names must be unique.');
        }

        return $tables;
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

        $driver = (string) \TinyCat\Settings\Store::config('database.driver', 'mysql');
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
                $status['account_ready'] = \TinyCat\Users\Profiles::app_auth_account_ready();
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
