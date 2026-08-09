<?php
declare(strict_types=1);

namespace TinyCat\Tools\DemoImport;

use PDO;
use RuntimeException;
use Throwable;

final readonly class Schema
{
    private const array REQUIRED_TABLES = [
        'users',
        'content',
        'terms',
        'content_tags',
        'links',
        'content_links',
        'content_likes',
        'content_comments',
        'comment_likes',
        'user_followers',
        'user_profile_links',
        'notifications',
        'content_reports',
        'settings',
    ];

    private const array RESET_TABLES = [
        'password_reset_tokens',
        'notifications',
        'comment_likes',
        'content_reports',
        'content_comments',
        'content_likes',
        'content_tags',
        'content_links',
        'user_followers',
        'user_profile_links',
        'content_images',
        'links',
        'terms',
        'content',
        'users',
        'ip_action_limits',
    ];

    public function __construct(private PDO $database)
    {
    }

    public function validate(): void
    {
        $statement = $this->database->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to inspect TinyCat tables.');
        }
        $tables = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
        $missing = array_values(array_filter(
            self::REQUIRED_TABLES,
            static fn (string $table): bool => !isset($tables[$table]),
        ));
        if ($missing !== []) {
            throw new RuntimeException('Incomplete TinyCat schema: ' . implode(', ', $missing));
        }
    }

    public function reset(): void
    {
        $existing = array_fill_keys($this->tables(), true);
        $this->database->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::RESET_TABLES as $table) {
                if (isset($existing[$table])) {
                    $this->database->exec('TRUNCATE TABLE `' . $table . '`');
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to reset benchmark data: ' . $exception->getMessage(), 0, $exception);
        } finally {
            $this->database->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function configureSettings(): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO settings
                (setting_key, setting_group, setting_value, setting_type, autoload, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                setting_group = VALUES(setting_group),
                setting_value = VALUES(setting_value),
                setting_type = VALUES(setting_type),
                autoload = VALUES(autoload),
                updated_at = VALUES(updated_at)'
        );
        foreach ([
            ['site.name', 'site', 'TinyCat benchmark', 'string'],
            ['auth.registration.enabled', 'security', '1', 'bool'],
            ['auth.registration.auto_approve', 'security', '1', 'bool'],
            ['site.timezone', 'localization', 'UTC', 'string'],
            ['site.locale', 'localization', 'en', 'string'],
        ] as $setting) {
            $statement->execute($setting);
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        $statement = $this->database->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to inspect TinyCat tables.');
        }

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}
