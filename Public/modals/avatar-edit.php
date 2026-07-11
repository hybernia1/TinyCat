<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = (array) ($user ?? []);
$authorId = (int) ($author_id ?? 0);
$action = (string) ($action ?? '');
$username = username_normalize((string) ($user['username'] ?? ''));
$avatarUrl = user_avatar_url($user);

if ($authorId < 1 || $action === '' || !username_valid($username)) {
    http_response_code(404);
    return;
}

ob_start();
?>
<div class="avatar-upload" data-avatar-upload>
    <div class="avatar-upload-preview" aria-label="<?= et('account.avatar_preview') ?>">
        <img<?= $avatarUrl !== '' ? ' src="' . e($avatarUrl) . '"' : '' ?> alt="<?= et('account.avatar_preview') ?>" data-avatar-upload-preview<?= $avatarUrl === '' ? ' hidden' : '' ?>>
        <span class="avatar-upload-empty" data-avatar-upload-empty<?= $avatarUrl !== '' ? ' hidden' : '' ?>>
            <?= icon('user') ?>
            <span><?= et('account.avatar_upload_empty') ?></span>
        </span>
    </div>
    <label class="avatar-upload-drop">
        <input class="sr-only" type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required data-avatar-upload-input>
        <?= icon('upload') ?>
        <span><?= et('account.avatar_upload_label') ?></span>
    </label>
</div>
<?php

$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('account.save_avatar') . '</span></button>';

echo render('modals/layout', [
    'id' => author_avatar_edit_modal_id($authorId),
    'title' => t('account.avatar_edit'),
    'icon' => 'upload',
    'action' => $action,
    'ajax' => true,
    'multipart' => true,
    'size' => 'avatar-edit-modal-panel',
    'formAttributes' => [
        'data-avatar-upload-form' => 'true',
        'data-confirm-unsaved' => 'true',
        'data-confirm-unsaved-title' => t('common.unsaved_title'),
        'data-confirm-unsaved-message' => t('common.unsaved_message'),
        'data-confirm-unsaved-ok' => t('common.leave'),
        'data-confirm-unsaved-cancel' => t('common.stay'),
    ],
    'body' => $body,
    'footer' => $footer,
]);
