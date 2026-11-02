<?php
declare(strict_types=1);
namespace TinyCat\Status;

final class Redirects
{
    public static function target(int $id): ?int
    {
        $target = \TinyCat\Database\Connection::value("SELECT c.id FROM content_redirects r
            INNER JOIN content c ON c.id=r.content_id INNER JOIN users u ON u.id=c.author_id
            WHERE r.old_id=? AND c.status='published' AND c.published_at<=? AND u.status='active'", [$id, \TinyCat\Support\Dates::dateDb()]);
        return $target === null ? null : (int) $target;
    }
}
