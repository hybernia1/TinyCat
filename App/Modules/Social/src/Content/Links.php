<?php
declare(strict_types=1);

namespace TinyCat\Social\Content;

use TinyCat\Core\Links\Audio;
use TinyCat\Core\Links\Providers;
use TinyCat\Core\Text\LinkParser;
use TinyCat\Core\Text\PostText;

use RuntimeException;
use Throwable;

class Links extends \TinyCat\Core\Links\Presentation
{
    private const METADATA_FAILURE_TTL = 300;

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
            foreach (\TinyCat\Database\Queries::db_select('SELECT * FROM social_links')
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
        $canonical = LinkParser::fromRaw((string) ($link['normalized_url'] ?? ''));
        if ($canonical === null) { return []; }
        $normalizedUrl = $canonical['normalized_url'];
        $hash = $canonical['url_hash'];
        $link = array_replace($link, ['normalized_url' => $normalizedUrl, 'url_hash' => $hash]);

        $taxonomy = Providers::taxonomy($link);
        return [
            'normalized_url' => $normalizedUrl,
            'url_hash' => $hash,
            'provider' => \TinyCat\Support\Text::plain_text_limit($taxonomy['provider'], 40),
            'link_type' => $taxonomy['link_type'],
            'title' => \TinyCat\Support\Text::plain_text_limit((string) ($link['title'] ?? ''), 255),
            'description' => \TinyCat\Support\Text::plain_text_limit(PostText::normalize((string) ($link['description'] ?? '')), 500),
            'image_url' => Providers::youtubeThumbnails($normalizedUrl) !== [] ? '' : \TinyCat\Support\Text::plain_text_limit((string) ($link['image_url'] ?? ''), 2048),
            'video_id' => \TinyCat\Support\Text::plain_text_limit((string) ($link['video_id'] ?? ''), 80),
            'audio_url' => \TinyCat\Support\Text::plain_text_limit((string) ($link['audio_url'] ?? ''), 2048),
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

        return \TinyCat\Database\Queries::db_select('SELECT * FROM social_links')
            ->where('url_hash = ?', $hash)
            ->limit(1)
            ->one();
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_link_upsert(array $link): int
    {
        $data = \TinyCat\Social\Content\Links::status_link_data($link);

        if ($data === []) {
            return 0;
        }

        $existing = \TinyCat\Social\Content\Links::status_link_find_by_hash((string) $data['url_hash']);

        if ($existing === null) {
            try {
                $id = \TinyCat\Database\Connection::insert('social_links', $data + ['created_at' => \TinyCat\Support\Dates::dateDb()]);

                return max(0, (int) $id);
            } catch (Throwable $exception) {
                if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                    throw $exception;
                }

                $existing = \TinyCat\Social\Content\Links::status_link_find_by_hash((string) $data['url_hash']);
            }
        }

        $id = (int) ($existing['id'] ?? 0);

        if ($id > 0) {
            \TinyCat\Database\Connection::update('social_links', $data, ['id' => $id]);
        }

        return $id;
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_link_metadata_fresh(?array $link): bool
    {
        if ($link === null || Providers::needsMetadataRefresh($link)) {
            return false;
        }

        if (!\TinyCat\Social\Content\Links::status_link_metadata_has_content($link)) {
            return false;
        }

        $updatedAt = strtotime((string) ($link['updated_at'] ?? ''));

        return $updatedAt > 0 && $updatedAt >= time() - \TinyCat\Social\Content\Links::status_link_metadata_ttl();
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_link_metadata_has_content(array $link): bool
    {
        $type = (string) ($link['link_type'] ?? 'web');
        $title = trim((string) ($link['title'] ?? ''));
        $description = trim((string) ($link['description'] ?? ''));
        if ($type === 'audio' && !empty($link['audio_url'])) { return true; }

        if (Providers::googleMap((string) ($link['normalized_url'] ?? '')) !== null) { return $title !== ''; }

        if ($type === 'video') {
            return $title !== '' && !Providers::titleIsPlaceholder($title);
        }

        $fallback = \TinyCat\Core\Text\LinkParser::fromRaw((string) ($link['normalized_url'] ?? ''));
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
        foreach (['provider', 'link_type', 'title', 'description', 'image_url', 'video_id', 'audio_url'] as $key) {
            $value = (string) ($cached[$key] ?? '');

            if ($value !== '') {
                $link[$key] = $value;
            }
        }

        $updatedAt = (string) ($cached['updated_at'] ?? '');
        $link['_metadata_updated_at'] = $preserveTimestamp && $updatedAt !== '' ? $updatedAt : \TinyCat\Support\Dates::dateDb();

        return $link;
    }

    public static function status_link_image_cache(string $imageUrl, string $variant = 'card'): string
    {
        return Integration::blockedHost($imageUrl) === '' ? \TinyCat\Core\Files\RemoteImageCache::store($imageUrl, $variant) : '';
    }

    /** @return array{changed: int, has_more: bool} */
    public static function cleanupOrphanImages(int $limit, string $relativeDirectory = ''): array { return \TinyCat\Core\Files\RemoteImageCache::cleanup($limit, $relativeDirectory); }
    public static function status_link_image_exists(string $url): bool { return \TinyCat\Core\Files\RemoteImageCache::exists($url); }

    /**
     * @param array<mixed> $link
     * @param array<mixed> $cached
     * @return array<mixed>
     * @param array{url_hash: string, title: string, description: string, image_urls: list<string>, audio_url?: string}|null $source
     */
    public static function status_link_prepare_metadata(array $link, ?array $cached, bool $localizeImage = false, ?array $source = null): array
    {
        $failureKey = 'link-metadata-failure-v1-' . hash('sha256', (string) ($link['normalized_url'] ?? ''));
        $fromSource = $source !== null && $source['title'] !== '';
        if ($fromSource) {
            $prepared = array_replace($link, ['title' => $source['title'], 'description' => $source['description'], '_metadata_updated_at' => \TinyCat\Support\Dates::dateDb()]);
        } elseif ($cached !== null && self::status_link_metadata_fresh($cached)) {
            $prepared = self::status_link_apply_cached_metadata($link, $cached, true);
        } elseif (\TinyCat\Support\Cache::get($failureKey, self::METADATA_FAILURE_TTL) === true) {
            $prepared = $cached !== null ? self::status_link_apply_cached_metadata($link, $cached, true) : $link;
            // A failed attempt must not make old metadata fresh for another day.
            $prepared['_metadata_updated_at'] = (string) ($cached['updated_at'] ?? '1970-01-01 00:00:00');
        } else {
            $prepared = \TinyCat\Core\Links\Metadata::enrich($link);
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

        if (!empty($source['audio_url'])) {
            $audio = Audio::remote((string) $source['audio_url']);
            if ($audio !== '') { $prepared['audio_url'] = $audio; $prepared['link_type'] = 'audio'; }
        }
        if (Providers::youtubeThumbnails((string) ($prepared['normalized_url'] ?? '')) !== []) {
            $prepared['image_url'] = '';
        } elseif ($localizeImage) {
            $kind = Providers::presentation($prepared)['card_kind'];
            $variant = in_array($kind, ['social', 'video', 'reel'], true) ? $kind : 'card';
            $sources = array_values(array_unique(array_merge($source['image_urls'] ?? [], Providers::imageCandidates($prepared))));
            $prepared['image_url'] = '';
            foreach ($sources as $image) {
                if ($image === '') { continue; }
                $prepared['image_url'] = self::status_link_image_cache($image, $variant);
                if ($prepared['image_url'] !== '') { break; }
            }
            if ($prepared['image_url'] === '' && $fromSource) {
                // Only fetch the article when all images supplied by its feed failed.
                $fallback = self::status_link_prepare_metadata($link, $cached);
                $prepared['image_url'] = self::status_link_image_cache((string) ($fallback['image_url'] ?? ''), $variant);
            }
        }

        if (!empty($prepared['audio_url'])) { $prepared['audio_url'] = Audio::remote((string) $prepared['audio_url']); }
        return $prepared;
    }

    /**
     * @param array<mixed> $links
     * @param array{url_hash: string, title: string, description: string, image_urls: list<string>, audio_url?: string}|null $source
     */
    public static function status_sync_links(int $contentId, array $links, ?array $source = null): void
    {
        if ($contentId < 1) {
            return;
        }

        self::persistLinks($contentId, self::prepareLinks($links, $source));
    }

    /** @param array<mixed> $links
     * @param array{url_hash: string, title: string, description: string, image_urls: list<string>, audio_url?: string}|null $source
     * @return list<array<mixed>>
     */
    public static function prepareLinks(array $links, ?array $source = null): array
    {
        if (\TinyCat\Database\Connection::get()->inTransaction()) {
            throw new \LogicException('Link metadata must be prepared before starting a transaction.');
        }
        $unique = [];
        foreach ($links as $link) {
            $link = (array) $link;
            $canonical = LinkParser::fromRaw((string) ($link['normalized_url'] ?? ''));
            if ($canonical === null) { continue; }
            $unique[$canonical['url_hash']] ??= array_replace($link, ['normalized_url' => $canonical['normalized_url'], 'url_hash' => $canonical['url_hash']]);
        }
        $links = array_values($unique);
        $metadataCache = self::status_link_metadata_cache($links);
        return \TinyCat\Http\Session::withoutLock(static function () use ($links, $metadataCache, $source): array {
            $prepared = [];
            foreach ($links as $link) {
                $link = (array) $link;
                $hash = (string) ($link['url_hash'] ?? '');
                if (\TinyCat\Support\Text::plain_text_limit((string) ($link['normalized_url'] ?? ''), 2048) === '' || $hash === '') { continue; }
                $cacheImage = $source !== null && $source['url_hash'] !== '' && hash_equals($source['url_hash'], $hash);
                $prepared[] = self::status_link_prepare_metadata($link, $metadataCache[$hash] ?? null, true, $cacheImage ? $source : null);
            }
            return $prepared;
        });
    }

    /** @param list<array<mixed>> $links */
    public static function persistLinks(int $contentId, array $links): void
    {
        \TinyCat\Database\Transactions::run(static function () use ($contentId, $links): void {
            $previousLinkIds = self::status_link_ids_for_content($contentId);
            \TinyCat\Database\Connection::delete('social_post_links', ['content_id' => $contentId]);
            foreach ($links as $link) {
                $linkId = \TinyCat\Social\Content\Links::status_link_upsert($link);

                if ($linkId < 1) {
                    continue;
                }

                try {
                    \TinyCat\Database\Connection::insert('social_post_links', [
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

            \TinyCat\Social\Content\Links::status_cleanup_unused_link_ids($previousLinkIds);
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
            \TinyCat\Database\Connection::all('SELECT link_id FROM social_post_links WHERE content_id = ?', [$contentId]),
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
                    'DELETE FROM social_links
                        WHERE id IN (' . $placeholders . ')
                            AND NOT EXISTS (
                                SELECT 1
                                FROM social_post_links cl
                                WHERE cl.link_id = social_links.id
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
                        l.audio_url,
                        l.created_at,
                        l.updated_at
                    FROM social_post_links cl
                    INNER JOIN social_links l ON l.id = cl.link_id'
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
     * @return list<array<mixed>>
     */
    public static function status_links_for_content(int $contentId): array
    {
        $links = \TinyCat\Social\Content\Links::status_links_cache([$contentId]);
        $rows = $links[$contentId] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }
}
