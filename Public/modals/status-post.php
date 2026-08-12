<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = (array) ($item ?? []);
$comments = is_array($comments ?? null) ? $comments : [];
$user = isset($user) && is_array($user) ? $user : null;
$action = (string) ($action ?? '');
$contentId = (int) ($item['id'] ?? 0);

if ($contentId < 1) {
    http_response_code(404);
    return;
}

$modalId = status_post_modal_id($contentId);

ob_start();
?>
<div class="status-post-modal-content">
    <?= part('status/card', [
        'item' => $item,
        'user' => $user,
        'action' => $action,
        'anchor' => '',
        'open_modal' => false,
        'open_comments_modal' => false,
    ]) ?>
    <?= part('status/comments-thread', [
        'item' => $item,
        'comments' => $comments,
        'user' => $user,
        'action' => $action,
        'context' => 'modal-' . $contentId,
    ]) ?>
</div>
<?php
$body = trim((string) ob_get_clean());

echo render('modals/layout', [
    'id' => $modalId,
    'title' => t('account.status_thread_title'),
    'modalClass' => 'status-post-modal modal-mobile-fullscreen',
    'size' => 'modal-panel-lg status-post-modal-panel',
    'bodyClass' => 'status-post-modal-body',
    'body' => $body,
]);
