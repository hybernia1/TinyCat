<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$comment = (array) ($comment ?? []);
$history = array_reverse((array) ($history ?? []));
$commentId = (int) ($comment['id'] ?? 0);

if ($commentId < 1) {
    http_response_code(404);
    return;
}

ob_start();
?>
<?php if ($history === []): ?>
    <div class="alert alert-info"><?= et('account.status_comment_history_empty') ?></div>
<?php else: ?>
    <div class="status-comment-history">
        <?php foreach ($history as $entry): ?>
            <?php
            $changes = (array) ($entry['changes'] ?? []);
            $bodyChange = (array) ($changes['body'] ?? []);
            $actor = (array) ($entry['actor'] ?? []);
            $actorName = trim((string) ($actor['username'] ?? ''));
            $actorName = $actorName !== '' ? $actorName : t('account.status_comment_history_unknown');
            $changedAt = (string) ($entry['at'] ?? '');
            $before = (string) ($bodyChange['from'] ?? '');
            $after = (string) ($bodyChange['to'] ?? '');
            ?>
            <article class="status-comment-history-entry">
                <div class="status-comment-history-meta">
                    <strong><?= et('account.status_comment_history_updated_by', ['author' => $actorName]) ?></strong>
                    <?php if ($changedAt !== ''): ?>
                        <time datetime="<?= e(date_iso($changedAt)) ?>"> · <?= e(datetime($changedAt)) ?></time>
                    <?php endif; ?>
                </div>
                <div class="status-comment-history-diff" aria-label="<?= et('account.status_comment_history_change') ?>">
                    <?= status_comment_history_diff_html($before, $after) ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$body = trim((string) ob_get_clean());

echo render('modals/layout', [
    'id' => status_comment_history_modal_id($commentId),
    'title' => t('account.status_comment_history'),
    'icon' => 'clock',
    'size' => 'modal-panel-lg',
    'body' => $body,
]);
