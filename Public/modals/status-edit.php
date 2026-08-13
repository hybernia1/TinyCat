<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = (array) ($item ?? []);
$user = is_array($user ?? null) ? $user : [];
$editor = is_array($editor ?? null) ? $editor : [];
$action = (string) ($action ?? '');
$contentId = (int) ($item['id'] ?? 0);

if ($contentId < 1) {
    http_response_code(404);
    return;
}

$modalId = status_edit_modal_id($contentId);

ob_start();
?>
<input type="hidden" name="action" value="update">
<input type="hidden" name="id" value="<?= e($contentId) ?>">
<?= part('status/composer-body', [
    'user' => $user,
    'editor' => $editor,
    'submit_name' => 'save',
    'submit_label' => t('account.status_save'),
    'submit_icon' => 'save',
]) ?>
<?php
$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>';

echo render('modals/layout', [
    'id' => $modalId,
    'title' => t('account.status_edit'),
    'icon' => 'edit',
    'action' => $action,
    'ajax' => false,
    'multipart' => true,
    'size' => 'modal-panel-lg status-edit-modal-panel',
    'bodyClass' => 'status-edit-modal-body',
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
