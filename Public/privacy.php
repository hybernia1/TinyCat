<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

layout('layout', [
    'title' => t('privacy.title'),
    'current' => '/privacy',
    'meta' => [
        'description' => t('privacy.meta', ['site' => site_name()]),
        'url' => '/privacy',
        'image' => site_meta_image_url(),
        'type' => 'article',
    ],
], static function (): void {
    $translationVars = ['site' => site_name()];
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
                <h1 class="text-xl m-0 cluster gap-2"><?= icon('shield') ?> <?= et('privacy.title') ?></h1>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= et('privacy.intro', ['site' => site_name()]) ?></p>
            </div>
        </article>

        <div class="grid">
            <?php foreach ($privacyCards as [$titleKey, $iconName, $paragraphKeys]): ?>
                <article class="card">
                    <div class="card-header">
                        <h2 class="text-lg m-0 cluster gap-2"><?= icon($iconName) ?> <?= et($titleKey, $translationVars) ?></h2>
                    </div>
                    <div class="card-body stack">
                        <?php foreach ($paragraphKeys as $paragraphKey): ?>
                            <p class="mb-0"><?= et($paragraphKey, $translationVars) ?></p>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('shield') ?> <?= et('privacy.limits_title') ?></h2>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= et('privacy.limits_intro') ?></p>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?= et('privacy.reputation') ?></th>
                                <th><?= et('privacy.posts') ?></th>
                                <th><?= et('privacy.comments') ?></th>
                                <th><?= et('privacy.likes') ?></th>
                                <th><?= et('privacy.reports') ?></th>
                                <th><?= et('privacy.searches') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (tc_privacy_limit_rows() as $row): ?>
                                <tr>
                                    <td><strong><?= e($row['label']) ?></strong></td>
                                    <td><?= e($row['post']) ?></td>
                                    <td><?= e($row['comment']) ?></td>
                                    <td><?= e($row['like']) ?></td>
                                    <td><?= e($row['report']) ?></td>
                                    <td><?= e($row['search']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </article>

        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('info') ?> <?= et('privacy.cookies_title') ?></h2>
            </div>
            <div class="card-body stack">
                <p class="mb-0"><?= et('privacy.cookies_session') ?></p>
                <?php if (site_captcha_enabled()): ?>
                    <p class="mb-0"><?= et('privacy.cookies_captcha') ?></p>
                <?php endif; ?>
                <p class="mb-0"><?= et('privacy.cookies_remember') ?></p>
                <p class="mb-0"><?= et(
                    site_google_analytics_configured() ? 'privacy.cookies_analytics' : 'privacy.cookies_no_analytics',
                    ['site' => site_name()],
                ) ?></p>
            </div>
        </article>
    </section>
    <?php
});


function tc_privacy_limit_rows(): array
{
    $labels = [
        'new' => t('privacy.reputation_new'),
        'normal' => t('privacy.reputation_normal'),
        'trusted' => t('privacy.reputation_trusted'),
    ];
    $rules = moderation_action_rules();
    $rows = [];

    foreach ($labels as $key => $label) {
        $rule = (array) ($rules[$key] ?? []);
        $rows[] = [
            'label' => $label,
            'post' => tc_privacy_limit_label((array) ($rule['post'] ?? [])),
            'comment' => tc_privacy_limit_label((array) ($rule['comment'] ?? [])),
            'like' => tc_privacy_limit_label((array) ($rule['like'] ?? [])),
            'report' => tc_privacy_limit_label((array) ($rule['report'] ?? [])),
            'search' => tc_privacy_limit_label((array) ($rule['search'] ?? [])),
        ];
    }

    return $rows;
}

function tc_privacy_limit_label(array $rule): string
{
    $window = max(60, (int) ($rule[0] ?? 3600));
    $limit = max(0, (int) ($rule[1] ?? 0));
    $hours = max(1, (int) ceil($window / 3600));

    return $hours === 1
        ? t('privacy.limit_per_hour', ['count' => $limit])
        : t('privacy.limit_per_hours', ['count' => $limit, 'hours' => $hours]);
}
