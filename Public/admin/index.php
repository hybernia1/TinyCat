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
    ];
    ?>
    <section class="grid md:grid-4">
        <?php foreach ($stats as $item): ?>
            <?php $count = tc_admin_dashboard_count((string) $item['table']); ?>
            <article class="card">
                <div class="card-body stack">
                    <h2 class="text-lg m-0 cluster gap-2"><?= icon((string) $item['icon'], 'icon text-primary') ?> <?= e($item['label']) ?></h2>
                    <?php if ($count === null): ?>
                        <p class="text-muted mb-0"><?= et('admin.table_missing') ?></p>
                    <?php else: ?>
                        <p class="text-2xl m-0"><strong><?= e($count) ?></strong></p>
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
