<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once __DIR__ . '/../../App/Core/Runtime/autoload.php';

// Inspection only, using the same current-schema plan as the explicit optimizer.
$optimize = require __DIR__ . '/optimize-links.php';
echo json_encode($optimize(\TinyCat\Database\Connection::get()), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
