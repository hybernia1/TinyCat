<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$author = public_author_find((int) get('id', 0));

if ($author === null) {
    http_response_code(404);
    $notFoundTitle = t('public.author_not_found');
    $notFoundCurrent = '/author';
    require public_path('not-found.php');
    return;
}

$authorId = (int) $author['id'];
$authorName = user_display_name($author);
$bio = trim((string) ($author['bio'] ?? ''));
$avatarUrl = user_avatar_url($author);
$memberSince = (string) ($author['created_at'] ?? '');
$current = author_url($authorId);

if (method() === 'GET' && (int) get('page', 0) > 1) {
    redirect($current, 301);
}

$statusLimit = public_status_page_limit();
$pageUrl = $current;
$statusItems = public_status_items_by_author_cursor($authorId, $statusLimit);
$authUser = auth();
$statusItems = status_prepare_items_view($statusItems, $authUser);
$feedMore = status_feed_more_view_data(
    'status-feed-author-' . $authorId,
    'author',
    $statusItems,
    $statusLimit,
    ['author_id' => $authorId]
);
$canPost = $authUser !== null && (int) ($authUser['id'] ?? 0) === $authorId;
$editor = $canPost ? status_editor_view_data() : [];
$canEditProfile = user_can_edit_profile($author, $authUser);
$canSeeMute = $authUser !== null && ($canPost || user_is_admin($authUser));
$mutedUntil = user_muted_until($author);
$canFollow = $authUser !== null && (int) ($authUser['id'] ?? 0) !== $authorId;
$isFollowing = $canFollow && author_is_followed((int) ($authUser['id'] ?? 0), $authorId);
$followCounts = author_follow_counts($authorId);
$activityStats = author_activity_stats($authorId);
$publicPostCount = (int) ($activityStats['posts'] ?? 0);
$authorEntityId = absolute_url($current) . '#author';
$authorStructuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    '@id' => absolute_url($pageUrl),
    'url' => absolute_url($pageUrl),
    'dateCreated' => $memberSince !== '' ? date_iso($memberSince) : null,
    'dateModified' => (string) ($author['updated_at'] ?? '') !== '' ? date_iso((string) $author['updated_at']) : null,
    'mainEntity' => [
        '@type' => UserRoles::profileSchemaType((string) ($author['role'] ?? '')),
        '@id' => $authorEntityId,
        'name' => $authorName,
        'alternateName' => (string) ($author['username'] ?? ''),
        'identifier' => (string) $authorId,
        'description' => $bio !== '' ? $bio : null,
        'image' => $avatarUrl !== '' ? absolute_url($avatarUrl) : null,
    ],
    'hasPart' => array_values(array_map(static function (array $item) use ($authorEntityId): array {
        $image = status_image_jsonld($item) ?? (status_meta_link_image($item) ?: null);

        return array_filter([
            '@type' => 'DiscussionForumPosting',
            'url' => absolute_url(status_url((int) ($item['id'] ?? 0))),
            'image' => $image,
            'headline' => status_meta_title($item),
            'datePublished' => date_iso((string) ($item['published_at'] ?? '')),
            'author' => ['@id' => $authorEntityId],
        ], static fn (mixed $value): bool => $value !== null);
    }, $statusItems)),
];
$authorStructuredData = array_filter($authorStructuredData, static fn (mixed $value): bool => $value !== null && $value !== []);
$authorStructuredData['mainEntity'] = array_filter($authorStructuredData['mainEntity']);

layout('layout', [
    'title' => t('public.author_archive_title', ['author' => $authorName]),
    'current' => $current,
    'meta' => [
        'description' => $bio !== ''
            ? $bio
            : t('public.author_meta', ['author' => $authorName]),
        'url' => $pageUrl,
        'image' => $avatarUrl ?: site_meta_image_url(),
        'type' => 'profile',
        'rss' => author_feed_url($authorId),
        'robots' => $publicPostCount > 0 ? '' : 'noindex,follow',
        'jsonld' => $authorStructuredData,
    ],
], static function () use ($author, $authorId, $authorName, $bio, $memberSince, $statusItems, $canPost, $canEditProfile, $authUser, $canSeeMute, $mutedUntil, $canFollow, $isFollowing, $followCounts, $activityStats, $feedMore, $editor): void {
    $feedId = 'status-feed-author-' . $authorId;
    ?>
    <section class="profile-layout">
        <aside class="profile-sidebar">
            <div class="profile-sidebar-stack">
                <article class="card profile-card">
                    <div class="card-body profile-card-body">
                        <?php if ($canEditProfile): ?>
                            <button class="btn btn-secondary btn-sm btn-icon profile-edit-toggle" type="button" data-modal-open="<?= e(author_profile_edit_modal_id($authorId)) ?>" data-modal-url="<?= e(author_profile_edit_modal_url($authorId, 'bio')) ?>" title="<?= et('common.edit') ?>" aria-label="<?= et('common.edit') ?>">
                                <?= icon('edit') ?>
                            </button>
                        <?php endif; ?>
                        <div class="profile-identity">
                            <?php if ($canPost): ?>
                                <button class="avatar avatar-xl profile-avatar-button" type="button" data-modal-open="<?= e(author_avatar_edit_modal_id($authorId)) ?>" data-modal-url="<?= e(author_avatar_edit_modal_url($authorId)) ?>" title="<?= et('account.avatar_edit') ?>" aria-label="<?= et('account.avatar_edit') ?>">
                            <?php else: ?>
                                <div class="avatar avatar-xl">
                            <?php endif; ?>
                                <?= part('user/avatar', ['user' => $author, 'alt' => $authorName]) ?>
                            <?php if ($canPost): ?>
                                </button>
                            <?php else: ?>
                                </div>
                            <?php endif; ?>
                            <div class="profile-identity-main">
                                <h1 class="profile-name m-0"><?= e($authorName) ?></h1>
                            </div>
                        </div>
                        <div class="profile-details">
                            <?php if ($canSeeMute && $mutedUntil !== ''): ?>
                                <div class="alert alert-warning">
                                    <?= icon('lock') ?>
                                    <span><?= et('moderation.profile_muted_until', ['until' => datetime($mutedUntil)]) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($canEditProfile): ?>
                                <button class="profile-editable-text" type="button" data-modal-open="<?= e(author_profile_edit_modal_id($authorId)) ?>" data-modal-url="<?= e(author_profile_edit_modal_url($authorId, 'bio')) ?>">
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
                            <span class="profile-stat"><strong data-author-stat="followers" data-author-id="<?= e($authorId) ?>"><?= e((int) ($followCounts['followers'] ?? 0)) ?></strong> <span><?= et('public.followers') ?></span></span>
                            <button class="profile-stat profile-stat-button" type="button" data-modal-open="<?= e(author_following_modal_id($authorId)) ?>" data-modal-url="<?= e(author_following_modal_url($authorId)) ?>" aria-label="<?= et('public.following_profiles_title', ['author' => $authorName]) ?>" title="<?= et('public.following_profiles_title', ['author' => $authorName]) ?>">
                                <strong data-author-stat="following" data-author-id="<?= e($authorId) ?>"><?= e((int) ($followCounts['following'] ?? 0)) ?></strong>
                                <span><?= et('public.following') ?></span>
                            </button>
                            <span class="profile-stat"><strong><?= e((int) ($activityStats['posts'] ?? 0)) ?></strong> <span><?= et('public.profile_posts') ?></span></span>
                            <span class="profile-stat"><strong><?= e((int) ($activityStats['likes_given'] ?? 0)) ?></strong> <span><?= et('public.profile_likes_given') ?></span></span>
                            <span class="profile-stat"><strong><?= e((int) ($activityStats['likes_received'] ?? 0)) ?></strong> <span><?= et('public.profile_likes_received') ?></span></span>
                            <span class="profile-stat"><strong><?= e((int) ($activityStats['comments'] ?? 0)) ?></strong> <span><?= et('public.profile_comments') ?></span></span>
                            <?php if ($memberSince !== ''): ?>
                                <span class="profile-member-since"><?= icon('calendar') ?> <span><?= et('public.member_since', ['date' => date_value($memberSince)]) ?></span></span>
                            <?php endif; ?>
                        </div>
                        <div class="profile-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= e(author_feed_url($authorId)) ?>" target="_blank" rel="noopener" title="<?= et('public.rss_feed') ?>" aria-label="<?= et('public.rss_feed') ?>">
                                <?= icon('rss') ?> <span>RSS</span>
                            </a>
                            <?php if ($canFollow): ?>
                                <?= part('author/follow-button', [
                                    'author_id' => $authorId,
                                    'is_following' => $isFollowing,
                                ]) ?>
                            <?php elseif ($authUser === null): ?>
                                <a class="btn btn-secondary btn-sm" href="/login" data-modal-open="<?= e(auth_modal_id()) ?>" data-modal-url="<?= e(auth_modal_url()) ?>">
                                    <?= icon('login') ?> <span><?= et('public.follow') ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>

            </div>
        </aside>

        <div class="profile-main stack stack-gap-24">
            <section class="stack stack-gap-12">
                <?php if ($canPost && $authUser !== null && $mutedUntil !== ''): ?>
                    <div class="alert alert-warning">
                        <?= icon('lock') ?> <span><?= et('moderation.messages.account_muted', ['until' => datetime($mutedUntil)]) ?></span>
                    </div>
                <?php elseif ($canPost && $authUser !== null): ?>
                    <?= part('status/composer', [
                        'action' => author_url($authorId),
                        'user' => $authUser,
                        'editor' => $editor,
                    ]) ?>
                <?php endif; ?>

                <?php if ($statusItems === []): ?>
                    <div class="alert alert-info" data-status-empty><?= et('public.author_feed_empty') ?></div>
                <?php endif; ?>
                <div class="status-feed" id="<?= e($feedId) ?>" data-status-feed>
                    <?= part('status/feed', [
                        'items' => $statusItems,
                        'action' => author_url($authorId),
                        'user' => $authUser,
                    ]) ?>
                </div>
                <?= part('status/feed-more', $feedMore) ?>
            </section>
        </div>
    </section>
    <?php
});
