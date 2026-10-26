<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    $database->exec("DELETE FROM settings WHERE setting_key LIKE 'extensions.%'");
    $database->exec("DELETE FROM schema_migrations WHERE migration LIKE 'extension:%'");
};
