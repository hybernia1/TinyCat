<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../App/Core/Runtime/autoload.php';

// The original group ID remains stable; its coverage now verifies the only installed build.
require __DIR__ . '/../fixtures/assets/generation-cases.php';
