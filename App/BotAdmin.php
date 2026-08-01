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
        if (wants_partial()) {
            $payload = ['html' => self::html()];
            if ($id !== null) {
                $payload['id'] = $id;
            }

            return $payload;
        }

        $filterBotId = self::filterId();
        $sources = bot_sources($filterBotId > 0 ? $filterBotId : null);
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

        return $data;
    }

    public static function filterId(): int
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
        $query = ['view' => 'html'];
        $filterBotId = self::filterId();
        if ($filterBotId > 0) {
            $query['bot'] = $filterBotId;
        }

        return '/api/admin/bots?' . http_build_query($query);
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
        if (wants_partial()) {
            $payload = ['html' => self::accountsHtml()];
            if ($id !== null) {
                $payload['id'] = $id;
            }

            return $payload;
        }

        $filters = self::accountFilters();
        $page = self::accountsPage($filters);
        $payload = [
            'items' => array_map([self::class, 'accountResource'], $page['items']),
            'pagination' => $page['pagination'],
            'statuses' => admin_user_statuses(),
            'filters' => $filters,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            $payload['item'] = self::accountResource(self::accountById($id) ?? []);
        }

        return $payload;
    }

    public static function accountsHtml(): string
    {
        $filters = self::accountFilters();
        $page = self::accountsPage($filters);
        $accounts = $page['items'];
        $pagination = $page['pagination'];
        $params = self::accountListParams($filters, $pagination);
        $hasFilters = $filters['q'] !== '' || $filters['status'] !== '';
        ob_start();
        ?>
        <div class="stack stack-gap-14">
            <div class="admin-list-toolbar">
                <form class="admin-search-form" action="<?= e(self::accountsApiUrl([], false)) ?>" method="get" data-ajax-form data-ajax-target="#bot-accounts-list" data-history="/admin/bots/accounts">
                    <input type="hidden" name="view" value="html">
                    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
                    <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
                    <label class="sr-only" for="bot-accounts-search"><?= et('common.search') ?></label>
                    <span class="input-icon">
                        <?= icon('search') ?>
                        <input class="input" id="bot-accounts-search" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= et('bots.accounts_search_placeholder') ?>">
                    </span>
                    <button class="btn btn-secondary admin-search-submit" type="submit"><?= icon('search') ?> <span><?= et('common.search') ?></span></button>
                </form>
                <?php if ($hasFilters): ?>
                    <div class="admin-filter-actions">
                        <a class="btn btn-ghost" href="<?= e(self::accountsApiUrl(['per_page' => admin_per_page(), 'page' => 1], false)) ?>" data-ajax data-ajax-target="#bot-accounts-list" data-history="<?= e(admin_list_url('/admin/bots/accounts', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>">
                            <?= icon('close') ?> <span><?= et('common.clear_filters') ?></span>
                        </a>
                    </div>
                <?php endif; ?>
                <?= admin_per_page_control('/api/admin/bot-accounts', '#bot-accounts-list', $params, (int) ($pagination['per_page'] ?? admin_per_page()), '/admin/bots/accounts') ?>
            </div>
            <?php if ($accounts === []): ?>
                <div class="alert alert-info"><?= et($hasFilters ? 'bots.accounts_empty_filtered' : 'bots.accounts_empty') ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?= et('bots.account') ?></th>
                                <th><?= et('common.status') ?></th>
                                <th><?= et('bots.detail_stat_sources') ?></th>
                                <th><?= et('bots.detail_stat_posts') ?></th>
                                <th><?= et('common.updated') ?></th>
                                <th><?= et('common.actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                                <?php $id = (int) ($account['id'] ?? 0); ?>
                                <tr>
                                    <td><strong>@<?= e((string) ($account['username'] ?? '')) ?></strong></td>
                                    <td><?= admin_user_status_badge((string) ($account['status'] ?? '')) ?></td>
                                    <td><?= e((int) ($account['source_count'] ?? 0)) ?></td>
                                    <td><?= e((int) ($account['post_count'] ?? 0)) ?></td>
                                    <td><time class="table-meta" datetime="<?= e(date_iso((string) ($account['updated_at'] ?? ''))) ?>"><?= e(datetime((string) ($account['updated_at'] ?? ''))) ?></time></td>
                                    <td>
                                        <div class="table-actions">
                                            <a class="btn btn-sm btn-ghost btn-icon" href="/admin/bots/<?= e($id) ?>" aria-label="<?= et('bots.account_manage', ['username' => (string) ($account['username'] ?? '')]) ?>" title="<?= et('common.edit') ?>">
                                                <?= icon('edit') ?>
                                            </a>
                                            <form class="inline-flex" action="<?= e(self::accountsApiUrl(['id' => $id])) ?>" method="post" data-ajax-form data-ajax-target="#bot-accounts-list" data-confirm="<?= et('bots.account_delete_confirm', ['username' => (string) ($account['username'] ?? '')]) ?>" data-confirm-title="<?= et('bots.account_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_method" value="DELETE">
                                                <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" aria-label="<?= et('common.delete') ?>" title="<?= et('common.delete') ?>"><?= icon('trash') ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?= admin_pagination($pagination, '/api/admin/bot-accounts', '#bot-accounts-list', $params, 'page', 2, '/admin/bots/accounts') ?>
            <?php endif; ?>
            <?= self::accountCreateModal() ?>
            <?= self::accountFilterModal() ?>
        </div>
        <?php

        return trim((string) ob_get_clean());
    }

    public static function accountsApiUrl(array $params = [], bool $withFilters = true): string
    {
        $query = ['view' => 'html'];

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

        return '/api/admin/bot-accounts?' . http_build_query($query);
    }

    public static function accountCreateModal(): string
    {
        ob_start();
        ?>
        <div class="stack">
            <label class="field">
                <span class="label"><?= et('common.username') ?></span>
                <input class="input" name="username" autocomplete="off" autocapitalize="none" spellcheck="false" pattern="[a-z][a-z0-9_]{2,31}" maxlength="32" required>
                <span class="help"><?= e(username_hint()) ?></span>
            </label>
            <label class="field">
                <span class="label"><?= et('common.status') ?></span>
                <select class="select" name="status">
                    <?php foreach (admin_user_statuses() as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $value === 'active' ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="help m-0"><?= et('bots.account_create_help') ?></p>
        </div>
        <?php
        $body = trim((string) ob_get_clean());

        return render('modals/layout', [
            'id' => 'bot-account-create-modal',
            'title' => t('bots.new_account'),
            'icon' => 'user-plus',
            'action' => self::accountsApiUrl(),
            'method' => 'POST',
            'target' => '#bot-accounts-list',
            'reset' => true,
            'closeOnSuccess' => true,
            'body' => $body,
            'footer' => '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button><button class="btn btn-primary" type="submit">' . icon('user-plus') . ' <span>' . et('common.create') . '</span></button>',
        ]);
    }

    public static function accountFilterModal(): string
    {
        $filters = self::accountFilters();
        ob_start();
        ?>
        <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
        <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
        <input type="hidden" name="page" value="1">
        <label class="field">
            <span class="label"><?= et('common.status') ?></span>
            <select class="select" name="status">
                <option value=""><?= et('common.all') ?></option>
                <?php foreach (admin_user_statuses() as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php
        $body = trim((string) ob_get_clean());

        return render('modals/layout', [
            'id' => 'bot-accounts-filter-modal',
            'title' => t('bots.accounts_filter_title'),
            'icon' => 'filter',
            'action' => self::accountsApiUrl(),
            'method' => 'GET',
            'target' => '#bot-accounts-list',
            'closeOnSuccess' => true,
            'csrf' => false,
            'formAttributes' => ['data-history' => '/admin/bots/accounts'],
            'body' => $body,
            'footer' => '<a class="btn btn-secondary" href="' . e(self::accountsApiUrl(['per_page' => admin_per_page(), 'page' => 1], false)) . '" data-ajax data-ajax-target="#bot-accounts-list" data-history="/admin/bots/accounts" data-modal-close>' . icon('close') . ' <span>' . et('common.clear_filters') . '</span></a><button class="btn btn-primary" type="submit">' . icon('filter') . ' <span>' . et('common.apply_filters') . '</span></button>',
        ]);
    }

    public static function accountResource(array $account): array
    {
        if ($account === []) {
            return [];
        }

        return [
            'id' => (int) ($account['id'] ?? 0),
            'username' => (string) ($account['username'] ?? ''),
            'status' => (string) ($account['status'] ?? ''),
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

    public static function html(): string
    {
        $bots = self::bots();
        $filterBotId = self::filterId();
        $sources = bot_sources($filterBotId > 0 ? $filterBotId : null);
        ob_start();
        ?>
        <div class="stack stack-gap-14">
            <?php if ($bots === []): ?>
                <div class="alert alert-info"><?= et('bots.no_bots') ?></div>
            <?php else: ?>
                <div class="admin-list-toolbar">
                    <div class="admin-filter-actions">
                        <span class="badge"><?= et('bots.sources_count', ['count' => count($sources)]) ?></span>
                        <span class="badge badge-primary"><?= et('bots.active_sources_count', ['count' => count(array_filter($sources, static fn (array $source): bool => (bool) ($source['enabled'] ?? false)))]) ?></span>
                        <?php if ($filterBotId > 0): ?>
                            <a class="btn btn-ghost" href="/admin/bots/list"><?= icon('close') ?> <span><?= et('common.clear_filters') ?></span></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($sources === []): ?>
                    <div class="alert alert-info"><?= et($filterBotId > 0 ? 'bots.no_sources_filtered' : 'bots.no_sources') ?></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?= et('bots.source_name') ?></th>
                                    <th><?= et('bots.bot') ?></th>
                                    <th><?= et('common.status') ?></th>
                                    <th><?= et('bots.schedule') ?></th>
                                    <th><?= et('bots.last_import') ?></th>
                                    <th><?= et('common.actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sources as $source): ?>
                                    <?php $id = (int) ($source['id'] ?? 0); ?>
                                    <tr>
                                        <td>
                                            <strong><?= e((string) ($source['name'] ?? '')) ?></strong>
                                            <div class="table-meta"><a href="<?= e((string) ($source['feed_url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) ($source['feed_url'] ?? '')) ?></a></div>
                                            <?php if (!empty($source['last_error'])): ?><div class="table-meta text-danger"><?= e((string) $source['last_error']) ?></div><?php endif; ?>
                                        </td>
                                        <td><a href="/admin/bots/<?= e((int) ($source['bot_user_id'] ?? 0)) ?>">@<?= e((string) ($source['username'] ?? '')) ?></a></td>
                                        <td><span class="badge<?= (bool) ($source['enabled'] ?? false) ? ' badge-primary' : '' ?>"><?= et((bool) ($source['enabled'] ?? false) ? 'bots.enabled' : 'bots.disabled') ?></span></td>
                                        <td>
                                            <?= et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?>
                                            <?php if (!empty($source['next_run_at'])): ?><div class="table-meta"><?= et('bots.next_run', ['time' => datetime((string) $source['next_run_at'])]) ?></div><?php endif; ?>
                                        </td>
                                        <td class="table-meta"><?= !empty($source['last_imported_at']) ? e(datetime((string) $source['last_imported_at'])) : et('bots.never_imported') ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-open="bot-source-edit-<?= e($id) ?>" aria-label="<?= et('bots.edit_source') ?>" title="<?= et('bots.edit_source') ?>"><?= icon('edit') ?></button>
                                                <form class="inline-flex" method="post" action="/admin/bots/list">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="run_source"><input type="hidden" name="source_id" value="<?= e($id) ?>">
                                                    <button class="btn btn-sm btn-ghost btn-icon" type="submit" aria-label="<?= et('bots.detail_run_now') ?>" title="<?= et('bots.detail_run_now') ?>"><?= icon('refresh') ?></button>
                                                </form>
                                                <form class="inline-flex" method="post" action="<?= e(self::apiUrl()) ?>" data-ajax-form data-ajax-target="#bots-list" data-confirm="<?= et('bots.delete_confirm') ?>">
                                                    <?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><input type="hidden" name="id" value="<?= e($id) ?>">
                                                    <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" aria-label="<?= et('common.delete') ?>" title="<?= et('common.delete') ?>"><?= icon('trash') ?></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php foreach ($sources as $source): ?>
                        <?= self::sourceModal($source) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return trim((string) ob_get_clean());
    }

    public static function sourceModal(?array $source): string
    {
        $create = $source === null;
        $source ??= [];
        $id = (int) ($source['id'] ?? 0);
        $bots = self::bots();
        $filterBotId = self::filterId();
        if ($create && $filterBotId > 0) {
            $source['bot_user_id'] = $filterBotId;
        }
        ob_start();
        ?>
        <?php if (!$create): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
        <div class="stack">
            <div class="grid sm:grid-2">
                <label class="field"><span class="label"><?= et('bots.bot') ?></span><select class="select" name="bot_user_id" required><?php foreach ($bots as $bot): ?><option value="<?= e((int) $bot['id']) ?>"<?= (int) ($source['bot_user_id'] ?? 0) === (int) $bot['id'] ? ' selected' : '' ?>>@<?= e((string) $bot['username']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span class="label"><?= et('bots.source_name') ?></span><input class="input" name="name" maxlength="120" value="<?= e((string) ($source['name'] ?? '')) ?>" required></label>
            </div>
            <label class="field"><span class="label"><?= et('bots.feed_url') ?></span><input class="input" type="url" name="feed_url" maxlength="2048" value="<?= e((string) ($source['feed_url'] ?? '')) ?>" placeholder="https://example.com/feed/" required></label>
            <label class="field"><span class="label"><?= et('bots.interval') ?></span><input class="input" type="number" name="interval_minutes" min="5" max="43200" value="<?= e((int) ($source['interval_minutes'] ?? 60)) ?>" required><span class="help"><?= et('bots.interval_help') ?></span></label>
            <label class="field"><span class="label"><?= et('bots.template') ?></span><textarea class="textarea" name="post_template" rows="8" maxlength="2000" required><?= e((string) ($source['post_template'] ?? bot_source_default_template())) ?></textarea><span class="help"><?= et('bots.template_help') ?></span></label>
            <label class="check"><input type="checkbox" name="enabled" value="1"<?= $create || (bool) ($source['enabled'] ?? false) ? ' checked' : '' ?>> <span><?= et('bots.enabled') ?></span></label>
        </div>
        <?php
        $body = trim((string) ob_get_clean());

        return render('modals/layout', [
            'id' => $create ? 'bot-source-create-modal' : 'bot-source-edit-' . $id,
            'title' => t($create ? 'bots.new_source' : 'bots.edit_source'),
            'icon' => 'link',
            'action' => self::apiUrl(),
            'method' => $create ? 'POST' : 'PATCH',
            'ajax' => true,
            'target' => '#bots-list',
            'closeOnSuccess' => true,
            'size' => 'modal-panel-lg',
            'body' => $body,
            'footer' => '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button><button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.save') . '</span></button>',
        ]);
    }

    public static function filterModal(): string
    {
        $filterBotId = self::filterId();
        ob_start();
        ?>
        <label class="field">
            <span class="label"><?= et('bots.filter_by_bot') ?></span>
            <select class="select" name="bot">
                <option value="0"><?= et('bots.all_bots') ?></option>
                <?php foreach (self::bots() as $bot): ?>
                    <option value="<?= e((int) $bot['id']) ?>"<?= $filterBotId === (int) $bot['id'] ? ' selected' : '' ?>>@<?= e((string) $bot['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php
        $body = trim((string) ob_get_clean());

        return render('modals/layout', [
            'id' => 'bots-filter-modal',
            'title' => t('bots.filter_by_bot'),
            'icon' => 'filter',
            'action' => '/admin/bots/list',
            'method' => 'GET',
            'ajax' => false,
            'csrf' => false,
            'body' => $body,
            'footer' => '<a class="btn btn-secondary" href="/admin/bots/list">' . icon('close') . ' <span>' . et('common.clear_filters') . '</span></a><button class="btn btn-primary" type="submit">' . icon('filter') . ' <span>' . et('common.apply_filters') . '</span></button>',
        ]);
    }

    private static function bots(): array
    {
        return self::$bots ??= bot_users();
    }
}
