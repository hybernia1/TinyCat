<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The email template settings migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $statement = $database->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $statement->execute([$schema, 'email_templates']);

    if ($statement->fetchColumn() === false) {
        return;
    }

    $states = array_fill_keys([
        'welcome',
        'password_reset',
        'notification_content_like',
        'notification_content_comment',
        'notification_comment_like',
        'notification_follow',
        'notification_content_mention',
        'notification_comment_mention',
        'notification_report_resolved',
        'notification_report_dismissed',
    ], true);
    $rows = $database->query('SELECT template_key, enabled FROM email_templates')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $templateKey = (string) ($row['template_key'] ?? '');

        if (array_key_exists($templateKey, $states)) {
            $states[$templateKey] = (bool) ($row['enabled'] ?? false);
        }
    }

    $encoded = json_encode($states, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $upsert = $database->prepare(
        'INSERT INTO settings (setting_key, setting_group, setting_value, setting_type, autoload)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             setting_group = VALUES(setting_group),
             setting_value = VALUES(setting_value),
             setting_type = VALUES(setting_type),
             autoload = VALUES(autoload)'
    );
    $upsert->execute(['email.templates', 'email', $encoded, 'json']);

    if ($database->exec('DROP TABLE email_templates') === false) {
        throw new RuntimeException('Unable to remove the obsolete email template table.');
    }
};
