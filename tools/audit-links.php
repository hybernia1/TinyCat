<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';

// Inspection only. Applying changes belongs to the normal migration runner.
$migration = require dirname(__DIR__) . '/migrations/20261027_002_link_normalization.php';
echo json_encode($migration(\TinyCat\Database\Connection::get(), false), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
