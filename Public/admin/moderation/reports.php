<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post()) {
    csrf_require();

    if ((string) post('action', '') === 'report_review') {
        ModerationAdmin::reviewReport();
    }

    redirect('/admin/moderation/reports');
}

layout('layout', [
    'title' => t('moderation.reports_title'),
    'current' => '/admin/moderation/reports',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('flag') ?> <?= et('moderation.reports_title') ?></h2>
        </div>
        <div class="card-body" id="moderation-reports">
            <?= part('admin/moderation/reports', ModerationAdmin::reportsViewData()) ?>
        </div>
    </section>
    <?php
});
