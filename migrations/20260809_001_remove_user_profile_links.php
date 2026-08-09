<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The profile links removal migration requires MySQL or MariaDB.');
    }

    if ($database->exec('DROP TABLE IF EXISTS user_profile_links') === false) {
        throw new RuntimeException('Unable to remove the obsolete profile links table.');
    }
};
