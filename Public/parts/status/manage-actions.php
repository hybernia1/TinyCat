<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$user = is_array($user ?? null) ? $user : null;
$action = (string) ($action ?? '/');
$contentId = (int) ($item['id'] ?? 0);
$isLocked = status_edit_locked($item);
$canEdit = status_can_edit($item, $user);
$canDelete = status_can_delete($item, $user);
$canReport = $user !== null
    && (int) ($item['author_id'] ?? 0) !== (int) ($user['id'] ?? 0);

if ($contentId < 1) {
    return '';
}
?>
<div class="status-manage status-manage-top">
    <a class="btn btn-ghost btn-icon btn-sm status-manage-icon" href="<?= e(status_url($contentId)) ?>" title="<?= et('account.status_permalink') ?>" aria-label="<?= et('account.status_permalink') ?>">
        <?= icon('link') ?>
    </a>
    <?php if ($isLocked): ?>
        <span class="btn btn-ghost btn-icon btn-sm status-manage-icon" title="<?= et('account.status_edit_locked') ?>" aria-label="<?= et('account.status_edit_locked') ?>">
            <?= icon('lock') ?>
        </span>
    <?php endif; ?>
    <?php if ($canReport): ?>
        <button class="btn btn-ghost btn-icon btn-sm status-manage-icon" type="button" data-modal-open="<?= e(status_report_modal_id($contentId)) ?>" data-modal-url="<?= e(status_action_modal_url('report', $contentId, $action)) ?>" title="<?= et('moderation.report_status') ?>" aria-label="<?= et('moderation.report_status') ?>">
            <?= icon('flag') ?>
        </button>
    <?php endif; ?>
    <?php if ($canEdit): ?>
        <button class="btn btn-ghost btn-icon btn-sm status-manage-icon" type="button" data-modal-open="<?= e(status_edit_modal_id($contentId)) ?>" data-modal-url="<?= e(status_action_modal_url('edit', $contentId, $action)) ?>" title="<?= et('account.status_edit') ?>" aria-label="<?= et('account.status_edit') ?>">
            <?= icon('edit') ?>
        </button>
    <?php endif; ?>
    <?php if ($canDelete): ?>
        <form method="post" action="<?= e(status_api_url('delete', ['id' => $contentId])) ?>" data-status-form data-status-id="<?= e($contentId) ?>" data-confirm="<?= et('account.status_delete_confirm') ?>" data-confirm-title="<?= et('account.status_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e($contentId) ?>">
            <button class="btn btn-ghost btn-icon btn-sm status-manage-icon text-danger" type="submit" title="<?= et('account.status_delete') ?>" aria-label="<?= et('account.status_delete') ?>">
                <?= icon('trash') ?>
            </button>
        </form>
    <?php endif; ?>
</div>
