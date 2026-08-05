<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class BotAdmin
{
    private static ?int $filterBotId = null;
    private static ?array $bots = null;

    private function __construct()
    {
    }

    public static function payload(?int $id = null): array
    {
        $viewData = self::sourcesViewData();
        $filterBotId = (int) $viewData['filter_bot_id'];
        $sources = (array) $viewData['sources'];
        $data = [
            'items' => array_map('bot_source_resource', $sources),
            'bots' => array_map(static fn (array $user): array => [
                'id' => (int) ($user['id'] ?? 0),
                'username' => (string) ($user['username'] ?? ''),
                'status' => (string) ($user['status'] ?? ''),
            ], self::bots()),
            'filter_bot_user_id' => $filterBotId,
        ];

        if ($id !== null) {
            $source = bot_source_find($id);
            $data['id'] = $id;
            $data['item'] = $source !== null ? bot_source_resource($source) : null;
        }

        return api_payload($data, static function () use ($viewData, $id): array {
            $partial = ['html' => part('admin/bots/sources', $viewData)];
            if ($id !== null) {
                $partial['id'] = $id;
            }

            return $partial;
        });
    }

    private static function filterId(): int
    {
        if (self::$filterBotId !== null) {
            return self::$filterBotId;
        }

        $id = max(0, (int) get('bot', 0));

        self::$filterBotId = $id > 0 && (int) val('SELECT COUNT(*) FROM users WHERE id = ? AND role = ?', [$id, 'bot']) > 0
            ? $id
            : 0;

        return self::$filterBotId;
    }

    public static function apiUrl(): string
    {
        return self::sourceActionUrl();
    }

    public static function sourceActionUrl(string $action = ''): string
    {
        $query = [];
        $filterBotId = self::filterId();
        if ($filterBotId > 0) {
            $query['bot'] = $filterBotId;
        }

        $path = '/api/admin/bots';
        if (in_array($action, ['run', 'toggle'], true)) {
            $path .= '/' . $action;
        }

        return admin_list_url($path, $query);
    }

    public static function accountById(int $id): ?array
    {
        return $id > 0
            ? one(
                'SELECT u.*,
                    (SELECT COUNT(*) FROM bot_sources bs WHERE bs.bot_user_id = u.id) AS source_count,
                    (SELECT COUNT(*) FROM content c WHERE c.author_id = u.id) AS post_count
                FROM users u
                WHERE u.id = ? AND u.role = ?
                LIMIT 1',
                [$id, 'bot']
            )
            : null;
    }

    public static function accountCreatePayload(): array
    {
        $username = username_normalize((string) input('username', ''));
        $status = (string) input('status', 'active');
        $statuses = admin_user_statuses();
        $errors = [];

        if (!username_valid($username)) {
            $errors['username'][] = t('users.messages.username_invalid');
        } elseif (user_username_taken($username)) {
            $errors['username'][] = t('users.messages.username_taken');
        }

        if (!array_key_exists($status, $statuses)) {
            $errors['status'][] = t('users.validation.status_invalid');
        }

        if ($errors !== []) {
            api_validation($errors);
        }

        return [
            'username' => $username,
            'email' => null,
            'password' => null,
            'role' => 'bot',
            'status' => $status,
            'bio' => '',
            'created_at' => date_db(),
        ];
    }

    public static function accountsPayload(?int $id = null): array
    {
        $viewData = self::accountsViewData();
        $payload = [
            'items' => array_map([self::class, 'accountResource'], $viewData['accounts']),
            'pagination' => $viewData['pagination'],
            'statuses' => admin_user_statuses(),
            'filters' => $viewData['filters'],
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            $payload['item'] = self::accountResource(self::accountById($id) ?? []);
        }

        return api_payload($payload, static function () use ($viewData, $id): array {
            $partial = ['html' => part('admin/bots/accounts', $viewData)];
            if ($id !== null) {
                $partial['id'] = $id;
            }

            return $partial;
        });
    }

    public static function accountsViewData(): array
    {
        $filters = self::accountFilters();
        $page = self::accountsPage($filters);
        $pagination = $page['pagination'];

        return [
            'filters' => $filters,
            'accounts' => $page['items'],
            'pagination' => $pagination,
            'params' => self::accountListParams($filters, $pagination),
            'has_filters' => $filters['q'] !== '' || $filters['status'] !== '',
        ];
    }

    public static function accountsApiUrl(array $params = [], bool $withFilters = true): string
    {
        $query = [];

        if ($withFilters) {
            foreach (self::accountListParams() as $key => $value) {
                if ($value !== '' && !array_key_exists($key, $params)) {
                    $query[$key] = $value;
                }
            }
        }

        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $query[$key] = $value;
            }
        }

        return admin_list_url('/api/admin/bot-accounts', $query);
    }

    private static function accountResource(array $account): array
    {
        if ($account === []) {
            return [];
        }

        return [
            'id' => (int) ($account['id'] ?? 0),
            'username' => (string) ($account['username'] ?? ''),
            'status' => (string) ($account['status'] ?? ''),
            'bio' => (string) ($account['bio'] ?? ''),
            'avatar_url' => user_avatar_url($account),
            'source_count' => (int) ($account['source_count'] ?? 0),
            'post_count' => (int) ($account['post_count'] ?? 0),
            'created_at' => (string) ($account['created_at'] ?? ''),
            'updated_at' => (string) ($account['updated_at'] ?? ''),
        ];
    }

    private static function accountFilters(): array
    {
        $query = admin_filter_text((string) get('q', ''));
        $status = (string) get('status', '');

        return [
            'q' => $query,
            'status' => array_key_exists($status, admin_user_statuses()) ? $status : '',
        ];
    }

    private static function accountListParams(?array $filters = null, ?array $pagination = null): array
    {
        $filters ??= self::accountFilters();

        return $filters + [
            'per_page' => (int) ($pagination['per_page'] ?? admin_per_page()),
            'page' => (int) ($pagination['page'] ?? admin_page()),
        ];
    }

    private static function accountsPage(?array $filters = null): array
    {
        $filters ??= self::accountFilters();
        $clauses = ['u.role = ?'];
        $params = ['bot'];

        if ($filters['q'] !== '') {
            $clauses[] = 'u.username LIKE ? ESCAPE \'\\\\\'';
            $params[] = admin_search_like($filters['q']);
        }
        if ($filters['status'] !== '') {
            $clauses[] = 'u.status = ?';
            $params[] = $filters['status'];
        }

        $where = ' WHERE ' . implode(' AND ', $clauses);
        $pagination = pagination_meta(
            (int) val('SELECT COUNT(*) FROM users u' . $where, $params),
            admin_page(),
            admin_per_page()
        );
        $items = all(
            'SELECT u.*,
                (SELECT COUNT(*) FROM bot_sources bs WHERE bs.bot_user_id = u.id) AS source_count,
                (SELECT COUNT(*) FROM content c WHERE c.author_id = u.id) AS post_count
            FROM users u' . $where . '
            ORDER BY u.id DESC' . pagination_sql($pagination),
            $params
        );

        return [
            'items' => $items,
            'pagination' => $pagination + [
                'to' => $pagination['total'] === 0 ? 0 : $pagination['offset'] + count($items),
            ],
        ];
    }

    public static function sourcePayload(?int $sourceId = null): array
    {
        $botUserId = max(0, (int) input('bot_user_id', 0));
        $bot = $botUserId > 0
            ? one('SELECT id, status FROM users WHERE id = ? AND role = ? LIMIT 1', [$botUserId, 'bot'])
            : null;
        $name = trim((string) input('name', ''));
        $feedUrl = trim((string) input('feed_url', ''));
        $interval = (int) input('interval_minutes', 60);
        $template = trim((string) input('post_template', ''));
        $errors = [];

        if ($bot === null) {
            $errors['bot_user_id'][] = t('bots.validation.bot');
        }
        if ($name === '' || strlen($name) > 120) {
            $errors['name'][] = t('bots.validation.name');
        }

        $feedHash = bot_feed_source_hash($feedUrl);
        if (!LinkMetadata::isSafeRemoteUrl($feedUrl) || strlen($feedUrl) > 2048 || $feedHash === '') {
            $errors['feed_url'][] = t('bots.validation.feed_url');
        } elseif (bot_source_duplicate_exists($feedUrl, max(0, (int) $sourceId))) {
            $errors['feed_url'][] = t('bots.validation.feed_duplicate');
        }
        if ($interval < 5 || $interval > 43200) {
            $errors['interval_minutes'][] = t('bots.validation.interval');
        }
        if ($template === '' || strlen($template) > 2000) {
            $errors['post_template'][] = t('bots.validation.template');
        }
        if ($errors !== []) {
            api_validation($errors);
        }

        $enabled = in_array(input('enabled', null), [true, 1, '1', 'true', 'on'], true)
            && (string) ($bot['status'] ?? '') === 'active';

        return [
            'bot_user_id' => $botUserId,
            'name' => $name,
            'feed_url' => $feedUrl,
            'feed_hash' => $feedHash,
            'interval_minutes' => $interval,
            'post_template' => $template,
            'enabled' => $enabled ? 1 : 0,
            'next_run_at' => null,
            'last_error' => null,
        ];
    }

    public static function sourcesViewData(): array
    {
        $bots = self::bots();
        $filterBotId = self::filterId();

        return [
            'bots' => $bots,
            'filter_bot_id' => $filterBotId,
            'sources' => bot_sources($filterBotId > 0 ? $filterBotId : null),
        ];
    }

    public static function sourceFormData(?array $source): array
    {
        $create = $source === null;
        $source ??= [];
        $filterBotId = self::filterId();
        if ($create && $filterBotId > 0) {
            $source['bot_user_id'] = $filterBotId;
        }

        return [
            'source' => $source,
            'bots' => self::bots(),
            'create' => $create,
            'action' => self::apiUrl(),
        ];
    }

    public static function sourceFilterData(): array
    {
        return [
            'bots' => self::bots(),
            'filter_bot_id' => self::filterId(),
        ];
    }

    private static function bots(): array
    {
        return self::$bots ??= all(
            'SELECT id, username, status FROM users WHERE role = ? ORDER BY username ASC',
            ['bot']
        );
    }
}
