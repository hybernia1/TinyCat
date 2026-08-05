<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

$moderationBlockingApi = route_path() === '/api/admin/moderation/blocking';

if (!$moderationBlockingApi && method() !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo 'Method not allowed.';
    return;
}

if ($moderationBlockingApi && method() === 'GET') {
    api_ok(tc_admin_moderation_blocking_payload());
}

if ($moderationBlockingApi && method() === 'POST') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $rules = moderation_blocked_url_rules((string) input('blocked_urls', ''));
        setting_set('moderation.blocked_urls', implode(', ', $rules), 'string', 'moderation');
        flash('success', t('moderation.messages.url_blocker_saved'));
        api_ok(tc_admin_moderation_blocking_payload(true), t('moderation.messages.url_blocker_saved'));
    });
}

if ($moderationBlockingApi) {
    api_error('Method not allowed.', 405, 'method_not_allowed');
}

$blockedUrls = tc_admin_moderation_blocked_urls_value();

layout('layout', [
    'title' => t('moderation.url_blocker_title'),
    'current' => '/admin/moderation/blocking',
], static function () use ($blockedUrls): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('lock') ?> <?= et('moderation.url_blocker_title') ?></h2>
        </div>
        <form method="post" action="/api/admin/moderation/blocking" data-ajax-form>
            <?= csrf_field() ?>
            <div class="card-body stack">
                <label class="field">
                    <span class="label"><?= et('moderation.url_blocker_label') ?></span>
                    <textarea class="textarea" name="blocked_urls" rows="6" placeholder="<?= et('moderation.url_blocker_placeholder') ?>"><?= e($blockedUrls) ?></textarea>
                </label>
                <p class="text-muted m-0"><?= et('moderation.url_blocker_help') ?></p>
            </div>
            <div class="card-footer cluster justify-end">
                <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button>
            </div>
        </form>
    </section>
    <?php
});

function tc_admin_moderation_blocked_urls_value(): string
{
    return implode(', ', moderation_blocked_url_rules());
}

function tc_admin_moderation_blocking_payload(bool $redirect = false): array
{
    $rules = moderation_blocked_url_rules();

    $payload = [
        'items' => $rules,
        'value' => implode(', ', $rules),
    ];

    if ($redirect) {
        $payload['redirect'] = '/admin/moderation/blocking';
    }

    return $payload;
}
