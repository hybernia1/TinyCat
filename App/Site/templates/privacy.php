<?php
declare(strict_types=1);
/** @var list<array<string, string>> $privacyLimitRows */
/** @var mixed $siteName */
/** @var mixed $siteCaptchaEnabled */
/** @var mixed $siteGoogleAnalyticsConfigured */
$translationVars = ['site' => $siteName];
$privacyCards = [
    ['privacy.public_title', 'globe', [
        'privacy.public_open',
        'privacy.public_content',
        'privacy.public_images',
        'privacy.public_no_private',
        'privacy.public_no_personal',
    ]],
    ['privacy.feed_title', 'rss', [
        'privacy.feed_no_algorithm',
        'privacy.feed_following',
        'privacy.feed_no_shadow_bans',
    ]],
    ['privacy.data_title', 'database', [
        'privacy.data_account',
        'privacy.data_email',
        'privacy.data_content',
        'privacy.data_technical',
        'privacy.data_server_logs',
    ]],
    ['privacy.recovery_title', 'key', [
        'privacy.recovery_email',
        'privacy.recovery_no_contact',
        'privacy.recovery_lost',
        'privacy.recovery_rotation',
    ]],
    ['privacy.reporting_title', 'flag', [
        'privacy.reporting_how',
        'privacy.reporting_duplicate',
        'privacy.reporting_notification',
    ]],
    ['privacy.moderation_title', 'lock', [
        'privacy.moderation_right',
        'privacy.moderation_actions',
        'privacy.moderation_mute',
    ]],
];
?>
<section class="stack max-w-prose mx-auto">
    <article class="card">
        <div class="card-header">
            <h1 class="text-xl m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('shield') ?> <?= \TinyCat\Support\Html::et('privacy.title') ?></h1>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('privacy.intro', ['site' => $siteName]) ?></p>
        </div>
    </article>

    <div class="grid">
        <?php foreach ($privacyCards as [$titleKey, $iconName, $paragraphKeys]): ?>
            <article class="card">
                <div class="card-header">
                    <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon($iconName) ?> <?= \TinyCat\Support\Html::et($titleKey, $translationVars) ?></h2>
                </div>
                <div class="card-body stack">
                    <?php foreach ($paragraphKeys as $paragraphKey): ?>
                        <p class="mb-0"><?= \TinyCat\Support\Html::et($paragraphKey, $translationVars) ?></p>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('shield') ?> <?= \TinyCat\Support\Html::et('privacy.limits_title') ?></h2>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('privacy.limits_intro') ?></p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= \TinyCat\Support\Html::et('privacy.reputation') ?></th>
                            <th><?= \TinyCat\Support\Html::et('privacy.posts') ?></th>
                            <th><?= \TinyCat\Support\Html::et('privacy.comments') ?></th>
                            <th><?= \TinyCat\Support\Html::et('privacy.likes') ?></th>
                            <th><?= \TinyCat\Support\Html::et('privacy.reports') ?></th>
                            <th><?= \TinyCat\Support\Html::et('privacy.searches') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($privacyLimitRows as $row): ?>
                            <tr>
                                <td><strong><?= \TinyCat\Support\Html::e($row['label']) ?></strong></td>
                                <td><?= \TinyCat\Support\Html::e($row['post']) ?></td>
                                <td><?= \TinyCat\Support\Html::e($row['comment']) ?></td>
                                <td><?= \TinyCat\Support\Html::e($row['like']) ?></td>
                                <td><?= \TinyCat\Support\Html::e($row['report']) ?></td>
                                <td><?= \TinyCat\Support\Html::e($row['search']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </article>

    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('info') ?> <?= \TinyCat\Support\Html::et('privacy.cookies_title') ?></h2>
        </div>
        <div class="card-body stack">
            <p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.cookies_session') ?></p>
            <?php if ($siteCaptchaEnabled): ?>
                <p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.cookies_captcha') ?></p>
            <?php endif; ?>
            <p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.cookies_remember') ?></p>
            <p class="mb-0"><?= \TinyCat\Support\Html::et(
                $siteGoogleAnalyticsConfigured ? 'privacy.cookies_analytics' : 'privacy.cookies_no_analytics',
                ['site' => $siteName],
            ) ?></p>
        </div>
    </article>
</section>
