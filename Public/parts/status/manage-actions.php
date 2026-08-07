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
$isLocked = status_edit_locked($item) && (string) ($user['role'] ?? '') !== 'admin';
$canEdit = status_can_edit($item, $user);
$canDelete = status_can_delete($item, $user);
$canReport = $user !== null
    && (int) ($item['author_id'] ?? 0) !== (int) ($user['id'] ?? 0);
$hasMenuActions = $canReport || $canEdit || $canDelete;

if ($contentId < 1) {
    return '';
}

$permalinkLabel = status_permalink_label($item);
?>
<div class="status-manage status-manage-top">
    <a class="btn btn-ghost btn-icon btn-sm status-manage-icon" href="<?= e(status_url($contentId)) ?>" title="<?= e($permalinkLabel) ?>" aria-label="<?= e($permalinkLabel) ?>">
        <?= icon('link') ?>
    </a>
    <?php if ($isLocked): ?>
        <span class="btn btn-ghost btn-icon btn-sm status-manage-icon" role="img" title="<?= et('account.status_edit_locked') ?>" aria-label="<?= et('account.status_edit_locked') ?>">
            <?= icon('lock') ?>
        </span>
    <?php endif; ?>
    <?php if ($hasMenuActions): ?>
        <details class="context-menu status-manage-menu" data-dismissible-menu>
            <summary class="btn btn-ghost btn-icon btn-sm status-manage-icon" aria-label="<?= et('common.actions') ?>">
                <?= icon('more') ?>
            </summary>
            <div class="context-menu-popover">
                <?php if ($canReport): ?>
                    <button class="context-menu-action" type="button" data-dismissible-menu-action data-modal-open="<?= e(status_report_modal_id($contentId)) ?>" data-modal-url="<?= e(status_action_modal_url('report', $contentId, $action)) ?>">
                        <?= et('moderation.report_action') ?>
                    </button>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <button class="context-menu-action" type="button" data-dismissible-menu-action data-modal-open="<?= e(status_edit_modal_id($contentId)) ?>" data-modal-url="<?= e(status_action_modal_url('edit', $contentId, $action)) ?>">
                        <?= et('common.edit') ?>
                    </button>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form method="post" action="<?= e(status_api_url('delete', ['id' => $contentId])) ?>" data-status-form data-status-id="<?= e($contentId) ?>" data-confirm="<?= et('account.status_delete_confirm') ?>" data-confirm-title="<?= et('account.status_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= e($contentId) ?>">
                        <button class="context-menu-action is-danger" type="submit" data-dismissible-menu-action><?= et('account.status_delete') ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>
</div>
