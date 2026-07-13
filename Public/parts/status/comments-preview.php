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
$latestComment = $contentId > 0 ? status_latest_parent_comment($contentId) : null;
$commentsCount = array_key_exists('comments_count', $item)
    ? (int) ($item['comments_count'] ?? 0)
    : status_comment_count($contentId);

if ($contentId < 1 || $latestComment === null) {
    return '';
}
?>
<section class="status-comments">
    <a class="link-button status-comments-open" href="<?= e(status_url($contentId) . '#status-comments-thread-' . $contentId) ?>" data-modal-open data-status-comments-label>
        <?= et('account.status_view_comments', ['count' => $commentsCount]) ?>
    </a>
    <?= status_comment_item($latestComment, $user, $action, 0, 'preview-' . $contentId, false, false) ?>
</section>
