<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post()) {
    csrf_require();

    if ((string) post('action') !== 'reset-opcache') {
        http_response_code(400);
        echo 'Invalid action.';
        return;
    }

    $reset = Cache::resetOpcache();
    flash($reset ? 'success' : 'error', t($reset ? 'admin.cache.opcache_reset_success' : 'admin.cache.opcache_reset_failed'));
    redirect('/admin');
}

if (method() !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    echo 'Method not allowed.';
    return;
}

layout('layout', [
    'title' => t('admin.dashboard_title'),
    'current' => '/admin',
], static function (): void {
    $stats = [
        ['icon' => 'users', 'label' => t('admin.stats.users'), 'table' => 'users'],
        ['icon' => 'file', 'label' => t('admin.stats.content'), 'table' => 'content'],
        ['icon' => 'link', 'label' => t('admin.stats.links'), 'table' => 'links'],
        ['icon' => 'thumb-up', 'label' => t('admin.stats.likes'), 'table' => 'content_likes'],
        ['icon' => 'message-circle', 'label' => t('admin.stats.comments'), 'table' => 'content_comments'],
        ['icon' => 'hash', 'label' => t('admin.stats.tags'), 'table' => 'terms'],
        ['icon' => 'user-plus', 'label' => t('admin.stats.follows'), 'table' => 'user_followers'],
        ['icon' => 'bell', 'label' => t('admin.stats.notifications'), 'table' => 'notifications'],
        ['icon' => 'flag', 'label' => t('admin.stats.reports'), 'table' => 'content_reports'],
    ];
    $counts = tc_admin_dashboard_counts(array_map(
        static fn (array $item): string => (string) $item['table'],
        $stats
    ));
    $cache = Cache::diagnostics();
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
                    <h2 class="text-lg m-0 cluster gap-2"><?= icon((string) $item['icon'], 'icon text-primary') ?> <?= e($item['label']) ?></h2>
                    <?php if ($count === null): ?>
                        <p class="text-muted mb-0"><?= et('admin.table_missing') ?></p>
                    <?php else: ?>
                        <p class="text-2xl m-0"><strong><?= e(tc_admin_dashboard_number($count)) ?></strong></p>
                        <p class="table-meta m-0"><code><?= e((string) $item['table']) ?></code></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <section class="card mt-6">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('database') ?> <?= et('admin.cache.title') ?></h2>
            <?php if ($cache['available']): ?>
                <span class="badge badge-primary"><?= et('admin.cache.available') ?></span>
            <?php else: ?>
                <span class="badge badge-danger"><?= et('admin.cache.fallback') ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body stack gap-2">
            <p class="m-0 text-muted">
                <?= e($cache['driver'] === 'memcached' ? t('admin.cache.memcached') : t('admin.cache.filesystem')) ?>
            </p>
            <p class="m-0 text-muted">
                <strong><?= et('admin.cache.memcached_label') ?>:</strong>
                <?php if (!$memcached['configured']): ?>
                    <?= et('admin.cache.memcached_disabled') ?>
                <?php elseif ($memcached['available']): ?>
                    <?= et('admin.cache.memcached_running') ?>
                <?php elseif (!$memcached['extension']): ?>
                    <?= et('admin.cache.memcached_extension_missing') ?>
                <?php else: ?>
                    <?= et('admin.cache.memcached_unreachable') ?>
                <?php endif; ?>
            </p>
            <p class="m-0 text-muted">
                <strong><?= et('admin.cache.opcache_label') ?>:</strong>
                <?= et($opcache['enabled'] ? 'admin.cache.opcache_running' : 'admin.cache.opcache_disabled') ?>
            </p>
            <?php if ($memcached['available'] || $opcache['enabled']): ?>
                <div class="grid sm:grid-2 gap-3">
                    <?php if ($memcached['available']): ?>
                        <div class="stack gap-1">
                            <strong><?= et('admin.cache.memcached_details') ?></strong>
                            <p class="m-0 table-meta"><?= et('admin.cache.servers', ['value' => tc_admin_dashboard_number($memcachedStats['servers'])]) ?> · <?= et('admin.cache.version', ['value' => $memcachedStats['version'] !== '' ? $memcachedStats['version'] : '—']) ?></p>
                            <p class="m-0 table-meta"><?= et('admin.cache.uptime', ['value' => tc_admin_dashboard_number($memcachedStats['uptime'])]) ?> · <?= et('admin.cache.items', ['value' => tc_admin_dashboard_number($memcachedStats['items'])]) ?></p>
                            <p class="m-0 table-meta"><?= et('admin.cache.memory', ['used' => tc_admin_dashboard_bytes($memcachedStats['bytes']), 'limit' => tc_admin_dashboard_bytes($memcachedStats['limit_bytes'])]) ?></p>
                            <p class="m-0 table-meta"><?= et('admin.cache.hit_rate', ['value' => tc_admin_dashboard_hit_rate($memcachedStats['hits'], $memcachedStats['misses'])]) ?> · <?= et('admin.cache.evictions', ['value' => tc_admin_dashboard_number($memcachedStats['evictions'])]) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($opcache['enabled']): ?>
                        <div class="stack gap-1">
                            <div class="split">
                                <strong><?= et('admin.cache.opcache_details') ?></strong>
                                <?php if ($opcache['resettable']): ?>
                                    <form method="post" action="/admin" data-confirm="<?= et('admin.cache.opcache_reset_confirm') ?>" data-confirm-title="<?= et('admin.cache.opcache_reset') ?>" data-confirm-ok="<?= et('admin.cache.opcache_reset') ?>" data-confirm-cancel="<?= et('common.cancel') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reset-opcache">
                                        <button class="btn btn-secondary btn-sm" type="submit"><?= icon('refresh') ?> <span><?= et('admin.cache.opcache_reset') ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <p class="m-0 table-meta"><?= et('admin.cache.cached_scripts', ['value' => tc_admin_dashboard_number($opcacheStats['cached_scripts'])]) ?> / <?= et('admin.cache.max_scripts', ['value' => tc_admin_dashboard_number($opcacheConfig['max_scripts'])]) ?></p>
                            <p class="m-0 table-meta"><?= et('admin.cache.memory', ['used' => tc_admin_dashboard_bytes($opcacheStats['used_memory']), 'limit' => tc_admin_dashboard_bytes($opcacheConfig['memory_bytes'])]) ?> · <?= et('admin.cache.wasted_memory', ['value' => tc_admin_dashboard_bytes($opcacheStats['wasted_memory'])]) ?></p>
                            <p class="m-0 table-meta"><?= et('admin.cache.hit_rate', ['value' => tc_admin_dashboard_hit_rate($opcacheStats['hits'], $opcacheStats['misses'])]) ?> · <?= et('admin.cache.timestamp_checks', ['value' => $opcacheConfig['validate_timestamps'] ? t('admin.cache.enabled') : t('admin.cache.disabled')]) ?></p>
                            <p class="m-0 table-meta"><?= et('admin.cache.revalidate_frequency', ['value' => tc_admin_dashboard_number($opcacheConfig['revalidate_freq'])]) ?> · <?= et($opcacheConfig['file_cache'] ? 'admin.cache.file_cache_enabled' : 'admin.cache.file_cache_disabled') ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <section class="card mt-6">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('message-circle') ?> <?= et('admin.recent_comments') ?></h2>
            <a class="btn btn-secondary btn-sm" href="/admin/moderation/reports"><?= et('common.open') ?></a>
        </div>
        <div class="card-body stack">
            <?php if ($recentComments === []): ?>
                <p class="text-muted mb-0"><?= et('admin.no_recent_comments') ?></p>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($recentComments as $comment): ?>
                        <article class="card-body px-0 py-2 border-b">
                            <div class="split">
                                <strong><?= e('@' . (string) ($comment['username'] ?? '')) ?></strong>
                                <time class="table-meta" datetime="<?= e(date_iso((string) ($comment['created_at'] ?? ''))) ?>"><?= e(datetime((string) ($comment['created_at'] ?? ''))) ?></time>
                            </div>
                            <p class="m-0 text-muted"><?= e(plain_text_limit((string) ($comment['body'] ?? ''), 220)) ?></p>
                            <a class="table-meta" href="<?= e(status_url((int) ($comment['content_id'] ?? 0))) ?>"><?= e(plain_text_limit((string) ($comment['content_body'] ?? ''), 100)) ?></a>
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
        return all(
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
    $cached = Cache::get($cacheKey, 300);

    if (is_array($cached)) {
        return array_map(static fn (mixed $value): ?int => $value === null ? null : (int) $value, $cached);
    }

    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = tc_admin_dashboard_count($table);
    }

    Cache::put($cacheKey, $counts);

    return $counts;
}

function tc_admin_dashboard_count(string $table): ?int
{
    try {
        if ($table === 'users') {
            $managedRoles = UserRoles::rolesWith('managed_by_core_admin');

            return (int) val(
                'SELECT COUNT(*) FROM users WHERE role IN (' . implode(', ', array_fill(0, count($managedRoles), '?')) . ')',
                $managedRoles
            );
        }

        return total($table);
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
