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

    /** @return array<string, int> */
    public static function pageCounts(): array
    {
        // Only the directory is cached; document entries always recheck visibility.
        $cached = \TinyCat\Support\Cache::rememberArray('sitemap-page-counts-v1', 60, static function (): array {
            $pages = [];
            foreach (self::CORE_SECTIONS as $section) { $pages[$section] = self::pageCount($section); }
            return $pages;
        });
        $pages = [];
        foreach (self::CORE_SECTIONS as $section) { $pages[$section] = max(0, (int) ($cached[$section] ?? 0)); }
        return $pages;
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
            'pages' => count(self::staticEntries()),
            'authors' => (int) \TinyCat\Database\Connection::value(
                'SELECT COUNT(*) FROM users WHERE status = ?',
                ['active']
            ),
            'tags' => (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM terms t WHERE ' . self::publicTagPredicate(), [\TinyCat\Support\Dates::dateDb()]),
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

    private static function publicTagPredicate(): string
    {
        // Avoid indexing orphan tags and tags used only by hidden or future posts.
        return "EXISTS (SELECT 1 FROM content_tags ct INNER JOIN content c ON c.id = ct.content_id
            INNER JOIN users u ON u.id = c.author_id WHERE ct.term_id = t.id AND u.status = 'active'
            AND c.published_at <= ?)";
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
            'pages' => array_slice(self::staticEntries(), $offset, $limit),
            'authors' => \TinyCat\Database\Connection::all(
                'SELECT u.id
                    FROM users u
                    WHERE u.status = ?
                    ORDER BY u.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['active']
            ),
            'tags' => \TinyCat\Database\Connection::all(
                'SELECT t.id, t.name
                    FROM terms t WHERE ' . self::publicTagPredicate() . '
                    ORDER BY t.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset,
                [\TinyCat\Support\Dates::dateDb()]
            ),
            'status' => \TinyCat\Database\Connection::all(
                'SELECT c.id, ci.path AS image_path
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

    /** @return list<array{url: string, last_modified: null}> */
    private static function staticEntries(): array
    {
        $entries = [['url' => '/', 'last_modified' => null], ['url' => '/privacy', 'last_modified' => null]];
        foreach (array_keys(\TinyCat\Support\Languages::language_packages()) as $locale) {
            $entries[] = ['url' => LocaleRouting::url('/privacy', $locale), 'last_modified' => null];
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
