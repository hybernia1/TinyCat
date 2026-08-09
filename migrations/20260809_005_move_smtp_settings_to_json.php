<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The SMTP settings migration requires MySQL or MariaDB.');
    }

    $keys = [
        'host' => 'email.smtp.host',
        'port' => 'email.smtp.port',
        'username' => 'email.smtp.username',
        'password' => 'email.smtp.password',
        'encryption' => 'email.smtp.encryption',
        'from_address' => 'email.from_address',
        'from_name' => 'email.from_name',
    ];
    $smtp = [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_address' => '',
        'from_name' => 'TinyCat',
    ];
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $statement = $database->prepare(
        'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (' . $placeholders . ')'
    );
    $statement->execute(array_values($keys));
    $stored = [];

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stored[(string) ($row['setting_key'] ?? '')] = (string) ($row['setting_value'] ?? '');
    }

    foreach ($keys as $name => $key) {
        if (array_key_exists($key, $stored)) {
            $smtp[$name] = $name === 'port' ? (int) $stored[$key] : $stored[$key];
        }
    }

    $smtp['port'] = max(1, min(65535, (int) $smtp['port']));
    $encoded = json_encode($smtp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $upsert = $database->prepare(
        'INSERT INTO settings (setting_key, setting_group, setting_value, setting_type, autoload)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             setting_group = VALUES(setting_group),
             setting_value = VALUES(setting_value),
             setting_type = VALUES(setting_type),
             autoload = VALUES(autoload)'
    );
    $upsert->execute(['email.smtp', 'email', $encoded, 'json']);

    $cleanup = $database->prepare(
        'DELETE FROM settings WHERE setting_key IN (' . implode(', ', array_fill(0, count($keys) + 1, '?')) . ')'
    );
    $cleanup->execute([...array_values($keys), 'email.welcome_message']);
};
