<?php
declare(strict_types=1);
namespace TinyCat\Blog;

/** Legacy attachment URLs stay stable; reference ownership is held by Blog tables. */
final class ImageStorage extends \TinyCat\Core\Files\RasterStorage
{
    protected const BASE_DIRECTORY = 'uploads/status-images';
    protected const BASE_URL = '/uploads/status-images';
    protected static function option(string $key, mixed $default): mixed { return Settings::get('blog.' . $key, $default); }
    protected static function allowedRemote(string $url): bool { return Settings::blockedHost($url) === ''; }
}
