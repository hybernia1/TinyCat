<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Users\Access::require_admin();

if (\TinyCat\Http\Request::current()->method() !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo 'Method not allowed.';
    return;
}

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('bots.sources_title'),
    'current' => '/admin/bots/list',
    'actions' => '<button class="btn btn-primary btn-sm" type="button" data-modal-open="bot-source-create-modal">' . \TinyCat\Support\Html::icon('plus') . ' <span>' . \TinyCat\Support\Html::et('bots.new_source') . '</span></button>',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('rss') ?> <?= \TinyCat\Support\Html::et('bots.sources_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="bots-filter-modal">
                <?= \TinyCat\Support\Html::icon('filter') ?> <span><?= \TinyCat\Support\Html::et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="bots-list">
            <?= \TinyCat\Bots\Views::sources(\TinyCat\Bots\Administration::sourcesViewData()) ?>
        </div>
    </section>
    <?= \TinyCat\Bots\Views::sourceForm(\TinyCat\Bots\Administration::sourceFormData(null)) ?>
    <?= \TinyCat\Support\View::render('parts/bots/modals/source-filter', \TinyCat\Bots\Administration::sourceFilterData()) ?>
    <?php
});
