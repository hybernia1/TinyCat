<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Database\{Connection, Query};
use TinyCat\Support\{AdminSorting, AdminViews, Dates, Pagination};

/** Article-owned selection; the core supplies list transport and presentation primitives. */
final class Administration
{
    public const SORTING = ['/api/articles/management', '/admin/articles', '#blog-articles', ['title' => 'a.title', 'author' => 'u.username', 'published_at' => 'a.published_at'], 'a.published_at DESC, a.id DESC', 'a.id'];

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function listing(array $input): array
    {
        $q = AdminViews::admin_filter_text(is_string($input['q'] ?? null) ? $input['q'] : '');
        $status = is_string($input['status'] ?? null) && in_array($input['status'], ['published', 'hidden', 'scheduled'], true) ? $input['status'] : '';
        $authors = Connection::all("SELECT DISTINCT u.id, u.username FROM users u INNER JOIN blog_articles a ON a.author_id = u.id ORDER BY u.username, u.id");
        $author = is_scalar($input['author'] ?? null) ? (string) $input['author'] : '';
        if (!in_array($author, array_map(static fn (array $row): string => (string) $row['id'], $authors), true)) { $author = ''; }
        $query = new Query('SELECT a.id FROM blog_articles a INNER JOIN users u ON u.id = a.author_id');
        if ($q !== '') { $like = '%' . Connection::escapeLike($q) . '%'; $query->where("(a.title LIKE ? ESCAPE '!' OR a.body LIKE ? ESCAPE '!')", $like, $like); }
        if ($author !== '') { $query->where('a.author_id = ?', (int) $author); }
        if ($status === 'hidden') { $query->where('a.status = ?', 'hidden'); }
        if ($status === 'published' || $status === 'scheduled') { $query->where('a.status = ?', 'published')->where('a.published_at ' . ($status === 'scheduled' ? '>' : '<=') . ' ?', Dates::dateDb()); }
        $pagination = Pagination::paginationMeta($query->count(), max(1, (int) ($input['page'] ?? 1)), AdminViews::admin_per_page((int) ($input['per_page'] ?? 25)));
        $filters = ['q' => $q, 'status' => $status, 'author' => $author, 'per_page' => $pagination['per_page']] + AdminSorting::state(self::SORTING, $input);
        $ids = array_column($query->order(AdminSorting::order(self::SORTING, $filters))->limit((int) $pagination['per_page'], (int) $pagination['offset'])->all(), 'id');
        $items = [];
        if ($ids !== []) {
            $rows = (new Query('SELECT a.id, a.author_id, a.published_at, a.status, a.title, u.username AS author, u.status AS author_status FROM blog_articles a INNER JOIN users u ON u.id = a.author_id'))->whereIn('a.id', $ids)->all();
            $indexed = array_column($rows, null, 'id');
            foreach ($ids as $id) {
                $row = $indexed[$id];
                $row['id'] = (int) $row['id']; $row['author_id'] = (int) $row['author_id'];
                $row['state'] = $row['status'] === 'published' && $row['published_at'] > Dates::dateDb() ? 'scheduled' : $row['status'];
                $row['publicly_visible'] = $row['state'] === 'published' && $row['author_status'] === 'active';
                unset($row['author_status']);
                $items[] = $row;
            }
        }
        return compact('items', 'filters', 'authors', 'pagination') + ['sorting' => AdminSorting::headings(self::SORTING, $filters), 'navigation' => AdminViews::admin_pagination_view_data($pagination, self::SORTING[0], self::SORTING[2], $filters, historyPath: self::SORTING[1])];
    }

    /** @return array<string, mixed> */
    public static function editor(int $id = 0): array
    {
        $article = $id > 0 ? Articles::find($id, false) : ['id' => 0, 'title' => '', 'body' => '', 'status' => 'published'];
        if ($article === null) { \TinyCat\Http\Response::error('Article not found.', 404, 'article_not_found'); }
        return ['item' => $article, 'image' => ['url' => Images::url($article)], 'images_enabled' => Images::enabled(), 'permissions' => ['save' => true, 'delete' => $id > 0], 'limits' => ['title' => 200, 'body_bytes' => 60000], 'statuses' => ['published', 'hidden']];
    }
}
