<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $columns = $database->query('SHOW COLUMNS FROM links')->fetchAll(PDO::FETCH_ASSOC);
        $defaults = array_column($columns, 'Default', 'Field');
        if (($defaults['provider'] ?? null) !== '') {
            $database->exec("ALTER TABLE links ALTER COLUMN provider SET DEFAULT ''");
        }
        if (($defaults['link_type'] ?? null) !== 'web') {
            $database->exec("ALTER TABLE links ALTER COLUMN link_type SET DEFAULT 'web'");
        }
    }
    $database->exec("UPDATE links SET provider = '' WHERE provider = 'web'");
    $database->exec("UPDATE links SET link_type = 'web' WHERE link_type = 'link'");
};
