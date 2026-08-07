<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

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
        <div class="card-body">
            <p class="m-0 text-muted">
                <?= e($cache['driver'] === 'memcached' ? t('admin.cache.memcached') : t('admin.cache.filesystem')) ?>
            </p>
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
