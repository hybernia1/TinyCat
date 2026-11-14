<?php
declare(strict_types=1);
namespace TinyCat\Social\Profiles;

final class Schema
{
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(1, ['user_followers'], self::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.users.initial', dirname(__DIR__, 2) . '/migrations/users/001-initial.php')], null, new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.users.legacy-v1', dirname(__DIR__, 2) . '/migrations/users/legacy-v1.php'));
    }

    public static function install(\PDO $pdo): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS user_followers (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, follower_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id, follower_id))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS user_followers_follower_recent_index ON user_followers(follower_id, created_at DESC, user_id ASC)');
            return;
        }
        foreach (require __DIR__ . '/install-schema.mysql.php' as $sql) { $pdo->exec($sql); }
    }
}
