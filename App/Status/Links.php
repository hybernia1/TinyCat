<?php
declare(strict_types=1);

namespace TinyCat\Status;

use GdImage;
use RuntimeException;
use Throwable;

final class Links
{
    private const METADATA_FAILURE_TTL = 300;

    public static function status_link_title_is_placeholder(string $title): bool
    {
        return in_array(strtolower(trim($title)), [
            'youtube video',
            'vimeo video',
            'dailymotion video',
        ], true);
    }

    public static function status_url_host_key(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            return '';
        }

        return $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    public static function status_internal_url(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }

        $currentHost = \TinyCat\Status\Links::status_url_host_key(\TinyCat\Site\Presentation::absolute_url('/'));
        $urlHost = \TinyCat\Status\Links::status_url_host_key($url);

        return $currentHost !== '' && $urlHost !== '' && $currentHost === $urlHost;
    }

    public static function status_internal_url_path(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return \TinyCat\Http\Request::current()->path((string) (parse_url($url, PHP_URL_PATH) ?: $url));
        }

        if (!preg_match('~^https?://~i', $url) || !\TinyCat\Status\Links::status_internal_url($url)) {
            return '';
        }

        return \TinyCat\Http\Request::current()->path((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
    }

    public static function status_external_url_pattern(): string
    {
        return '~(?<![@\p{L}\p{N}_])(?:https?://|www\.)[^\s<>"\']+~iu';
    }

    public static function status_strip_external_urls(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace_callback(\TinyCat\Status\Links::status_external_url_pattern(), static function (array $match): string {
            [$url, $tail] = \TinyCat\Status\LinkParser::splitTail((string) ($match[0] ?? ''));

            if (\TinyCat\Status\Links::status_internal_url($url)) {
                return $url . $tail;
            }

            return $tail;
        }, $text);

        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace('/\s+([.,;:!?])/u', '$1', $text);
        $text = (string) preg_replace('/[ \t]+\R/u', "\n", $text);
        $text = (string) preg_replace('/\R[ \t]+/u', "\n", $text);
        $text = (string) preg_replace('/\R{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /**
     * @return array<mixed>
     */
    public static function status_links_from_text(string $text): array
    {
        return array_values(array_filter(
            \TinyCat\Status\LinkParser::extract($text),
            static fn (array $link): bool => !\TinyCat\Status\Links::status_link_is_internal($link)
        ));
    }

    /** @param array<mixed>|string $link */
    public static function status_link_is_internal(array|string $link): bool
    {
        if (is_array($link)) {
            foreach (['normalized_url'] as $key) {
                $url = trim((string) ($link[$key] ?? ''));

                if ($url !== '' && \TinyCat\Status\Links::status_internal_url($url)) {
                    return true;
                }
            }

            return false;
        }

        $url = trim($link);

        return $url !== '' && \TinyCat\Status\Links::status_internal_url($url);
    }

    public static function status_link_metadata_ttl(): int
    {
        return 86400;
    }

    /**
     * @param array<mixed> $links
     * @return array<mixed>
     */
    public static function status_link_metadata_cache(array $links): array
    {
        $hashes = [];

        foreach ($links as $link) {
            $link = (array) $link;
            $hash = (string) ($link['url_hash'] ?? '');

            if ($hash !== '') {
                $hashes[$hash] = $hash;
            }
        }

        if ($hashes === []) {
            return [];
        }

        $cached = [];

        try {
            foreach (\TinyCat\Database\Queries::db_select('SELECT * FROM links')
                ->whereIn('url_hash', array_values($hashes))
                ->order('updated_at DESC, id DESC')
                ->all() as $row) {
                $hash = (string) ($row['url_hash'] ?? '');

                if ($hash !== '' && !isset($cached[$hash])) {
                    $cached[$hash] = $row;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $cached;
    }

    /**
     * @param array<mixed> $link
     * @return array<mixed>
     */
    public static function status_link_data(array $link): array
    {
        $normalizedUrl = \TinyCat\Support\Text::plain_text_limit((string) ($link['normalized_url'] ?? ''), 2048);
        $hash = \TinyCat\Support\Text::plain_text_limit((string) ($link['url_hash'] ?? ''), 64);

        if ($normalizedUrl === '' || $hash === '') {
            return [];
        }

        return [
            'normalized_url' => $normalizedUrl,
            'url_hash' => $hash,
            'provider' => \TinyCat\Support\Text::plain_text_limit((string) ($link['provider'] ?? 'web'), 40),
            'link_type' => \TinyCat\Support\Text::plain_text_limit((string) ($link['link_type'] ?? 'link'), 20),
            'title' => \TinyCat\Support\Text::plain_text_limit((string) ($link['title'] ?? ''), 255),
            'description' => \TinyCat\Support\Text::plain_text_limit((string) ($link['description'] ?? ''), 500),
            'image_url' => \TinyCat\Support\Text::plain_text_limit((string) ($link['image_url'] ?? ''), 2048),
            'video_id' => \TinyCat\Support\Text::plain_text_limit((string) ($link['video_id'] ?? ''), 80),
            'updated_at' => (string) ($link['_metadata_updated_at'] ?? \TinyCat\Support\Dates::dateDb()),
        ];
    }

    /**
     * @return array<mixed>|null
     */
    public static function status_link_find_by_hash(string $hash): ?array
    {
        $hash = \TinyCat\Support\Text::plain_text_limit($hash, 64);

        if ($hash === '') {
            return null;
        }

        return \TinyCat\Database\Queries::db_select('SELECT * FROM links')
            ->where('url_hash = ?', $hash)
            ->limit(1)
            ->one();
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_link_upsert(array $link): int
    {
        $data = \TinyCat\Status\Links::status_link_data($link);

        if ($data === []) {
            return 0;
        }

        $existing = \TinyCat\Status\Links::status_link_find_by_hash((string) $data['url_hash']);

        if ($existing === null) {
            try {
                $id = \TinyCat\Database\Connection::insert('links', $data + ['created_at' => \TinyCat\Support\Dates::dateDb()]);

                return max(0, (int) $id);
            } catch (Throwable $exception) {
                if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                    throw $exception;
                }

                $existing = \TinyCat\Status\Links::status_link_find_by_hash((string) $data['url_hash']);
            }
        }

        $id = (int) ($existing['id'] ?? 0);

        if ($id > 0) {
            \TinyCat\Database\Connection::update('links', $data, ['id' => $id]);
        }

        return $id;
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_link_metadata_fresh(?array $link): bool
    {
        if ($link === null) {
            return false;
        }

        if (!\TinyCat\Status\Links::status_link_metadata_has_content($link)) {
            return false;
        }

        $updatedAt = strtotime((string) ($link['updated_at'] ?? ''));

        return $updatedAt > 0 && $updatedAt >= time() - \TinyCat\Status\Links::status_link_metadata_ttl();
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_link_metadata_has_content(array $link): bool
    {
        $type = (string) ($link['link_type'] ?? 'link');
        $provider = (string) ($link['provider'] ?? 'web');
        $title = trim((string) ($link['title'] ?? ''));
        $description = trim((string) ($link['description'] ?? ''));

        if ($type === 'video') {
            $fallbackTitle = match ($provider) {
                'youtube' => 'YouTube video',
                'vimeo' => 'Vimeo video',
                'dailymotion' => 'Dailymotion video',
                default => '',
            };

            return $title !== '' && ($fallbackTitle === '' || strcasecmp($title, $fallbackTitle) !== 0);
        }

        $fallback = \TinyCat\Status\LinkParser::fromRaw((string) ($link['normalized_url'] ?? ''));
        $fallbackTitle = trim((string) ($fallback['title'] ?? ''));
        $fallbackDescription = trim((string) ($fallback['description'] ?? ''));
        $hasTitle = $title !== '' && ($fallbackTitle === '' || strcasecmp($title, $fallbackTitle) !== 0);
        $hasDescription = $description !== '' && ($fallbackDescription === '' || strcasecmp($description, $fallbackDescription) !== 0);

        return $hasTitle || $hasDescription || trim((string) ($link['image_url'] ?? '')) !== '';
    }

    /**
     * @param array<mixed> $link
     * @param array<mixed> $cached
     * @return array<mixed>
     */
    public static function status_link_apply_cached_metadata(array $link, array $cached, bool $preserveTimestamp): array
    {
        foreach (['provider', 'link_type', 'title', 'description', 'image_url', 'video_id'] as $key) {
            $value = (string) ($cached[$key] ?? '');

            if ($value !== '') {
                $link[$key] = $value;
            }
        }

        $updatedAt = (string) ($cached['updated_at'] ?? '');
        $link['_metadata_updated_at'] = $preserveTimestamp && $updatedAt !== '' ? $updatedAt : \TinyCat\Support\Dates::dateDb();

        return $link;
    }

    public static function status_link_image_cache(string $imageUrl): string
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || !extension_loaded('gd') || !function_exists('imagewebp')) {
            return '';
        }
        if (str_starts_with($imageUrl, '/uploads/links/')) {
            if (!self::status_link_image_exists($imageUrl)) { return ''; }
            @touch(\TinyCat\Support\Paths::basePath(ltrim($imageUrl, '/')));
            return $imageUrl;
        }
        if (!\TinyCat\Status\Metadata::isSafeRemoteUrl($imageUrl)) {
            return '';
        }

        $subfolder = date('Y/m');
        $filename = substr(hash('sha256', $imageUrl), 0, 40) . '.webp';
        $directory = \TinyCat\Support\Paths::basePath('uploads/links/' . $subfolder);
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $localUrl = '/uploads/links/' . $subfolder . '/' . $filename;

        if (is_file($target)) {
            @touch($target);
            return $localUrl;
        }

        $response = \TinyCat\Status\Metadata::fetchImage($imageUrl);
        $body = (string) ($response['body'] ?? '');
        $info = $body !== '' && function_exists('getimagesizefromstring') ? @getimagesizefromstring($body) : false;
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $width = is_array($info) ? (int) $info[0] : 0;
        $height = is_array($info) ? (int) $info[1] : 0;
        $mime = is_array($info) ? strtolower((string) $info['mime']) : '';

        if (
            $response === null
            || !in_array($mime, $allowedMimes, true)
            || $width < 1
            || $height < 1
            || $width > 8192
            || $height > 8192
            || $height > intdiv(20_000_000, $width)
        ) {
            return '';
        }

        $source = @imagecreatefromstring($body);
        if (!$source instanceof GdImage) {
            return '';
        }

        $targetWidth = 184;
        $targetHeight = 172;
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $width / $height;
        $cropWidth = $width;
        $cropHeight = $height;
        $sourceX = 0;
        $sourceY = 0;

        if ($sourceRatio > $targetRatio) {
            $cropWidth = max(1, (int) round($height * $targetRatio));
            $sourceX = (int) floor(($width - $cropWidth) / 2);
        } elseif ($sourceRatio < $targetRatio) {
            $cropHeight = max(1, (int) round($width / $targetRatio));
            $sourceY = (int) floor(($height - $cropHeight) / 2);
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $background = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($background === false) { throw new RuntimeException('Unable to allocate image background.'); }
        imagefill($canvas, 0, 0, $background);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        );
        imagedestroy($source);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);
            return '';
        }

        $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
        $written = imagewebp($canvas, $temporary, 62);
        imagedestroy($canvas);

        if (!$written) {
            @unlink($temporary);
            return '';
        }
        if (is_file($target)) {
            @unlink($temporary);
            return $localUrl;
        }
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            return '';
        }

        return $localUrl;
    }

    /** @return array{changed: int, has_more: bool} */
    public static function cleanupOrphanImages(int $limit, string $relativeDirectory = ''): array
    {
        if ($relativeDirectory !== '' && preg_match('~^[0-9]{4}/[0-9]{2}$~D', $relativeDirectory) !== 1) {
            throw new \InvalidArgumentException('Invalid link image cleanup directory.');
        }
        $base = \TinyCat\Support\Paths::basePath('uploads/links');
        $directory = $base . ($relativeDirectory !== '' ? '/' . $relativeDirectory : '');
        $resolvedBase = realpath($base);
        $resolvedDirectory = realpath($directory);
        if ($resolvedBase === false || $resolvedDirectory === false) { return ['changed' => 0, 'has_more' => false]; }
        if ($resolvedDirectory !== $resolvedBase && !str_starts_with($resolvedDirectory, $resolvedBase . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Link image directory escapes upload storage.');
        }
        $changed = 0;
        $cutoff = time() - 3600;
        $pending = [];
        $flush = static function (array $paths) use (&$changed, $limit, $cutoff): bool {
            if ($paths === []) { return false; }
            $placeholders = implode(', ', array_fill(0, count($paths), '?'));
            $rows = \TinyCat\Database\Connection::all('SELECT image_url FROM links WHERE image_url IN (' . $placeholders . ')', array_keys($paths));
            $stored = array_fill_keys(array_column($rows, 'image_url'), true);
            foreach ($paths as $url => $file) {
                if (isset($stored[$url])) { continue; }
                clearstatcache(true, $file);
                $modified = @filemtime($file);
                if ($modified === false || $modified > $cutoff || is_link($file)) { continue; }
                if ($changed >= max(1, $limit)) { return true; }
                if (!@unlink($file) && is_file($file)) { throw new \RuntimeException('Unable to remove orphan link image.'); }
                $changed++;
            }
            return false;
        };
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getMTime() > $cutoff) { continue; }
            $parent = realpath($file->getPath());
            if ($parent === false || !str_starts_with($parent, $resolvedBase . DIRECTORY_SEPARATOR)) { continue; }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            if (preg_match('~^[0-9]{4}/[0-9]{2}/[a-f0-9]{40}\.webp$~D', $relative) !== 1) { continue; }
            $pending['/uploads/links/' . $relative] = $file->getPathname();
            if (count($pending) >= 100) {
                if ($flush($pending)) { return ['changed' => $changed, 'has_more' => true]; }
                $pending = [];
            }
        }
        $hasMore = $flush($pending);
        return ['changed' => $changed, 'has_more' => $hasMore];
    }

    public static function status_link_image_exists(string $url): bool
    {
        $relative = str_replace('\\', '/', trim(substr($url, strlen('/uploads/links/')), '/'));
        if (!preg_match('~^[0-9]{4}/[0-9]{2}/[a-f0-9]{40}\.webp$~', $relative) || str_contains($relative, '..')) {
            return false;
        }

        return is_file(\TinyCat\Support\Paths::basePath('uploads/links/' . $relative));
    }

    /**
     * @param array<mixed> $link
     * @param array<mixed> $cached
     * @return array<mixed>
     */
    public static function status_link_prepare_metadata(array $link, ?array $cached, bool $localizeImage = false, string $localImageSource = ''): array
    {
        $failureKey = 'link-metadata-failure-v1-' . hash('sha256', (string) ($link['normalized_url'] ?? ''));
        if ($cached !== null && self::status_link_metadata_fresh($cached)) {
            $prepared = self::status_link_apply_cached_metadata($link, $cached, true);
        } elseif (\TinyCat\Support\Cache::get($failureKey, self::METADATA_FAILURE_TTL) === true) {
            $prepared = $cached !== null ? self::status_link_apply_cached_metadata($link, $cached, true) : $link;
            // A failed attempt must not make old metadata fresh for another day.
            $prepared['_metadata_updated_at'] = (string) ($cached['updated_at'] ?? '1970-01-01 00:00:00');
        } else {
            $prepared = \TinyCat\Status\Metadata::enrich($link);
            if (empty($prepared['_metadata_fetched'])) {
                \TinyCat\Support\Cache::put($failureKey, true, self::METADATA_FAILURE_TTL, 0);
                if ($cached !== null) {
                    $prepared = self::status_link_apply_cached_metadata($prepared, $cached, true);
                }
                $prepared['_metadata_updated_at'] = (string) ($cached['updated_at'] ?? '1970-01-01 00:00:00');
            } else {
                \TinyCat\Support\Cache::forget($failureKey);
                $prepared['_metadata_updated_at'] = \TinyCat\Support\Dates::dateDb();
            }
        }

        if ($localizeImage) {
            $imageUrl = $localImageSource ?: (string) ($prepared['image_url'] ?? '');
            $prepared['image_url'] = \TinyCat\Status\Links::status_link_image_cache($imageUrl);
        }

        return $prepared;
    }

    /**
     * @param array<mixed> $links
     */
    public static function status_sync_links(int $contentId, array $links, string $localImageLinkHash = '', string $localImageSource = ''): void
    {
        if ($contentId < 1) {
            return;
        }

        self::persistLinks($contentId, self::prepareLinks($links, $localImageLinkHash, $localImageSource));
    }

    /** @param array<mixed> $links
     * @return list<array<mixed>>
     */
    public static function prepareLinks(array $links, string $localImageLinkHash = '', string $localImageSource = ''): array
    {
        if (\TinyCat\Database\Connection::get()->inTransaction()) {
            throw new \LogicException('Link metadata must be prepared before starting a transaction.');
        }
        $metadataCache = self::status_link_metadata_cache($links);
        return \TinyCat\Http\Session::withoutLock(static function () use ($links, $metadataCache, $localImageLinkHash, $localImageSource): array {
            $prepared = [];
            foreach ($links as $link) {
                $link = (array) $link;
                $hash = (string) ($link['url_hash'] ?? '');
                if (\TinyCat\Support\Text::plain_text_limit((string) ($link['normalized_url'] ?? ''), 2048) === '' || $hash === '') { continue; }
                $cacheImage = $localImageLinkHash !== '' && hash_equals($localImageLinkHash, $hash);
                $prepared[] = self::status_link_prepare_metadata($link, $metadataCache[$hash] ?? null, $cacheImage, $cacheImage ? $localImageSource : '');
            }
            return $prepared;
        });
    }

    /** @param list<array<mixed>> $links */
    public static function persistLinks(int $contentId, array $links): void
    {
        \TinyCat\Database\Transactions::run(static function () use ($contentId, $links): void {
            $previousLinkIds = self::status_link_ids_for_content($contentId);
            \TinyCat\Database\Connection::delete('content_links', ['content_id' => $contentId]);
            foreach ($links as $link) {
                $linkId = \TinyCat\Status\Links::status_link_upsert($link);

                if ($linkId < 1) {
                    continue;
                }

                try {
                    \TinyCat\Database\Connection::insert('content_links', [
                        'content_id' => $contentId,
                        'link_id' => $linkId,
                        'created_at' => \TinyCat\Support\Dates::dateDb(),
                    ]);
                } catch (Throwable $exception) {
                    if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                        throw $exception;
                    }
                    // A duplicate URL relation is harmless under a concurrent attach.
                }
            }

            \TinyCat\Status\Links::status_cleanup_unused_link_ids($previousLinkIds);
        });
    }

    /**
     * @return array<mixed>
     */
    public static function status_link_ids_for_content(int $contentId): array
    {
        if ($contentId < 1) {
            return [];
        }

        return \TinyCat\Support\Text::positive_int_ids(array_column(
            \TinyCat\Database\Connection::all('SELECT link_id FROM content_links WHERE content_id = ?', [$contentId]),
            'link_id'
        ));
    }

    /**
     * @param array<mixed> $linkIds
     */
    public static function status_cleanup_unused_link_ids(array $linkIds): void
    {
        $linkIds = \TinyCat\Support\Text::positive_int_ids($linkIds);

        if ($linkIds === []) {
            return;
        }

        foreach (array_chunk($linkIds, 100) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            try {
                \TinyCat\Database\Connection::exec(
                    'DELETE FROM links
                        WHERE id IN (' . $placeholders . ')
                            AND NOT EXISTS (
                                SELECT 1
                                FROM content_links cl
                                WHERE cl.link_id = links.id
                            )',
                    $chunk
                );
            } catch (Throwable) {
                // Link cleanup is opportunistic and must not block saving a post.
            }
        }
    }

    /**
     * @param array<mixed> $contentIds
     * @return array<mixed>
     */
    public static function status_links_cache(array $contentIds): array
    {
        static $cache = [];

        $ids = \TinyCat\Support\Text::positive_int_ids($contentIds);

        if ($ids === []) {
            return [];
        }

        $missing = array_values(array_filter($ids, static fn (int $id): bool => !array_key_exists($id, $cache)));

        foreach ($missing as $id) {
            $cache[$id] = [];
        }

        if ($missing !== []) {
            foreach (\TinyCat\Database\Queries::db_select(
                'SELECT cl.content_id,
                        l.id AS link_id,
                        l.normalized_url,
                        l.url_hash,
                        l.provider,
                        l.link_type,
                        l.title,
                        l.description,
                        l.image_url,
                        l.video_id,
                        l.created_at,
                        l.updated_at
                    FROM content_links cl
                    INNER JOIN links l ON l.id = cl.link_id'
            )
                ->whereIn('cl.content_id', $missing)
                ->order('cl.content_id ASC, l.id ASC')
                ->all() as $row) {
                $contentId = (int) ($row['content_id'] ?? 0);

                if ($contentId > 0) {
                    $cache[$contentId][] = $row;
                }
            }
        }

        $result = [];

        foreach ($ids as $id) {
            $result[$id] = $cache[$id] ?? [];
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    public static function status_links_for_content(int $contentId): array
    {
        $links = \TinyCat\Status\Links::status_links_cache([$contentId]);

        return $links[$contentId] ?? [];
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_link_display_url(array $link): string
    {
        $url = (string) ($link['normalized_url'] ?? '');
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return trim($host . ($path !== '/' ? $path : ''));
    }

    public static function status_video_embed_allowed(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return in_array($host, [
            'www.youtube-nocookie.com',
            'youtube-nocookie.com',
            'www.youtube.com',
            'youtube.com',
            'player.vimeo.com',
            'www.dailymotion.com',
            'dailymotion.com',
        ], true);
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_video_embed_url(array $link): string
    {
        $provider = (string) ($link['provider'] ?? '');
        $videoId = trim((string) ($link['video_id'] ?? ''));

        if ($videoId === '') {
            return '';
        }

        $encodedId = rawurlencode($videoId);

        return match ($provider) {
            'youtube' => 'https://www.youtube.com/embed/' . $encodedId,
            'vimeo' => 'https://player.vimeo.com/video/' . $encodedId,
            'dailymotion' => 'https://www.dailymotion.com/embed/video/' . $encodedId,
            default => '',
        };
    }

    /**
     * @param array<mixed> $link
     * @return array<mixed>
     */
    public static function status_video_thumbnail_sources(array $link): array
    {
        $provider = (string) ($link['provider'] ?? '');
        $videoId = trim((string) ($link['video_id'] ?? ''));
        $imageUrl = trim((string) ($link['image_url'] ?? ''));

        if ($provider === 'youtube' && $videoId !== '') {
            $encodedId = rawurlencode($videoId);

            return [
                'webp' => 'https://i.ytimg.com/vi_webp/' . $encodedId . '/hqdefault.webp',
                'fallback' => 'https://i.ytimg.com/vi/' . $encodedId . '/hqdefault.jpg',
            ];
        }

        return [
            'webp' => '',
            'fallback' => $imageUrl,
        ];
    }
}
