<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Users\Access::require_admin();

if (\TinyCat\Http\Request::current()->isPost()) {
    \TinyCat\Security\Csrf::requireToken();

    if ((string) \TinyCat\Http\Web::post('action') !== 'reset-opcache') {
        http_response_code(400);
        echo 'Invalid action.';
        return;
    }

    $reset = \TinyCat\Support\Cache::resetOpcache();
    \TinyCat\Http\Session::flash($reset ? 'success' : 'error', \TinyCat\Support\Localization::t($reset ? 'admin.cache.opcache_reset_success' : 'admin.cache.opcache_reset_failed'));
    \TinyCat\Http\Web::redirect('/admin');
}

if (\TinyCat\Http\Request::current()->method() !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    echo 'Method not allowed.';
    return;
}

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('admin.dashboard_title'),
    'current' => '/admin',
], static function (): void {
    $stats = [
        ['icon' => 'users', 'label' => \TinyCat\Support\Localization::t('admin.stats.users'), 'table' => 'users'],
        ['icon' => 'file', 'label' => \TinyCat\Support\Localization::t('admin.stats.content'), 'table' => 'content'],
        ['icon' => 'link', 'label' => \TinyCat\Support\Localization::t('admin.stats.links'), 'table' => 'links'],
        ['icon' => 'thumb-up', 'label' => \TinyCat\Support\Localization::t('admin.stats.likes'), 'table' => 'content_likes'],
        ['icon' => 'message-circle', 'label' => \TinyCat\Support\Localization::t('admin.stats.comments'), 'table' => 'content_comments'],
        ['icon' => 'hash', 'label' => \TinyCat\Support\Localization::t('admin.stats.tags'), 'table' => 'terms'],
        ['icon' => 'user-plus', 'label' => \TinyCat\Support\Localization::t('admin.stats.follows'), 'table' => 'user_followers'],
        ['icon' => 'bell', 'label' => \TinyCat\Support\Localization::t('admin.stats.notifications'), 'table' => 'notifications'],
        ['icon' => 'flag', 'label' => \TinyCat\Support\Localization::t('admin.stats.reports'), 'table' => 'content_reports'],
    ];
    $counts = tc_admin_dashboard_counts(array_map(
        static fn (array $item): string => (string) $item['table'],
        $stats
    ));
    $cache = \TinyCat\Support\Cache::diagnostics();
    $memcached = $cache['memcached'];
    $memcachedStats = $memcached['stats'];
    $opcache = $cache['opcache'];
    $opcacheStats = $opcache['stats'];
    $opcacheConfig = $opcache['configuration'];
    $recentComments = tc_admin_dashboard_recent_comments();
    ?>
    <section class="grid sm:grid-2 md:grid-4">
        <?php foreach ($stats as $item): ?>
            <?php $count = $counts[(string) $item['table']] ?? null; ?>
            <article class="card">
                <div class="card-body stack">
                    <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon((string) $item['icon'], 'icon text-primary') ?> <?= \TinyCat\Support\Html::e($item['label']) ?></h2>
                    <?php if ($count === null): ?>
                        <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('admin.table_missing') ?></p>
                    <?php else: ?>
                        <p class="text-2xl m-0"><strong><?= \TinyCat\Support\Html::e(tc_admin_dashboard_number($count)) ?></strong></p>
                        <p class="table-meta m-0"><code><?= \TinyCat\Support\Html::e((string) $item['table']) ?></code></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <section class="card mt-6">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('database') ?> <?= \TinyCat\Support\Html::et('admin.cache.title') ?></h2>
            <?php if ($cache['available']): ?>
                <span class="badge badge-primary"><?= \TinyCat\Support\Html::et('admin.cache.available') ?></span>
            <?php else: ?>
                <span class="badge badge-danger"><?= \TinyCat\Support\Html::et('admin.cache.fallback') ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body stack gap-2">
            <p class="m-0 text-muted">
                <?= \TinyCat\Support\Html::e($cache['driver'] === 'memcached' ? \TinyCat\Support\Localization::t('admin.cache.memcached') : \TinyCat\Support\Localization::t('admin.cache.filesystem')) ?>
            </p>
            <p class="m-0 text-muted">
                <strong><?= \TinyCat\Support\Html::et('admin.cache.memcached_label') ?>:</strong>
                <?php if (!$memcached['configured']): ?>
                    <?= \TinyCat\Support\Html::et('admin.cache.memcached_disabled') ?>
                <?php elseif ($memcached['available']): ?>
                    <?= \TinyCat\Support\Html::et('admin.cache.memcached_running') ?>
                <?php elseif (!$memcached['extension']): ?>
                    <?= \TinyCat\Support\Html::et('admin.cache.memcached_extension_missing') ?>
                <?php else: ?>
                    <?= \TinyCat\Support\Html::et('admin.cache.memcached_unreachable') ?>
                <?php endif; ?>
            </p>
            <p class="m-0 text-muted">
                <strong><?= \TinyCat\Support\Html::et('admin.cache.opcache_label') ?>:</strong>
                <?= \TinyCat\Support\Html::et($opcache['enabled'] ? 'admin.cache.opcache_running' : 'admin.cache.opcache_disabled') ?>
            </p>
            <?php if ($memcached['available'] || $opcache['enabled']): ?>
                <div class="grid sm:grid-2 gap-3">
                    <?php if ($memcached['available']): ?>
                        <div class="stack gap-1">
                            <strong><?= \TinyCat\Support\Html::et('admin.cache.memcached_details') ?></strong>
                            <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.servers', ['value' => tc_admin_dashboard_number($memcachedStats['servers'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.version', ['value' => $memcachedStats['version'] !== '' ? $memcachedStats['version'] : '—']) ?></p>
                            <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.uptime', ['value' => tc_admin_dashboard_number($memcachedStats['uptime'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.items', ['value' => tc_admin_dashboard_number($memcachedStats['items'])]) ?></p>
                            <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.memory', ['used' => tc_admin_dashboard_bytes($memcachedStats['bytes']), 'limit' => tc_admin_dashboard_bytes($memcachedStats['limit_bytes'])]) ?></p>
                            <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.hit_rate', ['value' => tc_admin_dashboard_hit_rate($memcachedStats['hits'], $memcachedStats['misses'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.evictions', ['value' => tc_admin_dashboard_number($memcachedStats['evictions'])]) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($opcache['enabled']): ?>
                        <div class="stack gap-1">
                            <div class="split">
                                <strong><?= \TinyCat\Support\Html::et('admin.cache.opcache_details') ?></strong>
                                <?php if ($opcache['resettable']): ?>
                                    <form method="post" action="/admin" data-confirm="<?= \TinyCat\Support\Html::et('admin.cache.opcache_reset_confirm') ?>" data-confirm-title="<?= \TinyCat\Support\Html::et('admin.cache.opcache_reset') ?>" data-confirm-ok="<?= \TinyCat\Support\Html::et('admin.cache.opcache_reset') ?>" data-confirm-cancel="<?= \TinyCat\Support\Html::et('common.cancel') ?>">
                                        <?= \TinyCat\Security\Csrf::field() ?>
                                        <input type="hidden" name="action" value="reset-opcache">
                                        <button class="btn btn-secondary btn-sm" type="submit"><?= \TinyCat\Support\Html::icon('refresh') ?> <span><?= \TinyCat\Support\Html::et('admin.cache.opcache_reset') ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.cached_scripts', ['value' => tc_admin_dashboard_number($opcacheStats['cached_scripts'])]) ?> / <?= \TinyCat\Support\Html::et('admin.cache.max_scripts', ['value' => tc_admin_dashboard_number($opcacheConfig['max_scripts'])]) ?></p>
                            <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.memory', ['used' => tc_admin_dashboard_bytes($opcacheStats['used_memory']), 'limit' => tc_admin_dashboard_bytes($opcacheConfig['memory_bytes'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.wasted_memory', ['value' => tc_admin_dashboard_bytes($opcacheStats['wasted_memory'])]) ?></p>
                            <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.hit_rate', ['value' => tc_admin_dashboard_hit_rate($opcacheStats['hits'], $opcacheStats['misses'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.timestamp_checks', ['value' => $opcacheConfig['validate_timestamps'] ? \TinyCat\Support\Localization::t('admin.cache.enabled') : \TinyCat\Support\Localization::t('admin.cache.disabled')]) ?></p>
                            <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.revalidate_frequency', ['value' => tc_admin_dashboard_number($opcacheConfig['revalidate_freq'])]) ?> · <?= \TinyCat\Support\Html::et($opcacheConfig['file_cache'] ? 'admin.cache.file_cache_enabled' : 'admin.cache.file_cache_disabled') ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <section class="card mt-6">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('message-circle') ?> <?= \TinyCat\Support\Html::et('admin.recent_comments') ?></h2>
            <a class="btn btn-secondary btn-sm" href="/admin/moderation/reports"><?= \TinyCat\Support\Html::et('common.open') ?></a>
        </div>
        <div class="card-body stack">
            <?php if ($recentComments === []): ?>
                <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('admin.no_recent_comments') ?></p>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($recentComments as $comment): ?>
                        <article class="card-body px-0 py-2 border-b">
                            <div class="split">
                                <strong><?= \TinyCat\Support\Html::e('@' . (string) ($comment['username'] ?? '')) ?></strong>
                                <time class="table-meta" datetime="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::dateIso((string) ($comment['created_at'] ?? ''))) ?>"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::displayDateTime((string) ($comment['created_at'] ?? ''))) ?></time>
                            </div>
                            <p class="m-0 text-muted"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Text::plain_text_limit((string) ($comment['body'] ?? ''), 220)) ?></p>
                            <a class="table-meta" href="<?= \TinyCat\Support\Html::e(\TinyCat\Status\Posts::status_url((int) ($comment['content_id'] ?? 0))) ?>"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Text::plain_text_limit((string) ($comment['content_body'] ?? ''), 100)) ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
});

function tc_admin_dashboard_recent_comments(int $limit = 8): array
{
    try {
        return \TinyCat\Database\Connection::all(
            'SELECT cc.id, cc.content_id, cc.body, cc.created_at, u.username, c.body AS content_body
             FROM content_comments cc
             INNER JOIN users u ON u.id = cc.user_id
             INNER JOIN content c ON c.id = cc.content_id
             ORDER BY cc.created_at DESC, cc.id DESC
             LIMIT ' . max(1, min(30, $limit))
        );
    } catch (Throwable) {
        return [];
    }
}

function tc_admin_dashboard_counts(array $tables): array
{
    $tables = array_values(array_unique(array_filter(array_map(
        static fn (mixed $table): string => trim((string) $table),
        $tables
    ), static fn (string $table): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1)));

    if ($tables === []) {
        return [];
    }

    $cacheKey = 'admin_dashboard_counts_managed_users_' . md5(implode('|', $tables));
    $cached = \TinyCat\Support\Cache::get($cacheKey, 300);

    if (is_array($cached)) {
        return array_map(static fn (mixed $value): ?int => $value === null ? null : (int) $value, $cached);
    }

    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = tc_admin_dashboard_count($table);
    }

    \TinyCat\Support\Cache::put($cacheKey, $counts);

    return $counts;
}

function tc_admin_dashboard_count(string $table): ?int
{
    try {
        if ($table === 'users') {
            $managedRoles = \TinyCat\Users\Roles::rolesWith('managed_by_core_admin');

            return (int) \TinyCat\Database\Connection::value(
                'SELECT COUNT(*) FROM users WHERE role IN (' . implode(', ', array_fill(0, count($managedRoles), '?')) . ')',
                $managedRoles
            );
        }

        return \TinyCat\Database\Connection::count($table);
    } catch (Throwable) {
        return null;
    }
}

function tc_admin_dashboard_number(int $value): string
{
    return number_format($value, 0, '.', ' ');
}

function tc_admin_dashboard_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;
    $value = (float) $bytes;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 1, '.', ' ') . ' ' . $units[$unit];
}

function tc_admin_dashboard_hit_rate(int $hits, int $misses): string
{
    $total = max(0, $hits) + max(0, $misses);

    return $total === 0 ? '—' : number_format((max(0, $hits) / $total) * 100, 1, '.', ' ') . '%';
}
