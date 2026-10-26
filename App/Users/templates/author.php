<?php
declare(strict_types=1);
/** @var array<mixed> $author */
/** @var int $authorId */
/** @var string $authorName */
/** @var string $bio */
/** @var string $memberSince */
/** @var array<mixed> $statusItems */
/** @var bool $canPost */
/** @var bool $canEditProfile */
/** @var array<mixed>|null $authUser */
/** @var bool $canSeeMute */
/** @var string $mutedUntil */
/** @var bool $canFollow */
/** @var bool $isFollowing */
/** @var array<mixed> $followCounts */
/** @var array<mixed> $activityStats */
/** @var array<mixed> $feedMore */
/** @var array<mixed> $editor */
/** @var mixed $authorProfileEditModalId */
/** @var mixed $authorProfileEditModalUrl */
/** @var mixed $authorAvatarEditModalId */
/** @var mixed $authorAvatarEditModalUrl */
/** @var mixed $authorFollowingModalId */
/** @var mixed $authorFollowingModalUrl */
/** @var mixed $authorFeedUrl */
/** @var mixed $authModalId */
/** @var mixed $authModalUrl */
/** @var mixed $authorUrl */
$feedId = 'status-feed-author-' . $authorId;
?>
<section class="profile-layout">
    <aside class="profile-sidebar">
        <div class="profile-sidebar-stack">
            <article class="card profile-card">
                <div class="card-body profile-card-body">
                    <?php if ($canEditProfile): ?>
                        <button class="btn btn-secondary btn-sm btn-icon profile-edit-toggle" type="button" data-modal-open="<?= \TinyCat\Support\Html::e($authorProfileEditModalId) ?>" data-modal-url="<?= \TinyCat\Support\Html::e($authorProfileEditModalUrl) ?>" title="<?= \TinyCat\Support\Html::et('common.edit') ?>" aria-label="<?= \TinyCat\Support\Html::et('common.edit') ?>">
                            <?= \TinyCat\Support\Html::icon('edit') ?>
                        </button>
                    <?php endif; ?>
                    <div class="profile-identity">
                        <?php if ($canEditProfile): ?>
                            <button class="avatar avatar-xl profile-avatar-button" type="button" data-modal-open="<?= \TinyCat\Support\Html::e($authorAvatarEditModalId) ?>" data-modal-url="<?= \TinyCat\Support\Html::e($authorAvatarEditModalUrl) ?>" title="<?= \TinyCat\Support\Html::et('account.avatar_edit') ?>" aria-label="<?= \TinyCat\Support\Html::et('account.avatar_edit') ?>">
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
                            <button data-author-bio data-author-id="<?= \TinyCat\Support\Html::e($authorId) ?>" class="profile-editable-text" type="button" data-modal-open="<?= \TinyCat\Support\Html::e($authorProfileEditModalId) ?>" data-modal-url="<?= \TinyCat\Support\Html::e($authorProfileEditModalUrl) ?>">
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
                        <button class="profile-stat profile-stat-button" type="button" data-modal-open="<?= \TinyCat\Support\Html::e($authorFollowingModalId) ?>" data-modal-url="<?= \TinyCat\Support\Html::e($authorFollowingModalUrl) ?>" aria-label="<?= \TinyCat\Support\Html::et('public.following_profiles_title', ['author' => $authorName]) ?>" title="<?= \TinyCat\Support\Html::et('public.following_profiles_title', ['author' => $authorName]) ?>">
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
                        <a class="btn btn-secondary btn-sm" href="<?= \TinyCat\Support\Html::e($authorFeedUrl) ?>" target="_blank" rel="noopener" title="<?= \TinyCat\Support\Html::et('public.rss_feed') ?>" aria-label="<?= \TinyCat\Support\Html::et('public.rss_feed') ?>">
                            <?= \TinyCat\Support\Html::icon('rss') ?> <span>RSS</span>
                        </a>
                        <?php if ($canFollow): ?>
                            <?= \TinyCat\Users\Views::followButton([
                                'author_id' => $authorId,
                                'is_following' => $isFollowing,
                            ]) ?>
                        <?php elseif ($authUser === null): ?>
                            <a class="btn btn-secondary btn-sm" href="/login" data-modal-open="<?= \TinyCat\Support\Html::e($authModalId) ?>" data-modal-url="<?= \TinyCat\Support\Html::e($authModalUrl) ?>">
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
                    'action' => $authorUrl,
                    'user' => $authUser,
                    'editor' => $editor,
                ]) ?>
            <?php endif; ?>

            <?php if ($statusItems === []): ?>
                <div class="alert alert-info" data-status-empty><?= \TinyCat\Support\Html::et('public.author_feed_empty') ?></div>
            <?php endif; ?>
            <div class="status-feed" id="<?= \TinyCat\Support\Html::e($feedId) ?>" data-status-feed>
                <?= \TinyCat\Support\View::part('Status/feed', [
                    'items' => $statusItems,
                    'action' => $authorUrl,
                    'user' => $authUser,
                ]) ?>
            </div>
            <?= \TinyCat\Status\Views::feedMore($feedMore) ?>
        </section>
    </div>
</section>
