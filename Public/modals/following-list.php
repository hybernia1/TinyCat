<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$author = (array) ($author ?? []);
$authorId = (int) ($author_id ?? 0);
$profiles = (array) ($profiles ?? []);
$done = (bool) ($done ?? true);
$nextUrl = (string) ($next_url ?? '');

if ($authorId < 1) {
    http_response_code(404);
    return;
}

ob_start();
?>
<div class="following-modal-grid">
    <?php foreach ($profiles as $profile): ?>
        <?= part('author/following-profile', ['profile' => (array) $profile]) ?>
    <?php endforeach; ?>
</div>
<?php
$body = trim((string) ob_get_clean());

ob_start();
?>
<?php if (!$done && $nextUrl !== ''): ?>
    <button class="btn btn-primary btn-sm" type="button" data-following-load data-following-url="<?= e($nextUrl) ?>">
        <?= icon('plus') ?> <span><?= et('public.load_more_posts') ?></span>
    </button>
<?php endif; ?>
<?php
$footer = trim((string) ob_get_clean());

echo render('modals/layout', [
    'id' => author_following_modal_id($authorId),
    'title' => t('public.following_profiles_title', ['author' => user_display_name($author)]),
    'icon' => 'users',
    'size' => 'modal-panel-lg following-modal-panel',
    'bodyClass' => 'modal-body following-modal-body',
    'body' => $body,
    'footer' => $footer,
]);
