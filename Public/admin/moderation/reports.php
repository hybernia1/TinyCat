<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Users\Access::require_admin();

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('moderation.reports_title'),
    'current' => '/admin/moderation/reports',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('flag') ?> <?= \TinyCat\Support\Html::et('moderation.reports_title') ?></h2>
        </div>
        <div class="card-body" id="moderation-reports">
            <?= \TinyCat\Moderation\Views::reports(\TinyCat\Moderation\Reports::tc_admin_moderation_reports_view_data()) ?>
        </div>
    </section>
    <?php
});
