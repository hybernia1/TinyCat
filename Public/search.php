<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

$query = trim((string) get('q', ''));
$hasQuery = (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) >= 2;
$current = '/search' . ($query !== '' ? '?q=' . rawurlencode($query) : '');

if (is_post()) {
    csrf_require();
    status_handle_post(require_auth('/login'), $current);
}

$results = $hasQuery ? public_search_results($query, 24) : [
    'query' => $query,
    'tags' => [],
    'users' => [],
    'content' => [],
];
$statusItems = $hasQuery ? tc_search_status_items($results) : [];

layout('layout', [
    'title' => $hasQuery ? t('public.search_title_query', ['query' => $query]) : t('public.search_title'),
    'current' => '/search',
    'meta' => [
        'description' => $hasQuery
            ? t('public.search_meta_query', ['query' => $query])
            : t('public.search_meta'),
        'url' => $current,
        'image' => site_meta_image_url(),
        'robots' => 'noindex,follow',
    ],
], static function () use ($query, $hasQuery, $statusItems, $current): void {
    ?>
    <section class="public-layout">
        <main class="home-feed-section stack" style="--stack-gap: 16px;">
            <header class="public-list-header">
                <h1 class="text-2xl m-0"><?= et('public.search_title') ?></h1>
            </header>

            <form class="search-page-form" action="/search" method="get" role="search">
                <label class="sr-only" for="search-page-q"><?= et('common.search') ?></label>
                <div class="input-icon">
                    <?= icon('search') ?>
                    <input class="input" id="search-page-q" type="search" name="q" value="<?= e($query) ?>" placeholder="<?= et('public.search_placeholder') ?>" minlength="2" maxlength="80" autofocus>
                </div>
                <button class="btn btn-primary" type="submit"><?= icon('search') ?> <span><?= et('common.search') ?></span></button>
            </form>

            <?php if (!$hasQuery): ?>
                <div class="alert alert-info"><?= et('public.search_min') ?></div>
            <?php elseif ($statusItems === []): ?>
                <div class="alert alert-info"><?= et('public.search_empty') ?></div>
            <?php else: ?>
                <div class="status-feed">
                    <?php foreach ($statusItems as $item): ?>
                        <?= status_card($item, $current) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
        <?= public_sidebar() ?>
    </section>
    <?php
});

function tc_search_status_items(array $results): array
{
    $ids = [];
    $items = [];

    foreach ((array) ($results['content'] ?? []) as $item) {
        $id = (int) ($item['id'] ?? 0);

        if ($id > 0 && !isset($ids[$id])) {
            $ids[$id] = true;
        }
    }

    foreach (public_status_items_by_ids(array_keys($ids)) as $item) {
        $id = (int) ($item['id'] ?? 0);

        if ($id > 0) {
            $items[$id] = $item;
        }
    }

    foreach ((array) ($results['users'] ?? []) as $user) {
        foreach (public_status_items_by_author((int) ($user['id'] ?? 0), 12) as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id > 0 && !isset($items[$id])) {
                $items[$id] = $item;
            }
        }
    }

    foreach ((array) ($results['tags'] ?? []) as $tag) {
        $name = trim((string) ($tag['title'] ?? ''), '# ');

        foreach (public_status_items_by_tag($name, 12) as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id > 0 && !isset($items[$id])) {
                $items[$id] = $item;
            }
        }
    }

    return array_slice(array_values($items), 0, 48);
}
