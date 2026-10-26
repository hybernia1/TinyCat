<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$query = trim((string) \TinyCat\Http\Web::get('q', ''));
$hasQuery = (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) >= 2;
$current = '/search' . ($query !== '' ? '?q=' . rawurlencode($query) : '');

if (\TinyCat\Http\Request::current()->isPost() && (string) \TinyCat\Http\Web::post('_search_captcha', '') === '1') {
    \TinyCat\Security\Csrf::requireToken();
    if (\TinyCat\Search\Guard::public_search_captcha_verify()) {
        \TinyCat\Http\Web::redirect($current);
    }

    \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('auth.invalid_captcha'));
    \TinyCat\Http\Web::redirect($current);
}

$searchBlocked = $hasQuery ? \TinyCat\Search\Guard::public_search_guard($query) : null;
$results = $hasQuery && $searchBlocked === null ? \TinyCat\Search\Queries::public_search_results($query, 24) : [
    'query' => $query,
    'tags' => [],
    'users' => [],
    'content' => [],
];
$authUser = \TinyCat\Users\Authentication::auth();
$statusItems = $hasQuery && $searchBlocked === null
    ? \TinyCat\Status\ViewData::status_prepare_items_view(tc_search_status_items($results), $authUser)
    : [];

\TinyCat\Support\View::layout('layout', [
    'title' => $hasQuery ? \TinyCat\Support\Localization::t('public.search_title_query', ['query' => $query]) : \TinyCat\Support\Localization::t('public.search_title'),
    'current' => '/search',
    'meta' => [
        'description' => $hasQuery
            ? \TinyCat\Support\Localization::t('public.search_meta_query', ['query' => $query])
            : \TinyCat\Support\Localization::t('public.search_meta'),
        'url' => $current,
        'image' => \TinyCat\Site\Presentation::site_meta_image_url(),
        'robots' => 'noindex,follow',
    ],
    'search_query' => $query,
], static function () use ($query, $hasQuery, $searchBlocked, $statusItems, $current, $authUser): void {
    ?>
    <section class="public-layout">
        <div class="home-feed-section stack stack-gap-16">
            <header class="public-list-header">
                <h1 class="text-2xl m-0"><?= \TinyCat\Support\Html::e($hasQuery ? \TinyCat\Support\Localization::t('public.search_title_query', ['query' => $query]) : \TinyCat\Support\Localization::t('public.search_title')) ?></h1>
            </header>

            <?php if (!$hasQuery): ?>
                <div class="alert alert-info"><?= \TinyCat\Support\Html::et('public.search_min') ?></div>
            <?php elseif ($searchBlocked !== null): ?>
                <?php if (($searchBlocked['code'] ?? '') === 'captcha_required'): ?>
                    <form class="card" action="/search" method="post">
                        <?= \TinyCat\Security\Csrf::field() ?>
                        <input type="hidden" name="_search_captcha" value="1">
                        <input type="hidden" name="q" value="<?= \TinyCat\Support\Html::e($query) ?>">
                        <div class="card-header">
                            <strong><?= \TinyCat\Support\Html::et('public.search_captcha_title') ?></strong>
                        </div>
                        <div class="card-body stack stack-gap-12">
                            <p class="muted m-0"><?= \TinyCat\Support\Html::et('public.search_captcha_required') ?></p>
                            <?= \TinyCat\Security\Captcha::field('search') ?>
                        </div>
                        <div class="card-footer justify-end">
                            <a class="btn btn-ghost" href="/login" data-modal-open="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Login::auth_modal_id()) ?>" data-modal-url="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Login::auth_modal_url()) ?>"><?= \TinyCat\Support\Html::icon('login') ?> <span><?= \TinyCat\Support\Html::et('auth.login_title') ?></span></a>
                            <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('confirm') ?> <span><?= \TinyCat\Support\Html::et('common.confirm') ?></span></button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info"><?= \TinyCat\Support\Html::e((string) ($searchBlocked['message'] ?? \TinyCat\Support\Localization::t('moderation.messages.action_limited'))) ?></div>
                <?php endif; ?>
            <?php elseif ($statusItems === []): ?>
                <div class="alert alert-info"><?= \TinyCat\Support\Html::et('public.search_empty') ?></div>
            <?php else: ?>
                <div class="status-feed">
                    <?= \TinyCat\Support\View::part('status/feed', [
                        'items' => $statusItems,
                        'action' => $current,
                        'user' => $authUser,
                    ]) ?>
                </div>
            <?php endif; ?>
        </div>
        <?= \TinyCat\Site\Presentation::public_sidebar() ?>
    </section>
    <?php
});

function tc_search_status_items(array $results): array
{
    $ids = [];

    foreach ((array) ($results['content'] ?? []) as $item) {
        $id = (int) ($item['id'] ?? 0);

        if ($id > 0 && !isset($ids[$id])) {
            $ids[$id] = true;
        }
    }

    foreach (array_slice((array) ($results['users'] ?? []), 0, 3) as $user) {
        foreach (\TinyCat\Status\Feed::public_status_ids_by_author((int) ($user['id'] ?? 0), 4) as $id) {
            if ($id > 0 && !isset($ids[$id])) {
                $ids[$id] = true;
            }

            if (count($ids) >= 48) {
                break 2;
            }
        }
    }

    foreach (array_slice((array) ($results['tags'] ?? []), 0, 3) as $tag) {
        $name = trim((string) ($tag['title'] ?? ''), '# ');

        foreach (\TinyCat\Status\Feed::public_status_ids_by_tag($name, 4) as $id) {
            if ($id > 0 && !isset($ids[$id])) {
                $ids[$id] = true;
            }

            if (count($ids) >= 48) {
                break 2;
            }
        }
    }

    return \TinyCat\Status\Feed::public_status_items_by_ids(array_slice(array_keys($ids), 0, 48));
}
