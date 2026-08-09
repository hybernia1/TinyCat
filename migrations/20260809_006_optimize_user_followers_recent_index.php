<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The follower index migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $indexExists = $database->prepare(
        'SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?
         LIMIT 1'
    );
    $hasIndex = static function (string $name) use ($indexExists, $schema): bool {
        $indexExists->execute([$schema, 'user_followers', $name]);

        return $indexExists->fetchColumn() !== false;
    };

    if (!$hasIndex('user_followers_follower_recent_index')
        && $database->exec(
            'ALTER TABLE user_followers
             ADD INDEX user_followers_follower_recent_index (follower_id, created_at DESC, user_id ASC)'
        ) === false
    ) {
        throw new RuntimeException('Unable to add the recent-followers index.');
    }

    if ($hasIndex('user_followers_follower_index')
        && $database->exec('ALTER TABLE user_followers DROP INDEX user_followers_follower_index') === false
    ) {
        throw new RuntimeException('Unable to remove the obsolete followers index.');
    }
};
