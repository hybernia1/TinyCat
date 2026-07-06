<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = (array) ($item ?? []);
$user = isset($user) && is_array($user) ? $user : null;
$action = (string) ($action ?? '');
$contentId = (int) ($item['id'] ?? 0);

if ($contentId < 1) {
    http_response_code(404);
    return;
}

if ($user === null) {
    return;
}

$modalId = status_share_modal_id($contentId);

ob_start();
?>
<input type="hidden" name="action" value="share">
<input type="hidden" name="id" value="<?= e($contentId) ?>">
<label class="field">
    <span class="sr-only"><?= et('account.status_share_body') ?></span>
    <textarea class="textarea status-share-note" name="body" rows="3" maxlength="2000" placeholder="<?= et('account.status_share_body') ?>"></textarea>
</label>
<div class="status-share-preview">
    <?= status_embedded_share_card($item) ?>
</div>
<?php
$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('share') . ' <span>' . et('account.status_share_submit') . '</span></button>';

echo render('modals/layout', [
    'id' => $modalId,
    'title' => t('account.status_share_title'),
    'icon' => 'share',
    'action' => $action,
    'ajax' => false,
    'size' => 'modal-panel-lg status-share-modal-panel',
    'formAttributes' => [
        'data-status-form' => true,
        'data-status-id' => $contentId,
        'data-confirm-unsaved' => 'true',
        'data-confirm-unsaved-title' => t('common.unsaved_title'),
        'data-confirm-unsaved-message' => t('common.unsaved_message'),
        'data-confirm-unsaved-ok' => t('common.leave'),
        'data-confirm-unsaved-cancel' => t('common.stay'),
    ],
    'body' => $body,
    'footer' => $footer,
]);
