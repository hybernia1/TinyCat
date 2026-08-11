<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The avatar storage migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $columnExists = $database->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $hasColumn = static function (string $column) use ($columnExists, $schema): bool {
        $columnExists->execute([$schema, 'users', $column]);

        return $columnExists->fetchColumn() !== false;
    };

    if (!$hasColumn('avatar_exists') && $database->exec('ALTER TABLE users ADD COLUMN avatar_exists TINYINT(1) NOT NULL DEFAULT 0 AFTER theme') === false) {
        throw new RuntimeException('Unable to add avatar_exists.');
    }

    if ($hasColumn('avatar_config')) {
        $avatars = $database->query('SELECT id, avatar_config FROM users WHERE avatar_config IS NOT NULL');

        if ($avatars === false) {
            throw new RuntimeException('Unable to read existing avatars.');
        }

        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the avatar directory.');
        }

        $setAvatarExists = $database->prepare('UPDATE users SET avatar_exists = ? WHERE id = ?');

        if ($setAvatarExists === false) {
            throw new RuntimeException('Unable to prepare avatar migration.');
        }

        foreach ($avatars->fetchAll(PDO::FETCH_ASSOC) as $avatar) {
            $userId = (int) ($avatar['id'] ?? 0);
            $config = json_decode((string) ($avatar['avatar_config'] ?? ''), true);
            $path = is_array($config) ? trim((string) ($config['path'] ?? '')) : '';

            if ($userId < 1 || preg_match('~^[a-z0-9/_-]+\.webp$~i', $path) !== 1 || str_contains($path, '..')) {
                continue;
            }

            $source = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $target = $directory . DIRECTORY_SEPARATOR . $userId . '.webp';

            if (!is_file($source)) {
                continue;
            }

            if (is_file($target)) {
                @unlink($source);
            } elseif (!@rename($source, $target)) {
                throw new RuntimeException('Unable to move the avatar for user ' . $userId . '.');
            }

            $setAvatarExists->execute([1, $userId]);
        }

        if ($database->exec('ALTER TABLE users DROP COLUMN avatar_config') === false) {
            throw new RuntimeException('Unable to remove avatar_config.');
        }
    }

    if ($hasColumn('avatar_path') && $database->exec('ALTER TABLE users DROP COLUMN avatar_path') === false) {
        throw new RuntimeException('Unable to remove avatar_path.');
    }
};
