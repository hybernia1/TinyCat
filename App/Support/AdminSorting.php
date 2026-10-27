<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** Allowlisted SQL expressions and shared list navigation state. */
final class AdminSorting
{
    private const LISTS = [
        'users' => ['/api/users', '/admin/users', '#users-list', ['username' => 'username', 'role' => 'role', 'status' => 'status', 'updated_at' => 'updated_at'], 'id DESC', 'id'],
        'accounts' => ['/api/bots/accounts', '/admin/bots/accounts', '#bot-accounts-list', ['username' => 'u.username', 'status' => 'u.status', 'source_count' => 'source_count', 'post_count' => 'post_count', 'updated_at' => 'u.updated_at'], 'u.id DESC', 'u.id'],
        'sources' => ['/api/bots/sources', '/admin/bots/list', '#bots-list', ['name' => 'bs.name', 'bot' => 'u.username', 'status' => 'bs.enabled', 'interval' => 'bs.interval_minutes', 'last_import' => 'bs.last_imported_at'], 'u.username ASC, bs.name ASC, bs.id ASC', 'bs.id'],
        'runs' => ['/api/scheduler/tasks', '/admin/cron/list', '#scheduler-list', ['task' => 'task', 'started_at' => 'started_at', 'status' => 'status', 'duration_ms' => 'duration_ms'], 'id DESC', 'id'],
        'reports' => ['/api/moderation/reports', '/admin/moderation/reports', '#moderation-reports', ['content' => 'c.body', 'reason' => 'cr.reason', 'status' => 'CASE WHEN rc.open_count > 0 THEN 0 ELSE 1 END'], 'CASE WHEN rc.open_count > 0 THEN 0 ELSE 1 END, rc.latest_reported_at DESC, cr.id DESC', 'cr.id'],
    ];

    /** @param array<string, mixed>|null $input
     * @return array{sort: string, direction: string}
     */
    public static function state(string $list, ?array $input = null): array
    {
        $sort = $input['sort'] ?? \TinyCat\Http\Web::get('sort', '');
        $direction = $input['direction'] ?? \TinyCat\Http\Web::get('direction', 'asc');
        return ['sort' => is_string($sort) && isset(self::LISTS[$list][3][$sort]) ? $sort : '',
            'direction' => $direction === 'desc' ? 'desc' : 'asc'];
    }

    /** @param array<string, mixed>|null $input */
    public static function order(string $list, ?array $input = null): string
    {
        $state = self::state($list, $input);
        $config = self::LISTS[$list];
        return $state['sort'] === '' ? $config[4] : $config[3][$state['sort']] . ' ' . strtoupper($state['direction']) . ', ' . $config[5] . ' DESC';
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function headings(string $list, array $params = []): array
    {
        $config = self::LISTS[$list];
        $state = self::state($list, $params);
        $headings = [];
        foreach ($config[3] as $key => $_expression) {
            $active = $state['sort'] === $key;
            $next = array_replace($params, ['sort' => $key, 'direction' => $active && $state['direction'] === 'asc' ? 'desc' : 'asc', 'page' => 1]);
            $headings[$key] = ['url' => AdminViews::admin_list_url($config[0], $next),
                'history' => AdminViews::admin_list_url($config[1], $next), 'target' => $config[2],
                'aria' => $active ? ($state['direction'] === 'asc' ? 'ascending' : 'descending') : 'none',
                'ascending' => $active && $state['direction'] === 'asc', 'descending' => $active && $state['direction'] === 'desc'];
        }
        return $headings;
    }
}
