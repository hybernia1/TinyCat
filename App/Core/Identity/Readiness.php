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
                    'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND %s <> ?',
                    \TinyCat\Database\Schema::app_sql_identifier('users'),
                    \TinyCat\Database\Schema::app_sql_identifier('password'),
                    \TinyCat\Database\Schema::app_sql_identifier('password')
                ),
                ['']
            ) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
