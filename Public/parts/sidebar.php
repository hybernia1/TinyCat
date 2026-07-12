<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$activeTag = status_tag_normalize((string) ($active_tag ?? ''));
$tags = is_array($tags ?? null) ? $tags : [];
$authors = is_array($authors ?? null) ? $authors : [];
$needsRefresh = (bool) ($needs_refresh ?? false);
$sidebarUrl = (string) ($sidebar_url ?? '/api/sidebar');
?>
<aside class="public-sidebar" aria-label="<?= et('public.sidebar_title') ?>"<?= $needsRefresh ? ' data-public-sidebar data-sidebar-url="' . e($sidebarUrl) . '"' : '' ?>>
    <article class="card public-sidebar-card">
        <div class="card-header">
            <h2 class="text-base m-0 cluster gap-2"><?= icon('hash') ?> <?= et('public.favorite_topics') ?></h2>
        </div>
        <div class="card-body">
            <?php if ($tags === []): ?>
                <p class="text-muted m-0"><?= et('public.favorite_topics_empty') ?></p>
            <?php else: ?>
                <nav class="topic-list" aria-label="<?= et('public.favorite_topics') ?>">
                    <?php foreach ($tags as $tag): ?>
                        <?php
                        $name = (string) ($tag['name'] ?? '');
                        $isActive = $activeTag !== '' && $activeTag === $name;
                        ?>
                        <a class="topic-link<?= $isActive ? ' is-active' : '' ?>" href="<?= e((string) ($tag['url'] ?? tag_url($name))) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                            <span class="topic-name">#<?= e($name) ?></span>
                            <span class="badge"><?= e((int) ($tag['posts_count'] ?? 0)) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>
    </article>
    <article class="card public-sidebar-card">
        <div class="card-header">
            <h2 class="text-base m-0 cluster gap-2"><?= icon('users') ?> <?= et('public.active_users') ?></h2>
        </div>
        <div class="card-body">
            <?php if ($authors === []): ?>
                <p class="text-muted m-0"><?= et('public.active_users_empty') ?></p>
            <?php else: ?>
                <nav class="sidebar-user-list" aria-label="<?= et('public.active_users') ?>">
                    <?php foreach ($authors as $author): ?>
                        <?php
                        $id = (int) ($author['id'] ?? 0);
                        $name = trim((string) ($author['name'] ?? ''));
                        ?>
                        <?php if ($id > 0 && $name !== ''): ?>
                            <a class="sidebar-user-link" href="<?= e(author_url($id)) ?>">
                                <span class="avatar avatar-sm">
                                    <?= user_avatar_html($author, $name) ?>
                                </span>
                                <span class="sidebar-user-main">
                                    <strong><?= e($name) ?></strong>
                                    <small><?= et('public.active_user_posts', ['count' => (int) ($author['posts_count'] ?? 0)]) ?></small>
                                </span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>
    </article>
</aside>
