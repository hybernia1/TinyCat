<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
define('TINYCAT', true);
require_once $root . '/App/autoload.php';
try {
    $validate = require __DIR__ . '/translation-catalogs.php';
    $catalogs = $validate($root);
    $validateClients = require __DIR__ . '/client-translations.php';
    $validateClients($catalogs);
    echo 'PASS translation catalogs and client declarations (' . count($catalogs) . " locales)\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
