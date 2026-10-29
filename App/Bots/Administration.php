<?php
declare(strict_types=1);

namespace TinyCat\Bots;


if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Administration
{
    public static function sourceFilter(mixed $value): string
    {
        if ($value === 'unknown') { return 'unknown'; }
        return (is_string($value) || is_int($value)) && ctype_digit((string) $value) && (int) $value > 0 ? (string) (int) $value : '';
    }

    public static function filterSource(\TinyCat\Database\Query $query, string $source): void
    {
        if ($source === 'unknown') { $query->where('bs.id IS NULL'); }
        elseif ($source !== '') { $query->where('bs.id = ?', (int) $source); }
    }

    /** @return array<string|int, string> */
    public static function sourceChoices(?int $botId = null): array
    {
        $choices = ['' => \TinyCat\Support\Localization::t('bots.all_sources'), 'unknown' => \TinyCat\Support\Localization::t('bots.unknown_source')];
        $query = new \TinyCat\Database\Query('SELECT bs.id, bs.name, u.username FROM bot_sources bs INNER JOIN users u ON u.id = bs.bot_user_id');
        $query->where('u.role = ?', 'bot');
        if ($botId !== null && $botId > 0) { $query->where('u.id = ?', $botId); }
        foreach ($query->order('bs.name ASC, bs.id ASC')->all() as $row) { $choices[(string) $row['id']] = (string) $row['name'] . ' (@' . (string) $row['username'] . ')'; }
        return $choices;
    }
    private static ?int $filterBotId = null;
    /** @var array<array<string, mixed>>|null */
    private static ?array $bots = null;

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(?int $id = null): array
    {
        $viewData = self::sourcesViewData();
        $filterBotId = (int) $viewData['filter_bot_id'];
        $sources = (array) $viewData['sources'];
        $data = [
            'items' => array_map([\TinyCat\Bots\Importer::class, 'sourceResource'], $sources),
            'bots' => array_map(static fn (array $user): array => [
                'id' => (int) ($user['id'] ?? 0),
                'username' => (string) ($user['username'] ?? ''),
                'status' => (string) ($user['status'] ?? ''),
            ], self::bots()),
            'filter_bot_user_id' => $filterBotId,
            'sorting' => \TinyCat\Support\AdminSorting::headings('sources', ['bot' => $filterBotId > 0 ? $filterBotId : '']),
        ];

        if ($id !== null) {
            $source = \TinyCat\Bots\Importer::findSource($id);
            $data['id'] = $id;
            $data['item'] = $source !== null ? \TinyCat\Bots\Importer::sourceResource($source) : null;
        }

        return $data;
    }

    private static function filterId(): int
    {
        if (self::$filterBotId !== null) {
            return self::$filterBotId;
        }

        $id = max(0, (int) \TinyCat\Http\Web::get('bot', 0));

        self::$filterBotId = $id > 0 && (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM users WHERE id = ? AND role = ?', [$id, 'bot']) > 0
            ? $id
            : 0;

        return self::$filterBotId;
    }

    public static function apiUrl(?int $id = null): string
    {
        return self::sourceActionUrl('', $id);
    }

    public static function sourceActionUrl(string $action = '', ?int $id = null): string
    {
        $query = [];
        $filterBotId = self::filterId();
        if ($filterBotId > 0) {
            $query['bot'] = $filterBotId;
        }

        $path = '/api/bots/sources';
        if ($id !== null) {
            $path .= '/' . $id;
            if ($action === 'run') { $path .= '/runs'; }
        }

        return \TinyCat\Support\AdminViews::admin_list_url($path, $query);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function accountById(int $id): ?array
    {
        return $id > 0
            ? \TinyCat\Database\Connection::one(
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

    /**
     * @return array<string, mixed>|null
     */
    public static function accountDetailData(int $id): ?array
    {
        $account = self::accountById($id);

        if ($account === null) {
            return null;
        }

        $sources = \TinyCat\Bots\Importer::sources($id);
        $runs = self::accountRuns($id);

        return [
            'account' => $account,
            'sources' => $sources,
            'runs' => $runs,
            'stats' => [
                ['key' => 'sources', 'value' => count($sources)],
                ['key' => 'active_sources', 'value' => count(array_filter(
                    $sources,
                    static fn (array $source): bool => (bool) ($source['enabled'] ?? false)
                ))],
                ['key' => 'posts', 'value' => \TinyCat\Moderation\Restrictions::moderation_user_post_count($id)],
                ['key' => 'runs', 'value' => count($runs)],
            ],
            'posts' => self::postsPayload($id),
            'last_run' => $runs[0] ?? null,
            'profile_url' => \TinyCat\Users\Profiles::author_url($id),
        ];
    }

    /** Administrator-only list, including posts waiting for publication.
     * @return array<string, mixed> */
    public static function postsPayload(int $botId): array
    {
        $query = new \TinyCat\Database\Query('SELECT c.id, c.body, c.published_at, bs.id AS source_id, bs.name AS source_name
            FROM content c LEFT JOIN bot_source_posts bsp ON bsp.content_id = c.id LEFT JOIN bot_sources bs ON bs.id = bsp.source_id');
        $query->where('c.author_id = ?', $botId);
        $source = self::sourceFilter(\TinyCat\Http\Web::get('source', ''));
        self::filterSource($query, $source);
        $publication = (string) \TinyCat\Http\Web::get('publication', '');
        $publication = in_array($publication, ['published', 'queued'], true) ? $publication : '';
        if ($publication !== '') { $query->where('c.published_at ' . ($publication === 'published' ? '<=' : '>') . ' ?', \TinyCat\Support\Dates::dateDb()); }
        $q = \TinyCat\Support\AdminViews::admin_filter_text((string) \TinyCat\Http\Web::get('q', ''));
        if ($q !== '') { $query->where("c.body LIKE ? ESCAPE '!'", '%' . \TinyCat\Database\Connection::escapeLike($q) . '%'); }
        $sortingState = \TinyCat\Support\AdminSorting::state('bot_posts');
        $pagination = \TinyCat\Support\Pagination::paginationMeta($query->count(), \TinyCat\Support\AdminViews::admin_page(), \TinyCat\Support\AdminViews::admin_per_page());
        $params = ['q' => $q, 'source' => $source, 'publication' => $publication, 'per_page' => $pagination['per_page']] + $sortingState;
        $apiUrl = '/api/bots/accounts/' . $botId . '/posts';
        $pageUrl = '/admin/bots/' . $botId;
        $items = $query->order(\TinyCat\Support\AdminSorting::order('bot_posts', $sortingState))->limit((int) $pagination['per_page'], (int) $pagination['offset'])->all();
        $now = \TinyCat\Support\Dates::dateDb();
        $items = array_map(static function (array $post) use ($botId, $now): array {
            $id = (int) $post['id'];
            $published = (string) $post['published_at'] <= $now;
            return $post + ['source' => Resource::postSource($post, $botId), 'excerpt' => \TinyCat\Support\Text::plain_text_limit((string) $post['body'], 180),
                'published' => $published, 'url' => $published ? \TinyCat\Status\Posts::status_url($id) : '',
                'edit_url' => '/api/bots/accounts/' . $botId . '/posts/' . $id . '/management?panel=edit',
                'action_url' => '/api/statuses/' . $id, 'published_iso' => \TinyCat\Support\Dates::dateIso((string) $post['published_at'])];
        }, $items);
        $sorting = \TinyCat\Support\AdminSorting::headings('bot_posts', $params, $apiUrl, $pageUrl);
        $totalCount = $q === '' && $source === '' && $publication === '' ? (int) $pagination['total'] : (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM content WHERE author_id = ?', [$botId]);
        return ['items' => $items, 'total_count' => $totalCount, 'sources' => self::sourceChoices($botId), 'pagination' => $pagination, 'sorting' => $sorting, 'filters' => $params, 'api_url' => $apiUrl, 'history_url' => $pageUrl,
            'url' => \TinyCat\Support\AdminViews::admin_list_url($apiUrl, $params + ['page' => $pagination['page']]),
            'navigation' => \TinyCat\Support\AdminViews::admin_pagination_view_data($pagination, $apiUrl, '#bot-posts-list', $params, historyPath: $pageUrl)];
    }

    /** @return array<string, mixed> */
    public static function postManagement(int $botId, int $postId): array
    {
        $item = \TinyCat\Database\Connection::one(\TinyCat\Status\Feed::public_status_select_sql() . ' WHERE c.id = ? AND c.author_id = ?', [$postId, $botId]);
        if ($item === null) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found'); }
        $user = \TinyCat\Users\Authentication::auth();
        return ['item' => \TinyCat\Status\Resource::post($item, $user), 'comments' => [],
            'viewer' => $user !== null ? \TinyCat\Users\Resource::profile($user) : null,
            'options' => ['images_enabled' => \TinyCat\Status\Images::status_images_enabled(), 'image_max_bytes' => \TinyCat\Status\Images::status_image_max_upload_bytes()]];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private static function accountRuns(int $id, int $limit = 30): array
    {
        return \TinyCat\Database\Connection::all(
            'SELECT br.*, bs.name AS source_name
             FROM bot_source_runs br
             INNER JOIN bot_sources bs ON bs.id = br.source_id
             WHERE bs.bot_user_id = ?
             ORDER BY br.started_at DESC, br.id DESC
             LIMIT ' . max(1, min(100, $limit)),
            [$id]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function accountCreatePayload(): array
    {
        \TinyCat\Http\Request::current()->validated(['username' => 'required|string', 'status' => 'string']);
        $username = \TinyCat\Users\Usernames::username_normalize((string) \TinyCat\Http\Request::current()->input('username', ''));
        $status = (string) \TinyCat\Http\Request::current()->input('status', 'active');
        $statuses = \TinyCat\Support\AdminViews::admin_user_statuses();
        $errors = [];

        if (!\TinyCat\Users\Usernames::username_valid($username)) {
            $errors['username'][] = \TinyCat\Http\Response::issue('user_username_invalid', \TinyCat\Support\Localization::t('users.messages.username_invalid'));
        } elseif (\TinyCat\Users\Usernames::user_username_taken($username)) {
            $errors['username'][] = \TinyCat\Http\Response::issue('user_username_taken', \TinyCat\Support\Localization::t('users.messages.username_taken'));
        }

        if (!array_key_exists($status, $statuses)) {
            $errors['status'][] = \TinyCat\Http\Response::issue('user_status_invalid', \TinyCat\Support\Localization::t('users.validation.status_invalid'));
        }

        if ($errors !== []) {
            \TinyCat\Http\Response::validation($errors);
        }

        return [
            'username' => $username,
            'email' => null,
            'password' => null,
            'role' => 'bot',
            'status' => $status,
            'bio' => '',
            'created_at' => \TinyCat\Support\Dates::dateDb(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function accountsPayload(?int $id = null): array
    {
        $viewData = self::accountsViewData();
        $payload = [
            'items' => array_map([self::class, 'accountResource'], $viewData['accounts']),
            'pagination' => $viewData['pagination'],
            'statuses' => \TinyCat\Support\AdminViews::admin_user_statuses(),
            'filters' => $viewData['filters'],
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            $payload['item'] = self::accountResource(self::accountById($id) ?? []);
        }

        return Resource::accounts($payload);
    }

    /**
     * @return array<string, mixed>
     */
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
            'per_page' => (int) $pagination['per_page'],
            'clear_api_url' => self::accountsApiUrl(['per_page' => (int) $pagination['per_page'], 'page' => 1], false),
            'clear_history_url' => \TinyCat\Support\AdminViews::admin_list_url('/admin/bots/accounts', ['per_page' => (int) $pagination['per_page'], 'page' => 1]),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
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

        return \TinyCat\Support\AdminViews::admin_list_url('/api/bots/accounts', $query);
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public static function accountResource(array $account): array
    {
        if ($account === []) {
            return [];
        }

        return [
            'id' => (int) ($account['id'] ?? 0),
            'username' => (string) ($account['username'] ?? ''),
            'status' => (string) ($account['status'] ?? ''),
            'bio' => (string) ($account['bio'] ?? ''),
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($account),
            'source_count' => (int) ($account['source_count'] ?? 0),
            'post_count' => (int) ($account['post_count'] ?? 0),
            'created_at' => !empty($account['created_at']) ? \TinyCat\Support\Dates::dateIso($account['created_at']) : '',
            'updated_at' => !empty($account['updated_at']) ? \TinyCat\Support\Dates::dateIso($account['updated_at']) : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function accountFilters(): array
    {
        $query = \TinyCat\Support\AdminViews::admin_filter_text((string) \TinyCat\Http\Web::get('q', ''));
        $status = (string) \TinyCat\Http\Web::get('status', '');

        return [
            'q' => $query,
            'status' => array_key_exists($status, \TinyCat\Support\AdminViews::admin_user_statuses()) ? $status : '',
        ] + \TinyCat\Support\AdminSorting::state('accounts');
    }

    /**
     * @param array<string, mixed>|null $filters
     * @param array<string, mixed>|null $pagination
     * @return array<string, mixed>
     */
    private static function accountListParams(?array $filters = null, ?array $pagination = null): array
    {
        $filters ??= self::accountFilters();

        return $filters + [
            'per_page' => (int) ($pagination['per_page'] ?? \TinyCat\Support\AdminViews::admin_per_page()),
            'page' => (int) ($pagination['page'] ?? \TinyCat\Support\AdminViews::admin_page()),
        ];
    }

    /**
     * @param array<string, mixed>|null $filters
     * @return array<string, mixed>
     */
    private static function accountsPage(?array $filters = null): array
    {
        $filters ??= self::accountFilters();
        $query = new \TinyCat\Database\Query('SELECT u.*,
            (SELECT COUNT(*) FROM bot_sources bs WHERE bs.bot_user_id = u.id) AS source_count,
            (SELECT COUNT(*) FROM content c WHERE c.author_id = u.id) AS post_count FROM users u');
        $query->where('u.role = ?', 'bot');
        if ($filters['q'] !== '') {
            $query->where("u.username LIKE ? ESCAPE '!'", '%' . \TinyCat\Database\Connection::escapeLike($filters['q']) . '%');
        }
        if ($filters['status'] !== '') { $query->where('u.status = ?', $filters['status']); }
        $pagination = \TinyCat\Support\Pagination::paginationMeta(
            $query->count('SELECT u.id FROM users u'),
            \TinyCat\Support\AdminViews::admin_page(),
            \TinyCat\Support\AdminViews::admin_per_page()
        );
        $items = $query->order(\TinyCat\Support\AdminSorting::order('accounts', $filters))
            ->limit((int) $pagination['per_page'], (int) $pagination['offset'])->all();

        return [
            'items' => $items,
            'pagination' => $pagination + [
                'to' => $pagination['total'] === 0 ? 0 : $pagination['offset'] + count($items),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sourcePayload(?int $sourceId = null): array
    {
        \TinyCat\Http\Request::current()->validated(['bot_user_id' => 'required|integer', 'name' => 'required|string', 'feed_url' => 'required|string', 'interval_minutes' => 'integer', 'publication_interval_minutes' => 'integer', 'post_template' => 'required|string', 'enabled' => 'boolean']);
        $botUserId = max(0, (int) \TinyCat\Http\Request::current()->input('bot_user_id', 0));
        $bot = $botUserId > 0
            ? \TinyCat\Database\Connection::one('SELECT id, status FROM users WHERE id = ? AND role = ? LIMIT 1', [$botUserId, 'bot'])
            : null;
        $name = trim((string) \TinyCat\Http\Request::current()->input('name', ''));
        $feedUrl = trim((string) \TinyCat\Http\Request::current()->input('feed_url', ''));
        $interval = (int) \TinyCat\Http\Request::current()->input('interval_minutes', 60);
        $existing = $sourceId !== null ? Importer::findSource($sourceId) : [];
        $publicationInterval = (int) \TinyCat\Http\Request::current()->input('publication_interval_minutes', Importer::publicationInterval($existing ?? []));
        $template = trim((string) \TinyCat\Http\Request::current()->input('post_template', ''));
        $errors = [];

        if ($bot === null) {
            $errors['bot_user_id'][] = \TinyCat\Http\Response::issue('bot_bot', \TinyCat\Support\Localization::t('bots.validation.bot'));
        }
        if ($name === '' || strlen($name) > 120) {
            $errors['name'][] = \TinyCat\Http\Response::issue('bot_name', \TinyCat\Support\Localization::t('bots.validation.name'));
        }

        $feedHash = \TinyCat\Bots\Importer::feedSourceHash($feedUrl);
        if (!\TinyCat\Status\Metadata::isSafeRemoteUrl($feedUrl) || strlen($feedUrl) > 2048 || $feedHash === '') {
            $errors['feed_url'][] = \TinyCat\Http\Response::issue('bot_feed_url', \TinyCat\Support\Localization::t('bots.validation.feed_url'));
        } elseif (\TinyCat\Bots\Importer::sourceDuplicateExists($feedUrl, max(0, (int) $sourceId))) {
            $errors['feed_url'][] = \TinyCat\Http\Response::issue('bot_feed_duplicate', \TinyCat\Support\Localization::t('bots.validation.feed_duplicate'));
        }
        if ($interval < 5 || $interval > 43200) {
            $errors['interval_minutes'][] = \TinyCat\Http\Response::issue('bot_interval', \TinyCat\Support\Localization::t('bots.validation.interval'));
        }
        if ($publicationInterval < 5 || $publicationInterval > 43200) {
            $errors['publication_interval_minutes'][] = \TinyCat\Http\Response::issue('bot_interval', \TinyCat\Support\Localization::t('bots.validation.interval'));
        }
        if ($template === '' || strlen($template) > 2000) {
            $errors['post_template'][] = \TinyCat\Http\Response::issue('bot_template', \TinyCat\Support\Localization::t('bots.validation.template'));
        }
        if ($errors !== []) {
            \TinyCat\Http\Response::validation($errors);
        }

        $enabled = in_array(\TinyCat\Http\Request::current()->input('enabled', null), [true, 1, '1', 'true', 'on'], true)
            && (string) ($bot['status'] ?? '') === 'active';

        return [
            'bot_user_id' => $botUserId,
            'name' => $name,
            'feed_url' => $feedUrl,
            'feed_hash' => $feedHash,
            'interval_minutes' => $interval,
            'publication_interval_minutes' => $publicationInterval,
            'post_template' => $template,
            'enabled' => $enabled ? 1 : 0,
            'next_run_at' => null,
            'last_error' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sourceForAction(int $sourceId, int $botId = 0): array
    {
        $source = \TinyCat\Bots\Importer::findSource($sourceId);

        if ($source === null || ($botId > 0 && (int) ($source['bot_user_id'] ?? 0) !== $botId)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        $bot = \TinyCat\Database\Connection::one(
            'SELECT id, status FROM users WHERE id = ? AND role = ? LIMIT 1',
            [(int) ($source['bot_user_id'] ?? 0), 'bot']
        );

        if ($bot === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.messages.not_found'), 404, 'bot_account_not_found');
        }

        $source['bot_status'] = (string) ($bot['status'] ?? '');

        return $source;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function runSource(array $source): array
    {
        if ((string) ($source['bot_status'] ?? '') !== 'active') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.detail_bot_inactive'), 409, 'bot_inactive');
        }

        if (!(bool) ($source['enabled'] ?? false)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.detail_source_disabled'), 409, 'bot_source_disabled');
        }

        $user = \TinyCat\Users\Authentication::auth();
        return \TinyCat\Http\Session::withoutLock(static fn (): array => \TinyCat\Scheduler\Execution::run(
            'feeds', ['source_id' => (int) $source['id']], 'admin', (int) ($user['id'] ?? 0)
        )['tasks']['feeds']);
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function toggleSource(array $source): void
    {
        $enabled = (bool) ($source['enabled'] ?? false);

        if (!$enabled && (string) ($source['bot_status'] ?? '') !== 'active') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('bots.detail_bot_inactive'), 409, 'bot_inactive');
        }

        \TinyCat\Database\Connection::update('bot_sources', [
            'enabled' => $enabled ? 0 : 1,
            'next_run_at' => $enabled ? null : \TinyCat\Support\Dates::dateDb(),
            'last_error' => null,
        ], ['id' => (int) ($source['id'] ?? 0)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function sourcesViewData(): array
    {
        $bots = self::bots();
        $filterBotId = self::filterId();

        return [
            'bots' => $bots,
            'filter_bot_id' => $filterBotId,
            'sources' => \TinyCat\Bots\Importer::sources($filterBotId > 0 ? $filterBotId : null, \TinyCat\Support\AdminSorting::order('sources')),
        ];
    }

    /**
     * @param array<string, mixed>|null $source
     * @return array<string, mixed>
     */
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
            'action' => self::apiUrl($create ? null : (int) $source['id']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sourceFilterData(): array
    {
        return [
            'bots' => self::bots(),
            'filter_bot_id' => self::filterId(),
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private static function bots(): array
    {
        return self::$bots ??= \TinyCat\Database\Connection::all(
            'SELECT id, username, status FROM users WHERE role = ? ORDER BY username ASC',
            ['bot']
        );
    }
}
