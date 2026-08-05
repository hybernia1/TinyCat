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
    $find->execute(['extensions.installed_versions']);
    $existing = $find->fetch(PDO::FETCH_ASSOC);
    $versions = is_array($existing)
        ? json_decode((string) ($existing['setting_value'] ?? ''), true)
        : [];
    $versions = is_array($versions) ? $versions : [];

    if (!isset($versions['bots'])) {
        $versions['bots'] = '1.0.0';
    }

    ksort($versions, SORT_STRING);
    $value = json_encode($versions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if (is_array($existing)) {
        $update = $database->prepare(
            "UPDATE settings
             SET setting_group = 'extensions', setting_value = ?, setting_type = 'json', autoload = 1
             WHERE id = ?"
        );
        $update->execute([$value, (int) $existing['id']]);
        return;
    }

    $insert = $database->prepare(
        "INSERT INTO settings
            (setting_key, setting_group, setting_value, setting_type, autoload)
         VALUES (?, 'extensions', ?, 'json', 1)"
    );
    $insert->execute(['extensions.installed_versions', $value]);
};
