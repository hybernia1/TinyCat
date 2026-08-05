<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    $find = $database->prepare(
        'SELECT id, setting_value
         FROM settings
         WHERE setting_key = ?
         LIMIT 1'
    );
    $find->execute(['bots.cron_token']);
    $legacy = $find->fetch(PDO::FETCH_ASSOC);

    if (!is_array($legacy)) {
        return;
    }

    $find->execute(['cron.token']);
    $current = $find->fetch(PDO::FETCH_ASSOC);

    if (is_array($current)) {
        $token = trim((string) ($current['setting_value'] ?? '')) !== ''
            ? (string) $current['setting_value']
            : (string) ($legacy['setting_value'] ?? '');
        $normalize = $database->prepare(
            "UPDATE settings
             SET setting_value = ?, setting_group = 'cron', setting_type = 'string', autoload = 1
             WHERE id = ?"
        );
        $normalize->execute([$token, (int) $current['id']]);

        $delete = $database->prepare('DELETE FROM settings WHERE id = ?');
        $delete->execute([(int) $legacy['id']]);
        return;
    }

    $rename = $database->prepare(
        "UPDATE settings
         SET setting_key = 'cron.token', setting_group = 'cron', setting_type = 'string', autoload = 1
         WHERE id = ?"
    );
    $rename->execute([(int) $legacy['id']]);
};
