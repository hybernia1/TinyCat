<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$commentId = (int) ($comment_id ?? 0);
$contentId = (int) ($content_id ?? 0);

if ($commentId < 1) {
    return '';
}
?>
<form class="status-comment-delete" method="post" action="<?= e(status_api_url('comment-delete', ['comment_id' => $commentId])) ?>" data-status-form<?= $contentId > 0 ? ' data-status-id="' . e($contentId) . '"' : '' ?> data-confirm="<?= et('account.status_comment_delete_confirm') ?>" data-confirm-title="<?= et('account.status_comment_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="comment_delete">
    <input type="hidden" name="comment_id" value="<?= e($commentId) ?>">
    <button class="link-button text-danger" type="submit"><?= et('account.status_comment_delete') ?></button>
</form>
