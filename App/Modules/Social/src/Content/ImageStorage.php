<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Social owns its storage policy; raster processing and safe I/O belong to core. */
final class ImageStorage extends \TinyCat\Core\Files\RasterStorage
{
    protected const BASE_DIRECTORY = 'uploads/status-images';
    protected const BASE_URL = '/uploads/status-images';
    protected static function option(string $key, mixed $default): mixed { return Integration::option(str_replace('images.', 'content_images.', $key), $default); }
    protected static function allowedRemote(string $url): bool { return Integration::blockedHost($url) === ''; }
}
