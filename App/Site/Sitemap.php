<?php
declare(strict_types=1);

namespace TinyCat\Site;

use InvalidArgumentException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Owns TinyCat sitemap sections.
 */
final class Sitemap
{
    public const string FILE_PATTERN = 'sitemap-[a-z][a-z0-9_-]{0,63}-[1-9][0-9]*[.]xml';

    private const array CORE_SECTIONS = ['pages', 'authors', 'status', 'tags'];
    private const int MAX_PAGE_SIZE = 1000;

    private function __construct()
    {
    }

    /**
     * @return array<mixed>
     */
    public static function sections(): array
    {
        return self::CORE_SECTIONS;
    }

    public static function hasSection(string $section): bool
    {
        $section = strtolower(trim($section));

        return in_array($section, self::CORE_SECTIONS, true);
    }

    public static function count(string $section): int
    {
        $section = strtolower(trim($section));

        return match ($section) {
            'pages' => 1,
            'authors' => (int) \TinyCat\Database\Connection::value(
                'SELECT COUNT(*) FROM users WHERE status = ?',
                ['active']
            ),
            'tags' => (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM terms'),
            'status' => (int) \TinyCat\Database\Connection::value(
                'SELECT COUNT(*)
                    FROM content c
                    INNER JOIN users u ON u.id = c.author_id
                    WHERE u.status = ?
                        AND c.published_at <= ?',
                ['active', \TinyCat\Support\Dates::dateDb()]
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

        $rows = match ($section) {
            'pages' => $offset === 0 ? [['url' => '/', 'last_modified' => null]] : [],
            'authors' => \TinyCat\Database\Connection::all(
                'SELECT u.id, u.updated_at AS last_modified
                    FROM users u
                    WHERE u.status = ?
                    ORDER BY u.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['active']
            ),
            'tags' => \TinyCat\Database\Connection::all(
                'SELECT t.id, t.name
                    FROM terms t
                    ORDER BY t.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset
            ),
            'status' => \TinyCat\Database\Connection::all(
                'SELECT c.id, c.published_at AS last_modified, ci.path AS image_path
                    FROM content c
                    INNER JOIN users u ON u.id = c.author_id
                    LEFT JOIN content_images ci ON ci.content_id = c.id
                    WHERE u.status = ?
                        AND c.published_at <= ?
                    ORDER BY c.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['active', \TinyCat\Support\Dates::dateDb()]
            ),
            default => throw new InvalidArgumentException('Sitemap section is not registered: ' . $section),
        };

        $entries = [];
        foreach ($rows as $row) {
            $url = match ($section) {
                'pages' => (string) ($row['url'] ?? '/'),
                'authors' => \TinyCat\Users\Profiles::author_url((int) ($row['id'] ?? 0)),
                'tags' => ($tag = \TinyCat\Status\Tags::status_tag_normalize((string) ($row['name'] ?? ''))) !== '' ? \TinyCat\Status\Tags::tag_url($tag) : '',
                default => \TinyCat\Status\Posts::status_url((int) ($row['id'] ?? 0)),
            };

            if ($url !== '') {
                $entry = [
                    'url' => $url,
                    'last_modified' => isset($row['last_modified']) ? (string) $row['last_modified'] : null,
                ];
                $imageUrl = \TinyCat\Status\ImageStorage::url((string) ($row['image_path'] ?? ''));
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
}
