<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$author = \TinyCat\Users\Repository::public_author_find((int) \TinyCat\Http\Web::get('id', 0));

if ($author === null) {
    http_response_code(404);
    $notFoundTitle = \TinyCat\Support\Localization::t('public.author_not_found');
    $notFoundCurrent = '/author';
    require \TinyCat\Support\Paths::publicPath('not-found.php');
    return;
}

$authorId = (int) $author['id'];
$authorName = \TinyCat\Users\Profiles::user_display_name($author);
$bio = trim((string) ($author['bio'] ?? ''));
$avatarUrl = \TinyCat\Users\Avatars::user_avatar_url($author);
$memberSince = (string) ($author['created_at'] ?? '');
$current = \TinyCat\Users\Profiles::author_url($authorId);

if (\TinyCat\Http\Request::current()->method() === 'GET' && (int) \TinyCat\Http\Web::get('page', 0) > 1) {
    \TinyCat\Http\Web::redirect($current, 301);
}

$statusLimit = \TinyCat\Status\Feed::public_status_initial_page_limit();
$nextStatusLimit = \TinyCat\Status\Feed::public_status_page_limit();
$pageUrl = $current;
$statusItems = \TinyCat\Status\Feed::public_status_items_by_author_cursor($authorId, $statusLimit);
$authUser = \TinyCat\Users\Authentication::auth();
$statusItems = \TinyCat\Status\ViewData::status_prepare_items_view($statusItems, $authUser);
$feedMore = \TinyCat\Status\ViewData::status_feed_more_view_data(
    'status-feed-author-' . $authorId,
    'author',
    $statusItems,
    $statusLimit,
    ['author_id' => $authorId],
    $nextStatusLimit
);
$canPost = $authUser !== null && (int) ($authUser['id'] ?? 0) === $authorId;
$editor = $canPost ? \TinyCat\Status\ViewData::status_editor_view_data() : [];
$canEditProfile = \TinyCat\Users\Profiles::user_can_edit_profile($author, $authUser);
$canSeeMute = $authUser !== null && ($canPost || \TinyCat\Users\Profiles::user_is_admin($authUser));
$mutedUntil = \TinyCat\Moderation\Restrictions::user_muted_until($author);
$canFollow = $authUser !== null && (int) ($authUser['id'] ?? 0) !== $authorId;
$isFollowing = $canFollow && \TinyCat\Users\Following::author_is_followed((int) ($authUser['id'] ?? 0), $authorId);
$followCounts = \TinyCat\Users\Following::author_follow_counts($authorId);
$activityStats = \TinyCat\Users\Repository::author_activity_stats($authorId);
$publicPostCount = (int) ($activityStats['posts'] ?? 0);
$authorEntityId = \TinyCat\Site\Presentation::absolute_url($current) . '#author';
$authorStructuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    '@id' => \TinyCat\Site\Presentation::absolute_url($pageUrl),
    'url' => \TinyCat\Site\Presentation::absolute_url($pageUrl),
    'dateCreated' => $memberSince !== '' ? \TinyCat\Support\Dates::dateIso($memberSince) : null,
    'dateModified' => (string) ($author['updated_at'] ?? '') !== '' ? \TinyCat\Support\Dates::dateIso((string) $author['updated_at']) : null,
    'mainEntity' => [
        '@type' => \TinyCat\Users\Roles::profileSchemaType((string) ($author['role'] ?? '')),
        '@id' => $authorEntityId,
        'name' => $authorName,
        'alternateName' => (string) ($author['username'] ?? ''),
        'identifier' => (string) $authorId,
        'description' => $bio !== '' ? $bio : null,
        'image' => $avatarUrl !== '' ? \TinyCat\Site\Presentation::absolute_url($avatarUrl) : null,
    ],
    'hasPart' => array_values(array_map(static function (array $item) use ($authorEntityId): array {
        $image = \TinyCat\Status\Images::status_image_jsonld($item) ?? (\TinyCat\Status\ViewData::status_meta_link_image($item) ?: null);

        return array_filter([
            '@type' => 'DiscussionForumPosting',
            'url' => \TinyCat\Site\Presentation::absolute_url(\TinyCat\Status\Posts::status_url((int) ($item['id'] ?? 0))),
            'image' => $image,
            'headline' => \TinyCat\Status\ViewData::status_meta_title($item),
            'datePublished' => \TinyCat\Support\Dates::dateIso((string) ($item['published_at'] ?? '')),
            'author' => ['@id' => $authorEntityId],
        ], static fn (mixed $value): bool => $value !== null);
    }, $statusItems)),
];
$authorStructuredData = array_filter($authorStructuredData, static fn (mixed $value): bool => $value !== null && $value !== []);
$authorStructuredData['mainEntity'] = array_filter($authorStructuredData['mainEntity']);

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('public.author_archive_title', ['author' => $authorName]),
    'current' => $current,
    'meta' => [
        'description' => $bio !== ''
            ? $bio
            : \TinyCat\Support\Localization::t('public.author_meta', ['author' => $authorName]),
        'url' => $pageUrl,
        'image' => $avatarUrl ?: \TinyCat\Site\Presentation::site_meta_image_url(),
        'type' => 'profile',
        'rss' => \TinyCat\Users\Profiles::author_feed_url($authorId),
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
                            <button class="btn btn-secondary btn-sm btn-icon profile-edit-toggle" type="button" data-modal-open="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Profiles::author_profile_edit_modal_id($authorId)) ?>" data-modal-url="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Profiles::author_profile_edit_modal_url($authorId, 'bio')) ?>" title="<?= \TinyCat\Support\Html::et('common.edit') ?>" aria-label="<?= \TinyCat\Support\Html::et('common.edit') ?>">
                                <?= \TinyCat\Support\Html::icon('edit') ?>
                            </button>
                        <?php endif; ?>
                        <div class="profile-identity">
                            <?php if ($canEditProfile): ?>
                                <button class="avatar avatar-xl profile-avatar-button" type="button" data-modal-open="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Avatars::author_avatar_edit_modal_id($authorId)) ?>" data-modal-url="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Avatars::author_avatar_edit_modal_url($authorId)) ?>" title="<?= \TinyCat\Support\Html::et('account.avatar_edit') ?>" aria-label="<?= \TinyCat\Support\Html::et('account.avatar_edit') ?>">
                            <?php else: ?>
                                <div class="avatar avatar-xl">
                            <?php endif; ?>
                                <?= \TinyCat\Users\Views::avatar(['user' => $author, 'alt' => $authorName]) ?>
                            <?php if ($canEditProfile): ?>
                                </button>
                            <?php else: ?>
                                </div>
                            <?php endif; ?>
                            <div class="profile-identity-main">
                                <h1 class="profile-name m-0"><?= \TinyCat\Support\Html::e($authorName) ?></h1>
                            </div>
                        </div>
                        <div class="profile-details">
                            <?php if ($canSeeMute && $mutedUntil !== ''): ?>
                                <div class="alert alert-warning">
                                    <?= \TinyCat\Support\Html::icon('lock') ?>
                                    <span><?= \TinyCat\Support\Html::et('moderation.profile_muted_until', ['until' => \TinyCat\Support\Dates::displayDateTime($mutedUntil)]) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($canEditProfile): ?>
                                <button data-author-bio data-author-id="<?= \TinyCat\Support\Html::e($authorId) ?>" class="profile-editable-text" type="button" data-modal-open="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Profiles::author_profile_edit_modal_id($authorId)) ?>" data-modal-url="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Profiles::author_profile_edit_modal_url($authorId, 'bio')) ?>">
                                    <?php if ($bio !== ''): ?>
                                        <?= nl2br(\TinyCat\Support\Html::e($bio)) ?>
                                    <?php else: ?>
                                        <span class="text-muted"><?= \TinyCat\Support\Html::et('account.bio') ?></span>
                                    <?php endif; ?>
                                </button>
                            <?php elseif ($bio !== ''): ?>
                                <p data-author-bio data-author-id="<?= \TinyCat\Support\Html::e($authorId) ?>" class="text-muted mb-0"><?= nl2br(\TinyCat\Support\Html::e($bio)) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="profile-stats">
                            <span class="profile-stat"><strong data-author-stat="followers" data-author-id="<?= \TinyCat\Support\Html::e($authorId) ?>"><?= \TinyCat\Support\Html::e((int) ($followCounts['followers'] ?? 0)) ?></strong> <span><?= \TinyCat\Support\Html::et('public.followers') ?></span></span>
                            <button class="profile-stat profile-stat-button" type="button" data-modal-open="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Following::author_following_modal_id($authorId)) ?>" data-modal-url="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Following::author_following_modal_url($authorId)) ?>" aria-label="<?= \TinyCat\Support\Html::et('public.following_profiles_title', ['author' => $authorName]) ?>" title="<?= \TinyCat\Support\Html::et('public.following_profiles_title', ['author' => $authorName]) ?>">
                                <strong data-author-stat="following" data-author-id="<?= \TinyCat\Support\Html::e($authorId) ?>"><?= \TinyCat\Support\Html::e((int) ($followCounts['following'] ?? 0)) ?></strong>
                                <span><?= \TinyCat\Support\Html::et('public.following') ?></span>
                            </button>
                            <span class="profile-stat"><strong><?= \TinyCat\Support\Html::e((int) ($activityStats['posts'] ?? 0)) ?></strong> <span><?= \TinyCat\Support\Html::et('public.profile_posts') ?></span></span>
                            <span class="profile-stat"><strong><?= \TinyCat\Support\Html::e((int) ($activityStats['likes_given'] ?? 0)) ?></strong> <span><?= \TinyCat\Support\Html::et('public.profile_likes_given') ?></span></span>
                            <span class="profile-stat"><strong><?= \TinyCat\Support\Html::e((int) ($activityStats['likes_received'] ?? 0)) ?></strong> <span><?= \TinyCat\Support\Html::et('public.profile_likes_received') ?></span></span>
                            <span class="profile-stat"><strong><?= \TinyCat\Support\Html::e((int) ($activityStats['comments'] ?? 0)) ?></strong> <span><?= \TinyCat\Support\Html::et('public.profile_comments') ?></span></span>
                            <?php if ($memberSince !== ''): ?>
                                <span class="profile-member-since"><?= \TinyCat\Support\Html::icon('calendar') ?> <span><?= \TinyCat\Support\Html::et('public.member_since', ['date' => \TinyCat\Support\Dates::dateValue($memberSince)]) ?></span></span>
                            <?php endif; ?>
                        </div>
                        <div class="profile-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Profiles::author_feed_url($authorId)) ?>" target="_blank" rel="noopener" title="<?= \TinyCat\Support\Html::et('public.rss_feed') ?>" aria-label="<?= \TinyCat\Support\Html::et('public.rss_feed') ?>">
                                <?= \TinyCat\Support\Html::icon('rss') ?> <span>RSS</span>
                            </a>
                            <?php if ($canFollow): ?>
                                <?= \TinyCat\Users\Views::followButton([
                                    'author_id' => $authorId,
                                    'is_following' => $isFollowing,
                                ]) ?>
                            <?php elseif ($authUser === null): ?>
                                <a class="btn btn-secondary btn-sm" href="/login" data-modal-open="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Login::auth_modal_id()) ?>" data-modal-url="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Login::auth_modal_url()) ?>">
                                    <?= \TinyCat\Support\Html::icon('login') ?> <span><?= \TinyCat\Support\Html::et('public.follow') ?></span>
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
                        <?= \TinyCat\Support\Html::icon('lock') ?> <span><?= \TinyCat\Support\Html::et('moderation.messages.account_muted', ['until' => \TinyCat\Support\Dates::displayDateTime($mutedUntil)]) ?></span>
                    </div>
                <?php elseif ($canPost && $authUser !== null): ?>
                    <?= \TinyCat\Status\Views::composer([
                        'action' => \TinyCat\Users\Profiles::author_url($authorId),
                        'user' => $authUser,
                        'editor' => $editor,
                    ]) ?>
                <?php endif; ?>

                <?php if ($statusItems === []): ?>
                    <div class="alert alert-info" data-status-empty><?= \TinyCat\Support\Html::et('public.author_feed_empty') ?></div>
                <?php endif; ?>
                <div class="status-feed" id="<?= \TinyCat\Support\Html::e($feedId) ?>" data-status-feed>
                    <?= \TinyCat\Support\View::part('status/feed', [
                        'items' => $statusItems,
                        'action' => \TinyCat\Users\Profiles::author_url($authorId),
                        'user' => $authUser,
                    ]) ?>
                </div>
                <?= \TinyCat\Status\Views::feedMore($feedMore) ?>
            </section>
        </div>
    </section>
    <?php
});
