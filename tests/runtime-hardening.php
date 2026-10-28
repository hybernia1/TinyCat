<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';

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
    $assert(!\TinyCat\Status\Metadata::isSafeRemoteUrl($url), 'Remote URL guard accepted: ' . $url);
}

$assert(
    \TinyCat\Status\Metadata::isSafeRemoteUrl('https://93.184.216.34/public'),
    'Remote URL guard rejected a globally routable IPv4 literal.'
);
$assert(
    \TinyCat\Status\Metadata::isSafeRemoteUrl('https://[2606:4700:4700::1111]/public'),
    'Remote URL guard rejected a globally routable IPv6 literal.'
);

$safeImage = [0 => 640, 1 => 480, 'mime' => 'image/png'];
$assert(\TinyCat\Security\Images::image_source_error($safeImage, 4096, 4096, ['image/png'], 8192, 8192, 16_777_216) === '', 'Safe raster metadata was rejected.');
$assert(\TinyCat\Security\Images::image_source_error($safeImage, 4096, 2048, ['image/png'], 8192, 8192, 16_777_216) === 'size', 'Reported and actual upload sizes may disagree.');
$assert(\TinyCat\Security\Images::image_source_error($safeImage, 4096, 4096, ['image/jpeg'], 8192, 8192, 16_777_216) === 'mime', 'Raster MIME allowlist was not enforced.');
$assert(\TinyCat\Security\Images::image_source_error([0 => 9000, 1 => 1, 'mime' => 'image/png'], 10, 10, ['image/png'], 100, 8192, 16_777_216) === 'dimensions', 'Raster dimension limit was not enforced.');
$assert(\TinyCat\Security\Images::image_source_error([0 => 4096, 1 => 4097, 'mime' => 'image/png'], 10, 10, ['image/png'], 100, 8192, 16_777_216) === 'dimensions', 'Raster pixel-memory limit was not enforced.');
$assert(\TinyCat\Security\Images::image_source_error(false, 10, 10, ['image/png'], 100, 8192, 16_777_216) === 'not_image', 'Malformed raster header was accepted.');

$assert(
    \TinyCat\Users\Avatars::user_avatar_url(['id' => 7, 'avatar_exists' => 1, 'updated_at' => '2026-08-11 12:00:00']) === '/uploads/avatars/7.webp?v=2026-08-11%2012%3A00%3A00',
    'A complete user avatar record did not produce its fixed avatar URL.'
);
$assert(
    \TinyCat\Users\Avatars::user_avatar_url(['author_id' => 99, 'id' => 7, 'avatar_exists' => 1, 'updated_at' => '2026-08-11 12:00:00']) === '/uploads/avatars/7.webp?v=2026-08-11%2012%3A00%3A00',
    'An incomplete author avatar projection prevented the user avatar fallback.'
);
$assert(
    \TinyCat\Users\Avatars::user_avatar_url(['author_id' => 99, 'author_avatar_exists' => 1, 'author_updated_at' => '2026-08-11 12:00:00']) === '/uploads/avatars/99.webp?v=2026-08-11%2012%3A00%3A00',
    'A complete author avatar projection did not retain its avatar URL.'
);
$assert(
    \TinyCat\Users\Profiles::user_can_edit_profile(['id' => 7], ['id' => 7, 'role' => 'member']),
    'Users may no longer edit their own avatar target.'
);
$assert(
    \TinyCat\Users\Profiles::user_can_edit_profile(['id' => 7], ['id' => 1, 'role' => 'admin']),
    'Administrators may no longer edit another user avatar target.'
);
$assert(
    !\TinyCat\Users\Profiles::user_can_edit_profile(['id' => 7], ['id' => 8, 'role' => 'member']),
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
            $decoded = \TinyCat\Security\Images::image_open($fixture, 'image/png');
            $assert($decoded === false, 'The shared decoder accepted malformed PNG data.');
        } finally {
            restore_error_handler();
            @unlink($fixture);
        }

        $assert($warnings === [], 'Malformed raster decoding emitted a PHP warning.');
    }
}

if (extension_loaded('gd') && function_exists('imagewebp')) {
    $rasterDirectory = sys_get_temp_dir() . '/tinycat-raster-' . bin2hex(random_bytes(6));
    mkdir($rasterDirectory, 0700);
    $source = imagecreatetruecolor(1200, 600);
    imagealphablending($source, false); imagesavealpha($source, true);
    imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
    imagefilledrectangle($source, 500, 200, 700, 400, imagecolorallocatealpha($source, 255, 0, 0, 0));
    try {
        $avatar = (new ReflectionMethod(\TinyCat\Users\Avatar::class, 'resizeSquare'))->invoke(null, $source);
        $post = (new ReflectionMethod(\TinyCat\Status\ImageStorage::class, 'resize'))->invoke(null, $source);
        $assert(imagesx($avatar) === 200 && imagesy($avatar) === 200, 'Avatar resampling preserves its square profile.');
        $assert(imagesx($post) === 800 && imagesy($post) === 400, 'Post resampling preserves its size bound and aspect ratio.');
        $assert(imagecolorsforindex($avatar, imagecolorat($avatar, 0, 0))['alpha'] === 127, 'Shared resampling preserves transparent corners.');
        $target = $rasterDirectory . '/image.webp';
        \TinyCat\Security\Images::image_write_webp($avatar, $target);
        $encoded = imagecreatefromwebp($target);
        $assert(imagesx($encoded) === 200 && imagecolorsforindex($encoded, imagecolorat($encoded, 0, 0))['alpha'] === 127, 'The shared WebP writer preserves dimensions and transparency.');
        imagedestroy($encoded);
        $hash = hash_file('sha256', $target);
        try {
            \TinyCat\Security\Images::image_write_webp($post, $target, [82, 40], 1);
            $assert(false, 'An impossible output byte limit must reject the image.');
        } catch (LengthException) {
            $assert(hash_file('sha256', $target) === $hash, 'Compression failure leaves an existing image untouched.');
        }
        \TinyCat\Security\Images::image_write_webp($post, $target, keepExisting: true);
        $assert(hash_file('sha256', $target) === $hash, 'Preview caching preserves an image already published by another writer.');
        $assert((glob($rasterDirectory . '/.image-*') ?: []) === [], 'The shared writer cleans up encoding and publication temporary files.');
        imagedestroy($avatar); imagedestroy($post);
    } finally {
        imagedestroy($source);
        foreach (new FilesystemIterator($rasterDirectory, FilesystemIterator::SKIP_DOTS) as $file) { unlink($file->getPathname()); }
        rmdir($rasterDirectory);
    }
}

$previewPath = \TinyCat\Support\Paths::basePath('uploads/links/' . date('Y/m') . '/' . bin2hex(random_bytes(20)) . '.webp');
if (!is_dir(dirname($previewPath))) { mkdir(dirname($previewPath), 0775, true); }
file_put_contents($previewPath, 'fixture');
$previewUrl = '/uploads/links/' . date('Y/m') . '/' . basename($previewPath);
try {
    $link = ['normalized_url' => 'http://127.0.0.1/article', 'url_hash' => hash('sha256', 'fixture'), 'link_type' => 'link'];
    $source = ['url_hash' => $link['url_hash'], 'title' => 'Feed title', 'description' => 'Feed summary', 'image_urls' => ['http://127.0.0.1/broken', $previewUrl]];
    $prepared = \TinyCat\Status\Links::status_link_prepare_metadata($link, null, true, $source);
    $assert($prepared['image_url'] === $previewUrl && $prepared['title'] === 'Feed title', 'Feed metadata avoids article requests and tries later image candidates.');
    $source['image_urls'] = ['http://127.0.0.1/broken'];
    $cached = array_replace($link, ['title' => 'Cached title', 'image_url' => $previewUrl, 'updated_at' => date('Y-m-d H:i:s')]);
    $prepared = \TinyCat\Status\Links::status_link_prepare_metadata($link, $cached, true, $source);
    $assert($prepared['image_url'] === $previewUrl, 'Failed feed images fall back to article metadata.');
} finally { unlink($previewPath); }

$linkSource = (string) file_get_contents(dirname(__DIR__) . '/App/Http/Client.php');
$assert(str_contains($linkSource, 'CURLOPT_FOLLOWLOCATION => false'), 'cURL redirects are not handled manually.');
$assert(str_contains($linkSource, "'follow_location' => 0"), 'Stream redirects are not handled manually.');

$generated = \TinyCat\Users\GeneratedAvatar::svg(42);
$assert($generated === \TinyCat\Users\GeneratedAvatar::svg(42), 'Generated avatars are deterministic.');
$assert(\TinyCat\Users\Avatar::url(42, false) === '/avatar/generated/v1/42.svg', 'Users without uploads receive a versioned generated avatar.');
$assert(\TinyCat\Users\Avatar::url(0, false) === '', 'Anonymous placeholders never become user avatars.');
$assert(\TinyCat\Users\GeneratedAvatar::appearance('{"gender":"female","future":"ignored"}') === ['version' => 1, 'gender' => 'female', 'fur' => 'auto', 'expression' => 'auto', 'accessory' => 'auto', 'outfit' => 'auto', 'background' => 'auto'], 'Appearance has a normalized versioned shape.');
$assert(\TinyCat\Users\GeneratedAvatar::appearance('broken') === ['version' => 1, 'gender' => 'neutral', 'fur' => 'auto', 'expression' => 'auto', 'accessory' => 'auto', 'outfit' => 'auto', 'background' => 'auto'], 'Malformed stored appearance uses neutral defaults.');
$assert(\TinyCat\Users\GeneratedAvatar::svg(42, 'female') !== $generated && \TinyCat\Users\GeneratedAvatar::svg(42, 'male') !== $generated, 'All three gender variants have a distinct illustration.');
$assert(\TinyCat\Users\Avatars::user_avatar_url(['author_id' => 42, 'author_avatar_exists' => 0, 'author_avatar_appearance' => '{"gender":"female"}']) === '/avatar/generated/v1/42.svg?gender=female', 'Joined author appearance reaches the generated avatar URL.');
$customAppearance = ['gender' => 'female', 'fur' => 'lilac', 'expression' => 'smile', 'accessory' => 'glasses'];
$customSvg = \TinyCat\Users\GeneratedAvatar::svg(42, 'neutral', $customAppearance);
$assert(str_contains($customSvg, 'fill="#c7a3cf"') && $customSvg !== $generated, 'Creator parameters select the requested SVG layers and fur colour.');
$assert(\TinyCat\Users\GeneratedAvatar::url(42, json_encode($customAppearance)) === '/avatar/generated/v1/42.svg?gender=female&fur=lilac&expression=smile&accessory=glasses', 'Creator parameters have a canonical cacheable image URL.');
$colourSvg = \TinyCat\Users\GeneratedAvatar::svg(42, 'neutral', ['outfit' => 'sage', 'background' => 'peach']);
$assert(str_contains($colourSvg, 'fill="#558675"') && str_contains($colourSvg, 'fill="#f3d7ce"'), 'Outfit and background apply their independent palettes.');
$assert(\TinyCat\Users\GeneratedAvatar::svg(42, 'neutral', ['outfit' => 'auto', 'background' => 'auto']) === $generated, 'Default colours preserve existing generated avatars.');
$avatars = [];
for ($id = 1; $id <= 1000; $id++) { $avatars[] = \TinyCat\Users\GeneratedAvatar::svg($id); }
$assert(count(array_unique($avatars)) === 1000, 'A sample of 1000 users has visually distinct avatar geometry or colours.');
$svgDocument = new DOMDocument();
$assert($svgDocument->loadXML($generated) && $svgDocument->documentElement?->localName === 'svg', 'Generated avatars are valid self-contained SVG images.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS runtime URL and raster hardening (' . $checks . " checks)\n";
