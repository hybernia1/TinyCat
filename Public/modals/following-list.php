<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$author = (array) ($author ?? []);
$authorId = (int) ($author_id ?? 0);
$profiles = (array) ($profiles ?? []);
$page = max(1, (int) ($page ?? 1));
$lastPage = max(1, (int) ($last_page ?? 1));
$total = max(0, (int) ($total ?? 0));

if ($authorId < 1) {
    http_response_code(404);
    return;
}

ob_start();
?>
<div class="following-modal-grid">
    <?php foreach ($profiles as $profile): ?>
        <?= author_following_profile_html((array) $profile) ?>
    <?php endforeach; ?>
</div>
<?php
$body = trim((string) ob_get_clean());

ob_start();
?>
<span class="following-modal-page"><?= et('public.following_profiles_page', ['page' => $page, 'pages' => $lastPage, 'total' => $total]) ?></span>
<?php if ($page > 1): ?>
    <button class="btn btn-secondary btn-sm" type="button" data-modal-open="<?= e(author_following_modal_id($authorId)) ?>" data-modal-url="<?= e(author_following_modal_url($authorId, $page - 1)) ?>">
        <?= icon('arrow-left') ?> <span><?= et('common.previous') ?></span>
    </button>
<?php endif; ?>
<?php if ($page < $lastPage): ?>
    <button class="btn btn-primary btn-sm" type="button" data-modal-open="<?= e(author_following_modal_id($authorId)) ?>" data-modal-url="<?= e(author_following_modal_url($authorId, $page + 1)) ?>">
        <span><?= et('common.next') ?></span> <?= icon('arrow-right') ?>
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
