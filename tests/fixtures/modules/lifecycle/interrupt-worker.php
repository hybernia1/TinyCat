<?php
declare(strict_types=1);

define('TINYCAT', true);
require $argv[1] . '/App/autoload.php';
TinyCat\Support\Config::load();
TinyCat\Core\Updates\Manager::runLifecycle(static function (): array {
    TinyCat\Database\Connection::exec('CREATE TABLE interrupted_module (id INTEGER)');
    exit(23);
}, true);
