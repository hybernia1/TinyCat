<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Database\{Connection, Query};
use TinyCat\Support\{AdminSorting, AdminViews, Pagination};

final class FeedAdministration
{
    public const SORTING = ['/api/article-sources/management', '/admin/article-sources', '#blog-sources', ['name' => 's.name', 'author' => 'u.username', 'last_checked_at' => 's.last_checked_at'], 's.id DESC', 's.id'];

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function listing(array $input): array
    {
        $q = AdminViews::admin_filter_text(is_string($input['q'] ?? null) ? $input['q'] : '');
        $enabled = in_array($input['enabled'] ?? '', ['0', '1'], true) ? $input['enabled'] : '';
        $query = new Query('SELECT s.*, u.username AS author FROM blog_sources s INNER JOIN users u ON u.id = s.author_id');
        if ($q !== '') { $like = '%' . Connection::escapeLike($q) . '%'; $query->where("(s.name LIKE ? ESCAPE '!' OR s.feed_url LIKE ? ESCAPE '!')", $like, $like); }
        if ($enabled !== '') { $query->where('s.enabled = ?', (int) $enabled); }
        $pagination = Pagination::paginationMeta($query->count(), max(1, (int) ($input['page'] ?? 1)), AdminViews::admin_per_page((int) ($input['per_page'] ?? 25)));
        $filters = ['q' => $q, 'enabled' => $enabled, 'per_page' => $pagination['per_page']] + AdminSorting::state(self::SORTING, $input);
        $items = array_map(self::resource(...), $query->order(AdminSorting::order(self::SORTING, $filters))->limit((int) $pagination['per_page'], (int) $pagination['offset'])->all());
        return compact('items', 'filters', 'pagination') + ['sorting' => AdminSorting::headings(self::SORTING, $filters), 'navigation' => AdminViews::admin_pagination_view_data($pagination, self::SORTING[0], self::SORTING[2], $filters, historyPath: self::SORTING[1])];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed> */
    public static function resource(array $row): array
    {
        unset($row['feed_hash'], $row['revision']);
        foreach (['id', 'author_id', 'interval_minutes'] as $key) { $row[$key] = (int) $row[$key]; }
        foreach (['enabled', 'import_images'] as $key) { $row[$key] = (bool) $row[$key]; }
        foreach (['last_checked_at', 'last_imported_at', 'next_run_at', 'created_at'] as $key) { $row[$key] = !empty($row[$key]) ? \TinyCat\Support\Dates::dateIso($row[$key]) : ''; }
        return $row;
    }

    /** @return array<string, mixed> */
    public static function editor(int $id = 0): array
    {
        $item = $id > 0 ? FeedSources::find($id) : ['id' => 0, 'author_id' => 0, 'name' => '', 'feed_url' => '', 'interval_minutes' => 60, 'publication_status' => 'hidden', 'enabled' => true, 'import_images' => false];
        if ($item === null) { \TinyCat\Http\Response::error('Source not found.', 404, 'article_source_not_found'); }
        $authors = Connection::all("SELECT id, username FROM users WHERE role = 'admin' AND status = 'active' ORDER BY username, id");
        if ($id === 0) { $item['author_id'] = (int) ($authors[0]['id'] ?? 0); }
        return ['item' => $id > 0 ? self::resource($item) : $item, 'authors' => $authors, 'statuses' => ['hidden', 'published'], 'permissions' => ['save' => true, 'delete' => $id > 0, 'change_feed_url' => $id === 0]];
    }

    public static function page(): void
    {
        \TinyCat\Core\Identity\Access::require_admin();
        $title = \TinyCat\Support\Localization::t('blog.feeds.title');
        $content = \TinyCat\Support\Components::render('blog/sources', self::listing(\TinyCat\Http\Request::current()->query()));
        $card = AdminViews::listCard($title, 'rss', 'blog-sources', 'blog-source-filter', new \TinyCat\Support\Markup($content), primary: true);
        Pages::render(['title' => $title, 'current' => '/admin/article-sources', 'actions' => AdminViews::modalAction('modal-article-sources-creation', \TinyCat\Support\Localization::t('blog.feeds.create'), 'plus', '/api/article-sources/creation')], $card);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/api/article-sources/management', static fn (\TinyCat\Http\Request $request): array => self::listing($request->query()), 'admin');
        $api->get('/api/article-sources/creation', static fn (): array => self::editor(), 'admin');
        $api->get('/api/article-sources/{id:[1-9][0-9]*}/management', static fn (\TinyCat\Http\Request $request, array $params): array => self::editor((int) $params['id']), 'admin');
        $save = static function (\TinyCat\Http\Request $request, array $params): array {
            try { $id = FeedSources::save($request->payload(), (int) ($params['id'] ?? 0)); }
            catch (\InvalidArgumentException) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('blog.feeds.invalid'), 422, 'article_source_invalid'); }
            catch (\DomainException) { \TinyCat\Http\Response::error('Source not found.', 404, 'article_source_not_found'); }
            return ['item' => self::resource(FeedSources::find($id) ?? throw new \LogicException('Saved source missing.'))];
        };
        $api->post('/api/article-sources', $save, 'admin');
        $api->put('/api/article-sources/{id:[1-9][0-9]*}', $save, 'admin');
        $api->delete('/api/article-sources/{id:[1-9][0-9]*}', static function (\TinyCat\Http\Request $request, array $params): array {
            Connection::delete('blog_sources', ['id' => (int) $params['id']]);
            return ['id' => (int) $params['id']];
        }, 'admin');
        $api->post('/api/article-sources/preview', static function (\TinyCat\Http\Request $request): array {
            try { return FeedSources::preview($request->payload()); }
            catch (\InvalidArgumentException|\RuntimeException) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('blog.feeds.invalid'), 422, 'article_feed_invalid'); }
        }, 'admin');
        $api->post('/api/article-sources/{id:[1-9][0-9]*}/run', static function (\TinyCat\Http\Request $request, array $params) use ($api): \TinyCat\Http\Result {
            $execution = \TinyCat\Http\Session::withoutLock(static fn (): array => \TinyCat\Core\Tasks\Execution::run('article_feeds', ['source_id' => (int) $params['id']], 'admin', (int) ($api->actor()['id'] ?? 0)));
            $result = $execution['tasks']['article_feeds'];
            if ($result['status'] === 'already_running') { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('cron.already_running'), 409, 'scheduler_already_running'); }
            $failed = empty($result['ok']);
            return new \TinyCat\Http\Result(['execution' => $result], \TinyCat\Support\Localization::t($failed ? 'cron.run_failed' : 'cron.run_finished'), ['type' => $failed ? 'danger' : 'success'], $failed ? 'article_import_failed' : 'article_import_finished');
        }, 'admin');
    }
}
