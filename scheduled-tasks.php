<?php
declare(strict_types=1);
if (!defined('TINYCAT')) { define('TINYCAT', true); }
require_once __DIR__ . '/App/autoload.php';
\TinyCat\Core\Runtime\Entrypoints::run(basename(__FILE__));
