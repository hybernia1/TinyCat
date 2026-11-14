<?php
declare(strict_types=1);
namespace TinyCat\Social\Profiles;

final class SitemapData
{
    public static function count(): int { return (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM users WHERE status = ?', ['active']); }
    /** @return list<array{url: string, last_modified: ?string}> */
    public static function entries(int $limit, int $offset): array
    {
        $rows = \TinyCat\Database\Connection::all('SELECT id FROM users WHERE status = ? ORDER BY id ASC LIMIT ' . $limit . ' OFFSET ' . $offset, ['active']);
        return array_values(array_map(static fn (array $r): array => ['url' => Profiles::author_url((int) $r['id']), 'last_modified' => null], $rows));
    }
}
