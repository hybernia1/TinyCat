<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

if (is_post()) {
    csrf_require();
    $user = require_auth('/login');
    $targetAuthorId = (int) get('id', 0);
    $userId = (int) ($user['id'] ?? 0);
    $action = (string) post('action', 'post');

    if ($action === 'follow') {
        author_follow($userId, $targetAuthorId);
        flash('success', t('public.followed'));
        redirect(author_url($targetAuthorId));
    }

    if ($action === 'unfollow') {
        author_unfollow($userId, $targetAuthorId);
        flash('success', t('public.unfollowed'));
        redirect(author_url($targetAuthorId));
    }

    if ($action === 'profile') {
        if ($userId !== $targetAuthorId) {
            flash('error', t('auth.forbidden'));
            redirect(author_url($targetAuthorId));
        }

        user_profile_update($user, author_url($targetAuthorId));
    }

    if ($action === 'avatar') {
        if ($userId !== $targetAuthorId) {
            flash('error', t('auth.forbidden'));
            redirect(author_url($targetAuthorId));
        }

        user_avatar_update($user, author_url($targetAuthorId));
    }

    if (in_array($action, ['react', 'comment', 'comment_like', 'comment_delete', 'update', 'delete'], true)) {
        status_handle_post($user, author_url($targetAuthorId));
    }

    if ($userId !== $targetAuthorId) {
        flash('error', t('auth.forbidden'));
        redirect(author_url($targetAuthorId));
    }

    status_handle_post($user, author_url($targetAuthorId));
}

$author = public_author_find((int) get('id', 0));

if ($author === null) {
    http_response_code(404);
    layout('layout', [
        'title' => t('public.author_not_found'),
        'current' => '/author',
        'meta' => [
            'description' => t('public.author_not_found'),
            'url' => '/author',
            'robots' => 'noindex,follow',
        ],
    ], static function (): void {
        ?>
        <div class="alert alert-info"><?= et('public.author_not_found') ?></div>
        <?php
    });
    return;
}

$authorId = (int) $author['id'];
$authorName = (string) ($author['name'] ?? '');
$website = trim((string) ($author['website'] ?? ''));
$bio = trim((string) ($author['bio'] ?? ''));
$avatarUrl = (string) ($author['avatar_url'] ?? '');
$memberSince = (string) ($author['created_at'] ?? '');
$statusLimit = public_status_page_limit();
$statusItems = public_status_items_by_author($authorId, $statusLimit);
$current = author_url($authorId);
$authUser = auth();
$canPost = $authUser !== null && (int) ($authUser['id'] ?? 0) === $authorId;
$canFollow = $authUser !== null && (int) ($authUser['id'] ?? 0) !== $authorId;
$isFollowing = $canFollow && author_is_followed((int) ($authUser['id'] ?? 0), $authorId);
$followCounts = author_follow_counts($authorId);
$activityStats = author_activity_stats($authorId);
$presence = author_presence($author);
$followingProfiles = author_following_profiles($authorId, 12);

layout('layout', [
    'title' => t('public.author_archive_title', ['author' => $authorName]),
    'current' => $current,
    'meta' => [
        'description' => $bio !== ''
            ? $bio
            : t('public.author_meta', ['author' => $authorName]),
        'url' => $current,
        'image' => $avatarUrl ?: site_meta_image_url(),
        'type' => 'profile',
    ],
], static function () use ($authorId, $authorName, $website, $bio, $avatarUrl, $memberSince, $statusItems, $statusLimit, $canPost, $authUser, $canFollow, $isFollowing, $followCounts, $activityStats, $presence, $followingProfiles): void {
    $feedId = 'status-feed-author-' . $authorId;
    ?>
    <section class="profile-layout">
        <aside class="profile-sidebar">
            <div class="profile-sidebar-stack">
                <article class="card profile-card" data-profile-editor>
                    <div class="card-body stack">
                        <?php if ($canPost): ?>
                            <form class="profile-avatar-upload" method="post" action="<?= e(author_url($authorId)) ?>" enctype="multipart/form-data" title="<?= et('account.avatar') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="avatar">
                                <label class="profile-avatar-button" title="<?= et('account.avatar') ?>">
                                    <span class="avatar avatar-xl">
                                        <?php if ($avatarUrl !== ''): ?>
                                            <img src="<?= e($avatarUrl) ?>" alt="<?= e($authorName) ?>" loading="lazy">
                                        <?php else: ?>
                                            <?= icon('user') ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="sr-only"><?= et('account.avatar') ?></span>
                                    <input class="sr-only" type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" data-submit-on-change>
                                    <span class="profile-avatar-overlay"><?= icon('upload') ?></span>
                                </label>
                            </form>
                        <?php else: ?>
                            <div class="avatar avatar-xl">
                                <?php if ($avatarUrl !== ''): ?>
                                    <img src="<?= e($avatarUrl) ?>" alt="<?= e($authorName) ?>" loading="lazy">
                                <?php else: ?>
                                    <?= icon('user') ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="stack" style="--stack-gap: 8px;">
                            <h1 class="text-xl m-0"><?= e($authorName) ?></h1>
                            <div class="profile-presence<?= ($presence['online'] ?? false) ? ' is-online' : '' ?>">
                                <span class="profile-presence-dot" aria-hidden="true"></span>
                                <?php if ((string) ($presence['datetime'] ?? '') !== ''): ?>
                                    <time datetime="<?= e((string) $presence['datetime']) ?>"><?= e((string) ($presence['label'] ?? '')) ?></time>
                                <?php else: ?>
                                    <span><?= e((string) ($presence['label'] ?? '')) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canPost): ?>
                                <button class="profile-editable-text" type="button" data-profile-edit-open data-profile-edit-focus="bio">
                                    <?php if ($bio !== ''): ?>
                                        <?= nl2br(e($bio)) ?>
                                    <?php else: ?>
                                        <span class="text-muted"><?= et('account.bio') ?></span>
                                    <?php endif; ?>
                                </button>
                            <?php elseif ($bio !== ''): ?>
                                <p class="text-muted mb-0"><?= nl2br(e($bio)) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="profile-stats">
                            <span><strong><?= e((int) ($followCounts['followers'] ?? 0)) ?></strong> <?= et('public.followers') ?></span>
                            <span><strong><?= e((int) ($followCounts['following'] ?? 0)) ?></strong> <?= et('public.following') ?></span>
                            <span><strong><?= e((int) ($activityStats['posts'] ?? 0)) ?></strong> <?= et('public.profile_posts') ?></span>
                            <span><strong><?= e((int) ($activityStats['likes_given'] ?? 0)) ?></strong> <?= et('public.profile_likes_given') ?></span>
                            <span><strong><?= e((int) ($activityStats['likes_received'] ?? 0)) ?></strong> <?= et('public.profile_likes_received') ?></span>
                            <span><strong><?= e((int) ($activityStats['comments'] ?? 0)) ?></strong> <?= et('public.profile_comments') ?></span>
                        </div>
                        <?php if ($memberSince !== ''): ?>
                            <div class="profile-meta-list">
                                <span><?= et('public.member_since', ['date' => date_value($memberSince)]) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="cluster gap-2">
                            <?php if ($canFollow): ?>
                                <form method="post" action="<?= e(author_url($authorId)) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>">
                                    <button class="btn <?= $isFollowing ? 'btn-secondary' : 'btn-primary' ?> btn-sm" type="submit">
                                        <?= icon($isFollowing ? 'check' : 'plus') ?> <span><?= et($isFollowing ? 'public.unfollow' : 'public.follow') ?></span>
                                    </button>
                                </form>
                            <?php elseif ($authUser === null): ?>
                                <a class="btn btn-secondary btn-sm" href="/login">
                                    <?= icon('login') ?> <span><?= et('public.follow_login') ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if ($canPost): ?>
                                <a class="btn btn-secondary btn-sm" href="/account">
                                    <?= icon('key') ?> <span><?= et('account.security_settings') ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if ($canPost): ?>
                                <button class="btn btn-secondary btn-sm" type="button" data-profile-edit-open data-profile-edit-focus="website">
                                    <?= icon('external-link') ?> <span><?= et('account.website') ?></span>
                                </button>
                            <?php elseif ($website !== ''): ?>
                                <a class="btn btn-secondary btn-sm" href="<?= e($website) ?>" target="_blank" rel="noopener">
                                    <?= icon('external-link') ?> <span><?= et('account.website') ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if ($canPost && $authUser !== null): ?>
                            <details class="profile-inline-editor" data-profile-editor-panel>
                                <summary class="btn btn-secondary btn-sm btn-icon profile-edit-toggle" title="<?= et('common.edit') ?>" data-profile-edit-open data-profile-edit-focus="name">
                                    <?= icon('edit') ?>
                                    <span class="sr-only"><?= et('common.edit') ?></span>
                                </summary>
                                <?= tc_author_profile_editor($authUser, $authorId) ?>
                            </details>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="card profile-following-card">
                    <div class="card-header">
                        <h2 class="text-base m-0 cluster gap-2"><?= icon('users') ?> <?= et('public.following_profiles') ?></h2>
                    </div>
                    <div class="card-body">
                        <?php if ($followingProfiles === []): ?>
                            <p class="text-muted m-0"><?= et('public.following_profiles_empty') ?></p>
                        <?php else: ?>
                            <nav class="sidebar-user-list" aria-label="<?= et('public.following_profiles') ?>">
                                <?php foreach ($followingProfiles as $profile): ?>
                                    <?php
                                    $profileId = (int) ($profile['id'] ?? 0);
                                    $profileName = trim((string) ($profile['name'] ?? ''));
                                    $profileAvatar = trim((string) ($profile['avatar_url'] ?? ''));
                                    ?>
                                    <a class="sidebar-user-link" href="<?= e(author_url($profileId)) ?>">
                                        <span class="avatar avatar-sm">
                                            <?php if ($profileAvatar !== ''): ?>
                                                <img src="<?= e($profileAvatar) ?>" alt="<?= e($profileName) ?>" loading="lazy">
                                            <?php else: ?>
                                                <?= icon('user') ?>
                                            <?php endif; ?>
                                        </span>
                                        <span class="sidebar-user-main">
                                            <strong><?= e($profileName) ?></strong>
                                            <small><?= et('public.active_user_posts', ['count' => (int) ($profile['posts_count'] ?? 0)]) ?></small>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </nav>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </aside>

        <main class="profile-main stack" style="--stack-gap: 24px;">
        <section class="stack" style="--stack-gap: 12px;">
            <header class="public-list-header">
                <h2 class="text-xl m-0"><?= et('account.posts_title') ?></h2>
            </header>

            <?php if ($canPost && $authUser !== null): ?>
                <?= status_composer(author_url($authorId), $authUser) ?>
            <?php endif; ?>

            <?php if ($statusItems === []): ?>
                <div class="alert alert-info"><?= et('public.author_feed_empty') ?></div>
            <?php else: ?>
                <div class="status-feed" id="<?= e($feedId) ?>" data-status-feed>
                    <?php foreach ($statusItems as $item): ?>
                        <?= status_card($item, author_url($authorId)) ?>
                    <?php endforeach; ?>
                </div>
                <?= status_feed_more_control($feedId, 'author', count($statusItems), $statusLimit, ['author_id' => $authorId]) ?>
            <?php endif; ?>
        </section>
        </main>
    </section>
    <?php
});

function tc_author_profile_editor(array $user, int $authorId): string
{
    $name = trim((string) ($user['name'] ?? ''));
    $email = trim((string) ($user['email'] ?? ''));
    $website = trim((string) ($user['website'] ?? ''));
    $bio = trim((string) ($user['bio'] ?? ''));
    $selectedLocale = language_code((string) ($user['locale'] ?? '')) ?: locale();
    $action = author_url($authorId);

    ob_start();
    ?>
    <form class="profile-edit-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <div class="profile-inline-grid">
            <label class="field">
                <span class="label"><?= et('common.name') ?></span>
                <input class="input" name="name" autocomplete="name" value="<?= e($name) ?>" required>
            </label>
            <label class="field">
                <span class="label"><?= et('common.email') ?></span>
                <input class="input" type="email" name="email" autocomplete="email" value="<?= e($email) ?>" required>
            </label>
            <label class="field">
                <span class="label"><?= et('account.website') ?></span>
                <input class="input" type="url" name="website" autocomplete="url" value="<?= e($website) ?>">
            </label>
            <label class="field">
                <span class="label"><?= et('common.language') ?></span>
                <select class="select" name="locale" required>
                    <?= language_options($selectedLocale) ?>
                </select>
            </label>
            <label class="field profile-inline-span">
                <span class="label"><?= et('account.bio') ?></span>
                <textarea class="textarea" name="bio" rows="5" maxlength="500"><?= e($bio) ?></textarea>
            </label>
        </div>
        <div class="profile-inline-footer">
            <button class="btn btn-secondary" type="button" data-profile-edit-close><?= icon('close') ?> <span><?= et('common.cancel') ?></span></button>
            <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('account.save_profile') ?></span></button>
        </div>
    </form>
    <?php

    return trim((string) ob_get_clean());
}
