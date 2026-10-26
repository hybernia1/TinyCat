<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Users\Access::require_admin();

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('bots.accounts_title'),
    'current' => '/admin/bots/accounts',
    'actions' => '<button class="btn btn-primary btn-sm" type="button" data-modal-open="bot-account-create-modal">' . \TinyCat\Support\Html::icon('user-plus') . ' <span>' . \TinyCat\Support\Html::et('bots.new_account') . '</span></button>',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('users') ?> <?= \TinyCat\Support\Html::et('bots.accounts_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="bot-accounts-filter-modal">
                <?= \TinyCat\Support\Html::icon('filter') ?> <span><?= \TinyCat\Support\Html::et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="bot-accounts-list">
            <?= \TinyCat\Bots\Views::accounts(\TinyCat\Bots\Administration::accountsViewData()) ?>
        </div>
    </section>
    <?php
});
