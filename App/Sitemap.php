<?php
declare(strict_types=1);

namespace TinyCat;

use Core;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Owns TinyCat sitemap sections and accepts additional extension sections.
 */
final class Sitemap
{
    public const string FILE_PATTERN = 'sitemap-[a-z][a-z0-9_-]{0,63}-[1-9][0-9]*[.]xml';

    private const array CORE_SECTIONS = ['pages', 'authors', 'status', 'tags'];
    private const array RESERVED_SECTIONS = ['index', ...self::CORE_SECTIONS];
    private const int MAX_PAGE_SIZE = 1000;

    private static array $extensionSections = [];

    private function __construct()
    {
    }

    public static function registerExtension(string $slug, mixed $definition): void
    {
        if ($definition === null) {
            return;
        }

        $slug = strtolower(trim($slug));
        if (
            preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1
            || in_array($slug, self::RESERVED_SECTIONS, true)
        ) {
            throw new LogicException('Extension sitemap conflicts with a reserved section: ' . $slug);
        }

        if (isset(self::$extensionSections[$slug])) {
            throw new LogicException('Extension sitemap section is already registered: ' . $slug);
        }

        if (!is_array($definition) || array_is_list($definition)) {
            throw new InvalidArgumentException('Invalid extension sitemap definition: ' . $slug);
        }

        $unknown = array_diff(array_keys($definition), ['count', 'entries']);
        if ($unknown !== [] || !is_callable($definition['count'] ?? null) || !is_callable($definition['entries'] ?? null)) {
            throw new InvalidArgumentException('Invalid extension sitemap definition: ' . $slug);
        }

        self::$extensionSections[$slug] = [
            'count' => $definition['count'],
            'entries' => $definition['entries'],
        ];
    }

    public static function sections(): array
    {
        return [...self::CORE_SECTIONS, ...array_keys(self::$extensionSections)];
    }

    public static function hasSection(string $section): bool
    {
        $section = strtolower(trim($section));

        return in_array($section, self::CORE_SECTIONS, true)
            || isset(self::$extensionSections[$section]);
    }

    public static function count(string $section): int
    {
        $section = strtolower(trim($section));

        if (isset(self::$extensionSections[$section])) {
            $count = self::$extensionSections[$section]['count']();
            if (!is_int($count) || $count < 0) {
                throw new RuntimeException('Extension sitemap count must be a non-negative integer: ' . $section);
            }

            return $count;
        }

        return match ($section) {
            'pages' => 1,
            'authors' => (int) Core::value(
                'SELECT COUNT(*) FROM users WHERE status = ?',
                ['active']
            ),
            'tags' => (int) Core::value('SELECT COUNT(*) FROM terms'),
            'status' => (int) Core::value(
                'SELECT COUNT(*)
                    FROM content c
                    INNER JOIN users u ON u.id = c.author_id
                    WHERE u.status = ?
                        AND c.published_at <= ?',
                ['active', date_db()]
            ),
            default => throw new InvalidArgumentException('Sitemap section is not registered: ' . $section),
        };
    }

    public static function pageCount(string $section, int $pageSize = self::MAX_PAGE_SIZE): int
    {
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));

        return max(0, (int) ceil(self::count($section) / $pageSize));
    }

    /**
     * @return list<array{url: string, last_modified: ?string, image_url?: string}>
     */
    public static function entries(string $section, int $limit, int $offset): array
    {
        $section = strtolower(trim($section));
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $offset = max(0, $offset);

        if (isset(self::$extensionSections[$section])) {
            $entries = self::$extensionSections[$section]['entries']($limit, $offset);
            if (!is_array($entries) || !array_is_list($entries) || count($entries) > $limit) {
                throw new RuntimeException('Extension sitemap entries must be a list within the requested limit: ' . $section);
            }

            return array_map(
                static fn (mixed $entry): array => self::validateExtensionEntry($section, $entry),
                $entries
            );
        }

        $rows = match ($section) {
            'pages' => $offset === 0 ? [['url' => '/', 'last_modified' => null]] : [],
            'authors' => Core::all(
                'SELECT u.id, u.updated_at AS last_modified
                    FROM users u
                    WHERE u.status = ?
                    ORDER BY u.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['active']
            ),
            'tags' => Core::all(
                'SELECT t.id, t.name
                    FROM terms t
                    ORDER BY t.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset
            ),
            'status' => Core::all(
                'SELECT c.id, c.published_at AS last_modified, ci.path AS image_path
                    FROM content c
                    INNER JOIN users u ON u.id = c.author_id
                    LEFT JOIN content_images ci ON ci.content_id = c.id
                    WHERE u.status = ?
                        AND c.published_at <= ?
                    ORDER BY c.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['active', date_db()]
            ),
            default => throw new InvalidArgumentException('Sitemap section is not registered: ' . $section),
        };

        $entries = [];
        foreach ($rows as $row) {
            $url = match ($section) {
                'pages' => (string) ($row['url'] ?? '/'),
                'authors' => author_url((int) ($row['id'] ?? 0)),
                'tags' => ($tag = status_tag_normalize((string) ($row['name'] ?? ''))) !== '' ? tag_url($tag) : '',
                default => status_url((int) ($row['id'] ?? 0)),
            };

            if ($url !== '') {
                $entry = [
                    'url' => $url,
                    'last_modified' => isset($row['last_modified']) ? (string) $row['last_modified'] : null,
                ];
                $imageUrl = \StatusImage::url((string) ($row['image_path'] ?? ''));
                if ($imageUrl !== '') {
                    $entry['image_url'] = $imageUrl;
                }
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public static function url(string $section = 'index', int $page = 1): string
    {
        $section = strtolower(trim($section));

        if ($section === 'index') {
            return '/sitemap.xml';
        }

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $section) !== 1) {
            return '';
        }

        return '/sitemap-' . $section . '-' . max(1, $page) . '.xml';
    }

    /**
     * @return array{section: string, page: int}|null
     */
    public static function parseFile(string $fileName): ?array
    {
        if (preg_match('/^sitemap-([a-z][a-z0-9_-]{0,63})-([1-9][0-9]*)\.xml$/D', $fileName, $matches) !== 1) {
            return null;
        }

        return [
            'section' => $matches[1],
            'page' => (int) $matches[2],
        ];
    }

    private static function validateExtensionEntry(string $section, mixed $entry): array
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new RuntimeException('Invalid extension sitemap entry: ' . $section);
        }

        $unknown = array_diff(array_keys($entry), ['url', 'last_modified', 'image_url']);
        $url = $entry['url'] ?? null;
        if ($unknown !== [] || !is_string($url) || $url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new RuntimeException('Invalid extension sitemap entry: ' . $section);
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || array_diff(array_keys($parts), ['path']) !== []
            || !str_starts_with($url, '/')
            || str_starts_with($url, '//')
            || Core::path($url) !== $url
        ) {
            throw new RuntimeException('Extension sitemap URLs must be normalized local paths: ' . $section);
        }

        $lastModified = $entry['last_modified'] ?? null;
        if ($lastModified !== null && (!is_string($lastModified) || $lastModified === '' || strtotime($lastModified) === false)) {
            throw new RuntimeException('Invalid extension sitemap last-modified value: ' . $section);
        }

        $imageUrl = $entry['image_url'] ?? null;
        if ($imageUrl !== null && (
            !is_string($imageUrl)
            || $imageUrl === ''
            || str_starts_with($imageUrl, '//')
            || Core::path($imageUrl) !== $imageUrl
        )) {
            throw new RuntimeException('Invalid extension sitemap image URL: ' . $section);
        }

        $validated = [
            'url' => $url,
            'last_modified' => $lastModified,
        ];

        if ($imageUrl !== null) {
            $validated['image_url'] = $imageUrl;
        }

        return $validated;
    }
}
