<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/bootstrap.php';

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    'http://127.0.0.1/',
    'http://10.0.0.1/',
    'http://169.254.1.1/',
    'http://172.16.0.1/',
    'http://192.168.0.1/',
    'http://[::1]/',
    'http://[fc00::1]/',
    'http://[fe80::1]/',
    'http://[::ffff:127.0.0.1]/',
    'https://user:password@93.184.216.34/private',
] as $url) {
    $assert(!LinkMetadata::isSafeRemoteUrl($url), 'Remote URL guard accepted: ' . $url);
}

$assert(
    LinkMetadata::isSafeRemoteUrl('https://93.184.216.34/public'),
    'Remote URL guard rejected a globally routable IPv4 literal.'
);
$assert(
    LinkMetadata::isSafeRemoteUrl('https://[2606:4700:4700::1111]/public'),
    'Remote URL guard rejected a globally routable IPv6 literal.'
);

$safeImage = [0 => 640, 1 => 480, 'mime' => 'image/png'];
$assert(image_source_error($safeImage, 4096, 4096, ['image/png'], 8192, 8192, 16_777_216) === '', 'Safe raster metadata was rejected.');
$assert(image_source_error($safeImage, 4096, 2048, ['image/png'], 8192, 8192, 16_777_216) === 'size', 'Reported and actual upload sizes may disagree.');
$assert(image_source_error($safeImage, 4096, 4096, ['image/jpeg'], 8192, 8192, 16_777_216) === 'mime', 'Raster MIME allowlist was not enforced.');
$assert(image_source_error([0 => 9000, 1 => 1, 'mime' => 'image/png'], 10, 10, ['image/png'], 100, 8192, 16_777_216) === 'dimensions', 'Raster dimension limit was not enforced.');
$assert(image_source_error([0 => 4096, 1 => 4097, 'mime' => 'image/png'], 10, 10, ['image/png'], 100, 8192, 16_777_216) === 'dimensions', 'Raster pixel-memory limit was not enforced.');
$assert(image_source_error(false, 10, 10, ['image/png'], 100, 8192, 16_777_216) === 'not_image', 'Malformed raster header was accepted.');

$assert(
    user_avatar_url(['id' => 7, 'avatar_exists' => 1, 'updated_at' => '2026-08-11 12:00:00']) === '/uploads/avatars/7.webp?v=2026-08-11%2012%3A00%3A00',
    'A complete user avatar record did not produce its fixed avatar URL.'
);
$assert(
    user_avatar_url(['author_id' => 99, 'id' => 7, 'avatar_exists' => 1, 'updated_at' => '2026-08-11 12:00:00']) === '/uploads/avatars/7.webp?v=2026-08-11%2012%3A00%3A00',
    'An incomplete author avatar projection prevented the user avatar fallback.'
);
$assert(
    user_avatar_url(['author_id' => 99, 'author_avatar_exists' => 1, 'author_updated_at' => '2026-08-11 12:00:00']) === '/uploads/avatars/99.webp?v=2026-08-11%2012%3A00%3A00',
    'A complete author avatar projection did not retain its avatar URL.'
);
$assert(
    user_can_edit_profile(['id' => 7], ['id' => 7, 'role' => 'member']),
    'Users may no longer edit their own avatar target.'
);
$assert(
    user_can_edit_profile(['id' => 7], ['id' => 1, 'role' => 'admin']),
    'Administrators may no longer edit another user avatar target.'
);
$assert(
    !user_can_edit_profile(['id' => 7], ['id' => 8, 'role' => 'member']),
    'A regular user may edit another user avatar target.'
);

if (extension_loaded('gd')) {
    $fixture = tempnam(sys_get_temp_dir(), 'tinycat-malformed-raster-');

    if (!is_string($fixture)) {
        $failures[] = 'Unable to create malformed raster fixture.';
    } else {
        file_put_contents($fixture, "\x89PNG\r\n\x1a\nmalformed", LOCK_EX);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if ((error_reporting() & $severity) !== 0) {
                $warnings[] = $message;
            }

            return true;
        });

        try {
            foreach ([Avatar::class, StatusImage::class] as $owner) {
                $decode = new ReflectionMethod($owner, 'createSource');
                $decoded = $decode->invoke(null, $fixture, 'image/png');
                $assert($decoded === false, $owner . ' accepted malformed PNG data.');
            }
        } finally {
            restore_error_handler();
            @unlink($fixture);
        }

        $assert($warnings === [], 'Malformed raster decoding emitted a PHP warning.');
    }
}

$linkSource = (string) file_get_contents(dirname(__DIR__) . '/App/LinkMetadata.php');
$assert(str_contains($linkSource, 'CURLOPT_FOLLOWLOCATION => false'), 'cURL redirects are not handled manually.');
$assert(str_contains($linkSource, "'follow_location' => 0"), 'Stream redirects are not handled manually.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS runtime URL and raster hardening (' . $checks . " checks)\n";
