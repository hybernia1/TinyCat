<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = (array) ($user ?? []);
$authorId = (int) ($author_id ?? 0);
$action = (string) ($action ?? '');
$focus = (string) ($focus ?? '');
$bio = trim((string) ($user['bio'] ?? ''));
$selectedLocale = language_code((string) ($user['locale'] ?? '')) ?: locale();
$selectedTheme = user_theme($user);

if ($authorId < 1 || $action === '') {
    http_response_code(404);
    return;
}

$autofocus = static fn (string $name): string => $focus === $name ? ' autofocus' : '';

ob_start();
?>
<input type="hidden" name="action" value="profile">
<div class="profile-modal-grid">
    <label class="field">
        <span class="label"><?= et('common.language') ?></span>
        <select class="select" name="locale" required<?= $autofocus('locale') ?>>
            <?= language_options($selectedLocale) ?>
        </select>
    </label>
    <label class="field">
        <span class="label"><?= et('account.theme') ?></span>
        <select class="select" name="theme" required<?= $autofocus('theme') ?>>
            <?= theme_options($selectedTheme) ?>
        </select>
    </label>
    <label class="field profile-modal-span">
        <span class="label"><?= et('account.bio') ?></span>
        <textarea class="textarea" name="bio" rows="6" maxlength="500"<?= $autofocus('bio') ?>><?= e($bio) ?></textarea>
    </label>
</div>
<?php

$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('account.save_profile') . '</span></button>';

echo render('modals/layout', [
    'id' => author_profile_edit_modal_id($authorId),
    'title' => t('account.profile_settings'),
    'icon' => 'edit',
    'action' => $action,
    'ajax' => true,
    'size' => 'modal-panel-lg profile-edit-modal-panel',
    'formAttributes' => [
        'data-confirm-unsaved' => 'true',
        'data-confirm-unsaved-title' => t('common.unsaved_title'),
        'data-confirm-unsaved-message' => t('common.unsaved_message'),
        'data-confirm-unsaved-ok' => t('common.leave'),
        'data-confirm-unsaved-cancel' => t('common.stay'),
    ],
    'body' => $body,
    'footer' => $footer,
]);
