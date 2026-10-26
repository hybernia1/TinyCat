<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Users\Access::require_admin();

$csrfToken = \TinyCat\Security\Csrf::token();

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('users.meta_title'),
    'current' => '/admin/users',
    'csrfToken' => $csrfToken,
    'actions' => '<button class="btn btn-primary btn-sm" type="button" data-modal-open="user-create-modal">' . \TinyCat\Support\Html::icon('user-plus') . ' <span>' . \TinyCat\Support\Html::et('users.new_user') . '</span></button>',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('users') ?> <?= \TinyCat\Support\Html::et('users.list_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="users-filter-modal">
                <?= \TinyCat\Support\Html::icon('filter') ?> <span><?= \TinyCat\Support\Html::et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="users-list">
            <?= \TinyCat\Users\Views::adminList(\TinyCat\Users\ViewData::tc_admin_users_view_data()) ?>
        </div>
    </section>
    <?php
});
