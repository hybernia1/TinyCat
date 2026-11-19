<?php
declare(strict_types=1);
define('TINYCAT', true);
require $argv[1] . '/App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();
TinyCat\Core\Identity\Lifecycle::delete(2, TinyCat\Core\Identity\Participants::current(true));
