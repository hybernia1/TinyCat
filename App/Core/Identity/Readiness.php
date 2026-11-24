<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use Throwable;

final class Readiness
{
    public static function ready(): bool
    {
        try {
            return (int) \TinyCat\Database\Connection::value(
                sprintf(
                    'SELECT 1 FROM %s WHERE %s IS NOT NULL AND %s <> ? LIMIT 1',
                    \TinyCat\Database\Schema::app_sql_identifier('users'),
                    \TinyCat\Database\Schema::app_sql_identifier('password'),
                    \TinyCat\Database\Schema::app_sql_identifier('password')
                ),
                ['']
            ) > 0;
        } catch (Throwable $failure) { \TinyCat\Core\Runtime\Failure::report($failure);
            return false;
        }
    }
}
