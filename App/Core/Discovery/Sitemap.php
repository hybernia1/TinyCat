<?php
declare(strict_types=1);
namespace TinyCat\Core\Discovery;

use TinyCat\Core\Modules\{Registrations, Resources, SitemapSection};
use TinyCat\Support\{Cache, LocalUrls, Xml};
use TinyCat\Http\Document;

/** XML and pagination only; active owners provide visibility and canonical URLs. */
final class Sitemap
{
    public const FILE_PATTERN = 'sitemap-[a-z][a-z0-9_-]{0,63}-[1-9][0-9]*[.]xml';
    /** @return list<string> */
    public static function sections(): array { return array_map(static fn (SitemapSection $s): string => $s->id, Registrations::current()->entries->sitemaps); }
    private static function section(string $id): SitemapSection
    {
        foreach (Registrations::current()->entries->sitemaps as $s) { if ($s->id === $id) { return $s; } }
        throw new \InvalidArgumentException('Sitemap section is not registered: ' . $id);
    }
    public static function hasSection(string $id): bool { return in_array($id, self::sections(), true); }
    public static function count(string $id): int { return max(0, (self::section($id)->count)()); }
    public static function pageCount(string $id, int $size = 1000): int { return (int) ceil(self::count($id) / max(1, min(1000, $size))); }
    /** @return array<string, int> */
    public static function pageCounts(): array
    {
        return Cache::rememberArray('sitemap-page-counts-v2-' . Resources::fingerprint(), 60, static function (): array { $result = []; foreach (self::sections() as $id) { $result[$id] = self::pageCount($id); } return $result; });
    }
    /** @return list<array{url: string, last_modified: ?string, image_url?: string}> */
    public static function entries(string $id, int $limit, int $offset): array { return (self::section($id)->entries)(max(1, min(1000, $limit)), max(0, $offset)); }
    public static function url(string $id = 'index', int $page = 1): string { return $id === 'index' ? '/sitemap.xml' : (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) ? '/sitemap-' . $id . '-' . max(1, $page) . '.xml' : ''); }
    /** @return array{section: string, page: int}|null */
    public static function parseFile(string $name): ?array { return preg_match('/^sitemap-([a-z][a-z0-9_-]{0,63})-([1-9][0-9]*)\.xml$/D', $name, $m) ? ['section' => $m[1], 'page' => (int) $m[2]] : null; }
    public static function respond(string $section = 'index', int $page = 1): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        if ($section === 'index') {
            $body .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (self::pageCounts() as $id => $pages) { for ($part = 1; $part <= $pages; $part++) { $body .= '<sitemap><loc>' . Xml::escape(LocalUrls::absolute(self::url($id, $part))) . '</loc></sitemap>'; } }
            $body .= '</sitemapindex>';
        } else {
            if ($page < 1 || $page > intdiv(PHP_INT_MAX, 1000) || !self::hasSection($section)) { Document::notFound('Sitemap not found.'); return; }
            $rows = self::entries($section, 1000, ($page - 1) * 1000);
            if ($rows === []) { Document::notFound('Sitemap page not found.'); return; }
            $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
            foreach ($rows as $row) {
                $body .= '<url><loc>' . Xml::escape(LocalUrls::absolute($row['url'])) . '</loc>';
                if (!empty($row['image_url'])) { $body .= '<image:image><image:loc>' . Xml::escape(LocalUrls::absolute($row['image_url'])) . '</image:loc></image:image>'; }
                if (!empty($row['last_modified'])) { $body .= '<lastmod>' . Xml::escape($row['last_modified']) . '</lastmod>'; }
                $body .= '</url>';
            }
            $body .= '</urlset>';
        }
        Document::respond($body . "\n", 'application/xml', 900);
    }
}
