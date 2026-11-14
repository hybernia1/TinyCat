<?php
declare(strict_types=1);
namespace TinyCat\Blog;

final class SearchData
{
    public static function source(): \TinyCat\Core\Modules\SearchSource
    {
        return new \TinyCat\Core\Modules\SearchSource('articles', static fn (): string => \TinyCat\Support\Localization::t('blog.articles'), self::page(...), static fn (string $q, int $n): array => self::page($q, [], $n)['items'], 2, 40, self::authorize(...));
    }
    public static function authorize(string $mode, string $query): void
    {
        if (\TinyCat\Core\Security\RateLimitPolicy::isExempt(\TinyCat\Core\Identity\Authentication::auth())) { return; }
        if (!\TinyCat\Core\Security\RateLimiter::consume(\TinyCat\Core\Security\RateLimiter::rate_limit_ip(), 'blog.search.' . $mode, 60, $mode === 'suggest' ? 120 : 60)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('common.request_failed'), 429, 'action_limited', ['retry_after' => \TinyCat\Core\Security\RateLimiter::window_retry_after(60)]);
        }
    }
    /** @param array<string, mixed> $cursor
     * @return array{items: list<array<string, mixed>>, cursor: array<string, mixed>} */
    public static function page(string $text, array $cursor, int $limit): array
    {
        $query = Articles::visible(new \TinyCat\Database\Query('SELECT a.id, a.published_at, a.title, a.body FROM blog_articles a INNER JOIN users u ON u.id = a.author_id'));
        $words = array_slice(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
        foreach ($words as $word) { $term = '%' . \TinyCat\Database\Connection::escapeLike($word) . '%'; $query->where("(a.title LIKE ? ESCAPE '!' OR a.body LIKE ? ESCAPE '!')", $term, $term); }
        [$where, $params] = \TinyCat\Database\Keyset::beforeDateId((string) ($cursor['cursor_at'] ?? ''), max(0, (int) ($cursor['cursor_id'] ?? 0)), 'a.published_at', 'a.id');
        if ($where !== '') { $query->where($where, ...$params); }
        $rows = $query->order('a.published_at DESC, a.id DESC')->limit($limit + 1)->all(); $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
        $last = $rows[array_key_last($rows)] ?? null;
        return ['items' => array_values(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'type' => 'article', 'title' => $r['title'], 'excerpt' => \TinyCat\Support\Text::plain_text_limit($r['body'], 180), 'url' => '/articles/' . (int) $r['id'], 'published_at' => \TinyCat\Support\Dates::dateIso($r['published_at'])], $rows)), 'cursor' => $more && $last !== null ? ['cursor_at' => $last['published_at'], 'cursor_id' => (int) $last['id']] : []];
    }
}
