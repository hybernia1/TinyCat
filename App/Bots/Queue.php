<?php
declare(strict_types=1);
namespace TinyCat\Bots;

use TinyCat\Database\Connection;
use TinyCat\Database\Query;
use TinyCat\Database\Transactions;
use TinyCat\Http\Response;
use TinyCat\Http\Result;
use TinyCat\Http\Web;
use TinyCat\Support\AdminSorting;
use TinyCat\Support\AdminViews;
use TinyCat\Support\Dates;
use TinyCat\Support\Localization;
use TinyCat\Support\Pagination;
use TinyCat\Support\Text;

/** Administrator's publication queue for all bot accounts. */
final class Queue
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        $bot = max(0, (int) Web::get('bot', 0));
        $status = (string) Web::get('status', '');
        $source = Administration::sourceFilter(Web::get('source', ''));
        $filters = ['q' => AdminViews::admin_filter_text((string) Web::get('q', '')), 'bot' => $bot > 0 ? (string) $bot : '',
            'status' => array_key_exists($status, AdminViews::admin_user_statuses()) ? $status : '', 'source' => $source] + AdminSorting::state('bot_queue');
        $query = new Query('SELECT c.id, c.body, c.published_at, u.id AS bot_id, u.username, u.status, bs.id AS source_id, bs.name AS source_name
            FROM content c INNER JOIN users u ON u.id = c.author_id
            LEFT JOIN bot_source_posts bsp ON bsp.content_id = c.id LEFT JOIN bot_sources bs ON bs.id = bsp.source_id');
        $query->where('u.role = ?', 'bot')->where('c.published_at > ?', Dates::dateDb());
        if ($filters['q'] !== '') { $query->where("c.body LIKE ? ESCAPE '!'", '%' . Connection::escapeLike($filters['q']) . '%'); }
        if ($bot > 0) { $query->where('u.id = ?', $bot); }
        if ($filters['status'] !== '') { $query->where('u.status = ?', $filters['status']); }
        Administration::filterSource($query, $source);
        $pagination = Pagination::paginationMeta($query->count(), AdminViews::admin_page(), AdminViews::admin_per_page());
        $params = $filters + ['per_page' => $pagination['per_page']];
        $items = [];
        foreach ($query->order(AdminSorting::order('bot_queue', $filters))->limit((int) $pagination['per_page'], (int) $pagination['offset'])->all() as $row) {
            $id = (int) $row['id'];
            $items[] = ['id' => $id, 'bot_id' => (int) $row['bot_id'], 'username' => (string) $row['username'], 'status' => (string) $row['status'],
                'bot_url' => '/admin/bots/' . (int) $row['bot_id'], 'excerpt' => Text::plain_text_limit((string) $row['body'], 180),
                'published_at' => Dates::dateIso((string) $row['published_at']), 'can_publish' => $row['status'] === 'active',
                'source' => Resource::postSource($row, (int) $row['bot_id']),
                'edit_url' => '/api/bots/accounts/' . (int) $row['bot_id'] . '/posts/' . $id . '/management?panel=edit',
                'publish_url' => AdminViews::admin_list_url('/api/bots/queue/' . $id . '/publication', $params + ['page' => $pagination['page']])];
        }
        $bots = ['' => Localization::t('bots.all_bots')];
        foreach (Connection::all('SELECT id, username FROM users WHERE role = ? ORDER BY username, id', ['bot']) as $row) { $bots[(string) $row['id']] = (string) $row['username']; }
        $navigation = AdminViews::admin_pagination_view_data($pagination, '/api/bots/queue', '#bot-queue-list', $params, historyPath: '/admin/bots/queue');
        unset($navigation['target']);
        return ['items' => $items, 'filters' => $filters, 'pagination' => $pagination, 'bots' => $bots, 'sources' => Administration::sourceChoices($bot), 'statuses' => array_keys(AdminViews::admin_user_statuses()),
            'sorting' => AdminSorting::headings('bot_queue', $params), 'navigation' => $navigation];
    }

    public static function listing(?string $message = null): Result
    {
        $data = self::payload();
        $pagination = $data['pagination']; unset($data['pagination']);
        return new Result($data, $message, ['pagination' => $pagination], $message !== null ? 'bot_queue_published' : null);
    }

    public static function publish(int $id): Result
    {
        $error = Transactions::run(static function () use ($id): ?string {
            $row = Connection::one('SELECT c.published_at, u.status FROM content c INNER JOIN users u ON u.id = c.author_id WHERE c.id = ? AND u.role = ?' . (Connection::driver() === 'mysql' ? ' FOR UPDATE' : ''), [$id, 'bot']);
            if ($row === null) { return 'missing'; }
            if ($row['status'] !== 'active') { return 'inactive'; }
            $now = Dates::dateDb();
            if ((string) $row['published_at'] > $now) { Connection::update('content', ['published_at' => $now], ['id' => $id]); }
            return null;
        });
        if ($error === 'missing') { Response::error(Localization::t('account.messages.status_not_found'), 404, 'status_not_found'); }
        if ($error === 'inactive') { Response::error(Localization::t('bots.detail_bot_inactive'), 409, 'bot_inactive'); }
        return self::listing(Localization::t('bots.queue_published'));
    }
}
