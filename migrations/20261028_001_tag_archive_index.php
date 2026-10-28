<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    \TinyCat\Status\Schema::ensureTagIndex($database);
};
