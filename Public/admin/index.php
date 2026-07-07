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
        ['icon' => 'thumb-up', 'label' => t('admin.stats.likes'), 'table' => 'content_reactions'],
        ['icon' => 'share', 'label' => t('admin.stats.shares'), 'table' => 'content_shares'],
        ['icon' => 'message-circle', 'label' => t('admin.stats.comments'), 'table' => 'content_comments'],
        ['icon' => 'link', 'label' => t('admin.stats.links'), 'table' => 'links'],
        ['icon' => 'hash', 'label' => t('admin.stats.tags'), 'table' => 'terms'],
        ['icon' => 'user-plus', 'label' => t('admin.stats.follows'), 'table' => 'user_followers'],
        ['icon' => 'bell', 'label' => t('admin.stats.notifications'), 'table' => 'notifications'],
        ['icon' => 'flag', 'label' => t('admin.stats.reports'), 'table' => 'content_reports'],
    ];
    ?>
    <section class="grid sm:grid-2 md:grid-4">
        <?php foreach ($stats as $item): ?>
            <?php $count = tc_admin_dashboard_count((string) $item['table']); ?>
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
    <?php
});

function tc_admin_dashboard_count(string $table): ?int
{
    try {
        return total($table);
    } catch (Throwable) {
        return null;
    }
}

function tc_admin_dashboard_number(int $value): string
{
    return number_format($value, 0, '.', ' ');
}
