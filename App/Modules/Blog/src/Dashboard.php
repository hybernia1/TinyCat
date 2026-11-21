<?php
declare(strict_types=1);
namespace TinyCat\Blog;

final class Dashboard
{
    /** @return array<string, mixed> */
    public static function statistics(): array
    {
        $now = \TinyCat\Support\Dates::dateDb();
        $rows = \TinyCat\Database\Connection::one("SELECT COUNT(CASE WHEN status = 'published' AND published_at <= ? THEN 1 END) AS published, COUNT(CASE WHEN status = 'published' AND published_at > ? THEN 1 END) AS scheduled, COUNT(CASE WHEN status = 'hidden' THEN 1 END) AS hidden FROM blog_articles", [$now, $now]);
        $items = [];
        foreach (['published', 'scheduled', 'hidden'] as $status) { $items[] = ['key' => $status, 'value' => (int) ($rows[$status] ?? 0)]; }
        return ['items' => $items, 'url' => '/admin/articles'];
    }

    /** @return array<string, array<string, mixed>> */
    public static function statisticsFields(): array
    {
        $fields = [];
        foreach (['published', 'scheduled', 'hidden'] as $status) { $fields[$status] = ['label' => ['t' => 'blog.' . $status]]; }
        return $fields;
    }

    /** @return array<string, mixed> */
    public static function items(): array
    {
        $rows = Articles::query(false, true)->order('a.published_at DESC, a.id DESC')->limit(5)->all();
        return ['items' => array_map(static fn (array $row): array => ['label' => $row['title'], 'url' => '/articles/' . (int) $row['id'], 'icon' => 'file-text', 'detail' => $row['author'], 'time' => \TinyCat\Support\Dates::displayDateTime($row['published_at'])], $rows), 'url' => '/admin/articles'];
    }
}
