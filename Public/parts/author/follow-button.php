<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$authorId = max(0, (int) ($author_id ?? 0));
$isFollowing = (bool) ($is_following ?? false);

if ($authorId < 1) {
    return '';
}
?>
<form method="post" action="<?= e(author_api_url($authorId, 'follow', ['view' => 'html'])) ?>" data-follow-form data-author-id="<?= e($authorId) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>">
    <button class="btn <?= $isFollowing ? 'btn-secondary' : 'btn-primary' ?> btn-sm" type="submit">
        <?= icon($isFollowing ? 'check' : 'plus') ?> <span><?= et($isFollowing ? 'public.unfollow' : 'public.follow') ?></span>
    </button>
</form>
