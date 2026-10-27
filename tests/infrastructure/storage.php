<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/autoload.php';

use TinyCat\Support\Storage;

$assert = static function (bool $ok): void {
    if (!$ok) { throw new RuntimeException('Storage contract failed.'); }
};
foreach (['../escape', '/absolute', 'C:/absolute', 'a//b', 'a/./b', "a\0b"] as $path) {
    try { Storage::path($path); } catch (InvalidArgumentException) { continue; }
    throw new RuntimeException('Unsafe storage path accepted.');
}
$assert(Storage::path('updates\\state.json') === Storage::path('updates/state.json'));
$directory = sys_get_temp_dir() . '/tinycat-storage-' . bin2hex(random_bytes(8));
$target = $directory . '/state.json';
try {
    $assert(Storage::write($target, 'first'));
    $assert(Storage::write($target, 'second'));
    $assert(file_get_contents($target) === 'second');
    $assert(Storage::write($target, ''));
    $assert(file_get_contents($target) === '');
    $assert(!Storage::write($target . '/blocked', 'data'));
    $assert(is_file($target));
    $assert(Storage::ensureDirectory($directory . '/occupied'));
    $assert(Storage::write($directory . '/occupied/keep', 'original'));
    $assert(!Storage::write($directory . '/occupied', 'replacement'));
    $assert(file_get_contents($directory . '/occupied/keep') === 'original');
    unlink($directory . '/occupied/keep');
    rmdir($directory . '/occupied');
    $assert(count(scandir($directory)) === 3);
} finally {
    if (is_file($target)) { unlink($target); }
    if (is_dir($directory)) { rmdir($directory); }
}
echo "PASS storage paths, replacement, empty writes and failed-write cleanup\n";
