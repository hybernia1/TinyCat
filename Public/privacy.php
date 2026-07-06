<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

layout('layout', [
    'title' => t('privacy.title'),
    'current' => '/privacy',
    'meta' => [
        'description' => t('privacy.meta'),
        'url' => '/privacy',
        'image' => site_meta_image_url(),
        'type' => 'article',
    ],
], static function (): void {
    ?>
    <section class="stack" style="max-width: 920px; margin-inline: auto;">
        <article class="card">
            <div class="card-header">
                <h1 class="text-xl m-0 cluster gap-2"><?= icon('shield') ?> <?= et('privacy.title') ?></h1>
            </div>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= et('privacy.intro') ?></p>
            </div>
        </article>

        <div class="grid lg:grid-2">
            <?= tc_privacy_card('privacy.data_title', 'database', [
                'privacy.data_account',
                'privacy.data_content',
                'privacy.data_technical',
                'privacy.data_server_logs',
            ]) ?>

            <?= tc_privacy_card('privacy.recovery_title', 'key', [
                'privacy.recovery_hash',
                'privacy.recovery_no_contact',
                'privacy.recovery_lost',
                'privacy.recovery_rotation',
            ]) ?>

            <?= tc_privacy_card('privacy.reporting_title', 'flag', [
                'privacy.reporting_how',
                'privacy.reporting_duplicate',
                'privacy.reporting_result',
                'privacy.reporting_notification',
            ]) ?>

            <?= tc_privacy_card('privacy.moderation_title', 'lock', [
                'privacy.moderation_right',
                'privacy.moderation_actions',
                'privacy.moderation_mute',
                'privacy.moderation_domains',
            ]) ?>
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
                                <th><?= et('privacy.shares') ?></th>
                                <th><?= et('privacy.comments') ?></th>
                                <th><?= et('privacy.likes') ?></th>
                                <th><?= et('privacy.reports') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (tc_privacy_limit_rows() as $row): ?>
                                <tr>
                                    <td><strong><?= e($row['label']) ?></strong></td>
                                    <td><?= e($row['post']) ?></td>
                                    <td><?= e($row['share']) ?></td>
                                    <td><?= e($row['comment']) ?></td>
                                    <td><?= e($row['like']) ?></td>
                                    <td><?= e($row['report']) ?></td>
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
                <p class="mb-0"><?= et('privacy.cookies_remember') ?></p>
                <p class="mb-0"><?= et('privacy.cookies_no_ads') ?></p>
            </div>
        </article>
    </section>
    <?php
});

function tc_privacy_card(string $titleKey, string $icon, array $paragraphKeys): string
{
    ob_start();
    ?>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon($icon) ?> <?= et($titleKey) ?></h2>
        </div>
        <div class="card-body stack">
            <?php foreach ($paragraphKeys as $key): ?>
                <p class="mb-0"><?= et($key) ?></p>
            <?php endforeach; ?>
        </div>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

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
            'share' => tc_privacy_limit_label((array) ($rule['share'] ?? [])),
            'comment' => tc_privacy_limit_label((array) ($rule['comment'] ?? [])),
            'like' => tc_privacy_limit_label((array) ($rule['like'] ?? [])),
            'report' => tc_privacy_limit_label((array) ($rule['report'] ?? [])),
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
